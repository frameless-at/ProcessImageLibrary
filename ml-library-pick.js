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

	// Refresh one image field in place: re-fetch the current edit page and
	// swap just this field's wrapper, so other fields' unsaved edits remain.
	// Falls back to a notice (never an automatic reload that would discard
	// unsaved work).
	function reloadField(field) {
		var id = 'wrap_Inputfield_' + field;
		var wrap = document.getElementById(id);
		if (!wrap) { notice(field); return; }
		fetch(location.href, { credentials: 'same-origin' })
			.then(function (r) { return r.text(); })
			.then(function (html) {
				var doc = new DOMParser().parseFromString(html, 'text/html');
				var fresh = doc.getElementById(id);
				if (!fresh) { notice(field); return; }
				wrap.replaceWith(fresh);
				// Re-init PW's InputfieldImage JS on the new nodes.
				if (window.jQuery) {
					try { window.jQuery(document).trigger('reloaded', [window.jQuery(fresh)]); } catch (e) {}
				}
			})
			.catch(function () { notice(field); });
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
