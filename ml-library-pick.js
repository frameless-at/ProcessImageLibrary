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

	// The button lives on the page editor, which doesn't load the module CSS.
	// Two things to inject once:
	//  - icon sizing for the fa-image glyph;
	//  - an outline (uk-button-default-style) look: the native submit button
	//    is filled with the theme colour, so override .ml-lib-pick.ui-button
	//    to a transparent button with a subtle border + inherited text colour
	//    (theme-agnostic). Higher specificity than the theme rule, no !important.
	//    ui-state-hover is folded into the base rule so jQuery UI's hover state
	//    keeps the SAME look — no custom :hover rule (that made it twitch).
	(function injectStyle() {
		if (document.getElementById('ml-lib-pick-style')) return;
		var s = document.createElement('style');
		s.id = 'ml-lib-pick-style';
		s.textContent =
			'.ml-lib-pick .fa{font-size:0.85em;width:1em;vertical-align:1px;text-align:center;margin-right:3px}'
			+ '.ml-lib-pick.ui-button,.ml-lib-pick.ui-button.ui-state-default,'
			+ '.ml-lib-pick.ui-button.ui-state-hover{background:transparent;color:inherit;'
			+ 'border:1px solid rgba(127,127,127,.5)}';
		document.head.appendChild(s);
	})();

	function openPicker(btn) {
		var url = btn.getAttribute('data-picker-url');
		if (!url) return;

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
			reloadField(btn);
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
	//
	// Resolve the field wrapper RELATIVE to the clicked button (which lives
	// inside the field's own .InputfieldContent). This works for top-level
	// fields AND repeater / repeater-matrix items: their wrapper id carries a
	// _repeater<n> suffix that a fixed "wrap_Inputfield_<field>" lookup would
	// miss — PW's reload handler reads that id (and the repeater item page) to
	// reload the right item.
	function reloadField(btn) {
		var wrap = btn && btn.closest ? btn.closest('.Inputfield') : null;
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
