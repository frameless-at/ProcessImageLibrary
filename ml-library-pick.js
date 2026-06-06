/**
 * "Choose from library" glue for the page editor.
 *
 * The module hooks InputfieldImage::render and appends a `.ml-lib-pick`
 * button. This script opens the library (picker mode) in a modal iframe;
 * when the user picks an image, the iframe posts a message back and we
 * refresh just that one image field — no full page reload, so unrelated
 * unsaved edits survive. Loaded only on screens that carry an image field.
 */
(function () {
	'use strict';

	function openPicker(btn) {
		var url = btn.getAttribute('data-picker-url');
		if (!url) return;
		var field = btn.getAttribute('data-field') || '';

		var dlg = document.createElement('dialog');
		dlg.className = 'ml-pick-dialog';
		dlg.style.cssText = 'width:min(1100px,96vw);height:88vh;max-width:96vw;max-height:92vh;'
			+ 'padding:0;border:0;border-radius:6px;overflow:hidden;background:#fff;';

		var bar = document.createElement('div');
		bar.style.cssText = 'display:flex;align-items:center;justify-content:space-between;'
			+ 'gap:1rem;padding:.5rem .8rem;background:#222;color:#fff;font-weight:600;';
		var title = document.createElement('span');
		title.textContent = btn.getAttribute('data-title') || 'Image library';
		var close = document.createElement('button');
		close.type = 'button';
		close.textContent = '✕';
		close.setAttribute('aria-label', 'Close');
		close.style.cssText = 'background:transparent;border:0;color:#fff;font-size:1.2rem;cursor:pointer;line-height:1;';
		bar.appendChild(title);
		bar.appendChild(close);

		var iframe = document.createElement('iframe');
		iframe.src = url;
		iframe.style.cssText = 'display:block;width:100%;height:calc(100% - 2.4rem);border:0;';

		dlg.appendChild(bar);
		dlg.appendChild(iframe);
		document.body.appendChild(dlg);

		function teardown() {
			window.removeEventListener('message', onMsg);
			if (dlg.open) dlg.close();
			dlg.remove();
		}
		function onMsg(e) {
			if (e.source !== iframe.contentWindow) return;
			if (!e.data || e.data.mlPicked !== true) return;
			teardown();
			reloadField(field);
		}
		close.addEventListener('click', teardown);
		dlg.addEventListener('close', teardown);
		window.addEventListener('message', onMsg);
		if (typeof dlg.showModal === 'function') dlg.showModal();
		else dlg.setAttribute('open', '');
	}

	// Refresh just this image field in place via ProcessWire's own native
	// field reload (InputfieldReloadEvent): it re-fetches the field over ajax
	// AND re-runs InputfieldsInit, so InputfieldImage rebuilds its thumbnails
	// correctly. Other fields' unsaved edits are untouched. A naive DOM swap
	// would leave empty placeholders because the field's init scripts wouldn't
	// run — this avoids that entirely.
	function reloadField(field) {
		var wrap = document.getElementById('wrap_Inputfield_' + field);
		if (wrap && window.jQuery) {
			window.jQuery(wrap).trigger('reload');
			return;
		}
		notice();
	}

	function notice() {
		alert('Image added. Reload the page to see it.');
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.ml-lib-pick');
		if (!btn) return;
		e.preventDefault();
		openPicker(btn);
	});
})();
