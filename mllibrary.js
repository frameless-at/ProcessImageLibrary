/**
 * TinyMCE plugin: "Insert from library".
 *
 * Adds a toolbar button (and menu item) to every InputfieldTinyMCE field that
 * opens the Image Library in picker INSERT mode. The picker posts the chosen
 * image(s) back ({ mlInsert: true, items: [{ url, alt }] }); we drop an <img>
 * referencing the library file straight in — no copy, one shared file embedded
 * everywhere (the whole point of a deduplicated media library).
 *
 * The plugin file's basename ("mllibrary") IS the TinyMCE plugin name and the
 * toolbar token — the PHP side injects both. The picker URL + label come from
 * ProcessWire.config.ImageLibraryInsert (set in addLibraryTinyMceButton).
 */
(function () {
	if (typeof tinymce === 'undefined') return;

	// True when the editor runs on a front-end page (not under the admin URL) —
	// i.e. PageFrontEdit inline editing, where the picker modal needs full width
	// and a high z-index to sit above the inline editor.
	function isFrontEnd() {
		try {
			var a = ProcessWire.config.urls.admin;
			return !!a && location.pathname.indexOf(a) !== 0;
		} catch (e) { return false; }
	}

	// Front-end only: lift EVERY jQuery-UI dialog above the inline editor — both
	// our picker AND PW's own image dialog (pwimage), which opens after a pick
	// and which we don't otherwise control. CSS so it applies whenever a dialog
	// exists, no timing games. Injected once.
	function liftDialogsFrontEnd() {
		if (!isFrontEnd() || document.getElementById('ml-fe-dialog-z')) return;
		var s = document.createElement('style');
		s.id = 'ml-fe-dialog-z';
		s.textContent = '.ui-dialog{z-index:9999 !important}.ui-widget-overlay{z-index:9998 !important}';
		(document.head || document.documentElement).appendChild(s);
	}

	tinymce.PluginManager.add('mllibrary', function (editor) {

		function cfg() {
			return (window.ProcessWire && ProcessWire.config && ProcessWire.config.ImageLibraryInsert) || {};
		}

		function open() {
			var c = cfg();
			if (!c.pickerUrl || typeof pwModalWindow === 'undefined') return;
			var label = c.label || 'Insert from library';

			var $iframe = pwModalWindow(
				c.pickerUrl,
				{ title: "<i class='fa fa-fw fa-image'></i> " + label },
				'large'
			);
			// Front-end ONLY: fill the dialog width and lift every dialog (this
			// picker + PW's image dialog after a pick) above the inline editor.
			// In the admin the default sizing/stacking is correct.
			if (isFrontEnd()) {
				liftDialogsFrontEnd();
				$iframe.css('width', '100%');
			}

			function onMessage(e) {
				if (e.origin !== location.origin) return;            // same-origin only
				if (!e.data || !e.data.mlInsert) return;
				var items = (e.data.items || []).filter(function (it) { return it && it.url; });
				cleanup();
				try { $iframe.dialog('close'); } catch (err) { /* already closed */ }
				if (!items.length) return;

				function alt(it) { return String(it.alt || '').replace(/"/g, '&quot;'); }

				// Single pick → drop it in and hand straight off to PW's own image
				// dialog (crop / resize / caption / align), exactly as if the user
				// had selected the image and clicked the native image button. PW's
				// pwimage reads the page id out of the src, so a cross-page library
				// image edits fine. Multiple picks (or no pwimage) → plain insert.
				if (items.length === 1 && typeof window.pwTinyMCE_image === 'function') {
					editor.insertContent('<img src="' + items[0].url + '" alt="' + alt(items[0]) + '" data-mlnew="1">');
					var img = editor.getBody().querySelector('img[data-mlnew="1"]');
					if (img) {
						img.removeAttribute('data-mlnew');
						editor.selection.select(img);
						window.pwTinyMCE_image(editor);
						return;
					}
				}
				var html = items.map(function (it) {
					return '<img src="' + it.url + '" alt="' + alt(it) + '">';
				}).join('');
				if (html) editor.insertContent(html);
			}
			function cleanup() { window.removeEventListener('message', onMessage); }

			window.addEventListener('message', onMessage);
			// Also drop the listener if the user just closes the modal.
			$iframe.on('dialogclose', cleanup);
		}

		// Same "stacked photos" icon as the CKEditor button (mllibrary-icon.svg)
		// so both editors match. Registered as a custom TinyMCE icon.
		editor.ui.registry.addIcon('mllibrary',
			'<svg width="24" height="24" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.1" stroke-linejoin="round" stroke-linecap="round">'
			+ '<path d="M5.6 4.6V3.2Q5.6 2.6 6.2 2.6H13Q13.6 2.6 13.6 3.2V10"/>'
			+ '<rect x="2.4" y="5" width="8.6" height="8.6" rx="1"/>'
			+ '<circle cx="4.8" cy="7.6" r="0.85" fill="currentColor" stroke="none"/>'
			+ '<path d="M2.9 12l2-2.2 1.5 1.6 1.7-1.9 2.2 2.4"/></svg>');

		editor.ui.registry.addButton('mllibrary', {
			icon: 'mllibrary',
			tooltip: cfg().label || 'Insert from library',
			onAction: open
		});
		editor.ui.registry.addMenuItem('mllibrary', {
			icon: 'mllibrary',
			text: cfg().label || 'Insert from library',
			onAction: open
		});

		return { getMetadata: function () { return { name: 'Image Library' }; } };
	});
})();
