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

	function cfg() {
		return (window.ProcessWire && ProcessWire.config && ProcessWire.config.ImageLibraryInsert) || {};
	}
	function label() { return cfg().label || 'Insert from library'; }
	// True on a front-end page (not under the admin URL) — PageFrontEdit inline.
	function isFrontEnd() {
		try {
			var a = ProcessWire.config.urls.admin;
			return !!a && location.pathname.indexOf(a) !== 0;
		} catch (e) { return false; }
	}
	// Front-end only: lift EVERY jQuery-UI dialog above the inline editor — our
	// picker AND PW's image dialog (pwimage), which opens after a pick. Once.
	function liftDialogsFrontEnd() {
		if (!isFrontEnd() || document.getElementById('ml-fe-dialog-z')) return;
		var s = document.createElement('style');
		s.id = 'ml-fe-dialog-z';
		s.textContent = '.ui-dialog{z-index:9999 !important}.ui-widget-overlay{z-index:9998 !important}';
		(document.head || document.documentElement).appendChild(s);
	}

	function openPicker(editor) {
		var c = cfg();
		if (!c.pickerUrl || typeof pwModalWindow === 'undefined') return;

		var $iframe = pwModalWindow(c.pickerUrl, { title: label() }, 'large');
		// Front-end ONLY: fill the dialog width and lift every dialog (this picker
		// + PW's image dialog after a pick) above the inline editor.
		if (isFrontEnd()) {
			liftDialogsFrontEnd();
			$iframe.css('width', '100%');
		}

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
			var btn = { label: label(), command: 'mllibrary', toolbar: 'insert,20' };
			var ic = cfg().iconUrl;
			if (ic) btn.icon = ic;   // a real SVG file URL (CKEditor 4 rejects data-URIs)
			editor.ui.addButton('PWImageLibrary', btn);
		}
	});
})();
