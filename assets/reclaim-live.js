/**
 * Live "scan & reclaim" driver for the module config page.
 *
 * Runs the chunked endpoints (scan-step → reclaim-step) and shows what the
 * module is doing in real time: a phase line, a progress bar, running totals,
 * and a per-cluster log. Replaces the old synchronous "blackbox" button.
 */
(function () {
	'use strict';

	function el(root, sel) { return root.querySelector(sel); }

	function post(url, body, csrf) {
		var fd = new FormData();
		Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
		if (csrf && csrf.name) fd.append(csrf.name, csrf.value);
		return fetch(url, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd
		}).then(function (r) { return r.json(); });
	}

	// cls is a UIkit utility class (e.g. uk-text-muted / uk-text-danger) so log
	// lines pick up the admin theme's colours in both light and dark mode.
	function logLine(ul, text, cls) {
		var li = document.createElement('li');
		if (cls) li.className = cls;
		li.textContent = text;
		ul.appendChild(li);
		ul.scrollTop = ul.scrollHeight;
	}

	function fmt(n) { return (n | 0).toLocaleString(); }

	// HTML-escape a server-provided string before it goes into innerHTML.
	// Today the audit fields are numbers / fixed reason keys, but escaping
	// keeps it safe if a path or filename ever flows into them.
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	// Byte → human string, matching the PHP formatFilesize so the live-counted
	// figure reads the same as the server's. Avoids `| 0` (32-bit) since byte
	// totals can exceed 2 GB.
	function humanBytes(bytes) {
		bytes = Math.max(0, Math.floor(bytes || 0));
		if (bytes === 0) return '0 B';
		var units = ['B', 'KB', 'MB', 'GB'], i = 0, size = bytes;
		while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
		return (i === 0 ? String(bytes) : (size >= 10 ? size.toFixed(0) : size.toFixed(1))) + ' ' + units[i];
	}

	// Keep the Status block honest: the "reclaimed" / "sharing" rows only make
	// sense when something is actually collapsed. After a reclaim they appear;
	// after a revert they disappear and the neutral note returns — no reload,
	// no misleading "0 MB" line. Mirrors the server-side initial render.
	function syncStatus(opts) {
		var status = document.querySelector('.ml-dedup-status');
		if (!status) return;
		var hasReclaim = (opts.linked | 0) > 0;
		var rowR = status.querySelector('.ml-stat-row-reclaimed');
		var rowS = status.querySelector('.ml-stat-row-shared');
		var empty = status.querySelector('.ml-stat-empty');
		var list = status.querySelector('.ml-stat-list');
		var clustersShown = list && list.querySelector('.ml-stat-row-clusters:not([hidden])');
		if (rowR) { rowR.hidden = !hasReclaim; if (opts.reclaimedHuman != null) { var s = rowR.querySelector('.ml-stat-reclaimed'); if (s) s.textContent = opts.reclaimedHuman; } }
		if (rowS) { rowS.hidden = !hasReclaim; var ss = rowS.querySelector('.ml-stat-shared'); if (ss) ss.textContent = fmt(opts.linked); }
		if (empty) empty.hidden = hasReclaim;
		if (list) list.style.display = (hasReclaim || clustersShown) ? '' : 'none';
	}

	function run(box, btn) {
		var panel  = el(box, '.ml-reclaim-panel');
		var phase  = el(box, '.ml-reclaim-phase');
		var bar    = el(box, '.ml-reclaim-bar');
		var totals = el(box, '.ml-reclaim-totals');
		var log    = el(box, '.ml-reclaim-log');
		var csrf   = { name: box.dataset.csrfName, value: box.dataset.csrfValue };
		var scanUrl = box.dataset.scanUrl, reclaimUrl = box.dataset.reclaimUrl;

		btn.disabled = true;
		panel.hidden = false;
		log.innerHTML = '';
		bar.value = 0;

		var linkedFiles = 0, freedHuman = '0', clustersDone = 0, clustersTotal = 0;
		// Live tallies: the disk isn't re-walked every chunk, so we count the
		// freed bytes / collapsed files from each chunk's per-cluster breakdown
		// on top of the pre-run baseline. On completion the server's measured
		// figures replace the estimate.
		var baseBytes = -1, baseShared = 0, freedBytes = 0, linkedNew = 0;

		function setStat(sel, text) {
			var n = document.querySelector(sel);
			if (n) n.textContent = text;
		}
		function updateTotals() {
			totals.innerHTML =
				'Reclaimed: <strong>' + freedHuman + '</strong> · '
				+ 'Files sharing an inode: <strong>' + fmt(linkedFiles) + '</strong> · '
				+ 'Clusters: <strong>' + fmt(clustersDone) + ' / ' + fmt(clustersTotal) + '</strong>';
			// Keep the top Status block in sync so it isn't stale after a run.
			syncStatus({ linked: linkedFiles, reclaimedHuman: freedHuman });
			if (clustersTotal) {
				setStat('.ml-stat-clusters', fmt(clustersTotal));
				var cr = document.querySelector('.ml-stat-row-clusters');
				if (cr) cr.hidden = false;
				var list = document.querySelector('.ml-stat-list');
				if (list) list.style.display = '';
			}
		}

		// Phase 1: fingerprint scan to completion.
		function scan(offset) {
			phase.textContent = 'Scanning images…';
			return post(scanUrl, { offset: offset }, csrf).then(function (d) {
				if (!d || !d.ok) throw new Error((d && d.error) || 'scan failed');
				var total = d.total | 0, done = Math.min(d.nextOffset | 0, total);
				bar.max = total || 1; bar.value = done;
				phase.textContent = 'Scanning images… ' + fmt(done) + ' / ' + fmt(total)
					+ '  (hashed ' + fmt(d.hashed) + ', skipped ' + fmt(d.skipped) + ')';
				if (!d.complete) return scan(d.nextOffset);
				logLine(log, '✓ Scan complete (' + fmt(total) + ' images).');
			});
		}

		// Phase 2: reclaim clusters, logging each.
		function reclaim(offset) {
			return post(reclaimUrl, { offset: offset }, csrf).then(function (d) {
				if (!d || !d.ok) throw new Error((d && d.error) || 'reclaim failed');
				clustersTotal = d.totalClusters | 0;
				clustersDone  = Math.min(d.nextOffset | 0, clustersTotal);
				if (baseBytes < 0) { baseBytes = d.reclaimedBytes || 0; baseShared = d.linkedTotal | 0; }
				bar.max = clustersTotal || 1; bar.value = clustersDone;
				phase.textContent = 'Reclaiming clusters… ' + fmt(clustersDone) + ' / ' + fmt(clustersTotal);
				(d.details || []).forEach(function (c) {
					freedBytes += (c.bytes || 0);
					linkedNew  += (c.originals || 0) + (c.variations || 0);
					var parts = [];
					if (c.originals)  parts.push(c.originals + '× original');
					if (c.variations) parts.push(c.variations + '× variation');
					if (c.already)    parts.push(c.already + ' already shared');
					if (!parts.length) parts.push('nothing to link');
					var human = c.bytes ? ' (+' + Math.round(c.bytes / 1024) + ' KB)' : '';
					logLine(log,
						'• ' + c.label + ' [' + c.members + ' copies]: ' + parts.join(', ') + human,
						(c.variations || c.originals) ? '' : 'uk-text-muted');
				});
				if (d.complete) {
					// Server re-measured the disk on the final step — use the truth.
					freedHuman  = d.reclaimedHuman || humanBytes(baseBytes + freedBytes);
					linkedFiles = d.linkedTotal | 0;
				} else {
					// Live estimate: baseline + what this run has freed so far.
					freedHuman  = humanBytes(baseBytes + freedBytes);
					linkedFiles = baseShared + linkedNew;
				}
				updateTotals();
				if (!d.complete) return reclaim(d.nextOffset);
				phase.textContent = 'Done.';
				logLine(log, '✓ Reclaim complete — ' + freedHuman + ' reclaimed across '
					+ fmt(linkedFiles) + ' shared file(s).');
			});
		}

		updateTotals();
		scan(0).then(function () { return reclaim(0); })
			.catch(function (e) {
				phase.textContent = 'Failed.';
				logLine(log, '✗ ' + (e && e.message ? e.message : 'error'), 'uk-text-danger');
			})
			.then(function () { btn.disabled = false; });
	}

	// Ground-truth disk audit: measures real on-disk savings (what `du` reports)
	// and the page-version breakdown, server-side — so the user can VERIFY the
	// reclaimed number in the browser without shell access.
	function audit(box, btn) {
		var out  = el(box, '.ml-audit-result');
		var csrf = { name: box.dataset.csrfName, value: box.dataset.csrfValue };
		btn.disabled = true;
		out.hidden = false;
		out.innerHTML = 'Measuring real disk usage… (this can take a moment)';
		post(box.dataset.auditUrl, {}, csrf).then(function (d) {
			if (!d || !d.ok) throw new Error((d && d.error) || 'audit failed');
			var rows = [
				['Files scanned',            fmt(d.files) + (d.truncated ? ' (capped)' : '')],
				['Logical size (what FTP/backup sees)', esc(d.apparentHuman)],
				['Actual disk usage (du)',   esc(d.actualHuman)],
				['→ Space saved by hardlinks', '<strong>' + esc(d.savedHuman) + '</strong>']
			];
			// Page Versions is a niche feature — only show its breakdown when the
			// install actually has version files.
			var hasVersions = (d.versionFiles | 0) > 0;
			if (hasVersions) {
				rows.push(['Version files total',  fmt(d.versionFiles)]);
				rows.push(['  · already shared',   fmt(d.versionShared)]);
				rows.push(['  · still standalone', fmt(d.versionStandalone) + ' (' + esc(d.versionStandaloneHuman) + ' reclaimable)']);
			}
			var html = '<table class="uk-table uk-table-small uk-table-divider uk-margin-remove">' +
				rows.map(function (r) {
					return '<tr><td class="uk-text-muted">' + r[0] +
						'</td><td class="uk-text-right">' + r[1] + '</td></tr>';
				}).join('') + '</table>';
			if (hasVersions && d.versionReasons && Object.keys(d.versionReasons).length) {
				html += '<div class="uk-text-muted uk-margin-small-top">Why standalone version files weren’t linked:</div>' +
					'<ul class="uk-list uk-margin-remove-top">' +
					Object.keys(d.versionReasons).map(function (k) {
						return '<li>' + esc(k) + ': <strong>' + fmt(d.versionReasons[k]) + '</strong></li>';
					}).join('') + '</ul>';
			}
			out.innerHTML = html;
		}).catch(function (e) {
			out.innerHTML = '<span class="uk-text-danger">✗ ' + esc(e && e.message ? e.message : 'error') + '</span>';
		}).then(function () { btn.disabled = false; });
	}

	// Un-share, chunked to completion (the old single GET was time-budgeted and
	// stopped half-way on a large tree). Re-POSTs the revert-step endpoint until
	// no shared inode is left, then flips the Status block back to "nothing
	// collapsed" so it can't show a stale reclaimed figure.
	function revert(box, trigger) {
		var panel  = el(box, '.ml-reclaim-panel');
		var phase  = el(box, '.ml-reclaim-phase');
		var bar    = el(box, '.ml-reclaim-bar');
		var totals = el(box, '.ml-reclaim-totals');
		var log    = el(box, '.ml-reclaim-log');
		var csrf   = { name: box.dataset.csrfName, value: box.dataset.csrfValue };
		var url    = box.dataset.revertUrl;

		panel.hidden = false; log.innerHTML = ''; bar.value = 0;
		if (trigger) trigger.style.pointerEvents = 'none';
		var startTotal = 0, undone = 0, freedHuman = '0';

		function step() {
			phase.textContent = 'Reverting…';
			return post(url, { offset: 0 }, csrf).then(function (d) {
				if (!d || !d.ok) throw new Error((d && d.error) || 'revert failed');
				var remaining = d.remaining | 0;          // files still sharing an inode
				if (!startTotal) startTotal = remaining + (d.expanded | 0);
				undone += d.expanded | 0;
				bar.max = startTotal || 1; bar.value = Math.max(0, startTotal - remaining);
				phase.textContent = 'Reverting… ' + fmt(undone) + ' un-shared, ' + fmt(remaining) + ' left';
				totals.innerHTML = 'Un-shared: <strong>' + fmt(undone) + '</strong> · Still sharing: <strong>' + fmt(remaining) + '</strong>';
				if (!d.complete) return step();
				phase.textContent = 'Done.';
				logLine(log, '✓ Revert complete — ' + fmt(undone) + ' file(s) given their own copy again.');
				syncStatus({ linked: 0, reclaimedHuman: d.reclaimedHuman });
			});
		}
		step().catch(function (e) {
			phase.textContent = 'Failed.';
			logLine(log, '✗ ' + (e && e.message ? e.message : 'error'), 'uk-text-danger');
		}).then(function () { if (trigger) trigger.style.pointerEvents = ''; });
	}

	// Capture phase so we run BEFORE the link's inline-onclick confirm and its
	// navigation, taking full control (with our own confirm). The href + inline
	// confirm stay as a no-JS fallback.
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('.ml-reclaim-revert');
		if (!a) return;
		e.preventDefault();
		e.stopPropagation();
		var box = document.querySelector('.ml-reclaim-live');
		if (!box) { window.location.href = a.href; return; }
		if (!window.confirm(a.dataset.confirm || 'Revert?')) return;
		revert(box, a);
	}, true);

	document.addEventListener('click', function (e) {
		var t = e.target.closest && e.target.closest('.ml-reclaim-start, .ml-audit-start');
		if (!t) return;
		e.preventDefault();
		var box = document.querySelector('.ml-reclaim-live');
		if (!box) return;
		if (t.classList.contains('ml-audit-start')) audit(box, t);
		else run(box, t);
	});
})();
