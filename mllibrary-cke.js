/**
 * CKEditor 4 plugin: "Insert from library" (legacy-editor twin of mllibrary.js).
 *
 * Registered + wired up by the inline glue (ckEditorGlueScript): the glue adds
 * this plugin to extraPlugins and the "PWImageLibrary" button to the toolbar.
 * Here we define the command + button: open the Image Library picker in insert
 * mode, then for a single pick open PW's native image dialog
 * (ProcessPageEditImageSelect) straight on the chosen library file for
 * crop / resize / caption / align.
 *
 * The image is inserted ONLY when the user confirms in that dialog — we do NOT
 * pre-insert an <img> first (an earlier version did, which rendered the full
 * image into the page; on a phone iOS then zoomed the page out to fit the giant
 * image and the dialog modal shrank with it). This mirrors wire's own
 * pwimage/plugin.js, minus its existing-node / figure / link unwrap logic since
 * we always insert a brand-new image.
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
	// picker AND PW's image dialog (pwimage) — and give the iframe full width
	// (admin ships CSS for this that the front end lacks). Once. NOT height — the
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
	// /1/2/3/ split-id layout), exactly like wire's pwimage plugin does.
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

	function pwLabels() {
		var d = {
			selectLabel: 'Select Image', insertBtn: 'Insert This Image',
			selectBtn: 'Select Another Image', cancelBtn: 'Cancel',
			savingNote: 'Saving Image', captionLabel: 'Caption text'
		};
		try {
			var l = ProcessWire.config.InputfieldCKEditor && ProcessWire.config.InputfieldCKEditor.pwimage;
			if (l) {
				for (var k in d) if (typeof l[k] !== 'undefined') d[k] = l[k];
			}
		} catch (e) { /* keep defaults */ }
		return d;
	}

	// Open PW's native image dialog pointed straight at a library file, then
	// insert the configured <img> on confirm. Faithful port of wire's pwimage
	// plugin, minus the existing-node / figure / link unwrap logic (always a
	// fresh insert) — caption + link-to-larger from the dialog are still honoured.
	function openNativeResize(editor, fileUrl, altText) {
		var $ = jQuery;
		var L = pwLabels();
		var modalUri = ProcessWire.config.urls.admin + 'page/image/';

		var $in = $('#Inputfield_id');
		var editPageId = $in.length ? $in.val()
			: $('#' + editor.name).closest('.Inputfield').attr('data-pid');
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

		var modalSettings = {
			title: "<i class='fa fa-fw fa-folder-open'></i> " + L.selectLabel,
			open: function () {
				if ($('.cke_maximized').length > 0) {
					$('.ui-dialog').css('z-index', 9999);
					$('.ui-widget-overlay').css('z-index', 9998);
				}
			}
		};

		var $iframe = pwModalWindow(modalUri + qs, modalSettings, 'large');
		if (isFrontEnd()) {
			liftDialogsFrontEnd();
			$iframe.css('width', '100%');
		}

		$iframe.on('load', function () {
			var $i = $iframe.contents();
			var buttons;

			if ($i.find('#selected_image').length > 0) {

				buttons = [{
					html: "<i class='fa fa-camera'></i> " + L.insertBtn,
					click: function () {

						function insertImage(src) {
							var $i = $iframe.contents();
							var $img = $('#selected_image', $i);
							var width = $img.attr('width');
							var alt = $('#selected_image_description', $i).val();
							var caption = $('#selected_image_caption', $i).is(':checked');
							var hidpi = $('#selected_image_hidpi', $i).is(':checked');
							var cls = $img.removeClass('ui-resizable No Alignment resizable_setup')
								.removeClass('rotate90 rotate180 rotate270 rotate-90 rotate-180 rotate-270')
								.removeClass('flip_vertical flip_horizontal').attr('class');
							var $linkToLarger = $('#selected_image_link', $i);
							var link = $linkToLarger.is(':checked') ? $linkToLarger.val() : '';
							var $insertHTML = $('<img />').attr('src', src).attr('alt', alt);

							if (hidpi) cls += (cls.length > 0 ? ' ' : '') + 'hidpi';
							// class lands on the <figure> (not the <img>) when captioned
							if (caption === false) $insertHTML.addClass(cls);
							if (width > 0 && $img.attr('data-nosize') != '1') $insertHTML.attr('width', width);

							if (link && link.length > 0) {
								$insertHTML = $('<a />').attr('href', link).append($insertHTML);
							}

							if (caption) {
								var $figure = $('<figure />');
								if (cls.length) $figure.addClass(cls);
								var $figureCaption = $('<figcaption />').append(
									(alt.length > 1) ? alt : L.captionLabel
								);
								$figure.append($figureCaption);
								$figure.prepend($insertHTML);
								$insertHTML = $figure;
							}

							editor.insertHtml($insertHTML[0].outerHTML);
							editor.fire('change');
							$iframe.dialog('close');
						}

						/*** INSERT BUTTON CLICKED ***/
						var $i = $iframe.contents();
						var $img = $('#selected_image', $i);

						$iframe.dialog('disable');
						$iframe.setTitle(L.savingNote);
						$img.removeClass('resized');

						var width = $img.attr('width');
						if (!width) width = $img.width();
						var height = $img.attr('height');
						if (!height) height = $img.height();
						var f = $img.attr('src');
						var pid = $('#page_id', $i).val();
						var hidpi = $('#selected_image_hidpi', $i).is(':checked') ? 1 : 0;
						var rotate = parseInt($('#selected_image_rotate', $i).val());
						f = f.substring(f.lastIndexOf('/') + 1);

						var resizeURL = modalUri + 'resize?id=' + pid +
							'&file=' + f + '&width=' + width + '&height=' + height +
							'&hidpi=' + hidpi + '&version=' + version;
						if (rotate) resizeURL += '&rotate=' + rotate;
						if ($img.hasClass('flip_horizontal')) resizeURL += '&flip=h';
						else if ($img.hasClass('flip_vertical')) resizeURL += '&flip=v';

						$.get(resizeURL, function (data) {
							var src = $('<div></div>').html(data).find('#selected_image').attr('src');
							insertImage(src);
						});
					}
				}, {
					html: "<i class='fa fa-folder-open'></i> " + L.selectBtn,
					'class': 'ui-priority-secondary',
					click: function () {
						var pid = $('#page_id', $iframe.contents()).val();
						$iframe.attr('src', modalUri + '?id=' + pid + '&modal=1&version=' + version);
						$iframe.setButtons({});
					}
				}, {
					html: "<i class='fa fa-times-circle'></i> " + L.cancelBtn,
					'class': 'ui-priority-secondary',
					click: function () { $iframe.dialog('close'); }
				}];

				$iframe.setButtons(buttons);
				$iframe.setTitle($i.find('title').html());

			} else {
				buttons = [];
				$('button.pw-modal-button, button[type=submit]:visible', $i).each(function () {
					var $button = $(this);
					buttons.push({ html: $button.html(), click: function () { $button.trigger('click'); } });
					if (!$button.hasClass('pw-modal-button-visible')) $button.hide();
				});
				buttons.push({
					html: "<i class='fa fa-times-circle'></i> " + L.cancelBtn,
					'class': 'ui-priority-secondary',
					click: function () { $iframe.dialog('close'); }
				});
				$iframe.setButtons(buttons);
			}
		});
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

			// Single pick → open PW's native image dialog straight on the library
			// file (crop / resize / caption / align). Nothing is put in the page
			// until the user confirms in that dialog.
			if (items.length === 1) {
				openNativeResize(editor, items[0].url, alt(items[0]));
				return;
			}
			// Multiple → plain inserts (no per-image dialog).
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
