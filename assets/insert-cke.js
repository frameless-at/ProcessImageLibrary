/**
 * CKEditor 4 adapter for "Insert from library" (legacy-editor twin of
 * insert-mce.js).
 *
 * Registered + wired up by the inline glue (ckEditorGlueScript): the glue adds
 * this plugin to extraPlugins and the "PWImageLibrary" button to the toolbar.
 * Here we define the command + button; the shared MLImageLibrary
 * (assets/insert-common.js) does the real work: open the library picker, then for a
 * single pick hand the file straight to PW's native image dialog WITHOUT
 * pre-inserting it.
 *
 * cfg comes from ProcessWire.config.ImageLibraryInsert (set by the glue).
 */
(function () {
	if (typeof CKEDITOR === 'undefined') return;

	function cfg() {
		return (window.ProcessWire && ProcessWire.config && ProcessWire.config.ImageLibraryInsert) || {};
	}
	function label() { return cfg().label || 'Insert from library'; }

	// Normalised labels for the shared dialog handoff, mapped from CKEditor's own
	// pwimage label set over sane defaults.
	function labels() {
		var d = {
			selectImage: 'Select Image', insertImage: 'Insert This Image',
			selectAnotherImage: 'Select Another Image', cancel: 'Cancel',
			savingImage: 'Saving Image', captionText: 'Caption text'
		};
		try {
			var l = ProcessWire.config.InputfieldCKEditor && ProcessWire.config.InputfieldCKEditor.pwimage;
			if (l) {
				if (l.selectLabel) d.selectImage = l.selectLabel;
				if (l.insertBtn) d.insertImage = l.insertBtn;
				if (l.selectBtn) d.selectAnotherImage = l.selectBtn;
				if (l.cancelBtn) d.cancel = l.cancelBtn;
				if (l.savingNote) d.savingImage = l.savingNote;
				if (l.captionLabel) d.captionText = l.captionLabel;
			}
		} catch (e) { /* keep defaults */ }
		return d;
	}

	CKEDITOR.plugins.add('mllibrary', {
		init: function (editor) {
			editor.addCommand('mllibrary', {
				exec: function () {
					if (!window.MLImageLibrary) return;
					MLImageLibrary.openPicker({
						cfg: cfg(),
						inputfieldId: editor.name,
						labels: labels(),
						insertHtml: function (html) { editor.insertHtml(html); editor.fire('change'); }
					});
				}
			});
			var btn = { label: label(), command: 'mllibrary', toolbar: 'insert,20' };
			var ic = cfg().iconUrl;
			if (ic) btn.icon = ic;   // a real SVG file URL (CKEditor 4 rejects data-URIs)
			editor.ui.addButton('PWImageLibrary', btn);
		}
	});
})();
