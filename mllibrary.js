/**
 * TinyMCE plugin: "Insert from library".
 *
 * Adds a toolbar button (and menu item) to every InputfieldTinyMCE field that
 * opens the Image Library in picker INSERT mode. The picker posts the chosen
 * image(s) back ({ mlInsert: true, items: [{ url, alt }] }); we reference the
 * library file straight in — no copy, one shared file embedded everywhere (the
 * whole point of a deduplicated media library).
 *
 * Single pick → hand the file URL straight to PW's native image dialog
 * (crop / resize / caption / align) by opening ProcessPageEditImageSelect on
 * that file directly. We do NOT pre-insert the <img> first (an earlier version
 * did, which rendered the full-size image into the page; on a phone iOS then
 * zoomed the page out to fit the giant image and the dialog modal shrank with
 * it). The image is inserted ONLY when the user confirms in the dialog — exactly
 * like clicking the native image button with no selection, just pre-pointed at
 * our cross-page library file. This mirrors wire's own pwimage.js.
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
	// and which we don't otherwise control — and give the iframe full width
	// (the admin ships CSS for this that the front end lacks). CSS so it applies
	// whenever a dialog exists, no timing games. Injected once. NOT height — the
	// front-end dialog content has no fixed height, so height:100% collapses it.
	function liftDialogsFrontEnd() {
		if (!isFrontEnd() || document.getElementById('ml-fe-dialog-z')) return;
		var s = document.createElement('style');
		s.id = 'ml-fe-dialog-z';
		s.textContent =
			'.ui-dialog{z-index:9999 !important}' +
			'.ui-widget-overlay{z-index:9998 !important}' +
			'.ui-dialog .pw-modal-window{width:100% !important}';
		(document.head || document.documentElement).appendChild(s);
	}

	// Pull the page id out of a library file URL (…/files/123/foo.jpg or the
	// /1/2/3/ split-id layout), exactly like wire's pwimage.js does from a src.
	function pageIdFromUrl(url) {
		var parts = String(url).split('/');
		parts.pop();                 // drop filename
		parts = parts.reverse();
		var id = '';
		for (var n = 0; n < parts.length; n++) {
			if (/^\d+$/.test(parts[n])) id = parts[n] + id;
			else if (id.length) break;
		}
		return parseInt(id, 10);
	}

	// Open PW's native image dialog (ProcessPageEditImageSelect) pointed straight
	// at a library file, then insert the configured <img> on confirm. Faithful
	// port of wire's pwimage.js, minus the existing-node / figure / link unwrap
	// logic (we always insert a brand-new image) — caption + link-to-larger from
	// the dialog's own checkboxes are still honoured.
	function openNativeResize(editor, fileUrl, altText) {
		var $ = jQuery;
		var modalUrl = ProcessWire.config.urls.admin + 'page/image/';

		var labels = {
			captionText: 'Caption text', savingImage: 'Saving', insertImage: 'Insert',
			selectImage: 'Select', selectAnotherImage: 'Select another', cancel: 'Cancel'
		};
		try {
			if (ProcessWire.config.InputfieldTinyMCE && ProcessWire.config.InputfieldTinyMCE.labels) {
				labels = ProcessWire.config.InputfieldTinyMCE.labels;
			}
		} catch (e) { /* keep defaults */ }

		var $in = $('#Inputfield_id');
		var $inputfield = $('#' + editor.id).closest('.Inputfield');
		var editPageId = $in.length ? $in.val() : $inputfield.attr('data-pid');
		var imagePageId = pageIdFromUrl(fileUrl);
		var file = String(fileUrl).split('/').pop();

		var version = 0;
		try {
			if (ProcessWire.config.PagesVersions && ProcessWire.config.PagesVersions.page == imagePageId) {
				version = ProcessWire.config.PagesVersions.version;
			}
		} catch (e) { /* none */ }

		var qs = '?id=' + imagePageId + '&edit_page_id=' + editPageId + '&modal=1' +
			'&file=' + file + '&hidpi=0';
		if (altText && altText.length) qs += '&description=' + encodeURIComponent(altText);
		qs += '&winwidth=' + ($(window).width() - 30);
		if (version) qs += '&version=' + version;

		// Build the inserted markup from the dialog's resized #selected_image.
		function insertImage(src, $i) {
			var $img = $('#selected_image', $i);
			var width = $img.attr('width');
			var alt = $('#selected_image_description', $i).val();
			var caption = $('#selected_image_caption', $i).is(':checked');
			var $hidpi = $('#selected_image_hidpi', $i);
			var hidpi = $hidpi.is(':checked') && !$hidpi.is(':disabled');
			var cls = $img
				.removeClass('ui-resizable No Alignment resizable_setup')
				.removeClass('rotate90 rotate180 rotate270 rotate-90 rotate-180 rotate-270')
				.removeClass('flip_vertical flip_horizontal')
				.attr('class');
			var $linkToLarger = $('#selected_image_link', $i);
			var linkHref = $linkToLarger.is(':checked') ? $linkToLarger.val() : '';
			var $el = $('<img />').attr('src', src).attr('alt', alt);

			if (hidpi) $el.addClass('hidpi');
			// class lands on the <figure> (not the <img>) when captioned
			if (caption === false && cls && cls.length) $el.addClass(cls);
			if (width > 0 && $img.attr('data-nosize') != '1') $el.attr('width', width);

			if (linkHref) $el = $('<a />').attr('href', linkHref).append($el);

			if (caption) {
				var $figure = $('<figure />');
				if (cls && cls.length) $figure.addClass(cls);
				var $figcaption = $('<figcaption />').append(
					(alt && alt.length > 1) ? alt : labels.captionText
				);
				$figure.append($figcaption);
				$figure.prepend($el);
				$el = $figure;
			}

			editor.insertContent($el[0].outerHTML);
		}

		// "Insert" clicked → ask PW to (re)generate the variation at the chosen
		// size/crop/rotation, then insert the returned src.
		function insertButtonClick($iframe) {
			var $i = $iframe.contents();
			var $img = $('#selected_image', $i);
			var width = $img.attr('width');
			var height = $img.attr('height');
			var f = $img.attr('src');
			var imgPageId = $('#page_id', $i).val();
			var hidpi = $('#selected_image_hidpi', $i).is(':checked') ? 1 : 0;
			var rotate = parseInt($('#selected_image_rotate', $i).val());

			$iframe.dialog('disable');
			$iframe.setTitle(labels.savingImage);
			$img.removeClass('resized');

			if (!width) width = $img.width();
			if (!height) height = $img.height();
			f = f.substring(f.lastIndexOf('/') + 1);

			var resizeUrl = modalUrl + 'resize' +
				'?id=' + imgPageId + '&file=' + f +
				'&width=' + width + '&height=' + height +
				'&hidpi=' + hidpi + '&version=' + version;
			if (rotate) resizeUrl += '&rotate=' + rotate;
			if ($img.hasClass('flip_horizontal')) resizeUrl += '&flip=h';
			else if ($img.hasClass('flip_vertical')) resizeUrl += '&flip=v';

			$.get(resizeUrl, function (data) {
				var src = $('<div></div>').html(data).find('#selected_image').attr('src');
				insertImage(src, $i);
				$iframe.dialog('close');
			});
		}

		function iframeLoad($iframe) {
			var $i = $iframe.contents();
			var $img = $('#selected_image', $i);
			var buttons = [];

			if ($img.length > 0) {
				buttons.push({
					html: "<i class='fa fa-camera'></i> " + labels.insertImage,
					click: function () { insertButtonClick($iframe); }
				});
				buttons.push({
					html: "<i class='fa fa-folder-open'></i> " + labels.selectAnotherImage,
					class: 'ui-priority-secondary',
					click: function () {
						var pid = $('#page_id', $iframe.contents()).val();
						$iframe.attr('src', modalUrl + '?id=' + pid + '&modal=1&version=' + version);
						$iframe.setButtons({});
					}
				});
			} else {
				// fell through to the file browser (Select another) — mirror its buttons
				$('button.pw-modal-button, button[type=submit]:visible', $i).each(function () {
					var $button = $(this);
					buttons.push({ html: $button.html(), click: function () { $button.trigger('click'); } });
					if (!$button.hasClass('pw-modal-button-visible')) $button.hide();
				});
			}

			buttons.push({
				html: "<i class='fa fa-times-circle'></i> " + labels.cancel,
				class: 'ui-priority-secondary',
				click: function () { $iframe.dialog('close'); }
			});

			$iframe.setButtons(buttons);
			$iframe.setTitle($i.find('title').html());
		}

		var $iframe = pwModalWindow(modalUrl + qs, {
			title: "<i class='fa fa-fw fa-folder-open'></i> " + labels.selectImage
		}, 'large');
		if (isFrontEnd()) {
			liftDialogsFrontEnd();
			$iframe.css('width', '100%');
		}
		$iframe.on('load', function () { iframeLoad($iframe); });
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

				// Single pick → open PW's native image dialog straight on the
				// library file (crop / resize / caption / align). Nothing is put
				// in the page until the user confirms in that dialog.
				if (items.length === 1) {
					openNativeResize(editor, items[0].url, String(items[0].alt || ''));
					return;
				}
				// Multiple picks → plain inserts (no per-image dialog).
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

		// TinyMCE's built-in "gallery" icon (a photo frame with stack lines) —
		// the same shape we ship to CKEditor as mllibrary-icon.svg, so both match.
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
