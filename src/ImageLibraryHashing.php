<?php namespace ProcessWire;

/**
 * Content-identity layer for ProcessImageLibrary (deduplication, phase 1).
 *
 * Gives every managed image two fingerprints and stores them so duplicate
 * detection (a later phase) can group copies cheaply:
 *
 *   - content_hash : exact byte hash (xxh128 where available, else md5).
 *                    Two rows with the same content_hash are byte-identical
 *                    copies of the same file.
 *   - dhash        : a 64-bit perceptual "difference hash" (pure PHP + GD),
 *                    stored as 16 hex chars. Small Hamming distance between
 *                    two dhashes => the same picture at a different size or
 *                    format (a NEAR-duplicate exact hashing can't catch).
 *
 * Storage is the module's own table `process_imagelibrary_hashes`, keyed by
 * the same identity the rest of the module uses — (page_id, field_name,
 * basename) — plus the file's (filesize, filemtime) so a fingerprint is only
 * recomputed when the bytes actually change. The table is created lazily
 * (CREATE TABLE IF NOT EXISTS) so it appears on already-installed sites
 * without an upgrade hook, and is dropped on uninstall.
 *
 * This trait is the engine only — it computes, caches and exposes
 * fingerprints. It does NOT change any rendering or default behaviour;
 * surfacing duplicates (a "duplicates" view / filter, where-used per cluster,
 * edit-once-propagate, optional hardlink reclaim) is built on top in later
 * phases. The one entry point that populates the table is the time-budgeted
 * scan (___executeScanHashes), which has no UI trigger yet.
 *
 * Composed into ProcessImageLibrary via `use`.
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
	 * Exact byte hash of a file, or null if unreadable.
	 */
	protected function computeContentHash(string $path): ?string {
		$h = @hash_file($this->hashAlgo(), $path);
		return ($h === false) ? null : $h;
	}

	/**
	 * 64-bit perceptual difference hash (dHash) as 16 hex chars, or null on
	 * decode failure. Algorithm: downscale to 9×8, greyscale luminance, and
	 * for each row emit one bit per adjacent pixel pair (right brighter than
	 * left → 1) — 8 rows × 8 comparisons = 64 bits. Downscaling normalises
	 * away resolution differences, so the same photo at 1600px and 800px (or
	 * jpg vs webp) yields a near-identical dHash. Robust by design: any
	 * failure returns null rather than throwing.
	 */
	protected function computeDHash(string $path): ?string {
		if (!function_exists('imagecreatefromstring')) return null;
		$data = @file_get_contents($path);
		if ($data === false || $data === '') return null;

		$src = @imagecreatefromstring($data);
		if (!$src) return null;

		$w = 9; $h = 8;
		$small = @imagecreatetruecolor($w, $h);
		if (!$small) { imagedestroy($src); return null; }

		$ok = @imagecopyresampled($small, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
		imagedestroy($src);
		if (!$ok) { imagedestroy($small); return null; }

		$bits = '';
		for ($y = 0; $y < $h; $y++) {
			$prevLum = 0;
			for ($x = 0; $x < $w; $x++) {
				$rgb = imagecolorat($small, $x, $y);
				$r = ($rgb >> 16) & 0xFF;
				$g = ($rgb >> 8) & 0xFF;
				$b = $rgb & 0xFF;
				$lum = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
				if ($x > 0) $bits .= ($lum > $prevLum) ? '1' : '0';
				$prevLum = $lum;
			}
		}
		imagedestroy($small);

		if (strlen($bits) !== 64) return null;
		// 64 bits → 16 hex nibbles.
		$hex = '';
		for ($i = 0; $i < 64; $i += 4) {
			$hex .= dechex(bindec(substr($bits, $i, 4)));
		}
		return $hex;
	}

	/**
	 * Hamming distance (0–64) between two 16-hex dHashes; 64 (max) when
	 * either is malformed. Used to cluster near-duplicates. Byte-wise XOR +
	 * popcount avoids any 64-bit signed-int pitfalls.
	 */
	protected function dhashDistance(?string $a, ?string $b): int {
		if (!is_string($a) || !is_string($b) || strlen($a) !== 16 || strlen($b) !== 16) return 64;
		$ba = @hex2bin($a);
		$bb = @hex2bin($b);
		if ($ba === false || $bb === false || strlen($ba) !== 8 || strlen($bb) !== 8) return 64;
		$d = 0;
		for ($i = 0; $i < 8; $i++) {
			$x = ord($ba[$i]) ^ ord($bb[$i]);
			$d += substr_count(decbin($x), '1');
		}
		return $d;
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
				'SELECT field_name, basename, filesize, filemtime, content_hash, dhash
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
	 * Time-budgeted fingerprint scan over the whole managed image set.
	 *
	 * Walks the cached row list from $offset, skipping images whose stored
	 * (filesize, filemtime) still match the file on disk, computing + storing
	 * the content hash and dHash for the rest, until HASH_SCAN_BUDGET_MS is
	 * spent or the list ends. Pages are loaded once and their stored hashes
	 * fetched once, so the per-image cost is a stat plus (when stale) the two
	 * hashes. Returns progress so a client can drive it to completion.
	 *
	 * @return array{total:int,processed:int,hashed:int,skipped:int,nextOffset:int,complete:bool}
	 */
	protected function scanHashes(int $offset = 0): array {
		$this->ensureHashTable();

		$rows  = $this->loadRows();
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
			$dhash   = $this->computeDHash($path);
			$this->storeImageHash($pid, $fn, $bn, (int) $size, (int) $mt, $content, $dhash);
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
}
