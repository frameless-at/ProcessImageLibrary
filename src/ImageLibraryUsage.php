<?php namespace ProcessWire;

/**
 * Where-used index for ProcessImageLibrary.
 *
 * Answers "where is THIS image embedded in rich-text content across the
 * whole site?" as a fast, at-a-glance lookup instead of a live query per
 * image. The naive approach — running findImageReferences() per table row
 * at render time — costs rows × textarea-fields queries on every page
 * build, which doesn't scale. So we invert the question: scan all
 * rich-text content ONCE, build an index keyed by image, and let each row
 * do an O(1) lookup.
 *
 *     (img_page_id, img_stem)  ->  [ (ref_page_id, field_name) … ]
 *
 * The shape deliberately mirrors the deduplication engine (ImageLibraryHashing):
 * a lazily-created DB table, a budgeted first scan that converges over a few
 * passes, prune-then-add maintenance on save, and an hourly LazyCron reconcile.
 * It reuses the same reference grammar pwimage embeds use — both the direct
 * same-page form and the cross-page "Insert from library" copy tagged with
 * -pid<sourceId> — so it covers exactly what the rename / delete preflight does.
 *
 * Composed into ProcessImageLibrary via `use`. Relies on the host class for:
 *   - $this->loadImageRowsAll()   (full storage-truth row enumeration)
 *   - $this->usageRefForPage()    (repeater item -> owner page resolution)
 *   - $this->fieldValueMatches()  (not used here, but the sibling matcher)
 *
 * See docs/where-used-index-design.md for the full rationale.
 */
trait ImageLibraryUsage {

	/** Reverse usage index. One row per (source image, referencing page,
	 *  referencing field). ASCII charset like the hash table: ids, sanitised
	 *  field names and image stems are all ASCII, keeping the composite key
	 *  well under InnoDB's index-length limit. */
	const USAGE_TABLE = 'process_imagelibrary_usage';

	/** Wall-clock budget for one usage scan request (ms). The maintenance
	 *  loop calls the scan repeatedly with a moving offset until it reports
	 *  complete, so a large site never blocks a single request past this. */
	const USAGE_SCAN_BUDGET_MS = 8000;

