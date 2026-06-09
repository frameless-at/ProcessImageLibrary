/**
 * TinyMCE adapter for "Insert from library".
 *
 * Registers a toolbar button (and Insert-menu item) on every InputfieldTinyMCE
 * field; the shared MLImageLibrary (mllibrary-common.js) does the real work:
 * open the library picker, then for a single pick hand the file straight to
 * PW's native image dialog WITHOUT pre-inserting it.
 *
 * The plugin file's basename ("mllibrary") IS the TinyMCE plugin name and the
 * toolbar token — the PHP glue injects both. cfg comes from
 * ProcessWire.config.ImageLibraryInsert (set by the glue).
 */
(function () {
	if (typeof tinymce === 'undefined') return;

	function cfg() {
		return (window.ProcessWire && ProcessWire.config && ProcessWire.config.ImageLibraryInsert) || {};
	}

	// Normalised labels for the shared dialog handoff, sourced from PW's own
	// TinyMCE label set (already keyed the way we need) over sane defaults.
	function labels() {
		var d = {
			selectImage: 'Select', insertImage: 'Insert', selectAnotherImage: 'Select another',
			cancel: 'Cancel', savingImage: 'Saving', captionText: 'Caption text'
		};
		try {
			var l = ProcessWire.config.InputfieldTinyMCE && ProcessWire.config.InputfieldTinyMCE.labels;
			if (l) for (var k in d) if (typeof l[k] !== 'undefined') d[k] = l[k];
		} catch (e) { /* keep defaults */ }
		return d;
	}

	tinymce.PluginManager.add('mllibrary', function (editor) {

		function open() {
			if (!window.MLImageLibrary) return;
			MLImageLibrary.openPicker({
				cfg: cfg(),
				inputfieldId: editor.id,
				labels: labels(),
				insertHtml: function (html) { editor.insertContent(html); }
			});
		}

		// TinyMCE's built-in "gallery" icon — the same shape we ship to CKEditor
		// as mllibrary-icon.svg, so both editors match.
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
