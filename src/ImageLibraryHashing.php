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
	 * Set of every image that is a byte-identical duplicate of another, keyed
	 * "pageId\0fieldName\0basename" — i.e. every member of every exact cluster.
	 * Used by the "Duplicates" view filter. Empty before a scan.
	 *
	 * @return array<string,bool>
	 */
	protected function loadDuplicateKeys(): array {
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
					'SELECT page_id, field_name, basename
					 FROM `' . self::HASH_TABLE . '` WHERE content_hash IN (' . $in . ')'
				);
				$rows->execute($hashes);
				while ($r = $rows->fetch(\PDO::FETCH_ASSOC)) {
					$keys[$r['page_id'] . "\0" . $r['field_name'] . "\0" . $r['basename']] = true;
				}
			}
		} catch (\Throwable $e) {
		}
		return $keys;
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
