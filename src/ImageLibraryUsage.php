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
 * It reads the grammar pwimage embeds use: the inserted variation always lives
 * in the SOURCE image's own files folder (/files/<sourcePid>/), optionally
 * tagged "-is" (inserted into rich text) and "-pid<editorPid>" (the page that
 * USES a cross-page insert, NOT the source). So the directory id identifies the
 * source image; this covers exactly what the rename / delete preflight does.
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

	/** Per-request memo for buildImageStemIndex(). The index is derived from
	 *  the whole-site image row set; building it more than once per request is
	 *  pure waste. Several code paths (a save that reindexes usage, then a
	 *  render) would otherwise rebuild it each time. null = not built yet. */
	protected ?array $stemIndexCache = null;

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
	 * row's pageId stays storage-truth even for repeater items, and that is
	 * the id the embed's "/files/<pid>/" directory points at; the "-pid<id>"
	 * marker, when present, is the consuming page, not this index's key).
	 *
	 *     [ storagePageId => [ stem => true, … ] ]
	 *
	 * @return array<int,array<string,bool>>
	 */
	protected function buildImageStemIndex(): array {
		if ($this->stemIndexCache !== null) return $this->stemIndexCache;
		$idx = [];
		foreach ($this->loadImageRowsAll() as $r) {
			$pid = (int) ($r['pageId'] ?? 0);
			$bn  = (string) ($r['basename'] ?? '');
			if (!$pid || $bn === '') continue;
			$stem = $this->basenameStem($bn);
			if ($stem === '') continue;
			$idx[$pid][$stem] = true;
		}
		return $this->stemIndexCache = $idx;
	}

	/**
	 * The library-file page ids a single rich-text value references: the
	 * "/site/assets/files/<pid>/" directory id of every embed token, plus any
	 * "-pid<id>" cross-page marker. Mirrors the candidate set extractUsageKeys
	 * resolves against, so a stem index scoped to these ids has everything that
	 * lookup will ask for. Pure string parsing, no DB.
	 *
	 * @return array<int,bool>  set of referenced page ids (id => true)
	 */
	protected function referencedFilePids(string $text): array {
		$out = [];
		if ($text === '' || strpos($text, '/') === false) return $out;
		$filesUrl = (string) $this->wire('config')->urls->files;
		if ($filesUrl === '' || strpos($text, $filesUrl) === false) return $out;
		$re = '~' . preg_quote($filesUrl, '~') . '(\d+)/([^"\'\s/?#]+)~i';
		if (!preg_match_all($re, $text, $matches, PREG_SET_ORDER)) return $out;
		foreach ($matches as $m) {
			$out[(int) $m[1]] = true;
			if (preg_match('#-pid(\d+)\b#', (string) $m[2], $pm)) $out[(int) $pm[1]] = true;
		}
		return $out;
	}

	/**
	 * Stem index for a SPECIFIC set of storage pages – the cheap counterpart to
	 * buildImageStemIndex(), which loads every image row on the site. Given the
	 * handful of pages a saved page's text references, this reads only those
	 * pages' image-field rows straight from the field tables (indexed by
	 * pages_id), so a normal save never triggers the whole-site enumeration.
	 *
	 * Kept equivalent to the full index: only non-blacklisted image fields are
	 * read (discoverImageFields), and referenced pages are first filtered to
	 * eligible (non-blacklisted) templates, so an embed pointing at an image on
	 * an out-of-scope page resolves the same way it would through the full path
	 * (i.e. not at all). Keyed by storage page id like buildImageStemIndex();
	 * for a repeater-hosted image that is the repeater item page, which is also
	 * the "/files/<pid>/" directory id, so no owner resolution is needed here.
	 *
	 * @param array<int,int> $pageIds referenced storage page ids
	 * @return array<int,array<string,bool>>  [ storagePageId => [ stem => true ] ]
	 */
	protected function buildScopedStemIndex(array $pageIds): array {
		$idx = [];
		$pageIds = array_values(array_unique(array_filter(array_map('intval', $pageIds))));
		if (!$pageIds) return $idx;

		$imageFields = $this->discoverImageFields();
		if (!$imageFields) return $idx;

		// Eligible (non-blacklisted) template ids, to match the full index scope.
		$eligibleIds = [];
		foreach ($this->discoverEligibleTemplates($imageFields) as $tname) {
			$t = $this->wire('templates')->get($tname);
			if ($t && $t->id) $eligibleIds[(int) $t->id] = true;
		}
		if (!$eligibleIds) return $idx;

		$db = $this->wire('database');
		// Keep only referenced pages on an eligible template.
		$in   = implode(',', $pageIds);
		$keep = [];
		try {
			$q = $db->query("SELECT id, templates_id FROM pages WHERE id IN ($in)");
			while (($r = $q->fetch(\PDO::FETCH_NUM)) !== false) {
				if (isset($eligibleIds[(int) $r[1]])) $keep[(int) $r[0]] = true;
			}
		} catch (\Throwable $e) {
			return $idx;
		}
		if (!$keep) return $idx;

		$keepIn = implode(',', array_keys($keep));
		foreach ($imageFields as $fname) {
			$field = $this->wire('fields')->get($fname);
			if (!$field) continue;
			$table = $db->escapeTable($field->getTable());
			if ($table === '') continue;
			try {
				$q = $db->query("SELECT pages_id, data FROM `{$table}` WHERE pages_id IN ({$keepIn})");
			} catch (\Throwable $e) {
				continue;   // field without its table (mid-migration) – skip
			}
			while (($r = $q->fetch(\PDO::FETCH_NUM)) !== false) {
				$pid  = (int) $r[0];
				$stem = $this->basenameStem(basename((string) $r[1]));
				if ($stem !== '') $idx[$pid][$stem] = true;
			}
		}
		return $idx;
	}

	/**
	 * Extract the set of LIBRARY images a chunk of rich-text references.
	 * Parses every "/site/assets/files/<pid>/<filename>" token and resolves it
	 * to a source image key. pwimage stores the inserted variation in the SOURCE
	 * image's own folder, so the URL <pid> is the source image's page for both
	 * same-page and cross-page inserts; the optional "-pid<id>" marker records
	 * the EDITING page that uses a cross-page insert (NOT the source), so it is
	 * only tried as a fallback. Multi-dot crop variations and -hidpi are handled
	 * because we key on the stem (everything before the first dot) matched
	 * against the known stems of that storage page (longest match wins).
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

			// Which page is the SOURCE image's storage page? pwimage stores the
			// inserted (sized/cropped) variation in the SOURCE image's own files
			// folder, so the URL directory id IS the source page — even for a
			// cross-page insert, where the "-pid<id>" suffix records the TARGET
			// page the variation was made for, NOT the source. (Verified against
			// real data: /files/1164/img.x-is-pid1171.jpeg is the 1164 image used
			// on page 1171.) We still try the -pid id as a fallback so the rarer
			// setups where the copy lands in the editing page's folder (tagged
			// with the source id) also resolve. First candidate that maps to a
			// known managed image on that page wins; the directory takes priority
			// because that's where the file physically lives.
			$candidates = [$dirPid];
			if (preg_match('#-pid(\d+)\b#', $file, $pm)) {
				$marker = (int) $pm[1];
				if ($marker !== $dirPid) $candidates[] = $marker;
			}

			foreach ($candidates as $srcPid) {
				$stems = $stemIndex[$srcPid] ?? null;
				if (!$stems) continue;   // no managed image on that page

				// The filename always begins "<originalStem>." — find the known
				// stem of this page that prefixes it. Longest match wins so
				// "foo.bar" isn't shadowed by a shorter "foo" when both exist.
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
				break;   // resolved this token to its source; don't double-count
			}
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
				$page->of(false);
				// Read this page's textarea content once, keeping the per-field
				// language slots, and collect every library-file page id the text
				// references (the "/files/<pid>/" directory id plus any "-pid<id>"
				// cross-page marker) so the stem index can be scoped to just those.
				$texts   = [];   // [fieldName => [text, …]]
				$refPids = [];
				foreach ($textareaFields as $name) {
					if (!$page->template->hasField($name)) continue;
					$vals = $this->textareaLangValues($page, $name);
					$texts[$name] = $vals;
					foreach ($vals as $text) {
						foreach ($this->referencedFilePids($text) as $pid => $_) {
							$refPids[$pid] = true;
						}
					}
				}
				if ($texts) {
					// A single save only needs to know the image stems of the
					// pages its own text references, not the whole site. Build a
					// stem index scoped to those pages instead of loading every
					// image row via loadImageRowsAll(). A caller that already holds
					// a full index (batch reindex / rename) passes it in and we
					// reuse that instead.
					$idx = $stemIndex ?? $this->buildScopedStemIndex(array_keys($refPids));
					foreach ($texts as $name => $vals) {
						$found = [];
						foreach ($vals as $text) {
							foreach ($this->extractUsageKeys($text, $idx) as $k => $pair) {
								$found[$k] = $pair;   // union across language slots
							}
						}
						foreach ($found as $pair) {
							$rows[] = [$pair[0], $pair[1], $name];
						}
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
	 * Re-index the where-used rows for a set of referencing pages after an embed
	 * rewrite (rename). Those pages were saved with a FIELD-ONLY save, which does
	 * NOT fire Pages::saved, so autoIndexUsageOnPageSave never ran for them and
	 * the index would keep the old stem (the row's "Used in" then shows nothing
	 * for the new basename). Build the stem index once and reuse it across the
	 * set. MUST be called AFTER the row cache is cleared, so the stem index
	 * reflects the new basename(s).
	 *
	 * @param array<int,int> $refPageIds
	 */
	protected function reindexUsageForRefPages(array $refPageIds): void {
		$refPageIds = array_values(array_unique(array_map('intval', $refPageIds)));
		if (!$refPageIds) return;
		// This runs AFTER a rename cleared the row cache and must reflect the NEW
		// basenames. Drop the per-request stem-index memo so buildImageStemIndex()
		// rebuilds from the updated data instead of returning a pre-rename copy.
		$this->stemIndexCache = null;
		$stemIndex = $this->buildImageStemIndex();
		foreach ($refPageIds as $rid) {
			$this->reindexPageUsage($rid, $stemIndex);
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
				// Don't let one bad field silently shrink the usage scan.
				$this->wire('log')->error('ImageLibrary: usage scan query failed for field ' . $name . ': ' . $e->getMessage());
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
			// A DB fault must not masquerade as "image used nowhere".
			$this->wire('log')->error('ImageLibrary: usage count query failed: ' . $e->getMessage());
			return 0;
		}
	}

	// ------------------------------------------------------------------
	// Content-based lookup — the "Used in" column.
	//
	// The column answers ONE question: "on which pages is THIS image
	// embedded in a rich-text field?" — content-based, so every dedup
	// placement of the same image gives the same answer. An embed only
	// ever references one physical copy, but the user thinks of it as one
	// image; we therefore aggregate usage across the whole byte-identical
	// cluster (via the dedup hash store) so whichever placement you look
	// at, you see the full set of embedding pages. Where-it-lives-in-image-
	// fields is the dedup feature's job; this is purely textarea embeds.
	// ------------------------------------------------------------------

	/** Stem (basename minus final extension), the key form the index uses. */
	protected function basenameStem(string $basename): string {
		$dot = strrpos($basename, '.');
		return $dot === false ? $basename : substr($basename, 0, $dot);
	}

	/**
	 * The whole usage index as an in-memory map, loaded once per request.
	 * Keyed by source image, each entry the raw referencing (page, field)
	 * pairs. Small table (only embedded images), so one read is cheap and
	 * lets the slice hydration resolve every row without per-row queries.
	 *
	 * @return array<string,array<int,array{0:int,1:string}>>  "imgPid\0stem" => [[refPid, field], …]
	 */
	protected function loadUsageByImage(): array {
		static $cache = null;
		if ($cache !== null) return $cache;
		$this->ensureUsageTable();
		$out = [];
		try {
			$stmt = $this->wire('database')->query(
				'SELECT img_page_id, img_stem, ref_page_id, field_name FROM `' . self::USAGE_TABLE . '`'
			);
			while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$out[(int) $r['img_page_id'] . "\0" . (string) $r['img_stem']][]
					= [(int) $r['ref_page_id'], (string) $r['field_name']];
			}
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: loadUsageByImage query failed: ' . $e->getMessage());
		}
		return $cache = $out;
	}

	/**
	 * Invert loadDuplicateKeyHashes() into "content_hash => [imgPid\0stem, …]",
	 * the member keys of every byte-identical cluster. Built purely in memory
	 * from the dup-key map (which already lists every duplicate member), so the
	 * batch needs no per-row cluster query.
	 *
	 * @param array<string,string> $dupKeyHashes  "pid\0field\0basename" => content_hash
	 * @return array<string,array<int,string>>
	 */
	protected function clusterMembersByHash(array $dupKeyHashes): array {
		$out = [];
		foreach ($dupKeyHashes as $identity => $hash) {
			$parts = explode("\0", $identity);   // pid, field, basename
			if (count($parts) !== 3) continue;
			$out[$hash][(int) $parts[0] . "\0" . $this->basenameStem($parts[2])] = true;
		}
		foreach ($out as $h => $set) $out[$h] = array_keys($set);
		return $out;
	}

	/**
	 * The set of index keys ("imgPid\0stem") that make up one image's
	 * byte-identical cluster — itself plus every dedup twin — so usage is
	 * aggregated content-wide. An image with no duplicates (or not yet hashed)
	 * resolves to just itself.
	 *
	 * @param array<string,string> $dupKeyHashes    loadDuplicateKeyHashes()
	 * @param array<string,array<int,string>> $membersByHash  clusterMembersByHash()
	 * @return array<int,string>  list of "imgPid\0stem" keys
	 */
	protected function imageClusterKeys(int $pageId, string $fieldName, string $basename, array $dupKeyHashes, array $membersByHash): array {
		$self = $pageId . "\0" . $this->basenameStem($basename);
		$hash = $dupKeyHashes[$this->hashKey($pageId, $fieldName, $basename)] ?? null;
		if ($hash === null || empty($membersByHash[$hash])) return [$self];

		$keys = [$self => true];
		foreach ($membersByHash[$hash] as $k) $keys[$k] = true;
		return array_keys($keys);
	}

	/**
	 * Resolve a set of cluster keys to the distinct content pages that embed
	 * the image — one entry per page, listing EVERY field the image appears in
	 * on that page (comma-joined into fieldName). Each raw (refPage, field) is
	 * run through usageRefForPage so repeater/matrix item pages fold up to
	 * their owning content page; entries for the same owner page are merged so
	 * an image used in two textareas of one page reads "Page · body, summary"
	 * rather than showing the page twice or naming only the first field.
	 *
	 * @param array<int,string> $keys           imageClusterKeys()
	 * @param array<string,array<int,array{0:int,1:string}>> $usageByImage  loadUsageByImage()
	 * @param array<int,Page|null> $pageCache    shared across a batch, by reference
	 * @return array<int,array{pageId:int,pageTitle:string,editUrl:string,fieldName:string}>
	 */
	protected function resolveUsagePages(array $keys, array $usageByImage, array &$pageCache): array {
		$pages  = $this->wire('pages');
		$byPage = [];   // ownerPageId => ['pageId','pageTitle','editUrl','fields'=>set]
		foreach ($keys as $key) {
			foreach ($usageByImage[$key] ?? [] as [$refPid, $field]) {
				if (!array_key_exists($refPid, $pageCache)) {
					$p = $pages->get($refPid);
					$pageCache[$refPid] = $p->id ? $p : null;
				}
				$rp = $pageCache[$refPid];
				if (!$rp) continue;
				$ref = $this->usageRefForPage($rp, $field);
				$pid = $ref['pageId'];
				if (!isset($byPage[$pid])) {
					$byPage[$pid] = [
						'pageId'    => $pid,
						'pageTitle' => $ref['pageTitle'],
						'editUrl'   => $ref['editUrl'],
						'fields'    => [],
					];
				}
				$byPage[$pid]['fields'][$ref['fieldName']] = true;   // de-dupe fields per page
			}
		}
		$out = [];
		foreach ($byPage as $entry) {
			$fields = array_keys($entry['fields']);
			sort($fields, SORT_NATURAL | SORT_FLAG_CASE);   // stable, readable order
			$out[] = [
				'pageId'    => $entry['pageId'],
				'pageTitle' => $entry['pageTitle'],
				'editUrl'   => $entry['editUrl'],
				'fieldName' => implode(', ', $fields),
			];
		}
		return $out;
	}

	/**
	 * Content-based where-used for a single image: the distinct pages that
	 * embed it in rich-text. Drives the column's click-through dialog.
	 *
	 * @return array<int,array{pageId:int,pageTitle:string,editUrl:string,fieldName:string}>
	 */
	public function usagePagesForImage(int $pageId, string $fieldName, string $basename): array {
		if ($pageId < 1 || $basename === '') return [];
		$dupKeyHashes  = $this->loadDuplicateKeyHashes();
		$membersByHash = $this->clusterMembersByHash($dupKeyHashes);
		$keys  = $this->imageClusterKeys($pageId, $fieldName, $basename, $dupKeyHashes, $membersByHash);
		$cache = [];
		return $this->resolveUsagePages($keys, $this->loadUsageByImage(), $cache);
	}

	/**
	 * Batch usage page-counts for a slice of rows — one in-memory pass, no
	 * per-row queries. Returns rowKey ("pageId\0fieldName\0basename") => count
	 * of distinct embedding pages, omitting rows with zero (so the caller can
	 * render a dash). Used by hydrateSlice for the visible rows only.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,int>
	 */
	public function usagePageCountsForRows(array $rows): array {
		if (!$rows) return [];
		$usageByImage = $this->loadUsageByImage();
		if (!$usageByImage) return [];   // nothing embedded anywhere → all zero
		$dupKeyHashes  = $this->loadDuplicateKeyHashes();
		$membersByHash = $this->clusterMembersByHash($dupKeyHashes);
		$pageCache     = [];
		$out = [];
		foreach ($rows as $row) {
			$pid = (int) ($row['pageId'] ?? 0);
			$fn  = (string) ($row['fieldName'] ?? '');
			$bn  = (string) ($row['basename'] ?? '');
			if (!$pid || $bn === '') continue;
			$keys  = $this->imageClusterKeys($pid, $fn, $bn, $dupKeyHashes, $membersByHash);
			$pages = $this->resolveUsagePages($keys, $usageByImage, $pageCache);
			if ($pages) $out[$pid . "\0" . $fn . "\0" . $bn] = count($pages);
		}
		return $out;
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
			$this->wire('log')->error('ImageLibrary: usage stats query failed: ' . $e->getMessage());
			return ['references' => 0, 'images' => 0];
		}
	}
}
