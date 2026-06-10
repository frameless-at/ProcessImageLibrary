<?php namespace ProcessWire;

/**
 * Content-identity layer for ProcessImageLibrary (exact-duplicate detection +
 * hardlink reclaim).
 *
 * Fingerprints every managed image by its EXACT byte content so duplicate
 * copies can be grouped, managed together, and optionally collapsed:
 *
 *   - content_hash : exact byte hash (xxh128 where available, else md5).
 *                    Two rows with the same content_hash are byte-identical
 *                    copies of the same file.
 *
 * (A `dhash` column also exists in the table but is unused — a perceptual
 * near-duplicate hash was tried and dropped: it grouped unrelated photos that
 * merely shared a tonal layout, even with a DCT pHash, so it was not reliable
 * enough. The column is left in place to avoid a schema migration.)
 *
 * Storage is the module's own table `process_imagelibrary_hashes`, keyed by
 * the same identity the rest of the module uses — (page_id, field_name,
 * basename) — plus the file's (filesize, filemtime) so a fingerprint is only
 * recomputed when the bytes actually change. The table is created lazily
 * (CREATE TABLE IF NOT EXISTS) so it appears on already-installed sites
 * without an upgrade hook, and is dropped on uninstall.
 *
 * Also home to the hardlink-reclaim engine (collapse byte-identical copies to
 * one inode) and its reversible manifest. Composed into ProcessImageLibrary
 * via `use`.
 */
trait ImageLibraryHashing {

	/** Identity + fingerprint store. ASCII charset: every column (ids,
	 *  sanitised filenames, hex hashes) is ASCII, which keeps the composite
	 *  primary key well under InnoDB's index-length limit on any MySQL. */
	const HASH_TABLE = 'process_imagelibrary_hashes';

	/** Wall-clock budget for one scan request (ms). The client calls the
	 *  scan repeatedly with a moving offset until it reports complete, so a
	 *  1000+ image site never blocks a single request past this. */
	const HASH_SCAN_BUDGET_MS = 8000;

	/** Per-placement "keep custom metadata" locks. A row here means that
	 *  (page, field, basename) is excluded from cluster-wide propagation, so
	 *  a deliberately different caption/alt is never overwritten. */
	const METALOCK_TABLE = 'process_imagelibrary_metalock';

	/**
	 * Best available content-hash algorithm. xxh128 (PHP 8.1+) is several×
	 * faster than md5 and ample for dedup (non-cryptographic is fine);
	 * md5 is the universal fallback. Memoised for the request.
	 */
	protected function hashAlgo(): string {
		static $algo = null;
		if ($algo === null) {
			$algo = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'md5';
		}
		return $algo;
	}

