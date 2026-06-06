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

	/** Combined fingerprint version stored per row: content-hash algorithm +
	 *  perceptual-hash version. A scanned row whose stored value differs from
	 *  this is recomputed on the next scan, so an algorithm change re-hashes
	 *  even files that haven't changed on disk. */
	protected function expectedAlgo(): string {
		return $this->hashAlgo() . '/' . self::PERCEPTUAL_VERSION;
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
			$this->wire('database')->exec('DROP TABLE IF EXISTS `' . self::HARDLINK_TABLE . '`');
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
	 * 64-bit perceptual hash (pHash, DCT-based) as 16 hex chars, or null on
	 * decode failure. Stored in the same `dhash` column as the old difference
	 * hash — the format is identical (64 bits), only far more discriminating.
	 *
	 * Algorithm (the standard pHash): downscale to 32×32 greyscale, take the
	 * 2-D DCT, keep the top-left 8×8 low-frequency block (the gist of the
	 * image), and set each of those 64 coefficients to 1 if it's above the
	 * block's median, else 0. Because it captures frequency STRUCTURE rather
	 * than adjacent-pixel brightness, the same photo at any size/format hashes
	 * within a handful of bits, while genuinely different photos that merely
	 * share a tonal layout (sky over ground, dark scene with a bright spot)
	 * land 25–35 bits apart — exactly the separation the 9×8 dHash lacked.
	 */
	protected function computeDHash(string $path): ?string {
		if (!function_exists('imagecreatefromstring')) return null;
		$data = @file_get_contents($path);
		if ($data === false || $data === '') return null;

		$src = @imagecreatefromstring($data);
		if (!$src) return null;

		$N = 32;   // sample size
		$M = 8;    // low-frequency block kept (M×M = 64 bits)
		$small = @imagecreatetruecolor($N, $N);
		if (!$small) { imagedestroy($src); return null; }
		$ok = @imagecopyresampled($small, $src, 0, 0, 0, 0, $N, $N, imagesx($src), imagesy($src));
		imagedestroy($src);
		if (!$ok) { imagedestroy($small); return null; }

		$g = [];
		for ($y = 0; $y < $N; $y++) {
			for ($x = 0; $x < $N; $x++) {
				$rgb = imagecolorat($small, $x, $y);
				$g[$y][$x] = 0.299 * (($rgb >> 16) & 0xFF)
					+ 0.587 * (($rgb >> 8) & 0xFF)
					+ 0.114 * ($rgb & 0xFF);
			}
		}
		imagedestroy($small);

		// Cosine table (memoised) for the M×N DCT basis.
		static $cos = null;
		if ($cos === null) {
			$cos = [];
			for ($u = 0; $u < $M; $u++) {
				for ($x = 0; $x < $N; $x++) {
					$cos[$u][$x] = cos((2 * $x + 1) * $u * M_PI / (2 * $N));
				}
			}
		}

		$dct = [];
		for ($u = 0; $u < $M; $u++) {
			for ($v = 0; $v < $M; $v++) {
				$sum = 0.0;
				for ($y = 0; $y < $N; $y++) {
					$cy = $cos[$v][$y];
					$row = $g[$y];
					for ($x = 0; $x < $N; $x++) {
						$sum += $row[$x] * $cos[$u][$x] * $cy;
					}
				}
				$dct[] = $sum;
			}
		}

		$sorted = $dct;
		sort($sorted);
		$median = ($sorted[31] + $sorted[32]) / 2;

		$bits = '';
		for ($i = 0; $i < 64; $i++) $bits .= ($dct[$i] > $median) ? '1' : '0';

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
				$contentHash, $dhash, $this->expectedAlgo(),
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

	/** pHash Hamming distance at/under which two images count as the same
	 *  picture (different size/format/recompression). With the DCT pHash,
	 *  genuine variants test at 0–5 while structurally different photos land
	 *  ~25–35 apart, so 10 sits comfortably in the gap. */
	const NEAR_DHASH_THRESHOLD = 10;

	/** Bumped whenever the perceptual-hash ALGORITHM changes, so already-
	 *  scanned images are recomputed on the next scan even though their files
	 *  haven't changed. p1 = 9×8 dHash (retired); p2 = 32→8 DCT pHash. */
	const PERCEPTUAL_VERSION = 'p2';

	/** Two images can only be near-dup candidates if their aspect ratios
	 *  match within this factor. A real "same photo, smaller" keeps its
	 *  ratio exactly; this alone rules out portrait-vs-landscape mismatches. */
	const NEAR_ASPECT_TOLERANCE = 1.02;

	/** Pairwise near-dup clustering is O(n²); above this many fingerprinted
	 *  images it's skipped (with a note) to keep the view responsive. */
	const NEAR_MAX_IMAGES = 6000;

	/**
	 * Near-duplicate groups: images whose perceptual hashes are within
	 * NEAR_DHASH_THRESHOLD of each other (the same picture at a different
	 * size / format / recompression). Built by union-find over pairwise
	 * Hamming distance. Only groups that span ≥2 DISTINCT content hashes are
	 * returned — a group that's all one content hash is just an exact
	 * duplicate (already shown), not a near one.
	 *
	 * @return array{capped:bool, groups:array<int,array<int,array{pageId:int,fieldName:string,basename:string,contentHash:?string}>>}
	 */
	protected function loadNearClusters(int $threshold = self::NEAR_DHASH_THRESHOLD): array {
		$this->ensureHashTable();
		$items = [];
		try {
			$stmt = $this->wire('database')->query(
				'SELECT page_id, field_name, basename, content_hash, dhash
				 FROM `' . self::HASH_TABLE . '` WHERE dhash IS NOT NULL'
			);
			while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$bin = @hex2bin((string) $r['dhash']);
				if ($bin === false || strlen($bin) !== 8) continue;
				$items[] = [
					'pageId'      => (int) $r['page_id'],
					'fieldName'   => (string) $r['field_name'],
					'basename'    => (string) $r['basename'],
					'contentHash' => $r['content_hash'],
					'bin'         => $bin,
				];
			}
		} catch (\Throwable $e) {
			return ['capped' => false, 'groups' => []];
		}

		// Attach each image's aspect ratio (from the live rows) and drop
		// anything we can't size. The aspect gate is the main thing that
		// stops unrelated photos which merely share a tonal layout (sky over
		// ground) — or worse, a portrait vs a landscape — from grouping.
		$dims = [];
		foreach ($this->loadRows() as $r) {
			$w = (int) ($r['width'] ?? 0); $h = (int) ($r['height'] ?? 0);
			if ($w > 0 && $h > 0) {
				$dims[$r['pageId'] . "\0" . $r['fieldName'] . "\0" . $r['basename']] = $w / $h;
			}
		}
		$sized = [];
		foreach ($items as $it) {
			$k = $it['pageId'] . "\0" . $it['fieldName'] . "\0" . $it['basename'];
			if (!isset($dims[$k])) continue;
			$it['aspect'] = $dims[$k];
			$sized[] = $it;
		}
		$items = $sized;

		$n = count($items);
		if ($n < 2) return ['capped' => false, 'groups' => []];
		if ($n > self::NEAR_MAX_IMAGES) return ['capped' => true, 'groups' => []];

		// Union-find (iterative path-halving — no recursion).
		$parent = range(0, $n - 1);
		$find = function (int $x) use (&$parent): int {
			while ($parent[$x] !== $x) { $parent[$x] = $parent[$parent[$x]]; $x = $parent[$x]; }
			return $x;
		};
		for ($a = 0; $a < $n; $a++) {
			$ba = $items[$a]['bin']; $aspA = $items[$a]['aspect'];
			for ($b = $a + 1; $b < $n; $b++) {
				// Aspect gate first — a different shape can't be the same
				// photo resized, so it's never a near-duplicate.
				$aspB = $items[$b]['aspect'];
				$ratio = $aspA > $aspB ? ($aspA / $aspB) : ($aspB / $aspA);
				if ($ratio > self::NEAR_ASPECT_TOLERANCE) continue;

				$bb = $items[$b]['bin'];
				$d = 0;
				for ($i = 0; $i < 8; $i++) {
					$x = ord($ba[$i]) ^ ord($bb[$i]);
					$d += substr_count(decbin($x), '1');
					if ($d > $threshold) break;
				}
				if ($d <= $threshold) {
					$ra = $find($a); $rb = $find($b);
					if ($ra !== $rb) $parent[$ra] = $rb;
				}
			}
		}

		// Collect connected components.
		$byRoot = [];
		for ($k = 0; $k < $n; $k++) {
			unset($items[$k]['bin']);
			$byRoot[$find($k)][] = $items[$k];
		}
		// Keep only genuinely-near groups: ≥2 members AND ≥2 content hashes.
		$groups = [];
		foreach ($byRoot as $g) {
			if (count($g) < 2) continue;
			$hashes = [];
			foreach ($g as $m) if ($m['contentHash'] !== null) $hashes[$m['contentHash']] = true;
			if (count($hashes) < 2) continue;
			$groups[] = $g;
		}
		return ['capped' => false, 'groups' => $groups];
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
				&& (int) $existing['filemtime'] === (int) $mt
				&& ($existing['algo'] ?? '') === $this->expectedAlgo()) {
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

	// ------------------------------------------------------------------
	// Hardlink reclaim — collapse byte-identical copies to one inode.
	// ------------------------------------------------------------------
	// Each exact-duplicate cluster keeps ONE canonical file; every other
	// copy's file is replaced by a hardlink to that inode, so N copies cost
	// 1× the bytes. Safe against PW ops (read / variation / rename / deleting
	// one copy all keep the shared inode; only a byte-replace diverges, and
	// gracefully). A manifest table records each link so the savings can be
	// reported and the whole thing un-shared (expandManifest) — reversible.
	// Caveat: backup/deploy tooling that doesn't preserve hardlinks will
	// re-expand them over time (loses the saving, never corrupts).

	/** Manifest of collapsed copies: which file links to which canonical. */
	const HARDLINK_TABLE = 'process_imagelibrary_hardlinks';

	protected function ensureHardlinkTable(): void {
		static $ensured = false;
		if ($ensured) return;
		$ensured = true;
		$sql = 'CREATE TABLE IF NOT EXISTS `' . self::HARDLINK_TABLE . '` (
			page_id INT UNSIGNED NOT NULL,
			field_name VARCHAR(128) NOT NULL,
			basename VARCHAR(191) NOT NULL,
			canon_page_id INT UNSIGNED NOT NULL,
			canon_field VARCHAR(128) NOT NULL,
			canon_basename VARCHAR(191) NOT NULL,
			bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			linked_at DATETIME NOT NULL,
			PRIMARY KEY (page_id, field_name, basename)
		) ENGINE=InnoDB DEFAULT CHARSET=ascii';
		try {
			$this->wire('database')->exec($sql);
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: hardlink table create failed: ' . $e->getMessage());
		}
	}

	/** Total bytes currently reclaimed (sum of the manifest). */
	protected function totalReclaimedBytes(): int {
		$this->ensureHardlinkTable();
		try {
			return (int) $this->wire('database')->query(
				'SELECT COALESCE(SUM(bytes), 0) FROM `' . self::HARDLINK_TABLE . '`'
			)->fetchColumn();
		} catch (\Throwable $e) {
			return 0;
		}
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
	 *  own bytes — the un-share half. Used by expandManifest. */
	protected function expandFile(string $path): bool {
		$tmp = $path . '.' . uniqid('mlxp', true) . '.tmp';
		if (!@copy($path, $tmp)) return false;                 // new inode
		if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
		return true;
	}

	/** Resolve a member identity to its on-disk file path, or null. */
	protected function memberFilePath(array $m): ?string {
		$page = $this->wire('pages')->get((int) $m['pageId']);
		if (!$page->id) return null;
		$img = $this->resolvePageimage($page, (string) $m['fieldName'], (string) $m['basename']);
		if (!$img) return null;
		$f = (string) $img->filename;
		return ($f !== '' && is_file($f)) ? $f : null;
	}

	protected function recordHardlink(array $member, array $canon, int $bytes): void {
		try {
			$stmt = $this->wire('database')->prepare(
				'INSERT INTO `' . self::HARDLINK_TABLE . '`
				 (page_id, field_name, basename, canon_page_id, canon_field, canon_basename, bytes, linked_at)
				 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
				 ON DUPLICATE KEY UPDATE canon_page_id=VALUES(canon_page_id), canon_field=VALUES(canon_field),
				   canon_basename=VALUES(canon_basename), bytes=VALUES(bytes), linked_at=NOW()'
			);
			$stmt->execute([
				(int) $member['pageId'], (string) $member['fieldName'], (string) $member['basename'],
				(int) $canon['pageId'], (string) $canon['fieldName'], (string) $canon['basename'], $bytes,
			]);
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: hardlink record failed: ' . $e->getMessage());
		}
	}

	/**
	 * Collapse one cluster's copies onto a single canonical inode.
	 *
	 * @param array<int,array{pageId:int,fieldName:string,basename:string}> $members
	 * @return array{linked:int,already:int,skipped:int,bytes:int}
	 */
	protected function reclaimMembers(array $members): array {
		$res = ['linked' => 0, 'already' => 0, 'skipped' => 0, 'bytes' => 0];
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
				$this->recordHardlink($m, $canon, $size);
				$res['linked']++; $res['bytes'] += $size;
			} else {
				$res['skipped']++;
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
		$this->ensureHardlinkTable();
		$clusters = $this->loadExactClusters()['clusters'];
		$total = count($clusters);
		if ($offset < 0) $offset = 0;

		$deadline = microtime(true) + (self::HASH_SCAN_BUDGET_MS / 1000);
		$linked = 0; $already = 0; $skipped = 0; $bytes = 0; $processed = 0;

		$i = $offset;
		for (; $i < $total; $i++) {
			if (microtime(true) > $deadline) break;
			$r = $this->reclaimMembers($clusters[$i]['members']);
			$linked += $r['linked']; $already += $r['already'];
			$skipped += $r['skipped']; $bytes += $r['bytes'];
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
	 * Un-share: walk the manifest and give every collapsed copy its own
	 * independent file again, then forget it. Time-budgeted, offset-driven.
	 *
	 * @return array{total:int,processed:int,expanded:int,nextOffset:int,complete:bool}
	 */
	protected function expandManifest(int $offset = 0): array {
		$this->ensureHardlinkTable();
		$rows = [];
		try {
			$stmt = $this->wire('database')->query(
				'SELECT page_id, field_name, basename FROM `' . self::HARDLINK_TABLE . '`
				 ORDER BY page_id, field_name, basename'
			);
			while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) $rows[] = $r;
		} catch (\Throwable $e) {
			return ['total' => 0, 'processed' => 0, 'expanded' => 0, 'nextOffset' => 0, 'complete' => true];
		}
		$total = count($rows);
		if ($offset < 0) $offset = 0;

		$deadline = microtime(true) + (self::HASH_SCAN_BUDGET_MS / 1000);
		$expanded = 0; $processed = 0;

		$i = $offset;
		for (; $i < $total; $i++) {
			if (microtime(true) > $deadline) break;
			$r = $rows[$i];
			$processed++;
			$path = $this->memberFilePath([
				'pageId' => (int) $r['page_id'], 'fieldName' => $r['field_name'], 'basename' => $r['basename'],
			]);
			if ($path !== null) {
				if ($this->expandFile($path)) $expanded++;
			}
			// Either way drop the manifest row (file gone, or now independent).
			try {
				$del = $this->wire('database')->prepare(
					'DELETE FROM `' . self::HARDLINK_TABLE . '` WHERE page_id=? AND field_name=? AND basename=?'
				);
				$del->execute([(int) $r['page_id'], $r['field_name'], $r['basename']]);
			} catch (\Throwable $e) {
			}
		}
		return [
			'total'      => $total,
			'processed'  => $processed,
			'expanded'   => $expanded,
			'nextOffset' => $i,
			'complete'   => $i >= $total,
		];
	}
}
