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

	function logLine(ul, text, cls) {
		var li = document.createElement('li');
		if (cls) li.style.color = cls;
		li.textContent = text;
		ul.appendChild(li);
		ul.scrollTop = ul.scrollHeight;
	}

	function fmt(n) { return (n | 0).toLocaleString(); }

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
			setStat('.ml-stat-reclaimed', freedHuman);
			setStat('.ml-stat-shared', fmt(linkedFiles));
			if (clustersTotal) setStat('.ml-stat-clusters', fmt(clustersTotal));
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
				linkedFiles   = d.linkedTotal | 0;
				freedHuman    = d.reclaimedHuman || freedHuman;
				bar.max = clustersTotal || 1; bar.value = clustersDone;
				phase.textContent = 'Reclaiming clusters… ' + fmt(clustersDone) + ' / ' + fmt(clustersTotal);
				(d.details || []).forEach(function (c) {
					var parts = [];
					if (c.originals)  parts.push(c.originals + '× original');
					if (c.variations) parts.push(c.variations + '× variation');
					if (c.already)    parts.push(c.already + ' already shared');
					if (!parts.length) parts.push('nothing to link');
					var human = c.bytes ? ' (+' + Math.round(c.bytes / 1024) + ' KB)' : '';
					logLine(log,
						'• ' + c.label + ' [' + c.members + ' copies]: ' + parts.join(', ') + human,
						c.variations ? '' : (c.originals ? '' : '#888'));
				});
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
				logLine(log, '✗ ' + (e && e.message ? e.message : 'error'), '#c33');
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
				['Logical size (what FTP/backup sees)', d.apparentHuman],
				['Actual disk usage (du)',   d.actualHuman],
				['→ Space saved by hardlinks', '<strong>' + d.savedHuman + '</strong>'],
				['Manifest says reclaimed',  d.manifestHuman],
				['Version files total',      fmt(d.versionFiles)],
				['  · already shared',       fmt(d.versionShared)],
				['  · still standalone',     fmt(d.versionStandalone) + ' (' + d.versionStandaloneHuman + ' reclaimable)']
			];
			out.innerHTML = '<table style="border-collapse:collapse;width:100%">' +
				rows.map(function (r) {
					return '<tr><td style="padding:2px 8px 2px 0;color:#555">' + r[0] +
						'</td><td style="padding:2px 0;text-align:right">' + r[1] + '</td></tr>';
				}).join('') + '</table>';
		}).catch(function (e) {
			out.innerHTML = '<span style="color:#c33">✗ ' + (e && e.message ? e.message : 'error') + '</span>';
		}).then(function () { btn.disabled = false; });
	}

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
