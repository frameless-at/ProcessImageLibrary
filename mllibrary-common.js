/**
 * Shared "Insert from library" logic for both rich-text editors.
 *
 * mllibrary.js (TinyMCE) and mllibrary-cke.js (CKEditor 4) are thin adapters:
 * each registers a toolbar button with its editor and, on click, calls
 * MLImageLibrary.openPicker(adapter). Everything else — opening the library
 * picker, receiving the pick, and handing a single pick to PW's native image
 * dialog (crop / resize / caption / align) WITHOUT pre-inserting the image —
 * lives here, so the two editors can't drift apart.
 *
 * The native-dialog handoff is a faithful port of wire's pwimage.js /
 * pwimage plugin, minus their existing-node / figure / link UNWRAP paths
 * (we always insert a brand-new image); caption + link-to-larger chosen in the
 * dialog are still honoured.
 *
 * An adapter is: {
 *   cfg:          ProcessWire.config.ImageLibraryInsert (pickerUrl, label, …),
 *   inputfieldId: DOM id to resolve the editor's .Inputfield (TinyMCE editor.id
 *                 / CKEditor editor.name),
 *   labels:       normalised { selectImage, insertImage, selectAnotherImage,
 *                 cancel, savingImage, captionText },
 *   insertHtml:   function(html) — drop final markup into the editor.
 * }
 *
 * Loaded once (before any editor inits) by the inline glue; see
 * ProcessImageLibrary::richtextCommonLoader().
 */
window.MLImageLibrary = (function () {

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
	// our picker AND PW's own image dialog — and give the iframe full width (the
	// admin ships CSS for this that the front end lacks). Injected once. NOT
	// height — the front-end dialog content has no fixed height, so height:100%
	// collapses it.
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
	// /1/2/3/ split-id layout), exactly like wire's pwimage does from an img src.
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

	// Safe <img> markup (jQuery escapes the attributes for us).
	function imgHtml(url, alt) {
		return jQuery('<img/>').attr('src', url).attr('alt', String(alt || ''))[0].outerHTML;
	}

	/**
	 * Open PW's native image dialog (ProcessPageEditImageSelect) pointed straight
	 * at a library file, then insert the configured <img> on confirm.
	 */
	function openNativeResize(adapter, fileUrl, altText) {
		var $ = jQuery;
		var L = adapter.labels;
		var modalUrl = ProcessWire.config.urls.admin + 'page/image/';

		var $in = $('#Inputfield_id');
		var editPageId = $in.length ? $in.val()
			: $('#' + adapter.inputfieldId).closest('.Inputfield').attr('data-pid');
		var imagePageId = pageIdFromUrl(fileUrl);
		var file = String(fileUrl).split('/').pop();

		var version = 0;
		try {
			if (ProcessWire.config.PagesVersions && ProcessWire.config.PagesVersions.page == imagePageId) {
				version = ProcessWire.config.PagesVersions.version;
			}
		} catch (e) { /* no versions */ }

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
				.attr('class') || '';
			var $linkToLarger = $('#selected_image_link', $i);
			var linkHref = $linkToLarger.is(':checked') ? $linkToLarger.val() : '';
			var $el = $('<img />').attr('src', src).attr('alt', alt);

			if (hidpi) cls = (cls ? cls + ' ' : '') + 'hidpi';
			// class lands on the <figure> (not the <img>) when captioned
			if (caption === false && cls.length) $el.addClass(cls);
			if (width > 0 && $img.attr('data-nosize') != '1') $el.attr('width', width);

			if (linkHref) $el = $('<a />').attr('href', linkHref).append($el);

			if (caption) {
				var $figure = $('<figure />');
				if (cls.length) $figure.addClass(cls);
				var $figcaption = $('<figcaption />').append(
					(alt && alt.length > 1) ? alt : L.captionText
				);
				$figure.append($figcaption);
				$figure.prepend($el);
				$el = $figure;
			}

			adapter.insertHtml($el[0].outerHTML);
		}

		// "Insert" clicked → ask PW to (re)generate the variation at the chosen
		// size / crop / rotation, then insert the returned src.
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
			$iframe.setTitle(L.savingImage);
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
					html: "<i class='fa fa-camera'></i> " + L.insertImage,
					click: function () { insertButtonClick($iframe); }
				});
				buttons.push({
					html: "<i class='fa fa-folder-open'></i> " + L.selectAnotherImage,
					'class': 'ui-priority-secondary',
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
				html: "<i class='fa fa-times-circle'></i> " + L.cancel,
				'class': 'ui-priority-secondary',
				click: function () { $iframe.dialog('close'); }
			});

			$iframe.setButtons(buttons);
			$iframe.setTitle($i.find('title').html());
		}

		var $iframe = pwModalWindow(modalUrl + qs, {
			title: "<i class='fa fa-fw fa-folder-open'></i> " + L.selectImage,
			open: function () {
				// keep the dialog above a maximized CKEditor
				if ($('.cke_maximized').length > 0) {
					$('.ui-dialog').css('z-index', 9999);
					$('.ui-widget-overlay').css('z-index', 9998);
				}
			}
		}, 'large');
		if (isFrontEnd()) {
			liftDialogsFrontEnd();
			$iframe.css('width', '100%');
		}
		$iframe.on('load', function () { iframeLoad($iframe); });
	}

	/**
	 * Open the Image Library picker in insert mode; route the result.
	 */
	function openPicker(adapter) {
		var c = adapter.cfg || {};
		if (!c.pickerUrl || typeof pwModalWindow === 'undefined') return;
		var label = c.label || 'Insert from library';

		var $iframe = pwModalWindow(
			c.pickerUrl,
			{ title: "<i class='fa fa-fw fa-image'></i> " + label },
			'large'
		);
		// Front-end ONLY: fill the dialog width and lift every dialog (this picker
		// + PW's image dialog after a pick) above the inline editor.
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

			// Single pick → PW's native image dialog (crop / resize / caption /
			// align), straight on the library file. Nothing enters the page until
			// the user confirms there. Multiple → plain inserts.
			if (items.length === 1) {
				openNativeResize(adapter, items[0].url, String(items[0].alt || ''));
				return;
			}
			var html = items.map(function (it) { return imgHtml(it.url, it.alt); }).join('');
			if (html) adapter.insertHtml(html);
		}
		function cleanup() { window.removeEventListener('message', onMessage); }

		window.addEventListener('message', onMessage);
		$iframe.on('dialogclose', cleanup);   // also drop the listener on plain close
	}

	return {
		isFrontEnd: isFrontEnd,
		openPicker: openPicker,
		openNativeResize: openNativeResize
	};
})();
