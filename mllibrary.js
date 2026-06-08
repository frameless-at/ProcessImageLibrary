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
				var items = e.data.items || [];
				var html = items.map(function (it) {
					if (!it || !it.url) return '';
					var alt = String(it.alt || '').replace(/"/g, '&quot;');
					return '<img src="' + it.url + '" alt="' + alt + '">';
				}).join('');
				if (html) editor.insertContent(html);
				cleanup();
				try { $iframe.dialog('close'); } catch (err) { /* already closed */ }
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