	/**
	 * Create the fingerprint table if it isn't there yet. Runs its CREATE at
	 * most once per request (static guard) and only when a hashing path is
	 * actually exercised, so normal admin page loads pay nothing.
	 */
	protected function ensureHashTable(): void {
		static $ensured = false;
		if ($ensured) return;
		$ensured = true;

		$table = self::HASH_TABLE;
		$sql = "CREATE TABLE IF NOT EXISTS `$table` (
			page_id INT UNSIGNED NOT NULL,
			field_name VARCHAR(128) NOT NULL,
			basename VARCHAR(191) NOT NULL,
			filesize BIGINT UNSIGNED NOT NULL,
			filemtime INT UNSIGNED NOT NULL,
			content_hash VARCHAR(40) DEFAULT NULL,
			dhash CHAR(16) DEFAULT NULL,
			algo VARCHAR(16) NOT NULL DEFAULT '',
			computed_at DATETIME NOT NULL,
			PRIMARY KEY (page_id, field_name, basename),
			KEY idx_content (content_hash),
			KEY idx_dhash (dhash)
		) ENGINE=InnoDB DEFAULT CHARSET=ascii";
		try {
			$this->wire('database')->exec($sql);
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: hash table create failed: ' . $e->getMessage());
		}
	}

	/**
	 * Drop the fingerprint table — called from ___uninstall.
	 */
	protected function dropHashTable(): void {
		try {
			$this->wire('database')->exec('DROP TABLE IF EXISTS `' . self::HASH_TABLE . '`');
			$this->wire('database')->exec('DROP TABLE IF EXISTS `' . self::METALOCK_TABLE . '`');
			// Legacy hardlink-manifest table from older versions — drop if present.
			$this->wire('database')->exec('DROP TABLE IF EXISTS `process_imagelibrary_hardlinks`');
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: hash table drop failed: ' . $e->getMessage());
		}
	}

	/** Create the metadata-lock table lazily (once per request). */
	protected function ensureMetaLockTable(): void {
		static $ensured = false;
		if ($ensured) return;
		$ensured = true;
		$sql = 'CREATE TABLE IF NOT EXISTS `' . self::METALOCK_TABLE . '` (
			page_id INT UNSIGNED NOT NULL,
			field_name VARCHAR(128) NOT NULL,
			basename VARCHAR(191) NOT NULL,
			PRIMARY KEY (page_id, field_name, basename)
		) ENGINE=InnoDB DEFAULT CHARSET=ascii';
		try {
			$this->wire('database')->exec($sql);
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: metalock table create failed: ' . $e->getMessage());
		}
	}

	/**
	 * All locked placements as a set keyed "pageId\0fieldName\0basename".
	 * Loaded once for the whole duplicates render.
	 *
	 * @return array<string,bool>
	 */
	protected function loadLockSet(): array {
		$this->ensureMetaLockTable();
		$out = [];
		try {
			$stmt = $this->wire('database')->query(
				'SELECT page_id, field_name, basename FROM `' . self::METALOCK_TABLE . '`'
			);
			while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$out[$r['page_id'] . "\0" . $r['field_name'] . "\0" . $r['basename']] = true;
			}
		} catch (\Throwable $e) {
		}
		return $out;
	}

	/** Add or remove one placement's metadata lock. */
	protected function setMetaLock(int $pageId, string $fieldName, string $basename, bool $locked): void {
		$this->ensureMetaLockTable();
		$db = $this->wire('database');
		try {
			if ($locked) {
				$stmt = $db->prepare(
					'INSERT IGNORE INTO `' . self::METALOCK_TABLE . '` (page_id, field_name, basename) VALUES (?, ?, ?)'
				);
			} else {
				$stmt = $db->prepare(
					'DELETE FROM `' . self::METALOCK_TABLE . '` WHERE page_id = ? AND field_name = ? AND basename = ?'
				);
			}
			$stmt->execute([$pageId, $fieldName, $basename]);
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: metalock write failed: ' . $e->getMessage());
		}
	}

	/**
	 * Members of one exact-duplicate cluster, by content hash.
	 *
	 * @return array<int,array{pageId:int,fieldName:string,basename:string}>
	 */
	protected function loadClusterMembers(string $contentHash): array {
		$this->ensureHashTable();
		$out = [];
		if ($contentHash === '') return $out;
		try {
			$stmt = $this->wire('database')->prepare(
				'SELECT page_id, field_name, basename FROM `' . self::HASH_TABLE . '` WHERE content_hash = ?'
			);
			$stmt->execute([$contentHash]);
			while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$out[] = [
					'pageId'    => (int) $r['page_id'],
					'fieldName' => (string) $r['field_name'],
					'basename'  => (string) $r['basename'],
				];
			}
		} catch (\Throwable $e) {
		}
		return $out;
	}

	/**
	 * Map of every image that is a byte-identical duplicate of another:
	 * "pageId\0fieldName\0basename" => content_hash. `isset()` answers
	 * membership (the "Duplicates" filter); the hash value lets the filter
	 * collapse a cluster to a single representative row. Empty before a scan.
	 *
	 * @return array<string,string>
	 */
	protected function loadDuplicateKeyHashes(): array {
		$this->ensureHashTable();
		$db = $this->wire('database');
		$keys = [];
		try {
			$hashes = [];
			$stmt = $db->query(
				'SELECT content_hash FROM `' . self::HASH_TABLE . '`
				 WHERE content_hash IS NOT NULL
				 GROUP BY content_hash HAVING COUNT(*) > 1'
			);
			while (($h = $stmt->fetchColumn()) !== false) $hashes[] = (string) $h;
			if ($hashes) {
				$in   = implode(',', array_fill(0, count($hashes), '?'));
				$rows = $db->prepare(
					'SELECT page_id, field_name, basename, content_hash
					 FROM `' . self::HASH_TABLE . '` WHERE content_hash IN (' . $in . ')'
				);
				$rows->execute($hashes);
				while ($r = $rows->fetch(\PDO::FETCH_ASSOC)) {
					$keys[$r['page_id'] . "\0" . $r['field_name'] . "\0" . $r['basename']]
						= (string) $r['content_hash'];
				}
			}
		} catch (\Throwable $e) {
		}
		return $keys;
	}

	/**
	 * Refresh only the stored filemtime for one fingerprint row, leaving its
	 * content_hash intact. Called after a hardlink reclaim: linking a copy onto
	 * the canonical inode changes the copy's on-disk mtime (it adopts the
	 * canonical's), which would otherwise make the budgeted scan see every
	 * reclaimed file as "changed" and re-hash it on the next pass — so the scan
	 * never advances past the reclaimed head and most of the library never gets
	 * fingerprinted. Writing the new mtime back keeps the (size, mtime) skip
	 * cache valid so the scan converges. Affects 0 rows if not yet scanned.
	 */
	protected function refreshStoredMtime(int $pageId, string $fieldName, string $basename, int $filemtime): void {
		try {
			$stmt = $this->wire('database')->prepare(
				'UPDATE `' . self::HASH_TABLE . '` SET filemtime = ?
				 WHERE page_id = ? AND field_name = ? AND basename = ?'
			);
			$stmt->execute([$filemtime, $pageId, $fieldName, $basename]);
		} catch (\Throwable $e) {
		}
	}

	/**
	 * Follow a file rename in the fingerprint + metalock tables: move the row
	 * from the old basename to the new one, keeping its content_hash intact.
	 *
	 * Renaming a file doesn't change its bytes, so the fingerprint is still
	 * valid — we just re-key it. This must happen on rename because a field-only
	 * save ($page->save($field), which is what rename does) fires Pages::
	 * savedField, NOT Pages::saved, so the auto-hash hook never runs to re-key
	 * it. Without this, the renamed image's row stays under the OLD basename
	 * while its display row uses the NEW one — they no longer match, so the
	 * image silently drops out of its duplicate cluster. Both tables are keyed
	 * (page_id, field_name, basename), so both must follow the rename.
	 */
	protected function renameFingerprintRows(int $pageId, string $fieldName, string $oldBasename, string $newBasename): void {
		if ($oldBasename === '' || $newBasename === '' || $oldBasename === $newBasename) return;
		$this->ensureHashTable();
		$this->ensureMetaLockTable();
		$db = $this->wire('database');
		foreach ([self::HASH_TABLE, self::METALOCK_TABLE] as $table) {
			try {
				// Clear any row already sitting on the new basename (rename guards
				// against on-disk collisions, but keep the (page,field,basename)
				// primary key safe), then re-key the old row onto the new name.
				$del = $db->prepare(
					'DELETE FROM `' . $table . '` WHERE page_id = ? AND field_name = ? AND basename = ?'
				);
				$del->execute([$pageId, $fieldName, $newBasename]);
				$upd = $db->prepare(
					'UPDATE `' . $table . '` SET basename = ? WHERE page_id = ? AND field_name = ? AND basename = ?'
				);
				$upd->execute([$newBasename, $pageId, $fieldName, $oldBasename]);
			} catch (\Throwable $e) {
				$this->wire('log')->error('ImageLibrary: fingerprint rename failed (' . $table . ') for '
					. $pageId . '/' . $fieldName . ' ' . $oldBasename . ' → ' . $newBasename . ': ' . $e->getMessage());
			}
		}
	}

	/**
	 * Exact byte hash of a file, or null if unreadable.
	 */
	protected function computeContentHash(string $path): ?string {
		$h = @hash_file($this->hashAlgo(), $path);
		return ($h === false) ? null : $h;
	}

	/**
	 * Load all stored fingerprints for one page, keyed "field\0basename", so
	 * a scan checks a whole page's images with a single query.
	 *
	 * @return array<string,array{filesize:int,filemtime:int,content_hash:?string,dhash:?string}>
	 */
	protected function loadHashRowsForPage(int $pageId): array {
		$out = [];
		try {
			$stmt = $this->wire('database')->prepare(
				'SELECT field_name, basename, filesize, filemtime, content_hash, dhash, algo
				 FROM `' . self::HASH_TABLE . '` WHERE page_id = ?'
			);
			$stmt->execute([$pageId]);
			while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$key = $r['field_name'] . "\0" . $r['basename'];
				$out[$key] = [
					'filesize'     => (int) $r['filesize'],
					'filemtime'    => (int) $r['filemtime'],
					'content_hash' => $r['content_hash'],
					'dhash'        => $r['dhash'],
					'algo'         => (string) $r['algo'],
				];
			}
		} catch (\Throwable $e) {
			// Missing table on the very first call is handled by ensureHashTable;
			// any other error just means "no cached rows" → recompute.
		}
		return $out;
	}

	/**
	 * Upsert one image's fingerprints.
	 */
	protected function storeImageHash(
		int $pageId, string $fieldName, string $basename,
		int $filesize, int $filemtime, ?string $contentHash, ?string $dhash
	): void {
		try {
			$stmt = $this->wire('database')->prepare(
				'INSERT INTO `' . self::HASH_TABLE . '`
				 (page_id, field_name, basename, filesize, filemtime, content_hash, dhash, algo, computed_at)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
				 ON DUPLICATE KEY UPDATE
				   filesize = VALUES(filesize), filemtime = VALUES(filemtime),
				   content_hash = VALUES(content_hash), dhash = VALUES(dhash),
				   algo = VALUES(algo), computed_at = NOW()'
			);
			$stmt->execute([
				$pageId, $fieldName, $basename, $filesize, $filemtime,
				$contentHash, $dhash, $this->hashAlgo(),
			]);
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: hash store failed for '
				. $pageId . '/' . $fieldName . '/' . $basename . ': ' . $e->getMessage());
		}
	}

	/**
	 * Exact-duplicate clusters from the fingerprint store: every content_hash
	 * shared by 2+ images, with each member's identity, biggest cluster first.
	 * Also returns how many images are fingerprinted so the caller can tell
	 * "not scanned yet" (0) from "scanned, no duplicates" (>0, no clusters).
	 *
	 * @return array{scanned:int, clusters:array<int,array{hash:string, members:array<int,array{pageId:int,fieldName:string,basename:string}>}>}
	 */
	protected function loadExactClusters(): array {
		$this->ensureHashTable();
		$db = $this->wire('database');

		$scanned = 0;
		try {
			$scanned = (int) $db->query(
				'SELECT COUNT(*) FROM `' . self::HASH_TABLE . '` WHERE content_hash IS NOT NULL'
			)->fetchColumn();
		} catch (\Throwable $e) {
			return ['scanned' => 0, 'clusters' => []];
		}
		if (!$scanned) return ['scanned' => 0, 'clusters' => []];

		$clusters = [];
		try {
			$dupHashes = [];
			$stmt = $db->query(
				'SELECT content_hash, COUNT(*) c FROM `' . self::HASH_TABLE . '`
				 WHERE content_hash IS NOT NULL
				 GROUP BY content_hash HAVING c > 1 ORDER BY c DESC, content_hash'
			);
			while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$dupHashes[] = (string) $r['content_hash'];
			}
			if ($dupHashes) {
				$in = implode(',', array_fill(0, count($dupHashes), '?'));
				$m  = $db->prepare(
					'SELECT content_hash, page_id, field_name, basename
					 FROM `' . self::HASH_TABLE . '` WHERE content_hash IN (' . $in . ')'
				);
				$m->execute($dupHashes);
				$byHash = [];
				while ($r = $m->fetch(\PDO::FETCH_ASSOC)) {
					$byHash[(string) $r['content_hash']][] = [
						'pageId'    => (int) $r['page_id'],
						'fieldName' => (string) $r['field_name'],
						'basename'  => (string) $r['basename'],
					];
				}
				// Preserve the size-desc order from the GROUP BY.
				foreach ($dupHashes as $h) {
					if (!empty($byHash[$h])) {
						$clusters[] = ['hash' => $h, 'members' => $byHash[$h]];
					}
				}
			}
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: cluster query failed: ' . $e->getMessage());
		}
		return ['scanned' => $scanned, 'clusters' => $clusters];
	}

	/**
	 * Time-budgeted content-hash scan over the whole managed image set.
	 *
	 * Walks the cached row list from $offset, skipping images whose stored
	 * (filesize, filemtime) still match the file on disk, computing + storing
	 * the content hash for the rest, until HASH_SCAN_BUDGET_MS is spent or the
	 * list ends. Pages are loaded once and their stored hashes fetched once,
	 * so the per-image cost is a stat plus (when stale) one hash. Returns
	 * progress so a client can drive it to completion.
	 *
	 * @return array{total:int,processed:int,hashed:int,skipped:int,nextOffset:int,complete:bool}
	 */
	protected function scanHashes(int $offset = 0): array {
		$this->ensureHashTable();

		// Version-inclusive: also fingerprint page-version copies so their
		// byte-identical files get hardlinked (the display hides them).
		$rows  = $this->loadImageRowsAll();
		$total = count($rows);
		if ($offset < 0) $offset = 0;

		$deadline = microtime(true) + (self::HASH_SCAN_BUDGET_MS / 1000);
		$pages    = $this->wire('pages');

		$pageCache = [];  // pageId => Page
		$hashCache = [];  // pageId => loadHashRowsForPage()
		$processed = 0;
		$hashed    = 0;
		$skipped   = 0;

		$i = $offset;
		for (; $i < $total; $i++) {
			if (microtime(true) > $deadline) break;

			$row = $rows[$i];
			$pid = (int) ($row['pageId'] ?? 0);
			$fn  = (string) ($row['fieldName'] ?? '');
			$bn  = (string) ($row['basename'] ?? '');
			$processed++;
			if (!$pid || $fn === '' || $bn === '') continue;

			if (!array_key_exists($pid, $pageCache)) {
				$pageCache[$pid] = $pages->get($pid);
				$hashCache[$pid] = $this->loadHashRowsForPage($pid);
			}
			$page = $pageCache[$pid];
			if (!$page->id) continue;

			$img = $this->resolvePageimage($page, $fn, $bn);
			if (!$img) continue;

			$path = (string) $img->filename;
			$size = @filesize($path);
			$mt   = @filemtime($path);
			if ($size === false || $mt === false) continue;

			$existing = $hashCache[$pid][$fn . "\0" . $bn] ?? null;
			if ($existing
				&& $existing['content_hash'] !== null
				&& (int) $existing['filesize'] === (int) $size
				&& (int) $existing['filemtime'] === (int) $mt) {
				$skipped++;
				continue;
			}

			$content = $this->computeContentHash($path);
			$this->storeImageHash($pid, $fn, $bn, (int) $size, (int) $mt, $content, null);
			$hashed++;
		}

		return [
			'total'      => $total,
			'processed'  => $processed,
			'hashed'     => $hashed,
			'skipped'    => $skipped,
			'nextOffset' => $i,
			'complete'   => $i >= $total,
		];
	}

	/**
	 * Hash (or refresh) every managed image on ONE page, skipping files whose
	 * (size, mtime) are unchanged. Returns the distinct content hashes of the
	 * page's images so the caller can immediately reclaim those clusters. Used
	 * by the on-save auto-hash hook — covers top-level pages AND repeater item
	 * pages, since the hook fires on whichever page actually hosts the field.
	 *
	 * @return array<int,string>
	 */
	protected function hashPageImages(int $pageId): array {
		$this->ensureHashTable();
		$page = $this->wire('pages')->get($pageId);
		if (!$page->id) return [];
		$page->of(false);
		$fields = $this->discoverImageFields();
		if (!$fields) return [];

		$existing = $this->loadHashRowsForPage($pageId);
		$hashes   = [];
		$present  = [];   // field\0basename of images currently on the page
		foreach ($fields as $fn) {
			if (!$page->template->hasField($fn)) continue;
			$val = $page->get($fn);
			if (!$val) continue;
			$items = ($val instanceof Pageimage) ? [$val] : $val;   // Pageimages is iterable
			foreach ($items as $img) {
				if (!$img instanceof Pageimage) continue;
				$bn   = (string) $img->basename;
				$present[$fn . "\0" . $bn] = true;
				$path = (string) $img->filename;
				$size = @filesize($path);
				$mt   = @filemtime($path);
				if ($size === false || $mt === false) continue;

				$cur = $existing[$fn . "\0" . $bn] ?? null;
				if ($cur && $cur['content_hash'] !== null
					&& (int) $cur['filesize'] === (int) $size
					&& (int) $cur['filemtime'] === (int) $mt) {
					$hashes[(string) $cur['content_hash']] = true;
					continue;
				}
				$content = $this->computeContentHash($path);
				if ($content === null) continue;
				$this->storeImageHash($pageId, $fn, $bn, (int) $size, (int) $mt, $content, null);
				$hashes[$content] = true;
			}
		}

		// Drop fingerprint + hardlink-manifest rows for images that are no
		// longer on this page (deleted / renamed), so duplicate counts and the
		// reclaimed-bytes figure stay accurate without waiting for a re-scan.
		$this->pruneStaleRowsForPage($pageId, $present);

		return array_keys($hashes);
	}

	/**
	 * Remove fingerprint (HASH_TABLE) rows for the given page whose (field,
	 * basename) is NOT in $present — i.e. images that no longer exist on the
	 * page. Called after re-hashing a saved page. Per-page, few rows; safe to
	 * run on every save.
	 *
	 * @param array<string,bool> $present keys "field\0basename" still on the page
	 */
	protected function pruneStaleRowsForPage(int $pageId, array $present): void {
		$this->ensureHashTable();

		// The hash table only holds originals, but be defensive: keep a present
		// image's VARIATION rows (foo.300x200.jpg belongs to foo.jpg) rather than
		// prune them as orphans.
		$stemsByField = [];
		foreach (array_keys($present) as $key) {
			[$fn, $bn] = array_pad(explode("\0", $key, 2), 2, '');
			$dot  = strrpos($bn, '.');
			$stem = $dot === false ? $bn : substr($bn, 0, $dot);
			if ($stem !== '') $stemsByField[$fn][$stem] = true;
		}
		$isKept = function (string $fn, string $bn) use ($present, $stemsByField): bool {
			if (isset($present[$fn . "\0" . $bn])) return true;        // present original
			foreach ($stemsByField[$fn] ?? [] as $stem => $_) {        // variation of one
				if (strncmp($bn, $stem . '.', strlen($stem) + 1) === 0) return true;
			}
			return false;
		};

		$db = $this->wire('database');
		try {
			$sel = $db->prepare('SELECT field_name, basename FROM `' . self::HASH_TABLE . '` WHERE page_id = ?');
			$sel->execute([$pageId]);
			$stale = [];
			while ($r = $sel->fetch(\PDO::FETCH_ASSOC)) {
				if (!$isKept((string) $r['field_name'], (string) $r['basename'])) {
					$stale[] = [$r['field_name'], $r['basename']];
				}
			}
			if (!$stale) return;
			$del = $db->prepare(
				'DELETE FROM `' . self::HASH_TABLE . '` WHERE page_id = ? AND field_name = ? AND basename = ?'
			);
			foreach ($stale as [$fn, $bn]) {
				$del->execute([$pageId, $fn, $bn]);
			}
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: prune stale rows failed: ' . $e->getMessage());
		}
	}

	/**
	 * Global cleanup: drop fingerprint rows that no longer correspond to a LIVE
	 * image (per loadImageRowsAll) — e.g. page-version repeater items, or images
	 * deleted out-of-band. Run at the start of a maintenance / live-scan pass so
	 * the cluster counts reflect reality.
	 */
	protected function pruneOrphanedRows(): void {
		$this->ensureHashTable();

		$live = [];   // "page\0field\0basename" of live + version-copy originals
		// Version-inclusive: version-copy files are legitimately deduped, so
		// their fingerprint rows must NOT be pruned as orphans.
		foreach ($this->loadImageRowsAll() as $r) {
			$live[(int) $r['pageId'] . "\0" . (string) $r['fieldName'] . "\0" . (string) $r['basename']] = true;
		}
		// Safety: an empty live set means discovery/cache hiccuped, NOT that the
		// whole library vanished. Pruning against it would wipe every fingerprint
		// (and silently empty the Duplicates view). Skip this pass instead.
		if (!$live) return;

		$db = $this->wire('database');
		try {
			$sel   = $db->query('SELECT page_id, field_name, basename FROM `' . self::HASH_TABLE . '`');
			$stale = [];
			while ($r = $sel->fetch(\PDO::FETCH_ASSOC)) {
				$pid = (int) $r['page_id']; $fn = (string) $r['field_name']; $bn = (string) $r['basename'];
				if (isset($live[$pid . "\0" . $fn . "\0" . $bn])) continue;     // live original
				$stale[] = [$pid, $fn, $bn];
			}
			if ($stale) {
				$del = $db->prepare(
					'DELETE FROM `' . self::HASH_TABLE . '` WHERE page_id = ? AND field_name = ? AND basename = ?'
				);
				foreach ($stale as [$pid, $fn, $bn]) {
					$del->execute([$pid, $fn, $bn]);
				}
			}
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: prune orphaned rows failed: ' . $e->getMessage());
		}
	}

	/**
	 * One automatic maintenance pass: fingerprint changed / new images, then
	 * collapse byte-identical groups onto a shared inode. Both halves are
	 * internally time-budgeted (HASH_SCAN_BUDGET_MS per call); this loops them
	 * up to $maxSeconds wall-clock so a large initial backlog converges over a
	 * few invocations (install kicks one off, LazyCron repeats it hourly).
	 */
	protected function runMaintenancePass(int $maxSeconds = 8): void {
		$stop = microtime(true) + max(1, $maxSeconds);
		try {
			// Fresh row list (no stale version items) before pruning leftovers.
			$this->wire('cache')->deleteFor($this);
			$this->pruneOrphanedRows();   // drop version-item / deleted-image leftovers first

			$off = 0;
			do { $r = $this->scanHashes($off); $off = (int) $r['nextOffset']; }
			while (empty($r['complete']) && microtime(true) < $stop);

			$off = 0;
			do { $r = $this->reclaimAll($off); $off = (int) $r['nextOffset']; }
			while (empty($r['complete']) && microtime(true) < $stop);

			// Collapse page-version file copies (the v<n>/ subdirs) onto the live
			// inode — a quick, self-contained filesystem pass.
			$this->reclaimVersionFiles();
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: maintenance pass failed: ' . $e->getMessage());
		}
	}

	// ------------------------------------------------------------------
	// Hardlink reclaim — collapse byte-identical copies to one inode.
	// ------------------------------------------------------------------
	// Each exact-duplicate cluster keeps ONE canonical file; every other
	// copy's file is replaced by a hardlink to that inode, so N copies cost
	// 1× the bytes. Safe against PW ops (read / variation / rename / deleting
	// one copy all keep the shared inode; only a byte-replace diverges, and
	// gracefully). There is deliberately NO separate manifest table: the
	// FILESYSTEM is the single source of truth — link counts report what's
	// shared (diskAudit), and revert un-shares anything with link-count ≥ 2
	// (expandAllSharedStep). A second bookkeeping table only drifts.
	// Caveat: backup/deploy tooling that doesn't preserve hardlinks will
	// re-expand them over time (loses the saving, never corrupts).

	/**
	 * Ground-truth disk audit: walk the real site/assets/files tree and measure
	 * what `du` would report — independent of our manifest. For every regular
	 * file we record its size and (device, inode) pair. The "apparent" total
	 * sums every file's size; the "actual" total counts each unique inode once,
	 * so a file shared across N names is billed a single time. apparent − actual
	 * is the space currently saved by hardlinks, measured straight from the
	 * filesystem. We also break out page-version files (those inside a `/v<n>/`
	 * subdir) so we can SEE whether they are already shared (link count ≥ 2) or
	 * still standalone copies (link count 1, i.e. reclaimable but not yet linked).
	 *
	 * @return array{
	 *   files:int, apparent:int, actual:int, saved:int,
	 *   versionFiles:int, versionShared:int, versionStandalone:int,
	 *   versionStandaloneBytes:int, truncated:bool
	 * }
	 */
	protected function diskAudit(): array {
		$root = (string) $this->wire('config')->paths->files; // site/assets/files/
		$out = [
			'files' => 0, 'apparent' => 0, 'actual' => 0, 'saved' => 0, 'sharedFiles' => 0,
			'versionFiles' => 0, 'versionShared' => 0, 'versionStandalone' => 0,
			'versionStandaloneBytes' => 0, 'truncated' => false,
			'versionReasons' => [],   // why each standalone version file wasn't linked (reason => count)
		];
		if ($root === '' || !is_dir($root)) return $out;
		$rootLen = strlen($root);

		$seenInode = [];          // "dev:inode" => true, to count shared inodes once
		$max = 400000;            // safety cap so a giant tree can't run away
		try {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ($it as $file) {
				if (!$file->isFile() || $file->isLink()) continue;
				if ($out['files'] >= $max) { $out['truncated'] = true; break; }

				$size  = (int) $file->getSize();
				$inode = (int) $file->getInode();
				$dev   = (int) @stat($file->getPathname())['dev'];
				$nlink = (int) @stat($file->getPathname())['nlink'];
				$key   = $dev . ':' . $inode;

				$out['files']++;
				$out['apparent'] += $size;
				if ($nlink >= 2) $out['sharedFiles']++;   // file collapsed onto a shared inode
				if ($inode === 0 || !isset($seenInode[$key])) {
					$seenInode[$key] = true;
					$out['actual'] += $size;
				}

				// Page-version file? Path contains a /v<digits>/ segment.
				if (preg_match('~/v\d+/~', str_replace('\\', '/', $file->getPathname()))) {
					$out['versionFiles']++;
					if ($nlink >= 2) {
						$out['versionShared']++;
					} else {
						$out['versionStandalone']++;
						$out['versionStandaloneBytes'] += $size;
						// Diagnose exactly WHY this one wasn't linked to its live twin.
						$rel    = str_replace('\\', '/', substr($file->getPathname(), $rootLen));
						$reason = 'nestedPath';
						if (preg_match('~^(\d+)/(v\d+)/(.+)$~', $rel, $m)) {
							$livePath = $root . $m[1] . '/' . $m[3];
							if (!is_file($livePath)) {
								$reason = 'noLiveTwin';
							} elseif ($this->sameInode($livePath, $file->getPathname())) {
								$reason = 'alreadySameInode';   // (shouldn't be standalone — sanity)
							} else {
								$liveSize = (int) @filesize($livePath);
								if ($liveSize !== $size) {
									$reason = 'sizeDiffersFromLive';
								} elseif (@hash_file($this->hashAlgo(), $livePath) !== @hash_file($this->hashAlgo(), $file->getPathname())) {
									$reason = 'bytesDifferFromLive';   // same size, different content
								} else {
									$reason = 'identicalButUnlinked';   // link attempt must have failed
								}
							}
						}
						$out['versionReasons'][$reason] = ($out['versionReasons'][$reason] ?? 0) + 1;
					}
				}
			}
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: disk audit failed: ' . $e->getMessage());
		}

		$out['saved'] = max(0, $out['apparent'] - $out['actual']);
		return $out;
	}

	/**
	 * Dedup figures measured from the FILESYSTEM and CACHED (the audit is a full
	 * tree walk, too heavy for every page load): bytes currently saved by
	 * hardlinks, and how many files are collapsed onto a shared inode. This is
	 * the single source of truth — it can't drift the way a bookkeeping table
	 * does. Pass $refresh=true after a reclaim/revert run to re-walk.
	 *
	 * @return array{saved:int,shared:int}
	 */
	protected function cachedDiskStats(bool $refresh = false): array {
		$cache = $this->wire('cache');
		$key   = 'ml_disk_stats';
		if (!$refresh) {
			$v = $cache->getFor($this, $key);
			if (is_string($v) && $v !== '') {
				$d = json_decode($v, true);
				if (is_array($d) && isset($d['saved'], $d['shared'])) {
					return ['saved' => (int) $d['saved'], 'shared' => (int) $d['shared']];
				}
			}
		}
		$a   = $this->diskAudit();
		$out = ['saved' => (int) $a['saved'], 'shared' => (int) $a['sharedFiles']];
		$cache->saveFor($this, $key, json_encode($out), 3600); // 1h TTL; refreshed on runs
		return $out;
	}

	/** True if both paths already point at the same inode (already linked). */
	protected function sameInode(string $a, string $b): bool {
		$ia = @fileinode($a); $ib = @fileinode($b);
		return $ia !== false && $ib !== false && $ia === $ib;
	}

	/** Byte-identity check, re-verified at reclaim time (not trusting the
	 *  scan): cheap size guard first, then a full content hash. */
	protected function filesByteIdentical(string $a, string $b): bool {
		$sa = @filesize($a); $sb = @filesize($b);
		if ($sa === false || $sb === false || $sa !== $sb) return false;
		if ($sa === 0) return true;
		return @hash_file($this->hashAlgo(), $a) === @hash_file($this->hashAlgo(), $b);
	}

	/** Atomically replace $targetPath with a hardlink to $canonicalPath:
	 *  link to a temp name in the same dir, then rename over the target so
	 *  there is never a moment without the file. Same filesystem required. */
	protected function hardlinkReplace(string $canonicalPath, string $targetPath): bool {
		$tmp = $targetPath . '.' . uniqid('mlhl', true) . '.tmp';
		@unlink($tmp);
		if (!@link($canonicalPath, $tmp)) return false;        // cross-fs / perms
		if (!@rename($tmp, $targetPath)) { @unlink($tmp); return false; }
		return true;
	}

	/** Replace a (possibly hardlinked) file with an independent copy of its
	 *  own bytes — the un-share half. Used by expandAllSharedStep. */
	protected function expandFile(string $path): bool {
		$tmp = $path . '.' . uniqid('mlxp', true) . '.tmp';
		if (!@copy($path, $tmp)) return false;                 // new inode
		if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
		return true;
	}

	/**
	 * Resolve a member identity to its on-disk file path, or null. Handles
	 * both originals (resolved via the Pageimage API) AND variation basenames
	 * (e.g. "foo.300x200.jpg", which are not stored Pageimages): those resolve
	 * to the page's files directory — via filesPath(), so extended / secure
	 * file paths are honoured rather than hand-built.
	 */
	protected function memberFilePath(array $m): ?string {
		$page = $this->wire('pages')->get((int) $m['pageId']);
		if (!$page->id) return null;
		$bn  = (string) $m['basename'];
		$img = $this->resolvePageimage($page, (string) $m['fieldName'], $bn);
		if ($img) {
			$f = (string) $img->filename;
			return ($f !== '' && is_file($f)) ? $f : null;
		}
		// Variation (no matching Pageimage): build from the page's files dir.
		$f = $page->filesPath() . $bn;
		return is_file($f) ? $f : null;
	}

	/**
	 * Collapse one cluster's copies onto a single canonical inode.
	 *
	 * @param array<int,array{pageId:int,fieldName:string,basename:string}> $members
	 * @return array{linked:int,already:int,skipped:int,bytes:int}
	 */
	protected function reclaimMembers(array $members): array {
		$res = ['linked' => 0, 'already' => 0, 'skipped' => 0, 'bytes' => 0, 'varLinked' => 0, 'varBytes' => 0];
		if (count($members) < 2) return $res;

		// Deterministic canonical so re-runs always link to the same file.
		usort($members, function ($a, $b) {
			return [$a['pageId'], $a['fieldName'], $a['basename']]
				<=> [$b['pageId'], $b['fieldName'], $b['basename']];
		});

		$imageFields = $this->discoverImageFields();
		$canon = null; $canonPath = null; $canonIdx = -1;
		foreach ($members as $k => $m) {
			$p = $this->memberFilePath($m);
			if ($p !== null) { $canon = $m; $canonPath = $p; $canonIdx = $k; break; }
		}
		if ($canonPath === null) return $res;

		foreach ($members as $k => $m) {
			if ($k === $canonIdx) continue;
			if (!in_array($m['fieldName'], $imageFields, true)) { $res['skipped']++; continue; }
			$mp = $this->memberFilePath($m);
			if ($mp === null) { $res['skipped']++; continue; }
			if ($this->sameInode($canonPath, $mp)) { $res['already']++; continue; }
			if (!$this->filesByteIdentical($canonPath, $mp)) { $res['skipped']++; continue; }
			$size = (int) @filesize($mp);
			if ($this->hardlinkReplace($canonPath, $mp)) {
				$res['linked']++; $res['bytes'] += $size;
				// The copy now shares the canonical inode → its mtime changed.
				// Write that back so the next budgeted scan skips it instead of
				// re-hashing the whole reclaimed set (which would stall the scan
				// short of fingerprinting the rest of the library).
				$nm = @filemtime($mp);
				if ($nm !== false) {
					$this->refreshStoredMtime((int) $m['pageId'], (string) $m['fieldName'], (string) $m['basename'], (int) $nm);
				}
			} else {
				$res['skipped']++;
			}
		}

		// Also collapse the byte-identical VARIATIONS (sm/md/lg thumbnails …)
		// that pile up identically in each copy's folder. Variations are lazy
		// (they often don't exist yet at assign time), so the recurring
		// maintenance pass is what really catches them; here it's cheap once
		// linked (sameInode fast-skip).
		$v = $this->reclaimVariations($members);
		$res['varLinked'] = $v['linked'];
		$res['varBytes']  = $v['bytes'];

		return $res;
	}

	/**
	 * Reclaim a CHUNK of clusters ($offset … $offset+$limit) and return a
	 * per-cluster breakdown, for the live progress UI. Drives the same engine
	 * as reclaimAll but small + descriptive instead of time-budgeted.
	 *
	 * @return array{totalClusters:int,nextOffset:int,complete:bool,details:array<int,array<string,mixed>>}
	 */
	protected function reclaimStep(int $offset, int $limit = 4): array {
		$clusters = $this->loadExactClusters()['clusters'];
		$total = count($clusters);
		if ($offset < 0) $offset = 0;

		$details = [];
		$i   = $offset;
		$end = min($total, $offset + max(1, $limit));
		for (; $i < $end; $i++) {
			$members = $clusters[$i]['members'];
			$r = $this->reclaimMembers($members);
			$first = $members[0] ?? null;
			$details[] = [
				'label'      => $first ? (string) $first['basename'] : ('#' . $i),
				'members'    => count($members),
				'originals'  => (int) $r['linked'],
				'variations' => (int) $r['varLinked'],
				'already'    => (int) $r['already'],
				'skipped'    => (int) $r['skipped'],
				'bytes'      => (int) $r['bytes'] + (int) $r['varBytes'],
			];
		}
		return [
			'totalClusters' => $total,
			'nextOffset'    => $i,
			'complete'      => $i >= $total,
			'details'       => $details,
		];
	}

	/**
	 * Collapse the byte-identical variation files within one cluster. Identical
	 * originals rendered with identical parameters produce byte-identical
	 * variations — but they live as separate files in each copy's folder. Group
	 * every member's variations by the filename part AFTER the original stem
	 * (e.g. ".300x200.jpg") — that key is the same across copies even when the
	 * originals were renamed (foo.jpg vs foo-1.jpg) — then hardlink the
	 * identical ones onto one inode. Byte-identity is re-verified before every
	 * link, so a wrong link is impossible (worst case: a variation isn't
	 * linked). Reversible via the filesystem-level revert (expandAllSharedStep).
	 *
	 * @param array<int,array{pageId:int,fieldName:string,basename:string}> $members
	 * @return array{linked:int,bytes:int}
	 */
	protected function reclaimVariations(array $members): array {
		$res = ['linked' => 0, 'bytes' => 0];

		// key => list of { pageId, fieldName, basename, path }
		$groups = [];
		foreach ($members as $m) {
			$orig = $this->memberFilePath($m);
			if ($orig === null) continue;
			$dir  = dirname($orig) . '/';
			$obn  = basename($orig);                       // foo.jpg
			$dot  = strrpos($obn, '.');
			$stem = $dot === false ? $obn : substr($obn, 0, $dot);   // foo
			if ($stem === '') continue;
			// Variation files share the original's stem: "<stem>.*".
			foreach (glob($dir . $stem . '.*', GLOB_NOSORT) ?: [] as $path) {
				$bn = basename($path);
				if ($bn === $obn || !is_file($path)) continue;   // skip the original
				$key = substr($bn, strlen($stem));               // ".300x200.jpg"
				$groups[$key][] = [
					'pageId'    => (int) $m['pageId'],
					'fieldName' => (string) $m['fieldName'],
					'basename'  => $bn,
					'path'      => $path,
				];
			}
		}

		foreach ($groups as $list) {
			if (count($list) < 2) continue;
			$canon = null;
			foreach ($list as $e) { if (is_file($e['path'])) { $canon = $e; break; } }
			if ($canon === null) continue;
			foreach ($list as $e) {
				if ($e['path'] === $canon['path']) continue;
				if ($this->sameInode($canon['path'], $e['path'])) continue;
				if (!$this->filesByteIdentical($canon['path'], $e['path'])) continue;  // safety
				$size = (int) @filesize($e['path']);
				if ($this->hardlinkReplace($canon['path'], $e['path'])) {
					$res['linked']++; $res['bytes'] += $size;
				}
			}
		}
		return $res;
	}

	/**
	 * Time-budgeted reclaim over all exact-duplicate clusters, by cluster
	 * index. The client drives it with the returned nextOffset until complete.
	 *
	 * @return array{totalClusters:int,processed:int,linked:int,already:int,skipped:int,bytes:int,nextOffset:int,complete:bool}
	 */
	protected function reclaimAll(int $offset = 0): array {
		$clusters = $this->loadExactClusters()['clusters'];
		$total = count($clusters);
		if ($offset < 0) $offset = 0;

		$deadline = microtime(true) + (self::HASH_SCAN_BUDGET_MS / 1000);
		$linked = 0; $already = 0; $skipped = 0; $bytes = 0; $processed = 0;

		$i = $offset;
		for (; $i < $total; $i++) {
			if (microtime(true) > $deadline) break;
			$r = $this->reclaimMembers($clusters[$i]['members']);
			$linked += $r['linked'] + $r['varLinked']; $already += $r['already'];
			$skipped += $r['skipped']; $bytes += $r['bytes'] + $r['varBytes'];
			$processed++;
		}
		return [
			'totalClusters' => $total,
			'processed'     => $processed,
			'linked'        => $linked,
			'already'       => $already,
			'skipped'       => $skipped,
			'bytes'         => $bytes,
			'nextOffset'    => $i,
			'complete'      => $i >= $total,
		];
	}

	/**
	 * Un-share EVERY shared file on disk — the authoritative "revert all".
	 *
	 * Works straight off the filesystem (no bookkeeping that could drift below
	 * reality and leave orphaned hardlinks collapsed). It walks the real
	 * assets/files tree and gives an independent copy back to
	 * every file whose inode link-count is ≥ 2 — exactly what the filesystem
	 * reports as shared, regardless of bookkeeping. Time-budgeted; re-call until
	 * complete. When a cluster's first member is expanded the others' link-count
	 * drops, so later members are naturally seen as standalone and skipped.
	 *
	 * @return array{expanded:int,remaining:int,scanned:int,complete:bool}
	 */
	protected function expandAllSharedStep(): array {
		$out = ['expanded' => 0, 'remaining' => 0, 'scanned' => 0, 'complete' => true];
		$root = (string) $this->wire('config')->paths->files;
		if ($root === '' || !is_dir($root)) return $out;
		$deadline = microtime(true) + (self::HASH_SCAN_BUDGET_MS / 1000);
		try {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ($it as $file) {
				if (!$file->isFile() || $file->isLink()) continue;
				$out['scanned']++;
				$st = @stat($file->getPathname());
				if (!$st || (int) ($st['nlink'] ?? 1) < 2) continue;   // not shared
				if (microtime(true) > $deadline) { $out['remaining']++; continue; }
				if ($this->expandFile($file->getPathname())) $out['expanded']++;
				else $out['remaining']++;
			}
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: expand-all-shared failed: ' . $e->getMessage());
		}
		$out['complete'] = ($out['remaining'] === 0);
		return $out;
	}

	/**
	 * Reclaim page-version files. ProcessWire's Page Versions feature copies a
	 * page's files into a `v<n>/` subdirectory of the SAME page folder when a
	 * version is saved (PagesVersionsFiles::copyPageVersionFiles → dirPrefix 'v'),
	 * original AND every variation. Those copies are usually byte-identical to
	 * the live file one level up — e.g.
	 *     1/v2/hirsch_bg_16-9.jpg   ←→   1/hirsch_bg_16-9.jpg
	 * so each version copy can be collapsed onto the live inode. We work purely
	 * at the filesystem level (no Page Versions API), which is why this is safe
	 * and self-contained: the canonical is just the same basename without the
	 * `v<n>/` segment. Byte-identity is re-verified before linking; differing
	 * versions are left untouched. Reversible via the filesystem-level revert
	 * (expandAllSharedStep), which un-shares any inode with link-count >= 2.
	 *
	 * @return array{linked:int,already:int,skipped:int,bytes:int,versionFiles:int}
	 */
	protected function reclaimVersionFiles(): array {
		$out = ['linked' => 0, 'already' => 0, 'skipped' => 0, 'bytes' => 0, 'versionFiles' => 0];
		$root = (string) $this->wire('config')->paths->files;
		if ($root === '' || !is_dir($root)) return $out;
		$rootLen = strlen($root);

		try {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ($it as $file) {
				if (!$file->isFile() || $file->isLink()) continue;
				$rel = str_replace('\\', '/', substr($file->getPathname(), $rootLen));
				// Expect "<pageId>/v<n>/<basename>" — exactly one version segment.
				if (!preg_match('~^(\d+)/(v\d+)/([^/]+)$~', $rel, $m)) continue;
				$out['versionFiles']++;

				$versionPath = $file->getPathname();
				$livePath    = $root . $m[1] . '/' . $m[3];   // same basename, no v<n>/
				if (!is_file($livePath)) { $out['skipped']++; continue; }
				if ($this->sameInode($livePath, $versionPath)) { $out['already']++; continue; }
				if (!$this->filesByteIdentical($livePath, $versionPath)) { $out['skipped']++; continue; }

				$bytes = (int) @filesize($versionPath);
				if ($this->hardlinkReplace($livePath, $versionPath)) {
					$out['linked']++;
					$out['bytes'] += $bytes;
				} else {
					$out['skipped']++;
				}
			}
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: version-file reclaim failed: ' . $e->getMessage());
		}
		return $out;
	}
}
