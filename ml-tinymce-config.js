/**
 * Registers the "Insert from library" plugin + button on every TinyMCE editor.
 *
 * PW's InputfieldTinyMCE exposes a client-side hook, InputfieldTinyMCE.onConfig(),
 * that runs per editor right before init with the fully-merged settings — the one
 * reliable place to add a plugin/button (server-side injection is unreliable:
 * TinyMCE's renderReady isn't hookable and its settings are cached). We:
 *   - register our external plugin (mllibrary.js → the actual button behaviour),
 *   - append the button to the toolbar,
 *   - add it to the Insert menu.
 * URLs/labels come from ProcessWire.config.ImageLibraryInsert.
 */
(function () {
	function register() {
		if (!window.InputfieldTinyMCE || typeof InputfieldTinyMCE.onConfig !== 'function') return false;
		var cfg = (window.ProcessWire && ProcessWire.config && ProcessWire.config.ImageLibraryInsert) || {};
		if (!cfg.pluginUrl) return true; // configured elsewhere not to run; don't retry

		InputfieldTinyMCE.onConfig(function (settings) {
			settings.external_plugins = settings.external_plugins || {};
			settings.external_plugins.mllibrary = cfg.pluginUrl;

			if (typeof settings.toolbar === 'string' && settings.toolbar.indexOf('mllibrary') === -1) {
				settings.toolbar = settings.toolbar + ' mllibrary';
			}
			if (settings.menu && settings.menu.insert && typeof settings.menu.insert.items === 'string'
				&& settings.menu.insert.items.indexOf('mllibrary') === -1) {
				settings.menu.insert.items = settings.menu.insert.items + ' mllibrary';
			}
		});
		return true;
	}

	// InputfieldTinyMCE.js loads before us, so this normally registers
	// synchronously; the DOM-ready fallback covers load-order edge cases (always
	// before editors init, which happens after DOMContentLoaded).
	if (!register()) {
		document.addEventListener('DOMContentLoaded', register);
	}
})();
