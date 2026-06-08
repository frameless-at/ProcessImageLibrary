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

		editor.ui.registry.addButton('mllibrary', {
			icon: 'gallery',
			tooltip: cfg().label || 'Insert from library',
			onAction: open
		});
		editor.ui.registry.addMenuItem('mllibrary', {
			icon: 'gallery',
			text: cfg().label || 'Insert from library',
			onAction: open
		});

		return { getMetadata: function () { return { name: 'Image Library' }; } };
	});
})();
