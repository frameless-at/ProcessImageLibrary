/**
 * CKEditor 4 plugin: "Insert from library" (legacy-editor twin of mllibrary.js).
 *
 * Registered + wired up by the inline glue (ckEditorGlueScript): the glue adds
 * this plugin to extraPlugins and the "PWImageLibrary" button to the toolbar.
 * Here we define the command + button: open the Image Library picker in insert
 * mode, then on selection insert the image and — for a single pick — hand off to
 * PW's own image dialog (the pwimage command) for crop / resize / caption, just
 * like the TinyMCE version. No copy: the <img> references the shared library file.
 *
 * Config (pickerUrl, label) comes from ProcessWire.config.ImageLibraryInsert.
 */
(function () {
	if (typeof CKEDITOR === 'undefined') return;

	var SVG =
		'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">' +
		'<rect x="1" y="4" width="9" height="8" rx="1" fill="#fff" stroke="#5b5b5b"/>' +
		'<rect x="6" y="2" width="9" height="8" rx="1" fill="#fff" stroke="#5b5b5b"/>' +
		'<circle cx="8.5" cy="4.5" r="1" fill="#5b5b5b"/>' +
		'<path d="M6.5 8.5l2-2 1.5 1.5 2-2 2.5 2.5" fill="none" stroke="#5b5b5b"/></svg>';
	var ICON = 'data:image/svg+xml,' + encodeURIComponent(SVG);

	function cfg() {
		return (window.ProcessWire && ProcessWire.config && ProcessWire.config.ImageLibraryInsert) || {};
	}
	function label() { return cfg().label || 'Insert from library'; }

	function openPicker(editor) {
		var c = cfg();
		if (!c.pickerUrl || typeof pwModalWindow === 'undefined') return;

		var $iframe = pwModalWindow(c.pickerUrl, { title: label() }, 'large');

		function onMessage(e) {
			if (e.origin !== location.origin) return;
			if (!e.data || !e.data.mlInsert) return;
			var items = (e.data.items || []).filter(function (it) { return it && it.url; });
			cleanup();
			try { $iframe.dialog('close'); } catch (err) { /* already closed */ }
			if (!items.length) return;

			function alt(it) { return String(it.alt || ''); }

			// Single pick → insert and hand off to PW's native image dialog
			// (pwimage) for crop / resize / caption, exactly like the TinyMCE path.
			if (items.length === 1) {
				var img = editor.document.createElement('img', {
					attributes: { src: items[0].url, alt: alt(items[0]) }
				});
				editor.insertElement(img);
				editor.getSelection().selectElement(img);
				if (editor.commands && editor.commands.pwimage) {
					editor.execCommand('pwimage');
				}
				return;
			}
			// Multiple → plain inserts.
			items.forEach(function (it) {
				editor.insertElement(editor.document.createElement('img', {
					attributes: { src: it.url, alt: alt(it) }
				}));
			});
		}
		function cleanup() { window.removeEventListener('message', onMessage); }

		window.addEventListener('message', onMessage);
		$iframe.on('dialogclose', cleanup);
	}

	CKEDITOR.plugins.add('mllibrary', {
		init: function (editor) {
			editor.addCommand('mllibrary', { exec: function () { openPicker(editor); } });
			editor.ui.addButton('PWImageLibrary', {
				label: label(),
				command: 'mllibrary',
				toolbar: 'insert,20',
				icon: ICON
			});
		}
	});
})();