	/**
	 * Create the usage table if it isn't there yet. Runs its CREATE at most
	 * once per request (static guard) and only when an indexing path is
	 * actually exercised, so normal admin page loads pay nothing.
	 */
	protected function ensureUsageTable(): void {
		static $ensured = false;
		if ($ensured) return;
		$ensured = true;

		$table = self::USAGE_TABLE;
		// img_stem holds the original basename minus its final extension
		// (the prefix pwimage embeds as "/files/<pid>/<stem>.<variation>.<ext>").
		// PRIMARY KEY makes (image, referencing page, field) unique so a
		// re-scan of the same content can INSERT IGNORE without duplicating.
		// idx_ref drives the per-page prune (delete one referencing page's rows).
		$sql = "CREATE TABLE IF NOT EXISTS `$table` (
			img_page_id INT UNSIGNED NOT NULL,
			img_stem VARCHAR(191) NOT NULL,
			ref_page_id INT UNSIGNED NOT NULL,
			field_name VARCHAR(128) NOT NULL,
			PRIMARY KEY (img_page_id, img_stem, ref_page_id, field_name),
			KEY idx_ref (ref_page_id)
		) ENGINE=InnoDB DEFAULT CHARSET=ascii";
		try {
			$this->wire('database')->exec($sql);
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: usage table create failed: ' . $e->getMessage());
		}
	}

	/**
	 * Drop the usage table — called from ___uninstall.
	 */
	protected function dropUsageTable(): void {
		try {
			$this->wire('database')->exec('DROP TABLE IF EXISTS `' . self::USAGE_TABLE . '`');
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: usage table drop failed: ' . $e->getMessage());
		}
	}

	/**
	 * Names of every FieldtypeTextarea (rich-text) field in the system —
	 * the fields whose body can carry an embedded library image. Memoised
	 * for the request. Same direct iteration findImageReferences() uses.
	 *
	 * @return array<int,string>
	 */
	protected function discoverTextareaFields(): array {
		static $cache = null;
		if ($cache !== null) return $cache;
		$names = [];
		foreach ($this->wire('fields') as $field) {
			if ($field->type instanceof FieldtypeTextarea) {
				$names[] = $field->name;
			}
		}
		return $cache = $names;
	}

	/**
	 * Build the source-image stem index: for every managed image, which
	 * stems live on which storage page. Keyed by the STORAGE page id (the
	 * row's pageId stays storage-truth even for repeater items — that's the
	 * id the embed's "/files/<pid>/" path and "-pid<id>" marker point at).
	 *
	 *     [ storagePageId => [ stem => true, … ] ]
	 *
	 * @return array<int,array<string,bool>>
	 */
	protected function buildImageStemIndex(): array {
		$idx = [];
		foreach ($this->loadImageRowsAll() as $r) {
			$pid = (int) ($r['pageId'] ?? 0);
			$bn  = (string) ($r['basename'] ?? '');
			if (!$pid || $bn === '') continue;
			$dot  = strrpos($bn, '.');
			$stem = $dot === false ? $bn : substr($bn, 0, $dot);
			if ($stem === '') continue;
			$idx[$pid][$stem] = true;
		}
		return $idx;
	}

	/**
	 * Extract the set of LIBRARY images a chunk of rich-text references.
	 * Parses every "/site/assets/files/<pid>/<filename>" token and resolves
	 * it to a source image key:
	 *   - direct, same page:  source pid = the URL's <pid>
	 *   - cross-page insert:  source pid = the filename's "-pid<id>" marker
	 *     (pwimage copies a sized variation onto the EDITING page and records
	 *     the origin as -pid<id>, so the URL pid is the editing page, not the
	 *     image's). Multi-dot crop variations and -hidpi are handled because
	 *     we key on the stem (everything before the first dot) matched against
	 *     the known stems of that storage page — longest match wins.
	 * Tokens that don't resolve to a known managed image are ignored, so the
	 * index only ever holds references to actual library images.
	 *
	 * @param string $text  one rich-text value (single language slot)
	 * @param array<int,array<string,bool>> $stemIndex  buildImageStemIndex()
	 * @return array<string,array{0:int,1:string}>  "pid\0stem" => [pid, stem]
	 */
	protected function extractUsageKeys(string $text, array $stemIndex): array {
		$out = [];
		if ($text === '' || strpos($text, '/') === false) return $out;

		$filesUrl = (string) $this->wire('config')->urls->files;   // "/site/assets/files/"
		if ($filesUrl === '' || strpos($text, $filesUrl) === false) return $out;

		// Capture <pid>/<filename> for each files-path token. The filename run
		// stops at the first quote / whitespace / slash / query / fragment.
		// Delimiter is ~ (NOT #) because the stop-class itself contains a #
		// (fragment) — a # delimiter would terminate the pattern early.
		$re = '~' . preg_quote($filesUrl, '~') . '(\d+)/([^"\'\s/?#]+)~i';
		if (!preg_match_all($re, $text, $matches, PREG_SET_ORDER)) return $out;

		foreach ($matches as $m) {
			$dirPid = (int) $m[1];
			$file   = (string) $m[2];

			// Cross-page copy carries the origin page as -pid<id>; otherwise the
			// URL directory id IS the source page.
			$srcPid = $dirPid;
			if (preg_match('#-pid(\d+)\b#', $file, $pm)) {
				$srcPid = (int) $pm[1];
			}

			$stems = $stemIndex[$srcPid] ?? null;
			if (!$stems) continue;   // no managed image on that page → not ours

			// The filename always begins "<originalStem>." — find the known stem
			// of this page that prefixes it. Longest match wins so "foo.bar"
			// isn't shadowed by a shorter "foo" when both exist.
			$matchStem = null;
			foreach ($stems as $stem => $_) {
				$needle = $stem . '.';
				if (strncmp($file, $needle, strlen($needle)) === 0
					&& ($matchStem === null || strlen($stem) > strlen($matchStem))) {
					$matchStem = $stem;
				}
			}
			if ($matchStem === null) continue;

			$out[$srcPid . "\0" . $matchStem] = [$srcPid, $matchStem];
		}
		return $out;
	}

	/**
	 * Re-index ONE referencing page: drop its old usage rows and add the
	 * current ones (prune-then-add, mirroring hashPageImages /
	 * pruneStaleRowsForPage). The saved page may be a repeater item page —
	 * that's fine, we store its raw id as ref_page_id and resolve it to the
	 * owning content page only at read time (usageRefForPage), exactly like
	 * findImageReferences. Reads every textarea field on the page across all
	 * language slots.
	 *
	 * @param array<int,array<string,bool>>|null $stemIndex  reuse across a batch; built on demand
	 */
	protected function reindexPageUsage(int $pageId, ?array $stemIndex = null): void {
		if ($pageId < 1) return;
		$this->ensureUsageTable();

		$rows = [];   // [img_page_id, img_stem, field_name]
		$page = $this->wire('pages')->get($pageId);
		if ($page->id && $page->template) {
			$textareaFields = $this->discoverTextareaFields();
			if ($textareaFields) {
				if ($stemIndex === null) $stemIndex = $this->buildImageStemIndex();
				$page->of(false);
				foreach ($textareaFields as $name) {
					if (!$page->template->hasField($name)) continue;
					$found = [];
					foreach ($this->textareaLangValues($page, $name) as $text) {
						foreach ($this->extractUsageKeys($text, $stemIndex) as $k => $pair) {
							$found[$k] = $pair;   // union across language slots
						}
					}
					foreach ($found as $pair) {
						$rows[] = [$pair[0], $pair[1], $name];
					}
				}
			}
		}

		$db = $this->wire('database');
		try {
			$del = $db->prepare('DELETE FROM `' . self::USAGE_TABLE . '` WHERE ref_page_id = ?');
			$del->execute([$pageId]);
			if ($rows) {
				$ins = $db->prepare(
					'INSERT IGNORE INTO `' . self::USAGE_TABLE . '`'
					. ' (img_page_id, img_stem, ref_page_id, field_name) VALUES (?, ?, ?, ?)'
				);
				foreach ($rows as [$imgPid, $stem, $field]) {
					$ins->execute([$imgPid, $stem, $pageId, $field]);
				}
			}
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: reindex page usage failed for ' . $pageId . ': ' . $e->getMessage());
		}
	}

	/**
	 * All language slot values of a textarea field as plain strings (one
	 * entry per language for multilang, a single entry otherwise). Mirrors
	 * the multilang handling in fieldValueMatches / rewriteTextareaField.
	 *
	 * @return array<int,string>
	 */
	protected function textareaLangValues(Page $page, string $fieldName): array {
		$value = $page->getUnformatted($fieldName);
		if (is_object($value) && method_exists($value, 'getLanguageValue')) {
			$languages = $this->wire('languages');
			if ($languages) {
				$out = [];
				foreach ($languages as $lang) {
					$out[] = (string) $value->getLanguageValue($lang);
				}
				return $out;
			}
		}
		return [(string) $value];
	}

	/**
	 * Build the stable candidate set for the full scan: every page id whose
	 * textarea content mentions the files path at all. One substring-LIKE
	 * selector per textarea field (cheap, index-assisted), unioned + sorted
	 * so the offset cursor is consistent across budgeted passes. include=all
	 * so hidden / unpublished pages and repeater item pages are reached too,
	 * matching findImageReferences.
	 *
	 * @return array<int,int> sorted unique referencing page ids
	 */
	protected function usageCandidatePageIds(): array {
		$textareaFields = $this->discoverTextareaFields();
		if (!$textareaFields) return [];

		$filesUrl = (string) $this->wire('config')->urls->files;
		if ($filesUrl === '') return [];

		$pages    = $this->wire('pages');
		$needle   = $this->wire('sanitizer')->selectorValue($filesUrl);
		$idSet    = [];
		foreach ($textareaFields as $name) {
			try {
				$ids = $pages->findIDs($name . '%=' . $needle . ', include=all');
			} catch (\Throwable $e) {
				continue;
			}
			foreach ($ids as $id) $idSet[(int) $id] = true;
		}
		$ids = array_keys($idSet);
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	/**
	 * One budgeted slice of the full usage scan. Walks the candidate page
	 * list from $offset, re-indexing each page (prune-then-add) until the
	 * per-request wall-clock budget is spent, then reports where to resume.
	 * Same converging-offset shape as scanHashes().
	 *
	 * @return array{total:int,processed:int,nextOffset:int,complete:bool}
	 */
	protected function scanUsage(int $offset = 0): array {
		$this->ensureUsageTable();

		$candidates = $this->usageCandidatePageIds();
		$total      = count($candidates);
		if ($offset < 0) $offset = 0;

		$deadline  = microtime(true) + (self::USAGE_SCAN_BUDGET_MS / 1000);
		$stemIndex = $this->buildImageStemIndex();
		$processed = 0;

		$i = $offset;
		for (; $i < $total; $i++) {
			if (microtime(true) > $deadline) break;
			$this->reindexPageUsage((int) $candidates[$i], $stemIndex);
			$processed++;
		}

		return [
			'total'      => $total,
			'processed'  => $processed,
			'nextOffset' => $i,
			'complete'   => $i >= $total,
		];
	}

	/**
	 * Drop usage rows that can no longer be valid: references from pages that
	 * no longer mention the files path at all (embed removed, page deleted),
	 * and references to images whose stem no longer exists (image deleted /
	 * renamed). The per-page prune-then-add keeps touched pages correct; this
	 * is the backstop for pages that fell out of the candidate set entirely.
	 *
	 * Guarded like pruneOrphanedRows: an empty image set means discovery
	 * hiccuped, not that the library vanished — skip rather than wipe.
	 */
	protected function pruneOrphanedUsageRows(): void {
		$this->ensureUsageTable();

		$stemIndex = $this->buildImageStemIndex();
		if (!$stemIndex) return;   // discovery hiccup — do not prune against nothing

		$candidates = array_flip($this->usageCandidatePageIds());   // refPageId => idx

		$db = $this->wire('database');
		try {
			$sel        = $db->query('SELECT DISTINCT ref_page_id, img_page_id, img_stem FROM `' . self::USAGE_TABLE . '`');
			$staleRef   = [];   // ref_page_id set
			$staleImg   = [];   // "imgPid\0stem" => [imgPid, stem]
			while ($r = $sel->fetch(\PDO::FETCH_ASSOC)) {
				$ref  = (int) $r['ref_page_id'];
				$ipid = (int) $r['img_page_id'];
				$stem = (string) $r['img_stem'];
				if (!isset($candidates[$ref])) {
					$staleRef[$ref] = true;
				}
				if (!isset($stemIndex[$ipid][$stem])) {
					$staleImg[$ipid . "\0" . $stem] = [$ipid, $stem];
				}
			}
			if ($staleRef) {
				$delRef = $db->prepare('DELETE FROM `' . self::USAGE_TABLE . '` WHERE ref_page_id = ?');
				foreach (array_keys($staleRef) as $ref) $delRef->execute([$ref]);
			}
			if ($staleImg) {
				$delImg = $db->prepare('DELETE FROM `' . self::USAGE_TABLE . '` WHERE img_page_id = ? AND img_stem = ?');
				foreach ($staleImg as [$ipid, $stem]) $delImg->execute([$ipid, $stem]);
			}
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: prune orphaned usage rows failed: ' . $e->getMessage());
		}
	}

	/**
	 * How many places embed a given library image. Counts the raw
	 * (referencing page, field) rows — i.e. before repeater items are folded
	 * up to their owner page, which is what the detail dialog does. O(1):
	 * a single indexed lookup on the primary key prefix.
	 */
	protected function usageCountFor(int $imgPageId, string $stem): int {
		if ($imgPageId < 1 || $stem === '') return 0;
		$this->ensureUsageTable();
		try {
			$stmt = $this->wire('database')->prepare(
				'SELECT COUNT(*) FROM `' . self::USAGE_TABLE . '` WHERE img_page_id = ? AND img_stem = ?'
			);
			$stmt->execute([$imgPageId, $stem]);
			return (int) $stmt->fetchColumn();
		} catch (\Throwable $e) {
			return 0;
		}
	}

	/**
	 * Coarse diagnostics for the index — total reference rows and how many
	 * distinct library images they cover. Cheap aggregate; handy for a
	 * verifiable "is the index built?" readout and tests.
	 *
	 * @return array{references:int,images:int}
	 */
	protected function usageStats(): array {
		$this->ensureUsageTable();
		try {
			$db = $this->wire('database');
			$references = (int) $db->query('SELECT COUNT(*) FROM `' . self::USAGE_TABLE . '`')->fetchColumn();
			$images     = (int) $db->query('SELECT COUNT(*) FROM (SELECT 1 FROM `' . self::USAGE_TABLE . '` GROUP BY img_page_id, img_stem) t')->fetchColumn();
			return ['references' => $references, 'images' => $images];
		} catch (\Throwable $e) {
			return ['references' => 0, 'images' => 0];
		}
	}
}
