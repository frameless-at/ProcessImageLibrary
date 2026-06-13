/* ProcessImageLibrary — admin script.
 *
 * Inline-edit for cells marked with .ml-cell-editable + AJAX re-render
 * of the results region (.ml-results) on filter / sort / pagination.
 *
 * Save queue per pageId serializes saves to the same page; AJAX
 * re-render preserves the filter form (it lives outside .ml-results),
 * and event delegation on .ml-results survives the innerHTML swaps.
 */
(function () {
	'use strict';

	function init() {
		var root = document.querySelector('.ml-root');
		if (!root) return;

		root.classList.add('ml-js-loaded');

		var pwCfg = (window.ProcessWire && window.ProcessWire.config && window.ProcessWire.config.ProcessImageLibrary) || {};
		// Start from everything the server pushed via $config->js so
		// new PHP-side keys (userPrefs, userPrefsUrl, defaultHiddenColumns,
		// …) land in JS without a whitelist update. Fall back to
		// root.dataset for the boot-critical URLs + CSRF token on
		// admin themes that don't populate window.ProcessWire.config.
		var config = Object.assign({
			tplFields: {},
			labels:    {}
		}, pwCfg);
		config.saveUrl   = config.saveUrl   || root.dataset.saveUrl   || '';
		config.renderUrl = config.renderUrl || root.dataset.renderUrl || '';
		config.bulkUrl   = config.bulkUrl   || root.dataset.bulkUrl   || '';
		config.adminUrl  = config.adminUrl  || root.dataset.adminUrl  || '';
		config.clusterUrl = config.clusterUrl || root.dataset.clusterUrl || '';
		config.csrf = config.csrf || {
			name:  root.dataset.csrfName  || '',
			value: root.dataset.csrfValue || ''
		};
		if (!Array.isArray(config.languages)) config.languages = [];
		if (config.currentLangId == null) config.currentLangId = null;
		if (!config.saveUrl) return;

		var labels     = config.labels;
		var saveQueues = new Map();
		var results    = root.querySelector('.ml-results');
		var filterForm = root.querySelector('.ml-filter-bar');
		var isReplacing = false;
		var isBulking   = false;
		var selection   = new Set();

		// Write a value back into a cell. Textarea-backed cells
		// (description + custom textareas) render their text inside a
		// .ml-clamp box so CSS can cap the visible height; write into
		// that box (creating it on demand) so the clamp survives an
		// inline save, and drop it when the value is empty so the cell's
		// :empty "—" placeholder returns. Reads stay on td.textContent —
		// which still returns the full value through the box — so the
		// editor always opens with the complete text. Non-textarea cells
		// (tags, text customs) keep their plain text node.
		function setCellText(td, val) {
			val = String(val == null ? '' : val);
			if (td.dataset.input === 'textarea') {
				var box = td.querySelector('.ml-clamp');
				if (val === '') { if (box) box.remove(); else td.textContent = ''; return; }
				if (!box) {
					td.textContent = '';
					box = document.createElement('div');
					box.className = 'ml-clamp';
					td.appendChild(box);
				}
				box.textContent = val;
			} else {
				td.textContent = val;
			}
		}

		// Append the CSRF token to a FormData (no-op when the page
		// didn't ship one). Centralises the guard every POST endpoint
		// repeated verbatim.
		function appendCsrf(fd) {
			if (config.csrf && config.csrf.name) fd.append(config.csrf.name, config.csrf.value);
			return fd;
		}

		// -- Picker mode ------------------------------------------------
		// When the library is embedded as a picker (modal iframe in the page
		// editor), images are chosen via the normal selection checkboxes (in
		// BOTH the table and the masonry view). A "Use selected" button at the
		// top and bottom copies every selected image into the target field,
		// then messages the parent editor to refresh that field.
		if (root.dataset.picker === '1') {
			root.classList.add('ml-picker');
			var assignUrl = root.dataset.assignUrl || '';
			// Insert mode (rich-text embed): "Use selected" returns the chosen
			// image URLs to the opener instead of assigning to a field.
			var insertMode = root.dataset.pickMode === 'insert';

			function pickKeys() {
				return Array.prototype.map.call(
					(results || document).querySelectorAll('.ml-select-row:checked'),
					function (cb) { return cb.dataset.key || ''; }
				).filter(Boolean);
			}
			function syncPickBar() {
				var n = pickKeys().length;
				root.querySelectorAll('.ml-pick-count').forEach(function (el) {
					el.textContent = '(' + n + ')';
				});
				root.querySelectorAll('.ml-pick-confirm').forEach(function (btn) {
					btn.disabled = n === 0;
				});
			}
			// Selection changes (checkbox / select-all) update the bar.
			results && results.addEventListener('change', function (e) {
				if (e.target && e.target.classList &&
					(e.target.classList.contains('ml-select-row') ||
					 e.target.classList.contains('ml-select-all'))) {
					setTimeout(syncPickBar, 0);   // after the row handler ran
				}
			});

			// In the picker, clicking ANYWHERE on a tile toggles its selection —
			// the natural gesture when you're choosing an image, not just the
			// small checkbox. Skip the checkbox/label itself (it toggles
			// natively) and any link/button so those keep their own behaviour.
			results && results.addEventListener('click', function (e) {
				if (!e.target.closest) return;
				if (e.target.closest('.ml-card-select') || e.target.closest('a, button')) return;
				var card = e.target.closest('.ml-card');
				if (!card) return;
				var cb = card.querySelector('.ml-select-row');
				if (!cb) return;
				e.preventDefault();
				cb.checked = !cb.checked;
				cb.dispatchEvent(new Event('change', { bubbles: true }));
			});

			// Assign one (pageId:field:basename) key to the target field.
			function assignKey(key) {
				var parts = String(key).split(':');
				var pageId = parts.shift();
				var field  = parts.shift();
				var basename = parts.join(':');   // basenames may contain ':'? keep the rest
				var fd = new FormData();
				fd.append('srcPageId',    pageId || '');
				fd.append('srcField',     field || '');
				fd.append('srcBasename',  basename || '');
				fd.append('targetPageId', root.dataset.targetPage || '');
				fd.append('targetField',  root.dataset.targetField || '');
				// Editing a page version → assign into that version, not live.
				fd.append('targetVersion', root.dataset.targetVersion || '');
				appendCsrf(fd);
				return fetch(assignUrl, {
					method: 'POST', credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd
				}).then(function (r) { return r.json(); }).then(function (d) {
					return !!(d && d.ok);
				}).catch(function () { return false; });
			}

			root.addEventListener('click', function (e) {
				var btn = e.target.closest && e.target.closest('.ml-pick-confirm');
				if (!btn) return;
				e.preventDefault();

				// Insert mode: gather the selected tiles' image URLs (+ alt) and
				// hand them to the rich-text editor that opened the picker.
				if (insertMode) {
					var items = [];
					(results || document).querySelectorAll('.ml-select-row:checked').forEach(function (cb) {
						var card = cb.closest && cb.closest('.ml-card');
						if (card && card.dataset.insertUrl) {
							items.push({ url: card.dataset.insertUrl, alt: card.dataset.insertAlt || '' });
						}
					});
					if (!items.length) return;
					if (window.parent && window.parent !== window) {
						window.parent.postMessage({ mlInsert: true, items: items }, location.origin);
					}
					return;
				}

				if (!assignUrl) return;
				var keys = pickKeys();
				if (!keys.length) return;
				root.querySelectorAll('.ml-pick-confirm').forEach(function (b) { b.disabled = true; });
				// Assign sequentially so the target field's saves don't race.
				var ok = 0;
				keys.reduce(function (p, k) {
					return p.then(function () { return assignKey(k).then(function (good) { if (good) ok++; }); });
				}, Promise.resolve()).then(function () {
					if (window.parent && window.parent !== window) {
						window.parent.postMessage({
							mlPicked:    true,
							targetField: root.dataset.targetField,
							targetPage:  root.dataset.targetPage,
							count:       ok
						}, location.origin);
					} else {
						window.alert((labels.done || 'Added') + ' (' + ok + ')');
					}
				});
			});

			syncPickBar();
		}

		// -- Inline edit ------------------------------------------------

		function enqueueSave(pageId, task) {
			var prev = saveQueues.get(pageId) || Promise.resolve();
			var next = prev.catch(function () { return null; }).then(task);
			saveQueues.set(pageId, next);
			return next;
		}

		function postSave(payload) {
			var fd = new FormData();
			Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });
			// Send the current filter URL state so the server can
			// tell us whether the saved row still belongs in this view.
			fd.append('filterQs', location.search || '');
			appendCsrf(fd);
			return fetch(config.saveUrl, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			}).then(function (res) {
				return res.json().then(function (data) { return { status: res.status, data: data }; });
			});
		}

		function flashCell(td, ok) {
			var cls = ok ? 'ml-cell-saved' : 'ml-cell-error';
			td.classList.add(cls);
			setTimeout(function () { td.classList.remove(cls); }, 1200);
			announce((ok ? (labels.saved || 'Saved') : (labels.error || 'Save failed')));
		}

		// For a batch save, collect the matching subfield cell on every
		// currently-selected row. The originating cell is included, so
		// the caller can flash + optimistic-update the whole set in one
		// pass and the visual feedback covers every row the broadcast
		// will touch.
		function batchCellsForSubfield(subfield) {
			if (!results || !subfield) return [];
			var out = [];
			results.querySelectorAll('.ml-row').forEach(function (tr) {
				var cb = tr.querySelector('.ml-select-row');
				if (!cb || !cb.dataset.key || !selection.has(cb.dataset.key)) return;
				var c = tr.querySelector('[data-subfield="' + subfield + '"]');
				if (c) out.push(c);
			});
			return out;
		}

		// Mirror of the server-side resolveRenamePattern token grammar
		// so the batch-save optimistic update can show the resolved
		// per-row value instead of the raw template string. Same
		// tokens, same per-row context inputs (n / total / pageTitle /
		// pageName / date / field). The server still gets the raw
		// template + does its own resolution; this is purely visual.
		function resolveTemplateClient(template, ctx) {
			if (template == null || !/\(([nN]|n[2-5]|t|d|p|f)\)/.test(template)) {
				return template;
			}
			var n     = ctx.n     || 0;
			var total = ctx.total || 0;
			return template.replace(/\((n[2-5]?|N|t|d|p|f)\)/g, function (_m, tok) {
				switch (tok) {
					case 'n':  return String(n);
					case 'n2': return String(n).padStart(2, '0');
					case 'n3': return String(n).padStart(3, '0');
					case 'n4': return String(n).padStart(4, '0');
					case 'n5': return String(n).padStart(5, '0');
					case 'N':  return String(total);
					case 't':  return ctx.pageTitle || '';
					case 'd':  return ctx.date      || '';
					case 'p':  return ctx.pageName  || '';
					case 'f':  return ctx.field     || '';
				}
				return _m;
			});
		}

		// Today's date in the same YYYY-MM-DD form the server uses for
		// the (d) placeholder. Memoised per init so every cell in a
		// batch resolves to the same string.
		var todayIso = (function () {
			var d = new Date();
			var mm = String(d.getMonth() + 1).padStart(2, '0');
			var dd = String(d.getDate()).padStart(2, '0');
			return d.getFullYear() + '-' + mm + '-' + dd;
		})();

		// Visible "Page X of Y — Z images" string — patch the count
		// after a row falls out of the filtered view so the summary
		// reflects DOM state without a full re-render.
		function updatePaginationTotal(newTotal) {
			if (typeof newTotal !== 'number' || newTotal < 0) return;
			document.querySelectorAll('.ml-pagination-summary').forEach(function (el) {
				el.textContent = el.textContent.replace(/\b\d+\s+image/, newTotal + ' image');
			});
		}

		// Sequence after a successful save: flash green for 1200 ms so
		// the user SEES the value applied, brief 200 ms breath so the
		// post-flash state registers, then fade the row out over 250 ms,
		// then drop from DOM + bump the pagination total. Used by the
		// inline-edit success branch when the server says the row no
		// longer matches the active filter set.
		function fadeRowIfMismatched(td, data) {
			if (!td || !data || data.stillMatches !== false) return;
			setTimeout(function () {
				var tr = td.closest('tr');
				if (!tr || !tr.isConnected) return;
				tr.classList.add('ml-row-deleting');
				setTimeout(function () {
					var cb = tr.querySelector('.ml-select-row');
					if (cb && cb.dataset && cb.dataset.key) {
						selection.delete(cb.dataset.key);
					}
					var tbody = tr.parentNode;
					tr.remove();
					updatePaginationTotal(data.newTotal);
					syncSelectAllHeader();
					// Last row gone? Replace just the table wrapper
					// with the empty-state paragraph, matching what
					// the server emits for a zero-result filter URL.
					// The pagination above/below stays — it's still
					// shown on the no-results server render too.
					if (tbody && tbody.children.length === 0) {
						var tableWrap = root.querySelector('.ml-results .ml-table-scroll');
						if (tableWrap) {
							var msg = labels.emptyResult || 'No images match the current filters.';
							var p = document.createElement('p');
							p.className = 'ml-empty';
							p.textContent = msg;
							tableWrap.replaceWith(p);
						}
					}
				}, 250);
			}, 1400);
		}

		// Push a short message into the visually-hidden live region so
		// screen readers pick up state changes (saves, errors) that the
		// sighted UI signals only with a colour flash.
		var liveRegion = root.querySelector('.ml-live-region');
		var announceTimer = null;
		function announce(msg) {
			if (!liveRegion || !msg) return;
			// Clearing first forces re-announcement even when the same
			// message fires twice in a row (e.g. two saves landed at the
			// same instant).
			liveRegion.textContent = '';
			clearTimeout(announceTimer);
			announceTimer = setTimeout(function () {
				liveRegion.textContent = msg;
			}, 30);
		}

		// Column header text for the cell, used as the popup dialog's
		// label. Falls back to the raw subfield name if the <th> isn't
		// findable (defensive — shouldn't happen with the current table).
		function columnLabelFor(td) {
			var row = td.parentNode;
			if (!row) return td.dataset.subfield || '';
			var idx = Array.prototype.indexOf.call(row.children, td);
			var table = td.closest('table');
			var th = table && table.querySelectorAll('thead th')[idx];
			return th ? th.textContent.trim() : (td.dataset.subfield || '');
		}

		// Build the in-popup editor widget for a cell, dispatching by
		// subfield + tags mode + input type. Returns
		// { element, getValue, focus }. The popup container handles save /
		// cancel / batch radios so widgets only care about their own value.
		function buildPopupWidget(td, original) {
			var subfield = td.dataset.subfield;
			var tagsMode = parseInt(td.dataset.tagsMode || '0', 10);

			// Filename rename has its own widget — text input + locked
			// extension. Routed through commit()'s rename branch.
			if (td.dataset.input === 'filename') {
				return buildPopupFilename(td);
			}

			if (subfield === 'tags' && tagsMode === 2) {
				return buildPopupCheckboxes(td, original);
			}
			if (subfield === 'tags' && tagsMode === 3) {
				return buildPopupTagsAddable(td, original);
			}
			if (subfield === 'tags' && tagsMode === 1) {
				return buildPopupTextInput(original, td.dataset.tagsListId || '');
			}

			// Multilang inputs get language tabs — one textarea / input
			// per language, prefilled from the cell's data-lang-<id>
			// attrs. Only kick in when there are actually >1 languages
			// installed AND this cell carries lang attrs (i.e. the
			// underlying subfield is configured multilang).
			var langs = config.languages || [];
			// hasAttribute is the reliable check for data-lang-<id>;
			// `in` on DOMStringMap can be flaky across browsers.
			var hasLangData = langs.length > 1 && langs.some(function (l) {
				return td.hasAttribute('data-lang-' + l.id);
			});
			if (hasLangData) {
				return buildPopupMultilang(td, original, td.dataset.input === 'textarea');
			}

			if (td.dataset.input === 'checkbox') {
				return buildPopupCheckbox(original);
			}
			if (td.dataset.input === 'date') {
				return buildPopupDate(td, original);
			}
			if (td.dataset.input === 'number') {
				return buildPopupNumber(original);
			}
			if (td.dataset.input === 'select') {
				return buildPopupSelect(td, original);
			}
			if (td.dataset.input === 'page') {
				return buildPopupPageRef(td, original);
			}
			if (td.dataset.input === 'textarea') {
				return buildPopupTextarea(original);
			}
			return buildPopupTextInput(original, '');
		}

		// Page-reference widget: ask the server to render whatever
		// Inputfield the field's own config specifies (PageAutocomplete /
		// PageListSelect / ASMSelect / …), inject the HTML, load any
		// new scripts / styles the render added, then fire the
		// 'reloaded' DOM event so each inputfield's own JS module
		// initialises on the new nodes. getValue() walks every input
		// inside the container and joins the values it finds — the
		// shape works for hidden-input-based pickers (PageAutocomplete),
		// <select multiple> (ASM), and single <select> alike.
		function buildPopupPageRef(td, original) {
			var wrap = document.createElement('div');
			wrap.className = 'ml-popup-pageref';
			var status = document.createElement('div');
			status.className = 'ml-popup-pageref-loading';
			status.textContent = labels.loading || 'Loading…';
			wrap.appendChild(status);

			function loadAsset(url, kind) {
				return new Promise(function (resolve) {
					if (kind === 'script') {
						if (document.querySelector('script[src="' + url + '"]')) return resolve();
						var s = document.createElement('script');
						s.src = url;
						s.onload  = resolve;
						s.onerror = resolve;
						document.head.appendChild(s);
					} else {
						if (document.querySelector('link[href="' + url + '"]')) return resolve();
						var l = document.createElement('link');
						l.rel  = 'stylesheet';
						l.href = url;
						l.onload  = resolve;
						l.onerror = resolve;
						document.head.appendChild(l);
					}
				});
			}

			if (!config.widgetUrl) {
				status.textContent = 'No widget endpoint configured.';
				return {
					element: wrap,
					getValue: function () { return original; },
					focus: function () {}
				};
			}

			var url = config.widgetUrl
				+ '?pageId='   + encodeURIComponent(td.dataset.pageId   || '')
				+ '&fieldName='+ encodeURIComponent(td.dataset.field    || '')
				+ '&basename=' + encodeURIComponent(td.dataset.basename || '')
				+ '&subfield=' + encodeURIComponent(td.dataset.subfield || '');

			fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (!data || !data.ok || !data.html) {
						status.textContent = (data && data.error) || 'Widget load failed.';
						return;
					}
					var styleLoads  = (data.styles  || []).map(function (u) { return loadAsset(u, 'style');  });
					var scriptLoads = (data.scripts || []).map(function (u) { return loadAsset(u, 'script'); });
					return Promise.all(styleLoads.concat(scriptLoads)).then(function () {
						status.remove();
						var holder = document.createElement('div');
						holder.className = 'ml-popup-pageref-holder';
						holder.innerHTML = data.html;
						wrap.appendChild(holder);
						// PW's inputfield JS modules hook the 'reloaded'
						// DOM event via delegated jQuery handlers on
						// document, scoped to selectors like
						// .InputfieldPageAutocomplete / .InputfieldPage.
						// Delegated events fire only when the event
						// originates from a matching descendant, so we
						// trigger 'reloaded' on EACH .Inputfield in the
						// injected fragment (mirroring what ProcessPage-
						// Edit does after AJAX-loading a tab). Falls
						// back to a CustomEvent burst when jQuery isn't
						// available, although in the PW admin it
						// always is.
						var jq = window.jQuery;
						if (jq) {
							var $fields = jq(holder).find('.Inputfield').addBack('.Inputfield');
							$fields.trigger('reloaded', ['ml-widget']);
						} else {
							holder.querySelectorAll('.Inputfield').forEach(function (el) {
								el.dispatchEvent(new CustomEvent('reloaded', { bubbles: true, detail: ['ml-widget'] }));
							});
						}
						wrap._mlWidgetName = data.name || (td.dataset.subfield || '');
						wrap._mlWidgetId   = data.id   || '';
					});
				})
				.catch(function () { status.textContent = 'Widget load failed.'; });

			return {
				element: wrap,
				getValue: function () {
					// Collect every input value inside the widget holder
					// whose name starts with the subfield name. Covers
					// hidden-input pickers, multi-selects and singles.
					var subfield = td.dataset.subfield || '';
					var ids = [];
					wrap.querySelectorAll('input, select').forEach(function (el) {
						var name = el.name || '';
						if (!name) return;
						if (name !== subfield && name.indexOf(subfield) !== 0) return;
						if (el.type === 'checkbox' || el.type === 'radio') {
							if (el.checked && el.value) ids.push(el.value);
							return;
						}
						if (el.tagName === 'SELECT' && el.multiple) {
							Array.prototype.forEach.call(el.options, function (o) {
								if (o.selected && o.value) ids.push(o.value);
							});
							return;
						}
						if (el.value) ids.push(el.value);
					});
					// Dedup + drop blanks; comma-join for the save path.
					var seen = Object.create(null);
					return ids.filter(function (v) {
						v = String(v).trim();
						if (!v || seen[v]) return false;
						seen[v] = true;
						return true;
					}).join(',');
				},
				focus: function () {
					var first = wrap.querySelector('input, select, button');
					if (first) first.focus();
				}
			};
		}

		// Tabbed-textarea widget for multilang subfields. Reads each
		// language's starting value from the matching data-lang-<id>
		// attribute on the cell, keeps the per-tab DOM in a small map
		// keyed by lang id, and reports back getValue() as a
		// {langId: value} object so commit() can ship every language
		// in one POST. getPrimaryValue() returns whatever the
		// currently-active tab holds, used for the cell's optimistic
		// post-save display.
		function buildPopupMultilang(td, original, isTextarea) {
			var langs = config.languages || [];

			var wrap = document.createElement('div');
			wrap.className = 'ml-langtabs';

			var bar = document.createElement('div');
			bar.className = 'ml-langtabs-bar';
			var panes = document.createElement('div');
			panes.className = 'ml-langtabs-panes';

			var byId = Object.create(null);
			var activeId = null;

			// Pre-pick the tab to land on: server tells us the editor's
			// current admin-language id (matching the same 0=default
			// scheme as data-lang-<id>). Falls back to the first
			// language in the list if no match.
			var preferredId = null;
			if (config.currentLangId !== null) {
				var found = langs.some(function (l) {
					return Number(l.id) === Number(config.currentLangId);
				});
				if (found) preferredId = Number(config.currentLangId);
			}
			if (preferredId === null && langs.length) preferredId = Number(langs[0].id);

			langs.forEach(function (lang) {
				var tab = document.createElement('button');
				tab.type = 'button';
				tab.className = 'ml-langtabs-tab';
				tab.dataset.langId = String(lang.id);
				tab.textContent = lang.title || lang.name;
				bar.appendChild(tab);

				var pane;
				if (isTextarea) {
					pane = document.createElement('textarea');
					pane.rows = 6;
				} else {
					pane = document.createElement('input');
					pane.type = 'text';
				}
				pane.className = 'ml-langtabs-pane';
				pane.dataset.langId = String(lang.id);
				// data-lang-<id> attr is the stored value; the cell's
				// textContent reflects the current user-lang display,
				// so only that tab gets "original" as a fallback when
				// no attr is set.
				var stored = td.getAttribute('data-lang-' + lang.id);
				var isPreferred = Number(lang.id) === preferredId;
				pane.value = (stored !== null) ? stored : (isPreferred ? original : '');
				panes.appendChild(pane);
				byId[lang.id] = pane;

				if (isPreferred) {
					tab.classList.add('ml-langtabs-tab-active');
					activeId = lang.id;
				} else {
					pane.style.display = 'none';
				}

				tab.addEventListener('click', function () {
					Array.prototype.forEach.call(
						bar.querySelectorAll('.ml-langtabs-tab'),
						function (t) { t.classList.remove('ml-langtabs-tab-active'); }
					);
					tab.classList.add('ml-langtabs-tab-active');
					Object.keys(byId).forEach(function (id) {
						byId[id].style.display = (String(id) === String(lang.id)) ? '' : 'none';
					});
					activeId = lang.id;
					byId[lang.id].focus();
				});
			});

			wrap.appendChild(bar);
			wrap.appendChild(panes);

			return {
				element: wrap,
				multilang: true,
				getValue: function () {
					var out = {};
					Object.keys(byId).forEach(function (id) {
						out[id] = byId[id].value;
					});
					return out;
				},
				getPrimaryValue: function () {
					return (activeId !== null && byId[activeId]) ? byId[activeId].value : '';
				},
				focus: function () {
					var pane = (activeId !== null) ? byId[activeId] : null;
					if (pane) { pane.focus(); pane.select(); }
				}
			};
		}

		function buildPopupTextarea(original) {
			var ta = document.createElement('textarea');
			ta.value = original;
			ta.rows = 6;
			return {
				element: ta,
				getValue: function () { return ta.value; },
				focus:    function () { ta.focus(); ta.select(); }
			};
		}

		function buildPopupTextInput(original, datalistId) {
			var input = document.createElement('input');
			input.type = 'text';
			input.value = original;
			input.className = 'ml-popup-input';
			if (datalistId) input.setAttribute('list', datalistId);
			return {
				element: input,
				getValue: function () { return input.value; },
				focus:    function () { input.focus(); input.select(); }
			};
		}

		// Typed custom-subfield widgets. They round-trip the editor-RAW
		// value (data-value): checkbox → "1"/"0", date → "Y-m-d", select
		// → option id. The cell's visible text is a glyph / label, not
		// the value, so these never read it.
		function buildPopupCheckbox(original) {
			var label = document.createElement('label');
			label.className = 'ml-popup-checkbox';
			var cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.className = 'uk-checkbox';
			cb.checked = (original === '1' || original === 'on' || original === 'true');
			label.appendChild(cb);
			label.appendChild(document.createTextNode(' ' + (labels.enabled || 'Enabled')));
			return {
				element: label,
				getValue: function () { return cb.checked ? '1' : '0'; },
				focus:    function () { cb.focus(); }
			};
		}

		function buildPopupDate(td, original) {
			var input = document.createElement('input');
			input.type = (td && td.dataset.datetime === '1') ? 'datetime-local' : 'date';
			input.className = 'ml-popup-input';
			input.value = original || '';
			return {
				element: input,
				getValue: function () { return input.value; },
				focus:    function () { input.focus(); }
			};
		}

		function buildPopupSelect(td, original) {
			var options = [];
			try { options = JSON.parse(td.dataset.options || '[]'); }
			catch (e) { options = []; }
			var multiple = td.dataset.multiple === '1';
			var current = String(original || '').split(',').filter(Boolean);
			// Multi-select renders as a checkbox list — <select multiple>
			// is a UX nightmare (ctrl/cmd-click discoverability, no
			// touch-friendly behaviour, broken under uk-select's
			// appearance:none reset). Single keeps the native <select>.
			if (multiple) {
				var wrap = document.createElement('div');
				wrap.className = 'ml-popup-checklist';
				options.forEach(function (o) {
					var lbl = document.createElement('label');
					lbl.className = 'ml-popup-checklist-item';
					var cb = document.createElement('input');
					cb.type = 'checkbox';
					cb.className = 'uk-checkbox';
					cb.value = String(o.value);
					if (current.indexOf(String(o.value)) !== -1) cb.checked = true;
					lbl.appendChild(cb);
					lbl.appendChild(document.createTextNode(' ' + o.label));
					wrap.appendChild(lbl);
				});
				return {
					element: wrap,
					getValue: function () {
						return Array.prototype.filter
							.call(wrap.querySelectorAll('input[type="checkbox"]'), function (cb) { return cb.checked; })
							.map(function (cb) { return cb.value; })
							.join(',');
					},
					focus: function () {
						var first = wrap.querySelector('input[type="checkbox"]');
						if (first) first.focus();
					}
				};
			}
			var select = document.createElement('select');
			select.className = 'ml-popup-input uk-select';
			var blank = document.createElement('option');
			blank.value = '';
			blank.textContent = '—';
			select.appendChild(blank);
			options.forEach(function (o) {
				var opt = document.createElement('option');
				opt.value = String(o.value);
				opt.textContent = o.label;
				if (current.indexOf(String(o.value)) !== -1) opt.selected = true;
				select.appendChild(opt);
			});
			return {
				element: select,
				getValue: function () { return select.value; },
				focus:    function () { select.focus(); }
			};
		}

		function buildPopupNumber(original) {
			var input = document.createElement('input');
			input.type = 'number';
			input.className = 'ml-popup-input';
			input.step = 'any';
			input.value = original || '';
			return {
				element: input,
				getValue: function () { return input.value; },
				focus:    function () { input.focus(); input.select(); }
			};
		}

		// Filename rename: text input for the stem with the original
		// extension visible-but-locked beside it. The widget only ever
		// reports the stem back; commit() reattaches the extension on
		// the server side using the original basename it has on file.
		function buildPopupFilename(td) {
			var wrap = document.createElement('div');
			wrap.className = 'ml-popup-filename';
			var input = document.createElement('input');
			input.type = 'text';
			input.className = 'ml-popup-input';
			input.value = td.dataset.stem || '';
			input.setAttribute('aria-label', labels.rename || 'New filename');
			input.setAttribute('autocomplete', 'off');
			input.setAttribute('spellcheck', 'false');
			var extSpan = document.createElement('span');
			extSpan.className = 'ml-popup-filename-ext';
			extSpan.textContent = td.dataset.ext || '';
			wrap.appendChild(input);
			wrap.appendChild(extSpan);
			return {
				element: wrap,
				rename: true,
				getValue: function () { return input.value; },
				focus:    function () { input.focus(); input.select(); }
			};
		}

		// POST to the tag-bulk endpoint (preview count or apply).
		function tagBulkFetch(params) {
			var fd = new FormData();
			Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
			appendCsrf(fd);
			return fetch(config.tagBulkUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); });
		}

		// A small icon button for the manage controls on a predefined-tag chip.
		function mkTagBtn(cls, icon, title, onClick) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'ml-tag-manage ' + cls;
			b.title = title;
			b.setAttribute('aria-label', title);
			b.innerHTML = '<i class="fa ' + icon + '" aria-hidden="true"></i>';
			// mousedown preventDefault keeps focus on the inline-edit input so a
			// click on ✓ commits instead of blurring (which would cancel).
			b.addEventListener('mousedown', function (e) { e.preventDefault(); });
			// preventDefault/stopPropagation so the click doesn't toggle the
			// neighbouring checkbox or bubble to the cell.
			b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); onClick(); });
			return b;
		}

		// Apply a tag rename/delete library-wide WITHOUT a confirm dialog (used by
		// the inline rename). Toasts the affected count the server reports back.
		function tagManageApply(op, field, tag, newTag, onSuccess) {
			if (!config.tagBulkUrl || !canManageShared) return;
			var params = { op: op, field: field, tag: tag, apply: 1 };
			if (op === 'rename') params.newTag = newTag;
			tagBulkFetch(params).then(function (d) {
				if (d && d.ok) {
					announce((op === 'delete'
						? (labels.tagDeleted || 'Tag deleted from %d image(s)')
						: (labels.tagRenamed || 'Tag renamed on %d image(s)')).replace('%d', d.count));
					if (d.tagsAllowed) applyTagsAllowed(d.field, d.tagsAllowed);
					// Rename/delete already propagated to every image server-side;
					// mirror it onto the visible table rows so the change shows
					// everywhere live, not just after a reload.
					refreshTableTagCells(field, op, tag, newTag);
					if (onSuccess) onSuccess(d);
				} else {
					announce((d && d.error) || labels.error || 'Failed');
				}
			}).catch(function (err) { console.error('[ImageLibrary] tag apply failed:', err); });
		}

		// Live-update the displayed tag cells in the table after a library-wide
		// rename/delete. Tag cells are plain space-separated text tokens
		// (td.textContent); rewrite the matching token in place — case-insensitive
		// match like PW's tag keys — and de-dupe so a rename that merges onto an
		// existing tag doesn't leave the token twice. The cell whose modal
		// triggered this is swept too: the rename is committed on the server
		// regardless of whether that modal is later saved or cancelled, so the new
		// spelling is correct either way.
		function refreshTableTagCells(field, op, oldTag, newTag) {
			var oldLc = String(oldTag).toLowerCase();
			document.querySelectorAll('td[data-col="tags"][data-field="' + field + '"]').forEach(function (cell) {
				var cur = (cell.textContent || '').trim();
				if (!cur) return;
				var changed = false, out = [], seen = {};
				cur.split(/\s+/).forEach(function (t) {
					var rep = t;
					if (t.toLowerCase() === oldLc) {
						changed = true;
						if (op !== 'rename' || !newTag) return;   // delete → drop token
						rep = newTag;
					}
					var k = rep.toLowerCase();
					if (seen[k]) { changed = true; return; }      // merge duplicate
					seen[k] = true;
					out.push(rep);
				});
				if (changed) setCellText(cell, out.join(' '));
			});
		}

		// Find an existing chip checkbox in the same list whose tag matches (case-
		// insensitive), excluding one. Used to merge a rename onto an existing tag.
		function findChipCheckbox(parent, tag, exceptCb) {
			if (!parent) return null;
			var lc = tag.toLowerCase();
			var found = null;
			parent.querySelectorAll('.ml-tag-chip input[type="checkbox"]').forEach(function (b) {
				if (b !== exceptCb && b.value.toLowerCase() === lc) found = b;
			});
			return found;
		}

		function buildTagChip(tag, checked, td, predefined) {
			var chip = document.createElement('div');
			chip.className = 'ml-tag-chip';
			var label = document.createElement('label');
			var cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.className = 'uk-checkbox';
			cb.value = tag;
			cb.checked = !!checked;
			var txt = document.createTextNode(' ' + tag);
			label.appendChild(cb);
			label.appendChild(txt);
			chip.appendChild(label);
			chip._cb = cb;

			var field = td.dataset.field || '';
			if (predefined && canManageShared && field) {
				var input = null;        // the inline-edit input while renaming, else null
				var armed = false;       // delete-confirm armed (one click already)
				var armTimer = null;
				var countSpan = null;

				var renBtn = mkTagBtn('ml-tag-rename', 'fa-pencil', labels.tagRenameTitle || 'Rename tag', function () {
					if (input) { commitEdit(); return; }
					if (armed)  { disarmDelete(); return; }   // doubles as cancel while armed
					startEdit();
				});
				var delBtn = mkTagBtn('ml-tag-delete', 'fa-times', labels.tagDeleteTitle || 'Delete tag', function () {
					if (input) return;                        // not while renaming
					if (armed) { doDelete(); return; }
					armDelete();
				});
				chip.appendChild(renBtn);
				chip.appendChild(delBtn);

				function setIcon(btn, name, title) {
					var i = btn.querySelector('i'); if (i) i.className = 'fa ' + name;
					btn.title = title; btn.setAttribute('aria-label', title);
				}
				function setRenIcon(name, title) {
					setIcon(renBtn, name, title);
					renBtn.classList.toggle('ml-tag-confirm', name === 'fa-check');
				}

				// Inline delete confirm — no second modal. First × click arms it:
				// the × turns into a red ✓, the ✎ becomes a ✗ cancel, the affected
				// image count appears inline, and it auto-disarms after a few sec.
				function armDelete() {
					armed = true;
					chip.classList.add('ml-tag-deleting');
					setIcon(delBtn, 'fa-check', labels.tagConfirmDelete || 'Confirm delete');
					delBtn.classList.add('ml-tag-confirm-del');
					setIcon(renBtn, 'fa-times', labels.cancel || 'Cancel');
					countSpan = document.createElement('span');
					countSpan.className = 'ml-tag-count';
					countSpan.textContent = '…';
					chip.insertBefore(countSpan, renBtn);
					tagBulkFetch({ op: 'delete', field: field, tag: cb.value, apply: 0 }).then(function (d) {
						if (countSpan && d && d.ok) {
							countSpan.textContent = '(' + d.count + ')';
						}
					}).catch(function () {});
					armTimer = setTimeout(disarmDelete, 4000);
				}
				function disarmDelete() {
					armed = false;
					if (armTimer) { clearTimeout(armTimer); armTimer = null; }
					chip.classList.remove('ml-tag-deleting');
					delBtn.classList.remove('ml-tag-confirm-del');
					setIcon(delBtn, 'fa-times', labels.tagDeleteTitle || 'Delete tag');
					setIcon(renBtn, 'fa-pencil', labels.tagRenameTitle || 'Rename tag');
					if (countSpan) { countSpan.remove(); countSpan = null; }
				}
				function doDelete() {
					if (armTimer) { clearTimeout(armTimer); armTimer = null; }
					tagManageApply('delete', field, cb.value, null, function () { chip.remove(); });
				}
				// Inline rename: the label turns into a text input, the ✎ becomes a
				// ✓. Enter or ✓ commits, Esc or blur cancels — no second modal.
				function startEdit() {
					input = document.createElement('input');
					input.type = 'text';
					input.className = 'ml-tag-edit-input uk-input';
					input.value = cb.value;
					label.style.display = 'none';
					chip.insertBefore(input, renBtn);
					setRenIcon('fa-check', labels.save || 'Save');
					input.focus();
					input.select();
					input.addEventListener('keydown', function (e) {
						// stopPropagation so Enter/Esc never reach the tag modal (it
						// would otherwise Save/close the whole cell editor).
						if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); commitEdit(); }
						else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancelEdit(); }
					});
					// Blur cancels. Clicking the ✓ / × buttons no longer blurs the
					// input (their mousedown preventDefault keeps focus), so a blur
					// here always means the user clicked truly elsewhere.
					input.addEventListener('blur', function () { cancelEdit(); });
				}
				function endEdit() {
					if (input) { input.remove(); input = null; }
					label.style.display = '';
					setRenIcon('fa-pencil', labels.tagRenameTitle || 'Rename tag');
				}
				function cancelEdit() { endEdit(); }
				function commitEdit() {
					if (!input) return;
					var nt  = (input.value || '').trim().replace(/\s+/g, '_');
					var old = cb.value;
					var parent = chip.parentNode;
					endEdit();
					// Exact compare (NOT lowercased) so a case-only fix
					// ("poppy" → "Poppy") counts as a real change.
					if (nt === '' || nt === old) return;
					// Duplicate (same as adding): if the new tag already exists, drop
					// this chip and check the existing one — the server merges too.
					var existing = findChipCheckbox(parent, nt, cb);
					var wasChecked = cb.checked;
					tagManageApply('rename', field, old, nt, function (res) {
						var name = (res && res.newTag) || nt;
						if (existing) {
							// merged: this image now carries the existing tag iff it
							// had either tag before.
							existing.checked = existing.checked || wasChecked;
							chip.remove();
						} else {
							cb.value = name;
							txt.textContent = ' ' + name;
						}
					});
				}
			}
			return chip;
		}

		function buildPopupCheckboxes(td, original) {
			var allowed = [];
			try { allowed = JSON.parse(td.dataset.tagsAllowed || '[]'); }
			catch (e) { allowed = []; }
			// Show predefined tags alphabetically (case-insensitive).
			allowed.sort(function (a, b) {
				return String(a).toLowerCase().localeCompare(String(b).toLowerCase());
			});

			var currentSet = Object.create(null);
			original.split(/\s+/).filter(Boolean).forEach(function (t) {
				currentSet[t] = true;
			});

			var wrap = document.createElement('div');
			wrap.className = 'ml-popup-tag-list';
			allowed.forEach(function (tag) {
				wrap.appendChild(buildTagChip(tag, !!currentSet[tag], td, true));
			});

			return {
				element: wrap,
				getValue: function () {
					var sel = wrap.querySelectorAll('input[type="checkbox"]:checked');
					return Array.prototype.map.call(sel, function (cb) { return cb.value; }).join(' ');
				},
				focus: function () {
					var first = wrap.querySelector('input[type="checkbox"]');
					if (first) first.focus();
				}
			};
		}

		// Tags mode 3 ("predefined + can input their own"): the predefined tags
		// as a checkbox group (like mode 2), PLUS an input to add brand-new tags
		// — typed tags appear as checked chips. getValue collects every checked
		// box, so picks and new tags save together. New tags pass server-side
		// because the whitelist gate only fires for mode 2.
		function buildPopupTagsAddable(td, original) {
			var allowed = [];
			try { allowed = JSON.parse(td.dataset.tagsAllowed || '[]'); }
			catch (e) { allowed = []; }
			// Show predefined tags alphabetically (case-insensitive).
			allowed.sort(function (a, b) {
				return String(a).toLowerCase().localeCompare(String(b).toLowerCase());
			});

			var current = original.split(/\s+/).filter(Boolean);
			var currentSet = Object.create(null);
			current.forEach(function (t) { currentSet[t] = true; });

			var wrap = document.createElement('div');
			wrap.className = 'ml-popup-tag-list';
			var boxes = Object.create(null);   // tag => checkbox (also the dedupe set)

			// predefined=true gets the manager rename/delete controls; ad-hoc and
			// freshly-typed tags don't (they aren't in the field list yet).
			function addChip(tag, checked, predefined) {
				if (boxes[tag]) { if (checked) boxes[tag].checked = true; return boxes[tag]; }
				var chip = buildTagChip(tag, checked, td, !!predefined);
				wrap.appendChild(chip);
				boxes[tag] = chip._cb;
				return chip._cb;
			}

			// Predefined first (checked if already on the image) …
			allowed.forEach(function (tag) { addChip(tag, !!currentSet[tag], true); });
			// … then any existing tags that aren't in the predefined list.
			current.forEach(function (tag) { addChip(tag, true, false); });

			// Add-new row: a text input with the field's autocomplete datalist.
			var addRow = document.createElement('div');
			addRow.className = 'ml-popup-tag-add';
			var input = document.createElement('input');
			input.type = 'text';
			input.className = 'ml-popup-input';
			input.placeholder = labels.tagAddPlaceholder || 'Add tag…';
			var listId = td.dataset.tagsListId || '';
			if (listId) input.setAttribute('list', listId);
			addRow.appendChild(input);

			// Fold whatever is typed into chips (space / comma separated). Used
			// on Enter/comma and again at save time so a typed-but-not-confirmed
			// tag isn't lost.
			function flush() {
				input.value.split(/[\s,]+/).filter(Boolean).forEach(function (tag) {
					addChip(tag, true);
				});
				input.value = '';
			}
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); flush(); input.focus(); }
			});

			var container = document.createElement('div');
			container.appendChild(wrap);
			container.appendChild(addRow);

			return {
				element: container,
				getValue: function () {
					flush();
					var sel = wrap.querySelectorAll('input[type="checkbox"]:checked');
					return Array.prototype.map.call(sel, function (cb) { return cb.value; }).join(' ');
				},
				focus: function () { input.focus(); }
			};
		}

		// After a mode-3 save promotes new tags into a field's predefined list,
		// reflect the updated list onto open cells + the autocomplete datalist so
		// the next edit this session offers the new tags without a reload. Field
		// names are PW-safe (letters/digits/underscore), so no selector escaping.
		function applyTagsAllowed(field, list) {
			if (!field || !Array.isArray(list)) return;
			var json = JSON.stringify(list);
			document.querySelectorAll('td[data-col="tags"][data-field="' + field + '"]').forEach(function (cell) {
				if (cell.dataset.tagsMode === '2' || cell.dataset.tagsMode === '3') {
					cell.dataset.tagsAllowed = json;
				}
			});
			var dl = document.getElementById('ml-tags-used-' + field);
			if (dl) {
				var have = Object.create(null);
				Array.prototype.forEach.call(dl.querySelectorAll('option'), function (o) { have[o.value] = true; });
				list.forEach(function (t) {
					if (!have[t]) { var o = document.createElement('option'); o.value = t; dl.appendChild(o); }
				});
			}
		}

		// All cell edits run through one popup. The native <dialog>
		// gives a roomy editing canvas regardless of subfield type
		// (textarea, single-line text, whitelisted-tag checkboxes) and
		// keeps the table row from shifting under the user. Esc and
		// backdrop click dismiss; Save commits.
		function activateEditor(td) {
			if (td.classList.contains('ml-editing')) return;
			td.classList.add('ml-editing');

			// Typed cells (checkbox / date / select) display a glyph /
			// label, not the value — the editor reads the raw value from
			// data-value; everything else uses the cell text.
			var typedInput = td.dataset.input === 'checkbox'
				|| td.dataset.input === 'date'
				|| td.dataset.input === 'number'
				|| td.dataset.input === 'select'
				|| td.dataset.input === 'page';
			var original = typedInput ? (td.dataset.value || '') : td.textContent;
			var batch    = isBatchEdit(td);
			var widget   = buildPopupWidget(td, original);

			var dialog = document.createElement('dialog');
			dialog.className = 'ml-popup-editor';

			var header = document.createElement('header');
			header.textContent = columnLabelFor(td);
			dialog.appendChild(header);

			// Placeholder hint — shown for every prose-shaped editor:
			// filename rename + description + text / textarea customs.
			// NOT for tags (any mode): tags are token sets where
			// "(d)" → "2026-05-27" would land as a literal tag, which
			// is editorial noise rather than useful metadata.
			var hasPlaceholders =
				td.dataset.subfield !== 'tags' && (
					td.dataset.input === 'filename' ||
					td.dataset.input === 'textarea' ||
					td.dataset.input === 'text'
				);
			if (hasPlaceholders) {
				var hint = document.createElement('p');
				hint.className = 'ml-popup-hint';
				hint.textContent = labels.placeholderHint
					|| 'Placeholders: (n) counter, (n2)…(n5) padded, (N) total, (t) page title, (d) date, (p) page name, (f) field name.';
				dialog.appendChild(hint);
			}

			dialog.appendChild(widget.element);

			var batchBar = null;
			function getBatchMode() {
				if (!batchBar) return 'replace';
				var cb = batchBar.querySelector('input[type="radio"]:checked');
				return cb ? cb.value : 'add';
			}
			// Batch mode picker: Add / Replace for prose + custom
			// broadcasts; Add / Replace / Remove for tags (set
			// operations match the tag-token semantic — same rule
			// applies to checkbox-shaped custom subfields when those
			// land). Batch rename has just one mode (apply the
			// pattern with its (n) counter), so the radios stay
			// hidden there.
			// Add / Replace (+ Remove for tags) only make sense for
			// token / prose subfields. Filename and the typed widgets
			// (checkbox / date / select) are Replace-only → no radios.
			if (batch && (td.dataset.subfield === 'tags'
				|| td.dataset.input === 'text'
				|| td.dataset.input === 'textarea')) {
				batchBar = document.createElement('div');
				batchBar.className = 'ml-batch-mode';
				var radioName = 'mlBatchMode-' + Math.random().toString(36).slice(2, 8);
				var modes = (td.dataset.subfield === 'tags')
					? ['add', 'replace', 'remove']
					: ['add', 'replace'];
				var modeLabels = {
					add:     labels.add     || 'Add',
					replace: labels.replace || 'Replace',
					remove:  labels.remove  || 'Remove'
				};
				modes.forEach(function (mode) {
					var lbl = document.createElement('label');
					var rb = document.createElement('input');
					rb.type = 'radio';
					rb.className = 'uk-radio';
					rb.name = radioName;
					rb.value = mode;
					if (mode === modes[0]) rb.checked = true;
					lbl.appendChild(rb);
					lbl.appendChild(document.createTextNode(' ' + modeLabels[mode]));
					batchBar.appendChild(lbl);
				});
				dialog.appendChild(batchBar);
			}

			var footer = document.createElement('footer');
			var cancelBtn = document.createElement('button');
			cancelBtn.type = 'button';
			// Match the rest of the admin chrome — PW admin is UIkit.
			cancelBtn.className = 'ml-popup-cancel uk-button uk-button-secondary';
			cancelBtn.textContent = labels.cancel || 'Cancel';
			var saveBtn = document.createElement('button');
			saveBtn.type = 'button';
			saveBtn.className = 'ml-popup-save uk-button uk-button-primary';
			saveBtn.textContent = labels.save || 'Save';
			footer.appendChild(cancelBtn);
			footer.appendChild(saveBtn);
			dialog.appendChild(footer);

			document.body.appendChild(dialog);

			var committed = false;

			function teardown() {
				td.classList.remove('ml-editing');
				if (dialog.open) dialog.close();
				dialog.remove();
			}

			function cancel() {
				if (committed) return;
				committed = true;
				teardown();
			}

			function commit(mode) {
				if (committed) return;
				committed = true;

				// Filename rename — own endpoint, own success path. The
				// widget reports just the stem; the server reattaches
				// the original extension, sanitizes the stem, deletes
				// orphan variations and persists. On success we trigger
				// a results-region re-render so every cell in the row
				// (thumb URL, data-basename refs, selection key) catches
				// up to the new identity.
				if (td.dataset.input === 'filename') {
					var newStem = String(widget.getValue() || '').trim();
					var oldStem = td.dataset.stem || '';
					if (newStem === '' || newStem === oldStem) { teardown(); return; }
					teardown();
					// A rename now rewrites every rich-text embed of the
					// file automatically (server side), so there is nothing
					// to warn about up front — run it straight away, then
					// summarise which embeds were updated afterwards.
					var doRename = function () {
					// Optimistic update — resolve placeholders on the
					// originating cell now. For batch rename the per-row
					// optimistic update + resolution happens below
					// against bulkRenameCells; for single rename n=1,
					// total=1, picked up from the row's own data attrs.
					var stemEl = td.querySelector('.ml-fn-stem');
					var prevStem = td.dataset.stem || '';
					var trRename = td.closest('tr');
					var singleRenameCtx = {
						n: 1, total: 1,
						pageTitle: trRename ? (trRename.dataset.pageTitle || '') : '',
						pageName:  trRename ? (trRename.dataset.pageName  || '') : '',
						date:      todayIso,
						field:     td.dataset.field || ''
					};
					var resolvedStemSingle = resolveTemplateClient(newStem, singleRenameCtx);
					if (stemEl) stemEl.textContent = resolvedStemSingle;
					td.dataset.stem = resolvedStemSingle;
					td.classList.add('ml-cell-saving');
					td.title = labels.saving || 'Saving…';

					// Batch: broadcast the pattern across the selection via
					// the bulk endpoint (subfield=basename). Server tracks
					// (n) per item in the order JS sent them. Single: hit
					// the dedicated rename endpoint.
					if (batch) {
						// Flash + optimistic stem update on every
						// selected row's filename cell. Per-row
						// placeholder context: n = selection-order
						// index + 1, total = batch size, plus the row's
						// own data attrs. Each cell stores its prev
						// stem so a server failure can revert all.
						var bulkRenameCells = batchCellsForSubfield('basename');
						var renameTotal = bulkRenameCells.length;
						var prevStems = [];
						bulkRenameCells.forEach(function (c, idx) {
							var stEl = c.querySelector('.ml-fn-stem');
							prevStems.push({
								stemEl: stEl,
								prev:   c.dataset.stem || ''
							});
							var trC = c.closest('tr');
							var batchRenameCtx = {
								n: idx + 1, total: renameTotal,
								pageTitle: trC ? (trC.dataset.pageTitle || '') : '',
								pageName:  trC ? (trC.dataset.pageName  || '') : '',
								date:      todayIso,
								field:     c.dataset.field || td.dataset.field || ''
							};
							var resolvedForRow = resolveTemplateClient(newStem, batchRenameCtx);
							if (stEl) stEl.textContent = resolvedForRow;
							c.dataset.stem = resolvedForRow;
							if (c !== td) c.classList.add('ml-cell-saving');
						});
						runBulk('set', {
							subfield: 'basename',
							value:    newStem,
							mode:     'replace'
						}).then(function (result) {
							var ok = reportBulk(result);
							bulkRenameCells.forEach(function (c, idx) {
								if (!c.isConnected) return;
								c.classList.remove('ml-cell-saving');
								if (ok) {
									flashCell(c, true);
								} else {
									// Revert this cell's optimistic stem.
									var p = prevStems[idx];
									if (p && p.stemEl) p.stemEl.textContent = p.prev;
									c.dataset.stem = p ? p.prev : c.dataset.stem;
									flashCell(c, false);
								}
							});
							// Selection-set carry-over across rename:
							// the server returns a {oldKey: newKey}
							// map for every successful rename — apply
							// it to the persistent selection so the
							// user's choice survives the basename
							// change instead of getting wiped.
							var renamedMap = (result && result.data && result.data.renamed) || {};
							Object.keys(renamedMap).forEach(function (oldKey) {
								if (selection.has(oldKey)) {
									selection.delete(oldKey);
									selection.add(renamedMap[oldKey]);
								}
							});
							// Keep renamed images in any collection they belong to.
							migrateCollectionKeys(renamedMap);
							// Defer the re-render so the green flash plays
							// before the table swap — matches the single
							// inline save's flash → breath → action rhythm.
							// syncCheckboxes runs inside replaceFromQs so
							// the new-key checkboxes re-tick from the
							// updated selection.
							setTimeout(function () {
								replaceFromQs(location.search, false);
							}, ok ? 1400 : 0);
						}).catch(function (err) {
							bulkRenameCells.forEach(function (c, idx) {
								if (!c.isConnected) return;
								c.classList.remove('ml-cell-saving');
								var p = prevStems[idx];
								if (p && p.stemEl) p.stemEl.textContent = p.prev;
								c.dataset.stem = p ? p.prev : c.dataset.stem;
								flashCell(c, false);
							});
							td.title = (err && err.message) || labels.error || 'Network error';
						});
						return;
					}

					enqueueSave(td.dataset.pageId, function () {
						var fd = new FormData();
						fd.append('pageId',    td.dataset.pageId);
						fd.append('fieldName', td.dataset.field);
						fd.append('basename',  td.dataset.basename);
						fd.append('value',     newStem);
						appendCsrf(fd);
						return fetch(config.renameUrl, {
							method: 'POST',
							body: fd,
							credentials: 'same-origin'
						}).then(function (res) {
							return res.json().then(function (data) {
								return { status: res.status, data: data };
							});
						});
					}).then(function (result) {
						if (!td.isConnected) { return; }
						td.classList.remove('ml-cell-saving');
						if (result && result.data && result.data.ok) {
							flashCell(td, true);
							// If the rename also rewrote rich-text embeds,
							// show a summary dialog naming the new file and
							// every reference that was updated. A plain
							// rename (no embeds) just flashes green.
							var embedRefs = (result.data.embedsRefs) || [];
							if (embedRefs.length) {
								renameSummaryDialog(
									td.dataset.basename,
									result.data.basename,
									embedRefs
								);
							}
							// Carry the persistent selection across the
							// basename change — without this, ticking a
							// row, renaming, then re-using bulk would
							// either lose the selection or send a stale
							// key. Same renaming-map idea as the bulk
							// path, just for one item.
							var oldKey = td.dataset.pageId + ':'
								+ td.dataset.field + ':' + td.dataset.basename;
							var newKey = td.dataset.pageId + ':'
								+ td.dataset.field + ':' + result.data.basename;
							if (oldKey !== newKey && selection.has(oldKey)) {
								selection.delete(oldKey);
								selection.add(newKey);
							}
							// Keep the renamed image in any collection it belongs to.
							if (oldKey !== newKey) {
								var rmap = {}; rmap[oldKey] = newKey;
								migrateCollectionKeys(rmap);
							}
							// Defer re-render so the green flash plays
							// before every basename-bound attr (thumb URL,
							// data-basename refs, selection key) gets
							// rebuilt from the server response.
							setTimeout(function () {
								replaceFromQs(location.search, false);
							}, 1400);
						} else {
							// Revert the optimistic stem update.
							if (stemEl) stemEl.textContent = prevStem;
							td.dataset.stem = prevStem;
							var reason = (result && result.data && result.data.error)
								|| ('HTTP ' + (result && result.status));
							console.error('[ImageLibrary] rename failed:', result);
							td.title = reason;
							flashCell(td, false);
						}
					}).catch(function (err) {
						if (!td.isConnected) return;
						if (stemEl) stemEl.textContent = prevStem;
						td.dataset.stem = prevStem;
						td.classList.remove('ml-cell-saving');
						console.error('[ImageLibrary] rename errored:', err);
						td.title = (err && err.message) || labels.error || 'Network error';
						flashCell(td, false);
					});
					return;
					};
					// Advisory where-used preflight (mirrors delete): if any page
					// still embeds the file by its current basename, warn before
					// committing. The rename then auto-rewrites embeds anyway — this
					// just stops a silent surprise when a rewrite can't reach one
					// (unusual embed URL, etc.). No embeds → proceeds straight away.
					var renameItems = batch
						? selectionItems()
						: [{ pageId: td.dataset.pageId, fieldName: td.dataset.field, basename: td.dataset.basename }];
					confirmRename(renameItems, doRename);
					return;
				}

				// Multilang widgets hand back an object {langId: value};
				// single-lang widgets hand back a plain string. Pull
				// the primary value (active tab for multilang) for
				// display, and serialize the full lang map separately
				// so the save endpoints can apply each language.
				var raw = widget.getValue();
				var isMultilang = widget.multilang === true && raw && typeof raw === 'object';
				var primaryValue = isMultilang
					? (typeof widget.getPrimaryValue === 'function' ? widget.getPrimaryValue() : '')
					: raw;
				var langValuesJson = isMultilang ? JSON.stringify(raw) : '';

				// Typed widgets (checkbox / date / select) broadcast their
				// raw value as Replace to every selected row, then re-render
				// (the server produces the correct typed display). No
				// per-cell optimistic display, no Add/merge logic.
				if (batch && typedInput) {
					teardown();
					var typedCells = batchCellsForSubfield(td.dataset.subfield);
					typedCells.forEach(function (c) { c.classList.add('ml-cell-saving'); });
					runBulk('set', { subfield: td.dataset.subfield, value: primaryValue, mode: 'replace' })
						.then(function (result) {
							var ok = reportBulk(result);
							if (ok && result && result.data && Array.isArray(result.data.vanished)) {
								result.data.vanished.forEach(function (k) { selection.delete(k); });
							}
							typedCells.forEach(function (c) {
								if (!c.isConnected) return;
								c.classList.remove('ml-cell-saving');
								flashCell(c, ok);
							});
							setTimeout(function () {
								replaceFromQs(location.search, false);
							}, ok ? 1400 : 0);
						}).catch(function (err) {
							typedCells.forEach(function (c) {
								if (!c.isConnected) return;
								c.classList.remove('ml-cell-saving');
								flashCell(c, false);
							});
							if (td.isConnected) {
								td.title = (err && err.message) || labels.error || 'Network error';
							}
						});
					return;
				}

				if (batch) {
					var sendValue = primaryValue;
					if ((mode || 'replace') === 'add' && !isMultilang) {
						if (td.dataset.subfield === 'tags') {
							// Tag Add: ship only the delta vs. starting set
							// so we don't broadcast pre-existing tags back
							// to siblings. Server unions anyway, but this
							// keeps the payload honest.
							var origToks = original.split(/\s+/).filter(Boolean);
							var newToks  = sendValue.split(/\s+/).filter(Boolean);
							var origSet  = Object.create(null);
							origToks.forEach(function (t) { origSet[t] = true; });
							sendValue = newToks.filter(function (t) { return !origSet[t]; }).join(' ');
						} else if (sendValue.indexOf(original) === 0) {
							sendValue = sendValue.substring(original.length).replace(/^\s+/, '');
						}
						if (sendValue === '') { teardown(); return; }
					}
					teardown();
					// Optimistic update on EVERY selected row's matching
					// cell — Replace mode broadcasts the resolved
					// template, Add mode merges the resolved delta into
					// each row's own original. (n) / (N) / (t) / (p) /
					// (d) / (f) are resolved per row mirroring the
					// server's resolveRenamePattern so the cell shows
					// what the row will actually store, not the raw
					// template. Tags skip placeholder resolution (same
					// rule the server uses).
					var bulkCells = batchCellsForSubfield(td.dataset.subfield);
					var savedTexts = [];
					var resolvedMode = mode || 'replace';
					var totalCount  = bulkCells.length;
					var subf = td.dataset.subfield;
					bulkCells.forEach(function (c, idx) {
						savedTexts.push(c.textContent);
						var rowOriginal = c.textContent;
						var tr = c.closest('tr');
						var rowCtx = {
							n:         idx + 1,
							total:     totalCount,
							pageTitle: tr ? (tr.dataset.pageTitle || '') : '',
							pageName:  tr ? (tr.dataset.pageName  || '') : '',
							date:      todayIso,
							field:     c.dataset.field || td.dataset.field || ''
						};
						var resolved = (subf === 'tags')
							? primaryValue
							: resolveTemplateClient(primaryValue, rowCtx);
						var resolvedDelta = (subf === 'tags')
							? sendValue
							: resolveTemplateClient(sendValue, rowCtx);
						var optimistic = resolved;
						if (resolvedMode === 'add' && !isMultilang) {
							if (subf === 'tags') {
								var origToks = rowOriginal.split(/\s+/).filter(Boolean);
								var addToks  = resolvedDelta.split(/\s+/).filter(Boolean);
								var seen = Object.create(null);
								var merged = [];
								origToks.concat(addToks).forEach(function (t) {
									if (!seen[t]) { seen[t] = true; merged.push(t); }
								});
								optimistic = merged.join(' ');
							} else if (resolvedDelta !== '') {
								optimistic = (rowOriginal ? rowOriginal + ' ' : '') + resolvedDelta;
							}
						} else if (resolvedMode === 'remove' && !isMultilang && subf === 'tags') {
							// Set difference — mirror the server. Only
							// tags get Remove; prose subfields fall
							// through to the default Replace optimistic.
							var dropToks = sendValue.split(/\s+/).filter(Boolean);
							var dropSet  = Object.create(null);
							dropToks.forEach(function (t) { dropSet[t] = true; });
							optimistic = rowOriginal.split(/\s+/)
								.filter(function (t) { return t && !dropSet[t]; })
								.join(' ');
						}
						setCellText(c, optimistic);
						c.classList.add('ml-cell-saving');
					});
					var bulkExtra = {
						subfield: td.dataset.subfield,
						value:    sendValue,
						mode:     mode || 'replace'
					};
					if (langValuesJson) bulkExtra.langValues = langValuesJson;
					runBulk('set', bulkExtra).then(function (result) {
						var ok = reportBulk(result);
						bulkCells.forEach(function (c, i) {
							if (!c.isConnected) return;
							c.classList.remove('ml-cell-saving');
							if (ok) {
								flashCell(c, true);
							} else {
								setCellText(c, savedTexts[i]);
								flashCell(c, false);
							}
						});
						// Match-aware (parity with the single inline save):
						// drop rows the server says no longer pass the active
						// filter from the persistent selection, so they don't
						// silently re-tick when they reappear in another view.
						if (ok && result && result.data && Array.isArray(result.data.vanished)) {
							result.data.vanished.forEach(function (k) { selection.delete(k); });
						}
						// Bulk inline save doesn't change basenames, so the
						// surviving selection keys are still valid — re-sync
						// the checkbox state across the swap. Defer re-render
						// so the flash plays first, matching the single inline
						// save's rhythm.
						setTimeout(function () {
							replaceFromQs(location.search, false);
						}, ok ? 1400 : 0);
					}).catch(function (err) {
						bulkCells.forEach(function (c, i) {
							if (!c.isConnected) return;
							c.classList.remove('ml-cell-saving');
							setCellText(c, savedTexts[i]);
							flashCell(c, false);
						});
						if (td.isConnected) {
							td.title = (err && err.message) || labels.error || 'Network error';
						}
					});
					return;
				}

				if (!isMultilang && primaryValue === original) { teardown(); return; }

				teardown();
				// Optimistic update — resolve placeholders client-side
				// so the cell shows the actual stored value during the
				// AJAX round-trip, not the raw "(n) of (N)" template.
				// Single save → n=1, total=1; pageTitle / pageName come
				// from the row's data attrs. Tags skip resolution per
				// the server-side rule.
				var trSingle = td.closest('tr');
				var singleCtx = {
					n: 1, total: 1,
					pageTitle: trSingle ? (trSingle.dataset.pageTitle || '') : '',
					pageName:  trSingle ? (trSingle.dataset.pageName  || '') : '',
					date:      todayIso,
					field:     td.dataset.field || ''
				};
				if (!typedInput) {
					// Typed cells display a glyph / label, not the raw value
					// — skip the optimistic text and let the save response
					// set the real display.
					setCellText(td, (td.dataset.subfield === 'tags')
						? primaryValue
						: resolveTemplateClient(primaryValue, singleCtx));
				}
				td.classList.add('ml-cell-saving');
				td.title = labels.saving || 'Saving…';

				enqueueSave(td.dataset.pageId, function () {
					var payload = {
						pageId:    td.dataset.pageId,
						fieldName: td.dataset.field,
						basename:  td.dataset.basename,
						subfield:  td.dataset.subfield,
						value:     primaryValue
					};
					if (langValuesJson) payload.langValues = langValuesJson;
					return postSave(payload);
				}).then(function (result) {
					if (!td.isConnected) return;
					td.classList.remove('ml-cell-saving');
					if (result && result.data && result.data.ok) {
						setCellText(td, result.data.value);
						// Mode-3 tag save may have promoted new tags into the
						// field's predefined list — reflect it on open cells.
						if (result.data.tagsAllowed) {
							applyTagsAllowed(result.data.field, result.data.tagsAllowed);
						}
						// Typed cells: refresh data-value so reopening the
						// editor shows the freshly-stored raw value.
						if (typedInput && result.data.rawValue !== undefined) {
							td.dataset.value = String(result.data.rawValue);
						}
						// Refresh the per-language data attrs from the
						// post-save state so reopening the popup shows
						// the fresh value in every tab, not the stale
						// pre-save text.
						if (result.data.langValues && typeof result.data.langValues === 'object') {
							Object.keys(result.data.langValues).forEach(function (lid) {
								td.setAttribute('data-lang-' + lid, String(result.data.langValues[lid]));
							});
						}
						td.title = '';
						flashCell(td, true);
						fadeRowIfMismatched(td, result.data);
					} else {
						if (!typedInput) setCellText(td, original);
						var reason = (result && result.data && result.data.error)
							|| ('HTTP ' + (result && result.status));
						console.error('[ImageLibrary] save failed:', result);
						td.title = reason;
						flashCell(td, false);
					}
				}).catch(function (err) {
					if (!td.isConnected) return;
					td.classList.remove('ml-cell-saving');
					if (!typedInput) setCellText(td, original);
					console.error('[ImageLibrary] save errored:', err);
					td.title = (err && err.message) || labels.error || 'Network error';
					flashCell(td, false);
				});
			}

			cancelBtn.addEventListener('click', cancel);
			saveBtn.addEventListener('click', function () { commit(getBatchMode()); });

			// Enter commits — except inside a <textarea>, where plain
			// Enter inserts a newline and only Ctrl/Cmd+Enter saves.
			dialog.addEventListener('keydown', function (e) {
				if (e.key !== 'Enter') return;
				var inTextarea = e.target && e.target.tagName === 'TEXTAREA';
				if (inTextarea && !(e.ctrlKey || e.metaKey)) return;
				e.preventDefault();
				commit(getBatchMode());
			});

			// Native <dialog> fires "close" on Esc + dialog.close(). Any
			// non-committed close is a cancel so the cell can't get
			// stuck in ml-editing.
			dialog.addEventListener('close', function () {
				if (!committed) cancel();
			});

			dialog.showModal();
			widget.focus();
		}

		// Server-side, our module hooks InputfieldImage::renderItem
		// and suppresses every file whose hash doesn't match the
		// ml_focus_hash GET param, so the iframe arrives with only
		// one gridImage. All this function still does is auto-expand
		// the per-image action panel (Crop / Focus / Variations /
		// Actions + Description / Tags / Customs) so the user lands
		// directly in the editor instead of a collapsed thumbnail.
		function focusSingleImage(doc, fileHash) {
			if (!fileHash) return;
			var target = doc.getElementById('file_' + fileHash);
			if (!target || target.classList.contains('gridImage--text-open')) return;
			var trigger = target.querySelector('.gridImage__inner')
				|| target.querySelector('.InputfieldFileLink')
				|| target;
			if (trigger && trigger.click) trigger.click();
		}

		// Open PW's per-image action panel (Crop / Focus / Variations
		// / metadata fields) in a modal iframe. The page-edit form
		// is scoped to one image field via fields=…; our module's
		// InputfieldImage::renderItem hook reads ml_focus_hash and
		// renders only the one matching file (other Pagefiles in the
		// field don't even hit thumbnail generation). modal=1 strips
		// the admin chrome.
		function openImageEditor(td) {
			if (!config.adminUrl) return;
			var pageId    = td.dataset.pageId;
			var fieldName = td.dataset.field;
			var basename  = td.dataset.basename || '';
			var fileHash  = td.dataset.fileHash || '';
			if (!pageId || !fieldName || !basename || !fileHash) return;

			var url = config.adminUrl + 'page/edit/'
				+ '?id=' + encodeURIComponent(pageId)
				+ '&fields=' + encodeURIComponent(fieldName)
				+ '&modal=1'
				+ '&ml_focus_hash=' + encodeURIComponent(fileHash);

			var dialog = document.createElement('dialog');
			dialog.className = 'ml-image-modal';

			var bar = document.createElement('header');
			bar.className = 'ml-image-modal-bar';
			var title = document.createElement('span');
			title.className = 'ml-image-modal-title';
			var titleTpl = (labels.imageEditorTitle || 'Edit image: %s');
			title.textContent = titleTpl.replace('%s', basename);
			var closeBtn = document.createElement('button');
			closeBtn.type = 'button';
			closeBtn.className = 'ml-image-modal-close uk-button uk-button-secondary';
			closeBtn.textContent = labels.close || 'Close';
			bar.appendChild(title);
			bar.appendChild(closeBtn);

			var iframe = document.createElement('iframe');
			iframe.src = url;
			iframe.className = 'ml-image-modal-iframe';

			// Re-filter on every (re)load — PW's grid scripts may
			// touch the DOM after our first pass, and a save inside
			// the iframe triggers a full reload that drops our hiding.
			iframe.addEventListener('load', function () {
				try {
					var win = iframe.contentWindow;
					var doc = iframe.contentDocument;
					if (!win || !doc) return;
					// PW redirects to $page->editUrl() after save and
					// drops our extra GET — without re-applying it,
					// the next render would show every file again.
					// One throwaway reload re-arms the server filter.
					var search = win.location.search || '';
					if (search.indexOf('ml_focus_hash=' + fileHash) === -1) {
						var sep = search ? '&' : '?';
						var newHref = win.location.pathname + search + sep
							+ 'ml_focus_hash=' + encodeURIComponent(fileHash);
						win.location.replace(newHref);
						return;
					}
					focusSingleImage(doc, fileHash);
				} catch (e) {
					// Cross-origin guard — shouldn't trip for same-origin admin.
				}
			});

			dialog.appendChild(bar);
			dialog.appendChild(iframe);
			document.body.appendChild(dialog);

			closeBtn.addEventListener('click', function () {
				if (dialog.open) dialog.close();
			});
			dialog.addEventListener('close', function () {
				dialog.remove();
				// Nested modals in the PW edit iframe (Variations →
				// per-variation detail, etc.) sometimes leak
				// overflow:hidden + a scrollbar-compensation padding
				// onto the TOP-level <body> via parent.document
				// targeting. Their close handlers clear the inner
				// iframe's body but not ours, so the library page
				// stays locked. Wipe both side effects here — safe
				// because nothing on our admin page legitimately
				// needs the body locked once our modal is gone.
				if (document.body.style.overflow === 'hidden') {
					document.body.style.overflow = '';
				}
				if (document.body.style.paddingRight) {
					document.body.style.paddingRight = '';
				}
				// Always refresh — the user might have saved inside the
				// iframe before closing. A no-op refresh is cheap.
				replaceFromQs(location.search, false);
			});

			dialog.showModal();
		}

		// Masonry: a duplicated image is one tile with no inline editing. Clicking
		// it opens a modal whose body is the cluster-table endpoint's output — the
		// normal editable table, limited to this image's copies. The dialog is
		// appended INSIDE .ml-results so the existing delegated edit / thumb-open
		// handlers fire for the rows inside it without any re-binding.
		function openClusterModal(td) {
			if (!config.clusterUrl) return;
			var pid = td.dataset.clusterPid, field = td.dataset.clusterField, base = td.dataset.clusterBase || '';
			if (!pid || !field || !base) return;

			var sep = location.search ? '&' : '?';
			var url = config.clusterUrl + location.search + sep
				+ 'cpid='   + encodeURIComponent(pid)
				+ '&cfield=' + encodeURIComponent(field)
				+ '&cbase='  + encodeURIComponent(base);

			var dialog = document.createElement('dialog');
			dialog.className = 'ml-cluster-modal';

			var bar = document.createElement('header');
			bar.className = 'ml-image-modal-bar';
			var title = document.createElement('span');
			title.className = 'ml-image-modal-title';
			title.textContent = base;
			var closeBtn = document.createElement('button');
			closeBtn.type = 'button';
			closeBtn.className = 'ml-image-modal-close uk-button uk-button-secondary';
			closeBtn.textContent = labels.close || 'Close';
			bar.appendChild(title);
			bar.appendChild(closeBtn);

			var body = document.createElement('div');
			body.className = 'ml-cluster-modal-body';
			body.textContent = labels.loading || 'Loading…';

			dialog.appendChild(bar);
			dialog.appendChild(body);
			(results || document.body).appendChild(dialog);

			closeBtn.addEventListener('click', function () { if (dialog.open) dialog.close(); });
			// Click on the backdrop (outside the dialog box) closes it.
			dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });
			dialog.addEventListener('close', function () {
				dialog.remove();
				// A copy may have been edited / deleted inside — refresh the view.
				replaceFromQs(location.search, false);
			});

			dialog.showModal();

			fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
				.then(function (r) { return r.text(); })
				.then(function (html) {
					body.innerHTML = html;
					// The fresh table must honour the user's column prefs the same
					// way replaceFromQs does (both appliers query the whole document,
					// so they reach the modal table too).
					if (root._mlApplyColumnVisibility) root._mlApplyColumnVisibility();
					if (root._mlApplyColumnOrder) root._mlApplyColumnOrder();
				})
				.catch(function () { body.textContent = labels.error || 'Error'; });
		}

		// -- Replace image (click icon + drag-and-drop) ----------------
		// Two entry paths share the same /replace/ endpoint:
		//   1) The per-row Replace icon opens a hidden file picker.
		//   2) Dragging a file from the OS onto a row drops it onto
		//      that row's (pageId, field, basename) target.
		// Constraints: single file per drop, extension must match the
		// existing image's (server enforces too; client guards for a
		// nicer error). Editable rows only — non-editable rows have
		// no data-page-id, so the handlers never resolve a target.

		// A "row" is the table <tr> OR a masonry tile — both carry
		// .ml-row + the identity data-attrs when the host page is
		// editable, so replace / delete / drag resolve either the same way.
		function isEditableRow(tr) {
			return tr && tr.matches && tr.matches('.ml-row[data-page-id]');
		}

		function rowExt(tr) {
			var bn = tr.getAttribute('data-basename') || '';
			var dot = bn.lastIndexOf('.');
			return dot === -1 ? '' : bn.slice(dot + 1).toLowerCase();
		}

		function fileExt(f) {
			var name = (f && f.name) || '';
			var dot = name.lastIndexOf('.');
			return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
		}

		function replaceImage(tr, file) {
			if (!config.replaceUrl || !tr || !file) return;
			var pageId = tr.getAttribute('data-page-id');
			var field  = tr.getAttribute('data-field');
			var basename = tr.getAttribute('data-basename');
			if (!pageId || !field || !basename) return;

			// Client-side extension guard — server enforces too, but
			// failing fast saves an upload round trip.
			if (rowExt(tr) !== fileExt(file)) {
				announce(
					(labels.error || 'Replace failed') + ': '
					+ 'Extension mismatch (' + rowExt(tr) + ' vs ' + fileExt(file) + ')'
				);
				return;
			}

			tr.classList.add('ml-row-uploading');

			var fd = new FormData();
			fd.append('pageId', pageId);
			fd.append('fieldName', field);
			fd.append('basename', basename);
			fd.append('file', file);
			appendCsrf(fd);

			fetch(config.replaceUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
				body: fd
			}).then(function (res) {
				return res.json().catch(function () { return { ok: false, error: 'HTTP ' + res.status }; });
			}).then(function (data) {
				if (!data || !data.ok) {
					announce((labels.error || 'Replace failed') + (data && data.error ? ': ' + data.error : ''));
					return;
				}
				// Use the server-resolved thumb URL directly — it points
				// at the freshly-regenerated variation (the old one was
				// wiped by removeVariations) and carries the cache-bust
				// parameter so the browser doesn't reuse the old bytes.
				var img = tr.querySelector('.ml-cell-thumb img');
				if (img && data.thumbUrl) {
					img.src = data.thumbUrl;
				}
				// Patch the read-only metadata cells in place so the
				// row reflects the new file without a full table reload.
				// Description / tags / customs stay because the Pagefile
				// metadata is preserved across a replace.
				function patch(col, val) {
					if (val === undefined || val === null) return;
					var cell = tr.querySelector('[data-col="' + col + '"]');
					if (cell) cell.textContent = String(val);
				}
				patch('dimensions', data.dimensions);
				patch('size',       data.filesizeFormatted);
				patch('modified',   data.modifiedFormatted);
				patch('variations', data.variationsCount);
				announce(labels.saved || 'Saved');
			}).catch(function (err) {
				announce((labels.error || 'Replace failed') + ': ' + (err && err.message ? err.message : 'network error'));
			}).finally(function () {
				tr.classList.remove('ml-row-uploading');
			});
		}

		// Hidden file input — created once, reused for every click on
		// .ml-replace-btn. We re-target it (data-current-row) right
		// before opening so the change handler knows which row asked.
		var replaceFileInput = document.createElement('input');
		replaceFileInput.type = 'file';
		replaceFileInput.style.display = 'none';
		replaceFileInput.addEventListener('change', function () {
			var tr = replaceFileInput._mlRow;
			replaceFileInput._mlRow = null;
			if (tr && replaceFileInput.files && replaceFileInput.files[0]) {
				replaceImage(tr, replaceFileInput.files[0]);
			}
			replaceFileInput.value = '';
		});
		root.appendChild(replaceFileInput);

		// Click delegation on the results region — survives AJAX
		// swaps that replace .ml-results innerHTML.
		results && results.addEventListener('click', function (e) {
			var btn = e.target.closest && e.target.closest('.ml-replace-btn');
			if (!btn) return;
			// stopImmediatePropagation so the sibling delegated click
			// handler (below in this file) doesn't ALSO interpret this
			// click as a thumb activation and open the image editor.
			e.preventDefault();
			e.stopImmediatePropagation();
			var tr = btn.closest('.ml-row');
			if (!isEditableRow(tr)) return;
			// Hint to the OS picker: filter to extensions matching the
			// row's existing file. The user can still override but it
			// reduces accidental wrong-format picks.
			var ext = rowExt(tr);
			replaceFileInput.accept = ext ? '.' + ext : '';
			replaceFileInput._mlRow = tr;
			replaceFileInput.click();
		});

		// Drag-and-drop on rows. dataTransfer.types is a DOMStringList in
		// some engines (no Array methods), so we iterate by index.
		function dragHasFiles(e) {
			if (!e.dataTransfer) return false;
			var types = e.dataTransfer.types;
			if (!types) return false;
			for (var i = 0; i < types.length; i++) {
				if (types[i] === 'Files') return true;
			}
			return false;
		}
		// Global dragover preventDefault keeps the browser from opening
		// the file when the user drops outside a drop zone, AND is the
		// signal that lets `drop` events fire on descendants (HTML5
		// spec: drop only fires if the preceding dragover wasn't the
		// default-action one). Belt-and-suspenders: results gets its
		// own dragover listener too so dropEffect can be set explicitly.
		document.addEventListener('dragover', function (e) {
			if (dragHasFiles(e)) e.preventDefault();
		});
		document.addEventListener('drop', function (e) {
			// Catch anything that wasn't claimed by a row listener so the
			// browser doesn't navigate to the dropped file.
			if (dragHasFiles(e)) e.preventDefault();
		});
		if (results) {
			results.addEventListener('dragover', function (e) {
				if (!dragHasFiles(e)) return;
				e.preventDefault();
				var tr = e.target.closest && e.target.closest('.ml-row');
				if (e.dataTransfer) {
					e.dataTransfer.dropEffect = isEditableRow(tr) ? 'copy' : 'none';
				}
			});
			results.addEventListener('dragenter', function (e) {
				if (!dragHasFiles(e)) return;
				var tr = e.target.closest && e.target.closest('.ml-row');
				if (!isEditableRow(tr)) return;
				tr.classList.add('ml-row-drop-target');
			});
			results.addEventListener('dragleave', function (e) {
				var tr = e.target.closest && e.target.closest('.ml-row');
				if (!tr) return;
				// dragleave fires when crossing child boundaries too —
				// only drop the highlight when the pointer actually
				// exits the row.
				if (e.relatedTarget && tr.contains(e.relatedTarget)) return;
				tr.classList.remove('ml-row-drop-target');
			});
			results.addEventListener('drop', function (e) {
				if (!dragHasFiles(e)) return;
				e.preventDefault();
				e.stopPropagation();
				var tr = e.target.closest && e.target.closest('.ml-row');
				if (!isEditableRow(tr)) return;
				tr.classList.remove('ml-row-drop-target');
				if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
				if (e.dataTransfer.files.length > 1) {
					announce((labels.error || 'Replace failed') + ': only one file at a time');
					return;
				}
				replaceImage(tr, e.dataTransfer.files[0]);
			});
		}

		// -- Delete image (click icon + paintbrush on selection) -------
		// Single-row: click the per-row trash icon → confirm dialog
		// listing the one filename → POST.
		// Batch (paintbrush): when N rows are selected AND the click
		// landed on a selected row's trash icon, ALL selected items
		// are deleted (across pages too, since selection persists).
		// Always behind a confirm dialog — destructive, no undo.

		function rowItem(tr) {
			return tr ? {
				pageId:    tr.getAttribute('data-page-id'),
				fieldName: tr.getAttribute('data-field'),
				basename:  tr.getAttribute('data-basename')
			} : null;
		}

		function itemKey(item) {
			return item.pageId + ':' + item.fieldName + ':' + item.basename;
		}

		function openDeleteConfirm(items, onConfirm) {
			var dialog = document.createElement('dialog');
			dialog.className = 'ml-delete-confirm';

			var header = document.createElement('header');
			header.textContent = items.length === 1
				? (labels.deleteOne || 'Delete this image?')
				: (labels.deleteMany || 'Delete %d images?').replace('%d', items.length);
			dialog.appendChild(header);

			var intro = document.createElement('p');
			intro.textContent = items.length === 1
				? (labels.deleteOneIntro || 'The following file will be permanently removed:')
				: (labels.deleteManyIntro || 'The following files will be permanently removed:');
			dialog.appendChild(intro);

			// One file → render the basename inline as a code block,
			// no list bullet (a single-item bulleted list looks daft).
			// Many files → bulleted list, capped at 8 with "+N more".
			if (items.length === 1) {
				var solo = document.createElement('p');
				solo.className = 'ml-delete-confirm-solo';
				solo.textContent = items[0].basename;
				dialog.appendChild(solo);
			} else {
				var list = document.createElement('ul');
				list.className = 'ml-delete-confirm-list';
				var show = Math.min(items.length, 8);
				for (var i = 0; i < show; i++) {
					var li = document.createElement('li');
					li.textContent = items[i].basename;
					list.appendChild(li);
				}
				dialog.appendChild(list);
				// "+N more" is a sibling of the <ul>, never an <li>
				// inside it — otherwise the list's overflow scroll
				// can hide the line behind the scrollbar (the whole
				// point of the indicator is to STAY visible).
				if (items.length > show) {
					var more = document.createElement('p');
					more.className = 'ml-delete-confirm-list-more';
					more.textContent = '… +' + (items.length - show) + ' more';
					dialog.appendChild(more);
				}
			}

			// One slot below a divider that resolves to EITHER the
			// where-used list (when refs exist) OR the "this cannot
			// be undone" warning (when none do). The two carry the
			// same urgency — refs are inherently a "by the way, this
			// will break" message — so they take turns in the same
			// position rather than stacking on each other. Filled
			// once the usage preflight resolves; hidden until then.
			var tailBlock = document.createElement('div');
			tailBlock.className = 'ml-delete-confirm-usage';
			tailBlock.hidden = true;
			dialog.appendChild(tailBlock);

			var footer = document.createElement('footer');
			var cancelBtn = document.createElement('button');
			cancelBtn.type = 'button';
			cancelBtn.className = 'uk-button uk-button-secondary';
			cancelBtn.textContent = labels.cancel || 'Cancel';
			var okBtn = document.createElement('button');
			okBtn.type = 'button';
			okBtn.className = 'uk-button uk-button-primary';
			okBtn.textContent = labels.deleteOk || 'Delete';
			footer.appendChild(cancelBtn);
			footer.appendChild(okBtn);
			dialog.appendChild(footer);

			document.body.appendChild(dialog);

			function cleanup() {
				if (dialog.open) dialog.close();
				dialog.remove();
			}
			cancelBtn.addEventListener('click', cleanup);
			dialog.addEventListener('close', function () { dialog.remove(); });
			okBtn.addEventListener('click', function () {
				cleanup();
				onConfirm();
			});

			dialog.showModal();
			okBtn.focus();

			fetchUsage(items).then(function (usage) {
				renderUsageBlock(tailBlock, items, usage);
			});
		}

		// POST the (pageId, basename) tuples to /usage/, return the
		// { "pid:basename": [refs] } map. On any failure resolves to
		// {} so the dialog still works — usage is advisory, not a gate.
		function fetchUsage(items) {
			if (!config.usageUrl) return Promise.resolve({});
			var payload = items.map(function (i) {
				return { pageId: i.pageId, basename: i.basename };
			});
			var fd = new FormData();
			fd.append('items', JSON.stringify(payload));
			appendCsrf(fd);
			return fetch(config.usageUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
				body: fd
			}).then(function (res) {
				return res.json().catch(function () { return null; });
			}).then(function (data) {
				return (data && data.ok && data.usage) ? data.usage : {};
			}).catch(function () { return {}; });
		}

		function renderUsageBlock(holder, items, usage) {
			holder.textContent = '';
			var groups = [];
			items.forEach(function (it) {
				var key  = it.pageId + ':' + it.basename;
				var refs = usage[key] || [];
				if (refs.length) groups.push({ item: it, refs: refs });
			});

			// No refs → fall back to the bare "this cannot be undone"
			// warning in the same slot. Same red 600-weight heading
			// style as the usage-h so the urgency reads consistently.
			if (!groups.length) {
				var w = document.createElement('p');
				w.className = 'ml-delete-confirm-usage-h';
				w.textContent = labels.deleteWarn || 'This cannot be undone.';
				holder.appendChild(w);
				holder.hidden = false;
				return;
			}

			var h = document.createElement('p');
			h.className = 'ml-delete-confirm-usage-h';
			h.textContent = labels.usageHeading || 'Still referenced in rich-text fields:';
			holder.appendChild(h);

			var ul = document.createElement('ul');
			ul.className = 'ml-delete-confirm-usage-list';

			// Single-item delete → the dialog already names the file
			// above, so the refs render as a flat list with no per-file
			// header (otherwise the basename appears twice for no
			// reason). Multi-item delete keeps the per-file grouping so
			// the editor can tell which broadcast target carries which
			// embed.
			if (items.length === 1) {
				groups[0].refs.forEach(function (r) {
					var li = document.createElement('li');
					li.appendChild(buildUsageRef(r));
					ul.appendChild(li);
				});
			} else {
				ul.classList.add('ml-delete-confirm-usage-list-grouped');
				groups.forEach(function (g) {
					var li = document.createElement('li');
					var b  = document.createElement('strong');
					b.textContent = g.item.basename;
					li.appendChild(b);
					var sub = document.createElement('ul');
					g.refs.forEach(function (r) {
						var sli = document.createElement('li');
						sli.appendChild(buildUsageRef(r));
						sub.appendChild(sli);
					});
					li.appendChild(sub);
					ul.appendChild(li);
				});
			}
			holder.appendChild(ul);
			holder.hidden = false;
		}

		function buildUsageRef(r) {
			var fmt = labels.usageFieldFmt || '“%1$s” · %2$s';
			var label = fmt.replace('%1$s', r.pageTitle).replace('%2$s', r.fieldName);
			if (r.editUrl) {
				var a = document.createElement('a');
				a.href   = r.editUrl;
				a.target = '_blank';
				a.rel    = 'noopener';
				a.textContent = label;
				return a;
			}
			return document.createTextNode(label);
		}

		// "Used in" column → dialog listing every page (and the fields on it)
		// that embeds this image in rich-text. Content-based: the server
		// aggregates across the image's whole dedup cluster (usage-detail
		// endpoint), so the list matches the badge count regardless of which
		// placement was clicked. Same chrome as the other dialogs: header
		// title, scrollable body, Close button in a bottom footer.
		function openUsageDialog(pageId, field, basename) {
			var dialog = document.createElement('dialog');
			dialog.className = 'ml-usage-dialog';

			var header = document.createElement('header');
			// The label travels through the JS-config JSON; show a literal "&"
			// rather than an escaped "&amp;".
			header.textContent = (labels.usedInTitle || 'Embedded on these pages & fields')
				.replace(/&amp;/g, '&');
			dialog.appendChild(header);

			var body = document.createElement('div');
			body.className = 'ml-usage-dialog-body';
			body.textContent = labels.usedInLoading || 'Loading…';
			dialog.appendChild(body);

			var footer = document.createElement('footer');
			var closeBtn = document.createElement('button');
			closeBtn.type = 'button';
			closeBtn.className = 'uk-button uk-button-secondary';
			closeBtn.textContent = labels.close || 'Close';
			footer.appendChild(closeBtn);
			dialog.appendChild(footer);

			document.body.appendChild(dialog);
			closeBtn.addEventListener('click', function () { if (dialog.open) dialog.close(); });
			dialog.addEventListener('close', function () { dialog.remove(); });
			dialog.showModal();
			closeBtn.focus();

			if (!config.usageDetailUrl) { body.textContent = labels.usedInEmpty || ''; return; }
			var fd = new FormData();
			fd.append('pageId', pageId);
			fd.append('field', field || '');
			fd.append('basename', basename);
			appendCsrf(fd);
			fetch(config.usageDetailUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
				body: fd
			}).then(function (r) {
				return r.json().catch(function () { return null; });
			}).then(function (d) {
				body.textContent = '';
				var pages = (d && d.ok && d.pages) || [];
				if (!pages.length) {
					var p = document.createElement('p');
					p.className = 'ml-usage-dialog-empty';
					p.textContent = labels.usedInEmpty || 'Not embedded in any rich-text field.';
					body.appendChild(p);
					return;
				}
				// Reuse the existing where-used list styling (delete/rename dialogs).
				var ul = document.createElement('ul');
				ul.className = 'ml-delete-confirm-usage-list';
				pages.forEach(function (r) {
					var li = document.createElement('li');
					li.appendChild(buildUsageRef(r));
					ul.appendChild(li);
				});
				body.appendChild(ul);
			}).catch(function () { body.textContent = labels.usedInEmpty || ''; });
		}

		// Delegated on .ml-results so it survives the innerHTML swaps on
		// filter / sort / pagination.
		results && results.addEventListener('click', function (e) {
			var link = e.target.closest && e.target.closest('.ml-usage-link');
			if (!link) return;
			e.preventDefault();
			openUsageDialog(
				link.getAttribute('data-page-id'),
				link.getAttribute('data-field'),
				link.getAttribute('data-basename')
			);
		});

		// Post-rename summary. The rename already happened and every
		// rich-text embed of the file was rewritten server-side; this
		// dialog confirms the new filename and lists the references that
		// were updated (with links), so the editor can see what changed.
		// Only shown when at least one embed was rewritten — a plain
		// rename just flashes the cell green.
		// Where-used preflight for rename (advisory, mirrors the delete confirm).
		// If any item is still embedded by its current basename, show a confirm
		// dialog listing the pages so the editor knows before committing; with no
		// embeds it proceeds straight away (no dialog).
		function confirmRename(items, onConfirm) {
			fetchUsage(items).then(function (usage) {
				var hasRefs = items.some(function (it) {
					return (usage[it.pageId + ':' + it.basename] || []).length > 0;
				});
				if (!hasRefs) { onConfirm(); return; }

				var dialog = document.createElement('dialog');
				dialog.className = 'ml-delete-confirm';

				var header = document.createElement('header');
				header.textContent = labels.renameUsageTitle
					|| 'Heads up — still embedded in other pages';
				dialog.appendChild(header);

				var holder = document.createElement('div');
				holder.className = 'ml-delete-confirm-usage';
				dialog.appendChild(holder);
				renderUsageBlock(holder, items, usage);

				var footer = document.createElement('footer');
				var cancelBtn = document.createElement('button');
				cancelBtn.type = 'button';
				cancelBtn.className = 'uk-button uk-button-secondary';
				cancelBtn.textContent = labels.cancel || 'Cancel';
				var okBtn = document.createElement('button');
				okBtn.type = 'button';
				okBtn.className = 'uk-button uk-button-primary';
				okBtn.textContent = labels.renameAnyway || 'Rename anyway';
				footer.appendChild(cancelBtn);
				footer.appendChild(okBtn);
				dialog.appendChild(footer);

				document.body.appendChild(dialog);
				function cleanup() { if (dialog.open) dialog.close(); dialog.remove(); }
				cancelBtn.addEventListener('click', cleanup);
				dialog.addEventListener('close', function () { dialog.remove(); });
				okBtn.addEventListener('click', function () { cleanup(); onConfirm(); });
				dialog.showModal();
				okBtn.focus();
			});
		}

		function renameSummaryDialog(oldBasename, newBasename, refs) {
			var dialog = document.createElement('dialog');
			dialog.className = 'ml-delete-confirm';

			var header = document.createElement('header');
			header.textContent = labels.renameDoneTitle || 'Renamed';
			dialog.appendChild(header);

			// old → new, in the same monospace chip the delete dialog uses.
			var line = document.createElement('p');
			line.className = 'ml-delete-confirm-solo';
			line.textContent = oldBasename + '  →  ' + newBasename;
			dialog.appendChild(line);

			var holder = document.createElement('div');
			holder.className = 'ml-delete-confirm-usage';
			var h = document.createElement('p');
			// Neutral heading (not the red warning style) — this is a
			// success summary, nothing went wrong.
			h.className = 'ml-rename-done-usage-h';
			h.textContent = labels.embedsUpdatedHeading || 'Updated embedded references:';
			holder.appendChild(h);
			var ul = document.createElement('ul');
			ul.className = 'ml-delete-confirm-usage-list';
			refs.forEach(function (r) {
				var li = document.createElement('li');
				li.appendChild(buildUsageRef(r));
				ul.appendChild(li);
			});
			holder.appendChild(ul);
			dialog.appendChild(holder);

			var footer = document.createElement('footer');
			var okBtn = document.createElement('button');
			okBtn.type = 'button';
			okBtn.className = 'uk-button uk-button-secondary';
			okBtn.textContent = labels.close || 'Close';
			footer.appendChild(okBtn);
			dialog.appendChild(footer);

			document.body.appendChild(dialog);
			function cleanup() { if (dialog.open) dialog.close(); dialog.remove(); }
			okBtn.addEventListener('click', cleanup);
			dialog.addEventListener('close', function () { dialog.remove(); });
			dialog.showModal();
			okBtn.focus();
		}

		// Build a (data-key → <tr>) map once so we don't have to escape
		// arbitrary basenames into CSS attribute selectors. Refreshed
		// per call since AJAX swaps can replace .ml-results contents.
		function rowsByKey() {
			var map = {};
			if (!results) return map;
			results.querySelectorAll('.ml-select-row').forEach(function (cb) {
				var tr = cb.closest('.ml-row');
				if (cb.dataset.key && tr) map[cb.dataset.key] = tr;
			});
			return map;
		}

		function deleteItems(items) {
			if (!config.deleteUrl || !items.length) return;
			var fd = new FormData();
			fd.append('items', JSON.stringify(items));
			appendCsrf(fd);
			// Mark rows about to be deleted so they fade out — the
			// actual removal happens once the server reports back
			// which ones succeeded.
			var keys = items.map(itemKey);
			var map  = rowsByKey();
			keys.forEach(function (k) {
				if (map[k]) map[k].classList.add('ml-row-uploading');
			});

			fetch(config.deleteUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
				body: fd
			}).then(function (res) {
				return res.json().catch(function () { return { ok: false, error: 'HTTP ' + res.status }; });
			}).then(function (data) {
				// Drop the uploading state regardless — the rows we
				// actually keep should look normal again.
				keys.forEach(function (k) {
					if (map[k]) map[k].classList.remove('ml-row-uploading');
				});
				if (!data || !data.ok) {
					announce((labels.error || 'Delete failed') + (data && data.error ? ': ' + data.error : ''));
					return;
				}
				var succeeded = Array.isArray(data.succeeded) ? data.succeeded : [];
				var failed    = Array.isArray(data.failed)    ? data.failed    : [];
				// Fade-out + remove for every server-confirmed row,
				// drop from the persistent selection set as we go.
				succeeded.forEach(function (k) {
					selection.delete(k);
					var tr = map[k];
					if (!tr) return;
					tr.classList.add('ml-row-deleting');
					setTimeout(function () { tr.remove(); syncSelectAllHeader(); }, 220);
				});
				syncSelectAllHeader();
				if (failed.length) {
					// Reuse the bulk-result dialog for failure detail.
					showBulkResult(
						(labels.deletePartial || 'Deleted %d, %d failed')
							.replace('%d', succeeded.length).replace('%d', failed.length),
						failed
					);
				} else {
					announce(
						(labels.deleted || 'Deleted %d').replace('%d', succeeded.length)
					);
				}
				// Deleting a copy can change a duplicate cluster (a 2× pair
				// becomes unique) — the dup indicator / accordion is computed
				// server-side from the current set, so re-render to recompute
				// it. Deferred so the row fade-out plays first.
				if (succeeded.length) {
					setTimeout(function () { replaceFromQs(location.search, false); }, 280);
				}
			}).catch(function (err) {
				keys.forEach(function (k) {
					if (map[k]) map[k].classList.remove('ml-row-uploading');
				});
				announce((labels.error || 'Delete failed') + ': ' + (err && err.message ? err.message : 'network error'));
			});
		}

		results && results.addEventListener('click', function (e) {
			var btn = e.target.closest && e.target.closest('.ml-delete-btn');
			if (!btn) return;
			e.preventDefault();
			e.stopImmediatePropagation();
			var tr = btn.closest('.ml-row');
			if (!isEditableRow(tr)) return;
			var rowItm = rowItem(tr);
			if (!rowItm || !rowItm.pageId) return;

			// Paintbrush: if N rows are selected AND this row is in the
			// selection, treat the click as a batch on the selection.
			// Otherwise it's a single-row delete.
			var thisKey = itemKey(rowItm);
			var items;
			if (selection.size > 0 && selection.has(thisKey)) {
				items = selectionItems();
			} else {
				items = [rowItm];
			}
			openDeleteConfirm(items, function () { deleteItems(items); });
		});

		// -- AJAX re-render --------------------------------------------

		function replaceFromHref(href) {
			var u = new URL(href, location.href);
			replaceFromQs(u.search, true);
		}

		function replaceFromQs(qs, push, clearSelection) {
			// Picker mode: carry the picker context on every AJAX render
			// (pagination, sort, view switch, filters) so the results endpoint
			// keeps rendering picker checkboxes — without this, switching to
			// masonry and back loses them.
			if (root.dataset.picker === '1') {
				var pp = new URLSearchParams((qs || '').replace(/^\?/, ''));
				pp.set('picker', '1');
				pp.set('target_page',  root.dataset.targetPage || '');
				pp.set('target_field', root.dataset.targetField || '');
				if (root.dataset.pickMode) pp.set('pick_mode', root.dataset.pickMode);
				qs = '?' + pp.toString();
			}
			if (!config.renderUrl || !results) {
				// Degraded path: full reload to the URL with the query string.
				location.href = location.pathname + qs;
				return;
			}
			if (isReplacing) return;
			isReplacing = true;
			results.classList.add('ml-loading');

			fetch(config.renderUrl + qs, {
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			}).then(function (res) {
				if (!res.ok) throw new Error('HTTP ' + res.status);
				return res.text();
			}).then(function (html) {
				results.innerHTML = html;
				// Filter Apply / Reset / Bookmark change the result SET, so
				// they pass clearSelection=true to start a fresh selection.
				// Same-set view changes (pagination, page size, sort) and
				// in-place refreshes (bulk / rename / import / image-editor
				// close) keep the cross-page selection intact — that's the
				// selection-as-paintbrush across pages.
				if (clearSelection) selection.clear();
				// New rows = new checkboxes; restore checked state from the
				// persistent selection Set so it survives the swap.
				syncCheckboxes();
				// New cells = lost ml-col-hidden classes; re-apply the
				// user's column visibility prefs to the swapped DOM.
				if (root._mlApplyColumnVisibility) root._mlApplyColumnVisibility();
				if (root._mlApplyColumnOrder) root._mlApplyColumnOrder();
				// Re-rendered pagination row → re-sync the thumb-size slider.
				if (root._mlApplyThumbScale) root._mlApplyThumbScale();
				// Fresh gallery markup → (re)distribute tiles into columns.
				if (root._mlLayoutGallery) root._mlLayoutGallery();
				if (push) {
					history.pushState({ ml: qs }, '', location.pathname + qs);
				}
				if (root._mlSyncBookmarkActive) root._mlSyncBookmarkActive();
			}).catch(function (err) {
				// On network/server error, fall back to a full reload so the
				// user still gets the navigation they asked for.
				location.href = location.pathname + qs;
				throw err;
			}).finally(function () {
				results.classList.remove('ml-loading');
				isReplacing = false;
			});
		}

		// -- Bulk selection --------------------------------------------

		function syncSelectAllHeader() {
			if (!results) return;
			var head = results.querySelector('.ml-select-all');
			if (!head) return;
			var rows = results.querySelectorAll('.ml-select-row');
			var checkedRows = results.querySelectorAll('.ml-select-row:checked');
			head.checked = rows.length > 0 && rows.length === checkedRows.length;
			head.indeterminate = checkedRows.length > 0 && checkedRows.length < rows.length;
		}

		function syncCheckboxes() {
			if (!results) return;
			results.querySelectorAll('.ml-select-row').forEach(function (cb) {
				cb.checked = selection.has(cb.dataset.key);
			});
			syncSelectAllHeader();
		}

		function selectionItems() {
			return Array.from(selection).map(function (key) {
				var parts = key.split(':');
				// pageId : fieldName : basename — basename may itself contain
				// colons in pathological cases, so rejoin the tail.
				return {
					pageId:    parts[0],
					fieldName: parts[1],
					basename:  parts.slice(2).join(':')
				};
			});
		}

		function runBulk(action, extra) {
			if (isBulking || !config.bulkUrl) return Promise.reject(new Error('busy'));
			var items = selectionItems();
			if (!items.length) return Promise.reject(new Error('empty selection'));
			isBulking = true;

			var fd = new FormData();
			fd.append('action', action);
			fd.append('items', JSON.stringify(items));
			// Current filter URL so the server can report which saved rows
			// dropped out of the active filter (vanished) — parity with
			// the single-save postSave() path.
			fd.append('filterQs', location.search || '');
			if (extra) Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
			appendCsrf(fd);

			return fetch(config.bulkUrl, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin'
			}).then(function (res) {
				return res.json().then(function (data) { return { status: res.status, data: data }; });
			}).finally(function () {
				isBulking = false;
			});
		}

		// Lazy-built <dialog> for the bulk-result report. Replaces the
		// previous alert() which blocked the page and turned unreadable
		// once more than a handful of failures were involved. Both the
		// header (counts / top-level error) and the scrollable list
		// (per-row failures) update on each open; on close the dialog
		// stays in the DOM so reopening is instant. Backdrop click and
		// the close button both dismiss.
		var bulkDialog = null;
		function getBulkDialog() {
			if (bulkDialog) return bulkDialog;
			bulkDialog = document.createElement('dialog');
			bulkDialog.className = 'ml-bulk-result-dialog';
			bulkDialog.innerHTML =
				'<header class="ml-bulk-result-header"></header>'
				+ '<ul class="ml-bulk-result-list"></ul>'
				+ '<footer><button type="button" class="ml-bulk-result-close'
				+ ' uk-button uk-button-secondary">'
				+ ((labels && labels.close) ? labels.close : 'Close')
				+ '</button></footer>';
			root.appendChild(bulkDialog);
			bulkDialog.addEventListener('click', function (e) {
				if (e.target === bulkDialog
					|| (e.target.classList && e.target.classList.contains('ml-bulk-result-close'))
				) {
					bulkDialog.close();
				}
			});
			return bulkDialog;
		}
		function showBulkResult(headerText, failedLines) {
			var dlg  = getBulkDialog();
			var hdr  = dlg.querySelector('.ml-bulk-result-header');
			var list = dlg.querySelector('.ml-bulk-result-list');
			hdr.textContent = headerText;
			list.innerHTML = '';
			(failedLines || []).forEach(function (line) {
				var li = document.createElement('li');
				li.textContent = line;
				list.appendChild(li);
			});
			list.hidden = !(failedLines && failedLines.length);
			if (typeof dlg.showModal === 'function') dlg.showModal();
			else dlg.setAttribute('open', '');
		}

		function reportBulk(result) {
			var d = (result && result.data) || {};
			if (!d.ok) {
				showBulkResult(d.error || labels.error || 'Bulk action failed', []);
				return false;
			}
			// Mode-3 batches may have promoted new tags into one or more fields'
			// predefined lists ({field: [tags…]}) — reflect them on open cells.
			if (d.tagsAllowed && typeof d.tagsAllowed === 'object') {
				Object.keys(d.tagsAllowed).forEach(function (f) {
					applyTagsAllowed(f, d.tagsAllowed[f]);
				});
			}
			var failedCount = (d.failed || []).length;
			if (failedCount) {
				var msg = (labels.bulkResult || 'Succeeded: %1$d  ·  Failed: %2$d')
					.replace('%1$d', d.succeeded)
					.replace('%2$d', failedCount);
				showBulkResult(msg, d.failed);
			}
			return true;
		}

		function rowKey(td) {
			return td.dataset.pageId + ':' + td.dataset.field + ':' + td.dataset.basename;
		}

		function isBatchEdit(td) {
			return selection.size > 1 && selection.has(rowKey(td));
		}

		// Expand / collapse one duplicate cluster: show or hide every hidden
		// copy row that shares the head row's content hash. Content hashes are
		// hex, so they're safe to drop straight into the attribute selector.
		function toggleDupCluster(toggle) {
			var hash = toggle.dataset.dupHash;
			if (!hash || !results) return;
			var open = toggle.getAttribute('aria-expanded') === 'true';
			var members = results.querySelectorAll('tr.ml-dup-member[data-dup-hash="' + hash + '"]');
			members.forEach(function (tr) { tr.hidden = open; });
			toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
			toggle.classList.toggle('ml-dup-open', !open);
		}

		// -- Delegated click handler on the persistent .ml-results -----

		if (results) {
			results.addEventListener('click', function (e) {
				if (e.target && e.target.closest) {
					var sortLink = e.target.closest('.ml-th-sortable a');
					if (sortLink) {
						e.preventDefault();
						replaceFromHref(sortLink.href);
						return;
					}
					var pagLink = e.target.closest('.ml-pagination-link');
					if (pagLink) {
						e.preventDefault();
						replaceFromHref(pagLink.href);
						return;
					}
					// View-mode toggle (table / masonry). Track the choice
					// locally so saveUserPrefs doesn't clobber it, then let
					// the server render the new layout via ?view= in the href.
					var viewBtn = e.target.closest('.ml-view-btn');
					if (viewBtn) {
						e.preventDefault();
						var nextView = viewBtn.getAttribute('data-view');
						if (nextView && nextView !== viewMode) {
							viewMode = nextView;
							replaceFromHref(viewBtn.href);
						}
						return;
					}
					// Picker mode: sort / pagination / view (handled above) stay
					// live, but no editing — a thumbnail click must not open the
					// PW image editor inside the picker. The "Use" button is
					// handled by its own capture-phase listener.
					if (root.dataset.picker === '1') return;
					// Bulk-selection checkboxes handle their own state via the
					// change listener below — don't open an editor for them.
					if (e.target.classList && (
						e.target.classList.contains('ml-select-row') ||
						e.target.classList.contains('ml-select-all')
					)) return;
					// Duplicate indicator → expand / collapse the cluster's
					// hidden copy rows. Sits on the thumb cell, so handle it
					// before the thumbnail-open branch below.
					var dupToggle = e.target.closest('.ml-dup-toggle');
					if (dupToggle) {
						e.preventDefault();
						toggleDupCluster(dupToggle);
						return;
					}
					// Masonry duplicate tile → cluster modal (table of its copies).
					// Handled before the editor branch; this thumb has no
					// data-file-hash, so it never opens the per-image editor.
					var clusterTd = e.target.closest('.ml-cell-thumb.ml-dup-cluster');
					if (clusterTd) {
						e.preventDefault();
						openClusterModal(clusterTd);
						return;
					}
					// Thumbnail → PW image editor modal. The td only carries
					// the page-edit data attrs when the host page is
					// editable, so unauthorised users just see the thumb
					// without a clickable cursor.
					var nativeTd = e.target.closest('.ml-cell-thumb[data-file-hash], .ml-cell-native[data-file-hash]');
					if (nativeTd) {
						e.preventDefault();
						openImageEditor(nativeTd);
						return;
					}
					// Editable cell — but ignore clicks that landed on an
					// internal anchor (e.g. Page-link in the Page column).
					if (e.target.tagName === 'A' || e.target.closest('a')) return;
				}
				var td = e.target.closest && e.target.closest('.ml-cell-editable');
				if (!td) return;
				if (td.classList.contains('ml-editing')) return;
				activateEditor(td);
			});

			// Keyboard activation: editable cells + thumb cells carry
			// role="button" tabindex="0" server-side, so Tab focuses
			// them; Enter / Space here mirrors a mouse click.
			results.addEventListener('keydown', function (e) {
				if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
				var dupToggle = e.target.closest && e.target.closest('.ml-dup-toggle');
				if (dupToggle && e.target === dupToggle) {
					e.preventDefault();
					toggleDupCluster(dupToggle);
					return;
				}
				var clusterTd = e.target.closest && e.target.closest('.ml-cell-thumb.ml-dup-cluster');
				if (clusterTd && e.target === clusterTd) {
					e.preventDefault();
					openClusterModal(clusterTd);
					return;
				}
				var nativeTd = e.target.closest && e.target.closest('.ml-cell-thumb[data-file-hash], .ml-cell-native[data-file-hash]');
				if (nativeTd && e.target === nativeTd) {
					e.preventDefault();
					openImageEditor(nativeTd);
					return;
				}
				var editTd = e.target.closest && e.target.closest('.ml-cell-editable');
				if (editTd && e.target === editTd && !editTd.classList.contains('ml-editing')) {
					e.preventDefault();
					activateEditor(editTd);
				}
			});

			// Checkbox state changes.
			results.addEventListener('change', function (e) {
				var t = e.target;
				if (!t || !t.classList) return;
				if (t.classList.contains('ml-select-row')) {
					var key = t.dataset.key;
					if (t.checked) selection.add(key);
					else selection.delete(key);
					syncSelectAllHeader();
					// Selection drives the "Save collection" (+) affordance.
					syncBookmarkActive();
				} else if (t.classList.contains('ml-select-all')) {
					if (t.checked) {
						// Check all rows on the current page.
						results.querySelectorAll('.ml-select-row').forEach(function (cb) {
							cb.checked = true;
							selection.add(cb.dataset.key);
						});
					} else {
						// Deselect clears the ENTIRE selection — including
						// rows ticked on other pages / now filtered out — so
						// the master checkbox is the real "select nothing"
						// control (there's no separate clear button).
						selection.clear();
						results.querySelectorAll('.ml-select-row').forEach(function (cb) {
							cb.checked = false;
						});
					}
					syncSelectAllHeader();
					syncBookmarkActive();
				} else if (t.classList.contains('ml-page-size-picker')) {
					// Drop ?p — the current page number rarely makes
					// sense at the new size; landing back on page 1 is
					// the least surprising default. Persist the new
					// size to user meta so a fresh visit (clean URL)
					// on any device opens at the same size.
					saveUserPrefs();
					var url = new URL(location.href);
					url.searchParams.set('ps', t.value);
					url.searchParams.delete('p');
					replaceFromQs(url.search, true);
				}
			});
		}

		// -- Filter form + reset link ----------------------------------

		// Live-toggle the Reset button based on current filter form state.
		// PHP sets the initial hidden/visible based on URL; JS keeps it in
		// sync as the user types or toggles checkboxes (the filter bar
		// isn't part of the AJAX re-render, so it can't rely on a server
		// round-trip to refresh the button).
		function hasAnyFilterActive() {
			if (!filterForm) return false;
			var texts = filterForm.querySelectorAll('input[type="search"], input[type="text"]');
			for (var i = 0; i < texts.length; i++) {
				if (texts[i].value.trim() !== '') return true;
			}
			if (filterForm.querySelector('input[type="checkbox"]:checked')) return true;
			var selects = filterForm.querySelectorAll('select');
			for (var j = 0; j < selects.length; j++) {
				var s = selects[j];
				if (s.multiple) {
					if (s.selectedOptions.length > 0) return true;
				} else if (s.selectedIndex > 0) {
					return true;
				}
			}
			return false;
		}
		function updateResetVisibility() {
			if (!filterForm) return;
			// Reset is now a hand-rendered <a class="ml-reset"> inside
			// the actions markup (no PW Inputfield wrapper to hide);
			// toggle the anchor itself.
			var wrap = filterForm.querySelector('.ml-reset');
			if (wrap) wrap.hidden = !hasAnyFilterActive();
		}

		// Live narrowing of the Image-field dropdown based on the chosen
		// template — uses the {template: [fieldName, …]} map shipped via
		// $config->js() so we don't have to round-trip to the server.
		//
		// "All image fields" stays a real option (it's the no-field-filter
		// state) but gets relabelled when a template is active so the
		// user isn't told "all" while half the fields aren't actually in
		// scope. Specific fields the template doesn't host become
		// hidden+disabled; a currently-selected field that's no longer
		// valid snaps back to the blank option.
		function applyTemplateFieldFilter() {
			if (!filterForm) return;
			var tplSel = filterForm.querySelector('select[name="template"]');
			var fldSel = filterForm.querySelector('select[name="field"]');
			if (!tplSel || !fldSel) return;
			var chosen  = tplSel.value;
			var allowed = chosen ? (config.tplFields[chosen] || []) : null;

			var emptyOpt = fldSel.querySelector('option[value=""]');
			if (emptyOpt) {
				if (!emptyOpt.dataset.defaultLabel) {
					emptyOpt.dataset.defaultLabel = emptyOpt.textContent;
				}
				var scopedLabel = config.labels && config.labels.fieldEmptyScoped;
				emptyOpt.textContent = chosen && scopedLabel
					? scopedLabel.replace('%s', chosen)
					: emptyOpt.dataset.defaultLabel;
			}

			var resetSelection = false;
			Array.prototype.forEach.call(fldSel.options, function (opt) {
				if (!opt.value) return; // empty option handled above
				var ok = !allowed || allowed.indexOf(opt.value) !== -1;
				opt.hidden   = !ok;
				opt.disabled = !ok;
				if (!ok && opt.selected) {
					opt.selected = false;
					resetSelection = true;
				}
			});
			if (resetSelection) {
				fldSel.value = '';
				// Surface to the rest of the form (reset-visibility etc.).
				fldSel.dispatchEvent(new Event('change', { bubbles: true }));
			}
		}

		// Update the filter fieldset labels with their "(N)" suffix.
		// PHP renders the initial labels with the applied filter
		// count; this re-derives the same numbers from the live form
		// state after Apply / Reset since the filter form isn't part
		// of the AJAX-replaced region and would otherwise stay
		// frozen on whatever the page-load count was.
		function setFieldsetHeaderText(headerEl, baseLabel, count) {
			if (!headerEl) return;
			var text = count > 0 ? baseLabel + ' (' + count + ')' : baseLabel;
			// Replace the first non-empty text node in place so any
			// theme-injected icon/chevron siblings inside the header
			// stay intact.
			for (var i = 0; i < headerEl.childNodes.length; i++) {
				var node = headerEl.childNodes[i];
				if (node.nodeType === 3 && node.nodeValue.trim() !== '') {
					node.nodeValue = text;
					return;
				}
			}
			headerEl.appendChild(document.createTextNode(text));
		}
		function recomputeFilterLabels() {
			if (!filterForm) return;
			var outerCount = 0;
			var tagsCount  = 0;
			filterForm.querySelectorAll('input[type="search"], input[type="text"]').forEach(function (i) {
				if (i.value.trim() !== '') outerCount++;
			});
			filterForm.querySelectorAll('select:not([multiple])').forEach(function (s) {
				if (s.value !== '' && s.value != null) outerCount++;
			});
			filterForm.querySelectorAll('input[type="checkbox"]:checked').forEach(function (cb) {
				outerCount++;
				if (cb.name === 'tags[]') tagsCount++;
			});
			setFieldsetHeaderText(
				filterForm && filterForm.querySelector('.Inputfield_mlFilters > .InputfieldHeader'),
				(labels && labels.filtersLabel) || 'Filters',
				outerCount
			);
			setFieldsetHeaderText(
				filterForm && filterForm.querySelector('.Inputfield_tags > .InputfieldHeader'),
				(labels && labels.tagsLabel) || 'Tags',
				tagsCount
			);
		}

		// Field-capability narrowing: with a specific image field
		// selected, hide and uncheck any filter UI that doesn't apply
		// to that field — Tags fieldset, "Missing tags", "Missing
		// <custom>". Exact parallel of applyTemplateFieldFilter: PHP
		// emits the full DOM, JS toggles .hidden + .disabled per
		// element, and invalidated values are cleared so submission
		// reflects what the user actually sees.
		function applyFieldCapabilityFilter() {
			if (!filterForm) return;
			var caps      = (config.fieldCaps && typeof config.fieldCaps === 'object') ? config.fieldCaps : {};
			var tplFields = (config.tplFields && typeof config.tplFields === 'object') ? config.tplFields : {};
			var tplSel    = filterForm.querySelector('select[name="template"]');
			var fldSel    = filterForm.querySelector('select[name="field"]');
			var template  = tplSel ? tplSel.value : '';
			var field     = fldSel ? fldSel.value : '';

			// Effective capability set:
			//  - specific field → that field's caps
			//  - empty field + template → UNION of caps across the
			//    template's image fields (so a template whose only
			//    field has no tags / no customs collapses those
			//    filters too)
			//  - nothing selected → full universe (null = show all)
			var hasTags, customs;
			if (field && caps[field]) {
				hasTags = caps[field].useTags === true;
				customs = caps[field].customs || [];
			} else if (template && tplFields[template]) {
				var allowed = tplFields[template] || [];
				hasTags = allowed.some(function (f) {
					return caps[f] && caps[f].useTags === true;
				});
				var customsSet = {};
				allowed.forEach(function (f) {
					((caps[f] && caps[f].customs) || []).forEach(function (c) {
						customsSet[c] = true;
					});
				});
				customs = Object.keys(customsSet);
			} else {
				hasTags = true;
				customs = null;
			}
			var changed = false;

			// Tags filter fieldset (.Inputfield_mlTagsFs).
			var tagsWrap = filterForm.querySelector('.Inputfield_mlTagsFs');
			if (tagsWrap) {
				tagsWrap.hidden = !hasTags;
				if (!hasTags) {
					tagsWrap.querySelectorAll('input[type="checkbox"]:checked').forEach(function (cb) {
						cb.checked = false;
						changed = true;
					});
				}
			}

			// "Missing tags" checkbox (.Inputfield_no_tags).
			var missingTagsWrap = filterForm.querySelector('.Inputfield_no_tags');
			if (missingTagsWrap) {
				missingTagsWrap.hidden = !hasTags;
				var noTagsCb = missingTagsWrap.querySelector('input[type="checkbox"]');
				if (noTagsCb) noTagsCb.disabled = !hasTags;
				if (!hasTags && noTagsCb && noTagsCb.checked) {
					noTagsCb.checked = false;
					changed = true;
				}
			}

			// "Missing <custom>" wrappers (.Inputfield_no_custom_<name>).
			filterForm.querySelectorAll('[class*="Inputfield_no_custom_"]').forEach(function (w) {
				var match = /(?:^|\s)Inputfield_no_custom_([A-Za-z0-9_]+)(?:\s|$)/.exec(w.className);
				if (!match) return;
				var name = match[1];
				var ok   = customs === null || customs.indexOf(name) !== -1;
				w.hidden = !ok;
				var cb = w.querySelector('input[type="checkbox"]');
				if (cb) cb.disabled = !ok;
				if (!ok && cb && cb.checked) {
					cb.checked = false;
					changed = true;
				}
			});

			// Surface any auto-cleared filters to the reset-visibility +
			// label-recompute logic so they reflect current state.
			if (changed) {
				updateResetVisibility();
				recomputeFilterLabels();
			}
		}

		if (filterForm) {
			filterForm.addEventListener('input', updateResetVisibility);
			filterForm.addEventListener('change', updateResetVisibility);
			filterForm.addEventListener('change', function (e) {
				if (!e.target) return;
				if (e.target.name === 'template') applyTemplateFieldFilter();
				if (e.target.name === 'template' || e.target.name === 'field') {
					applyFieldCapabilityFilter();
				}
			});
			// Sync initial state: narrow the field dropdown to the URL's
			// template, then hide the Reset button if no filters apply
			// (the wrapper renders visible by default — PW doesn't know
			// about our visibility rule).
			applyTemplateFieldFilter();
			applyFieldCapabilityFilter();
			updateResetVisibility();
			// Expose the live-narrowing helpers so the bookmark click
			// handler (lives outside the filter-form scope) can run
			// them after pushing values onto the form.
			root._mlApplyTemplateFieldFilter   = applyTemplateFieldFilter;
			root._mlApplyFieldCapabilityFilter = applyFieldCapabilityFilter;
			root._mlUpdateResetVisibility      = updateResetVisibility;
			root._mlRecomputeFilterLabels      = recomputeFilterLabels;

			filterForm.addEventListener('submit', function (e) {
				e.preventDefault();
				var params = new URLSearchParams();
				// PW's InputfieldCheckboxes renders multi-checkboxes with
				// name="tags[]", which URLSearchParams percent-encodes
				// to %5B%5D. Collapse the selected values into a single
				// comma-separated ?tags=foo,bar so the URL stays readable.
				var tagsList = [];
				new FormData(filterForm).forEach(function (v, k) {
					if (k === 'apply') return;
					if (k === 'tags[]') {
						if (v !== '') tagsList.push(v);
					} else if (v !== '') {
						params.append(k, v);
					}
				});
				if (tagsList.length) params.append('tags', tagsList.join(','));
				// Filtering INSIDE a collection must narrow the collection, not
				// replace it — carry the active ?coll through so the server keeps
				// the membership set and applies the filters within it.
				var activeColl = currentColl();
				if (activeColl) params.append('coll', activeColl);
				var qs = params.toString() ? '?' + params.toString() : '';
				// Filter changes the result set → clear the selection.
				replaceFromQs(qs, true, true);
				recomputeFilterLabels();
				// Collapse the outer "Filters" fieldset after Apply —
				// the user has committed their choice; keeping the
				// fieldset open just occludes the results. PW's
				// InputfieldStateCollapsed class is the same hook the
				// admin theme uses for its own collapse state, so the
				// chevron + content-hide work without extra wiring.
				var outerFs = filterForm.querySelector('.Inputfield_mlFilters');
				if (outerFs) outerFs.classList.add('InputfieldStateCollapsed');
			});

			// "Reset" is an <a href="./">; intercept so it clears via AJAX too.
			// Also wipe the form's visible state — form.reset() goes back to
			// the values present at page load, which here ARE the user's
			// active filters, so we have to clear inputs manually.
			// Wipe every input on the filter form back to the empty
			// state. Shared by the Reset link AND the bookmark-click
			// handler (which applies the bookmark's values onto the
			// freshly-cleared form so stale checks / selects don't
			// linger from whatever was active before).
			function clearFilterForm() {
				if (!filterForm) return;
				filterForm.querySelectorAll('input[type="search"], input[type="text"], input[type="hidden"]').forEach(function (i) {
					i.value = '';
				});
				filterForm.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach(function (i) {
					i.checked = false;
				});
				filterForm.querySelectorAll('select').forEach(function (s) {
					if (s.multiple) {
						Array.prototype.forEach.call(s.options, function (o) { o.selected = false; });
					} else {
						s.selectedIndex = 0;
					}
				});
			}
			// Expose so the bookmark click handler (outside this
			// scope) can reuse it.
			root._mlClearFilterForm = clearFilterForm;

			filterForm.addEventListener('click', function (e) {
				var reset = e.target.closest && e.target.closest('a[href="./"]');
				if (!reset) return;
				e.preventDefault();
				clearFilterForm();
				applyTemplateFieldFilter();
				applyFieldCapabilityFilter();
				updateResetVisibility();
				recomputeFilterLabels();
				// Reset clears the filters → clear the selection too. Inside a
				// collection, keep ?coll so reset returns to the FULL collection
				// (not the whole library).
				var resetColl = currentColl();
				replaceFromQs(resetColl ? '?coll=' + encodeURIComponent(resetColl) : '', true, true);
			});
		}

		// -- View prefs (columns + page size) -------------------------
		// State is per-user, stored server-side in $user->meta via
		// the user-prefs endpoint. Cross-device by design — the same
		// user on a different browser sees the same column layout
		// and chosen page size. Admin can preset default-hidden
		// columns and a default page size via module config; those
		// kick in only when the user has no saved preference yet.
		var userPrefs    = (config.userPrefs && typeof config.userPrefs === 'object') ? config.userPrefs : {};
		var savedColumns = (userPrefs.columns && typeof userPrefs.columns === 'object') ? userPrefs.columns : {};
		var columnsState = {};
		var columnsOrder = [];
		var defaultHidden = {};
		(config.defaultHiddenColumns || []).forEach(function (k) { defaultHidden[k] = true; });
		if (savedColumns.visible && typeof savedColumns.visible === 'object') {
			Object.keys(savedColumns.visible).forEach(function (k) {
				columnsState[k] = !!savedColumns.visible[k];
			});
		}
		if (Array.isArray(savedColumns.order)) {
			columnsOrder = savedColumns.order.slice();
		}
		// Read the currently-selected page size off the server-
		// rendered picker. Re-queried on every save so we don't
		// hold a stale copy across AJAX re-renders that swap the
		// pagination block.
		function readCurrentPageSize() {
			var picker = document.querySelector('.ml-page-size-picker');
			if (picker) {
				var n = parseInt(picker.value, 10);
				if (n > 0) return n;
			}
			var saved = parseInt(userPrefs.pageSize, 10);
			return saved > 0 ? saved : null;
		}

		function isColumnHidden(col) {
			if (col in columnsState) return columnsState[col] === false;
			return !!defaultHidden[col];
		}

		function applyColumnVisibility() {
			var cells = document.querySelectorAll('[data-col]');
			Array.prototype.forEach.call(cells, function (cell) {
				cell.classList.toggle('ml-col-hidden', isColumnHidden(cell.dataset.col));
			});
		}

		// Reorder both header + body cells to match columnsOrder.
		// Cells without data-col (e.g. row-select checkbox column)
		// stay anchored at their original position; columns the user
		// hasn't reordered yet keep their server-rendered order at
		// the tail.
		function applyColumnOrder() {
			if (!columnsOrder.length) return;
			var posByCol = {};
			columnsOrder.forEach(function (col, i) { posByCol[col] = i; });
			var rows = document.querySelectorAll('.ml-table tr');
			Array.prototype.forEach.call(rows, function (tr) {
				var children = Array.prototype.slice.call(tr.children);
				var fixed = [], movable = [];
				children.forEach(function (c) {
					if (c.dataset && c.dataset.col) movable.push(c);
					else fixed.push(c);
				});
				movable.sort(function (a, b) {
					var aP = posByCol[a.dataset.col];
					var bP = posByCol[b.dataset.col];
					if (aP === undefined && bP === undefined) return 0;
					if (aP === undefined) return 1;
					if (bP === undefined) return -1;
					return aP - bP;
				});
				fixed.forEach(function (c) { tr.appendChild(c); });
				movable.forEach(function (c) { tr.appendChild(c); });
			});
		}

		// Debounced POST to the user-prefs endpoint. Avoids
		// hammering the server when the user flips checkboxes
		// quickly, drags a column through several positions, or
		// scrubs the page-size picker — only the last state within
		// the window goes over the wire. Always sends the full
		// {columns, pageSize} state so the server record stays
		// authoritative regardless of which control changed.
		// Team-wide shared store. Bookmarks AND collections both live here now —
		// there's no personal-vs-shared split for either any more (one store,
		// read by everyone, created/edited only by managers). The personal
		// arrays below stay empty; the shared arrays ARE the stores.
		var shared = (config.shared && typeof config.shared === 'object') ? config.shared : {};
		// Bookmarks: {id, name, qs, parent}. A FILTER bookmark carries a qs and is
		// always a leaf; a FOLDER (qs '') is an empty container that only groups
		// children and is the only thing allowed to be a parent. Recalled by qs.
		var bookmarks = Array.isArray(shared.bookmarks) ? shared.bookmarks.slice() : [];
		// Collections: saved sets of specific images ({id, name, keys[], parent}).
		// Recalled via ?coll=<id>; the keys live in the team store, never in the
		// URL (a 100-image collection stays a ~12-char link).
		var collections = [];
		var sharedCollections = Array.isArray(shared.collections) ? shared.collections.slice() : [];
		var canManageShared = !!config.canManageShared;
		// Lets the CSS gate curate affordances on shared tabs (read-only for
		// everyone else) without re-checking the flag per rule.
		if (root) root.classList.toggle('ml-can-manage-shared', canManageShared);

		var savePrefsTimer = null;
		var saveSharedTimer = null;
		// Debounced POST of the full shared store. No-op for non-managers
		// (the endpoint enforces this too) so a stray call can't 403-spam.
		function saveSharedPrefs() {
			if (!config.sharedPrefsUrl || !canManageShared) return;
			clearTimeout(saveSharedTimer);
			saveSharedTimer = setTimeout(function () {
				var fd = new FormData();
				fd.append('prefs', JSON.stringify({
					bookmarks: bookmarks,
					collections: sharedCollections
				}));
				appendCsrf(fd);
				fetch(config.sharedPrefsUrl, {
					method: 'POST',
					body: fd,
					credentials: 'same-origin'
				}).catch(function (err) {
					console.error('[ImageLibrary] save shared prefs failed:', err);
				});
			}, 400);
		}

		// Locate a collection by id across BOTH stores; tells the caller which
		// store it lives in so writes route to the right persist fn.
		function findCollection(id) {
			var c = collections.filter(function (x) { return x && x.id === id; })[0];
			if (c) return { coll: c, shared: false };
			c = sharedCollections.filter(function (x) { return x && x.id === id; })[0];
			if (c) return { coll: c, shared: true };
			return null;
		}

		// Current result layout ('table' | 'masonry'). The server is the
		// source of truth (it persists ?view= toggles), but saveUserPrefs
		// does a full overwrite of the prefs blob, so we carry the current
		// value here and include it in every save to avoid clobbering it.
		var viewMode = (userPrefs.viewMode === 'masonry') ? 'masonry' : 'table';

		function saveUserPrefs() {
			if (!config.userPrefsUrl) return;
			clearTimeout(savePrefsTimer);
			savePrefsTimer = setTimeout(function () {
				var fd = new FormData();
				fd.append('prefs', JSON.stringify({
					columns: {
						visible: columnsState,
						order:   columnsOrder
					},
					pageSize:  readCurrentPageSize(),
					bookmarks: bookmarks,
					collections: collections,
					thumbScale: thumbScale,
					viewMode:  viewMode
				}));
				appendCsrf(fd);
				fetch(config.userPrefsUrl, {
					method: 'POST',
					body: fd,
					credentials: 'same-origin'
				}).catch(function (err) {
					console.error('[ImageLibrary] save user prefs failed:', err);
				});
			}, 400);
		}

		// A rename changes an image's basename, hence its row key
		// (pageId:field:basename). Collections store members by that key, so
		// without this a renamed image silently drops out of every collection it
		// was in. Re-key the membership in place (personal + shared, if present)
		// and persist. `map` is { oldKey: newKey }.
		function migrateCollectionKeys(map) {
			if (!map || typeof map !== 'object') return;
			function apply(list) {
				if (!Array.isArray(list)) return false;
				var changed = false;
				list.forEach(function (c) {
					if (!c || !Array.isArray(c.keys)) return;
					var seen = Object.create(null), out = [], did = false;
					c.keys.forEach(function (k) {
						var nk = Object.prototype.hasOwnProperty.call(map, k) ? map[k] : k;
						if (nk !== k) did = true;
						if (!seen[nk]) { seen[nk] = true; out.push(nk); }
					});
					if (did) { c.keys = out; changed = true; }
				});
				return changed;
			}
			if (apply(collections)) saveUserPrefs();
			// Shared collections only exist on builds that have them.
			if (typeof sharedCollections !== 'undefined' && apply(sharedCollections)
				&& typeof saveSharedPrefs === 'function') {
				saveSharedPrefs();
			}
		}

		// -- Thumbnail size slider -------------------------------------
		// Per-user view zoom: thumbScale multiplies the configured thumb
		// dims via the --ml-thumb-scale CSS var on .ml-root. Server
		// renders the initial value (no flash) and re-renders the slider
		// in the pagination row on every AJAX swap; we keep it in sync,
		// update the var live while dragging, and persist (debounced).
		var thumbScaleMin = parseFloat(config.thumbScaleMin) || 0.5;
		var thumbScaleMax = parseFloat(config.thumbScaleMax) || 2.5;
		var thumbScale = (function () {
			var v = parseFloat(userPrefs.thumbScale);
			return (!isNaN(v) && v >= thumbScaleMin && v <= thumbScaleMax)
				? v : (parseFloat(config.thumbScaleDefault) || 1);
		})();
		// Apply a new scale: update the CSS var live, mirror it onto every
		// initialised slider (top + bottom pagination), and persist.
		// Programmatic jQuery-UI value sets don't fire slide/change, so
		// mirroring can't loop.
		function setThumbScale(v) {
			v = Math.min(thumbScaleMax, Math.max(thumbScaleMin, parseFloat(v)));
			if (isNaN(v)) return;
			thumbScale = v;
			root.style.setProperty('--ml-thumb-scale', String(v));
			var $ = window.jQuery;
			document.querySelectorAll('.ml-thumb-size-slider.ui-slider').forEach(function (el) {
				if ($ && $(el).slider('value') !== v) $(el).slider('value', v);
			});
			// Re-flow the gallery: a new zoom may change the column count.
			// Cheap no-op while dragging within one column-count band
			// (layoutGallery early-returns when the count is unchanged).
			if (root._mlLayoutGallery) root._mlLayoutGallery();
			saveUserPrefs();
		}
		// Turn an empty .ml-thumb-size-slider span into a jQuery-UI slider
		// (the same widget PW's own image-size slider uses, so the look
		// matches the admin). No-op if jQuery UI isn't present.
		function initThumbSlider(el) {
			var $ = window.jQuery;
			if (!$ || !$.fn || !$.fn.slider) return;
			var $el = $(el);
			if ($el.hasClass('ui-slider')) return; // already initialised
			$el.slider({
				min:   parseFloat(el.getAttribute('data-min'))  || thumbScaleMin,
				max:   parseFloat(el.getAttribute('data-max'))  || thumbScaleMax,
				step:  parseFloat(el.getAttribute('data-step')) || 0.1,
				value: thumbScale,
				range: 'min',
				slide:  function (e, ui) { setThumbScale(ui.value); },
				change: function (e, ui) { setThumbScale(ui.value); }
			});
		}
		function applyThumbScale() {
			root.style.setProperty('--ml-thumb-scale', String(thumbScale));
			var $ = window.jQuery;
			document.querySelectorAll('.ml-thumb-size-slider').forEach(function (el) {
				if ($ && $(el).hasClass('ui-slider')) {
					if ($(el).slider('value') !== thumbScale) $(el).slider('value', thumbScale);
				} else {
					initThumbSlider(el);
				}
			});
		}
		applyThumbScale();
		// Re-init / re-sync the slider after an AJAX swap re-renders the
		// pagination row (the CSS var on .ml-root persists across swaps).
		root._mlApplyThumbScale = applyThumbScale;

		// -- Gallery masonry layout ------------------------------------
		// TRUE masonry: each thumbnail keeps its natural aspect ratio (no
		// crop) and tiles are packed into N equal flex columns by always
		// dropping the next tile into the SHORTEST column so far — so the
		// columns stay height-balanced (no ragged bottoms / big gaps). The
		// predicted height comes from each <img>'s width/height attributes
		// (server-rendered natural ratio), so no wait for image load and no
		// reflow. N is derived from the container width and the size slider,
		// recomputed on render, on slider change and on resize. Re-parenting
		// cards is safe: they stay inside .ml-results (delegated handlers).
		var galleryCards = null; // source-ordered .ml-card nodes for this render
		function galleryColumnCount(masonry) {
			var w = masonry.clientWidth || 0;
			if (w <= 0) return 1;
			// Target column width: the 220px design size at zoom 1, with a
			// smaller base on phones / tablets so narrow screens still show
			// several columns. Scaled live by the slider (bigger zoom →
			// wider target → fewer, larger columns).
			var base = w <= 640 ? 88 : (w <= 1024 ? 132 : 220);
			var gap = 10;
			var n = Math.floor((w + gap) / (base * thumbScale + gap));
			return Math.max(1, n);
		}
		function layoutGallery() {
			var masonry = results && results.querySelector('.ml-masonry');
			if (!masonry) { galleryCards = null; return; }
			// On a fresh server render the cards are flat children in source
			// order (no data-cols yet) — capture them. Once columnised the
			// DOM order is column-major, so reuse the captured source order.
			if (!masonry.hasAttribute('data-cols')) {
				galleryCards = Array.prototype.slice.call(masonry.children).filter(function (el) {
					return el.classList && el.classList.contains('ml-card');
				});
			}
			if (!galleryCards || !galleryCards.length) return;
			var n = galleryColumnCount(masonry);
			if (masonry.getAttribute('data-cols') === String(n)) return; // unchanged → skip
			var cols = [], colH = [], i;
			for (i = 0; i < n; i++) {
				var col = document.createElement('div');
				col.className = 'ml-masonry-col';
				cols.push(col);
				colH.push(0);
			}
			// Per-tile height in column-width units (h/w of the natural-ratio
			// image), plus a small constant for the inter-tile gap so a column
			// of many short tiles still counts its gaps. Pack each tile into the
			// currently shortest column → balanced heights.
			var gapUnit = 0.04;   // ≈ one --ml-gap relative to a column width
			galleryCards.forEach(function (card) {
				var img = card.querySelector('img.ml-thumb');
				var w = img ? parseFloat(img.getAttribute('width'))  : 0;
				var h = img ? parseFloat(img.getAttribute('height')) : 0;
				var ratio = (w > 0 && h > 0) ? (h / w) : 1;   // relative height
				var min = 0;
				for (var c = 1; c < n; c++) { if (colH[c] < colH[min]) min = c; }
				cols[min].appendChild(card);
				colH[min] += ratio + gapUnit;
			});
			masonry.textContent = '';
			for (i = 0; i < n; i++) masonry.appendChild(cols[i]);
			masonry.setAttribute('data-cols', String(n));
		}
		root._mlLayoutGallery = layoutGallery;
		var galleryResizeTimer = null;
		window.addEventListener('resize', function () {
			if (galleryResizeTimer) clearTimeout(galleryResizeTimer);
			galleryResizeTimer = setTimeout(layoutGallery, 150);
		});
		layoutGallery();

		// -- Bookmarks -------------------------------------------------
		// Tab strip above the filter bar; each tab is a saved filter
		// combination. State lives in $user->meta via the existing
		// userPrefs endpoint. UI delegation: click a tab → AJAX-load
		// its querystring; × on hover → delete; "+" leftmost → name-
		// dialog → save current filter.

		// Same shape canonicalizeBookmarkQs produces server-side, so
		// active-tab detection is a straight string compare.
		function canonicalFilterQs(qs) {
			var u = new URLSearchParams((qs || '').replace(/^\?/, ''));
			var allow = ['q', 'template', 'field', 'tags', 'no_desc', 'no_tags', 'dupes'];
			var keep = [];
			u.forEach(function (v, k) {
				var ok = allow.indexOf(k) !== -1 || k.indexOf('no_custom_') === 0;
				if (!ok) return;
				if (v === '') return;
				keep.push([k, v]);
			});
			if (!keep.length) return '';
			keep.sort(function (a, b) { return a[0].localeCompare(b[0]); });
			var out = new URLSearchParams();
			keep.forEach(function (p) { out.append(p[0], p[1]); });
			return '?' + out.toString();
		}

		// Active collection id from the URL (?coll=<id>), '' if none.
		function currentColl() {
			return new URLSearchParams(location.search.replace(/^\?/, '')).get('coll') || '';
		}

		function syncBookmarkActive() {
			var tabs = document.querySelectorAll('.ml-bookmarks-tabs > li');
			if (!tabs.length) return;
			var current = canonicalFilterQs(location.search);   // filter-only
			var coll    = currentColl();
			var bookmarkMatched = false;
			tabs.forEach(function (li) {
				li.classList.remove('uk-active');
				var a = li.querySelector('a.ml-bookmark');
				if (!a) return;
				if (li.dataset.collId) {
					// Collection tab — active iff its id is the one in the URL.
					if (li.dataset.collId === coll && coll !== '') li.classList.add('uk-active');
				} else {
					// "Show all" (qs="") or a filter bookmark. A FOLDER bookmark also
					// has qs="" but must never be the active tab — only the real
					// "Show all" (no data-bookmark-id) matches the empty filter.
					var qs = a.dataset.qs || '';
					if (qs === '') {
						if (!li.dataset.bookmarkId && current === '' && coll === '') li.classList.add('uk-active');
					} else if (qs === current && coll === '') {
						li.classList.add('uk-active');
						bookmarkMatched = true;        // current URL IS a saved filter
					}
				}
			});
			// Nested (flyout) items: mark the active one and light up its parent tab
			// so the active entry is visible while the flyout is closed. Handles both
			// nested collections (by ?coll= id) AND nested filter bookmarks (by qs) —
			// the latter must also count as a bookmark match so the bar's "New" link
			// hides exactly like it does for a top-level bookmark.
			document.querySelectorAll('.ml-coll-flyout-item').forEach(function (fli) {
				if (fli.dataset.collId) {
					var on = fli.dataset.collId === coll && coll !== '';
					fli.classList.toggle('uk-active', on);
					if (on) {
						var parentTab = fli.closest('.ml-bookmarks-tabs > li');
						if (parentTab) parentTab.classList.add('uk-active');
					}
				} else if (fli.dataset.bookmarkId) {
					var fa = fli.querySelector('a.ml-bookmark');
					var fqs = fa ? (fa.dataset.qs || '') : '';
					var bon = (fqs !== '' && fqs === current && coll === '');
					fli.classList.toggle('uk-active', bon);
					if (bon) {
						bookmarkMatched = true;
						var pTab = fli.closest('.ml-bookmarks-tabs > li');
						if (pTab) pTab.classList.add('uk-active');
					}
				}
			});

			// A checkbox selection enables collection actions: the per-collection
			// "+" chips (CSS-gated by this class) and the add-button's collection
			// mode. Mark the strip so the CSS can reveal the chips.
			var hasSel = (typeof selection !== 'undefined') && selection.size > 0;
			var ul = bookmarksContainer();
			if (ul) ul.classList.toggle('ml-has-selection', hasSel);

			// Add button: shown when a checkbox selection exists (→ save as
			// collection — takes priority, works even under an active filter),
			// OR when a non-saved filter is active (→ save as filter bookmark).
			// Its label reflects which it'll do.
			var addLi = document.querySelector('.ml-bookmarks-add');
			if (addLi) {
				// Selection → "save as collection" is manager-only now; a filter →
				// "save as bookmark" stays available to everyone.
				addLi.hidden = !((hasSel && canManageShared) || (current !== '' && !bookmarkMatched));
				var addA = addLi.querySelector('a');
				if (addA) {
					var addLabel = hasSel
						? (labels.collectionAdd || 'Add collection')
						: (labels.bookmarkAdd || 'Add bookmark');
					addA.innerHTML = '<i class="fa fa-plus" aria-hidden="true"></i> ';
					addA.appendChild(document.createTextNode(addLabel));
				}
			}
		}

		function bookmarksContainer() {
			return document.querySelector('.ml-bookmarks-tabs');
		}

		// Mobile category switcher (Show all / Bookmarks / Collections): the class
		// on the strip (ml-bar-cat-*) drives which items CSS shows; this also marks
		// the active category tab. Desktop hides the switcher, so it's a no-op there.
		function setBarCat(cat) {
			var ul = bookmarksContainer();
			if (ul) {
				ul.classList.remove('ml-bar-cat-all', 'ml-bar-cat-bm', 'ml-bar-cat-coll');
				ul.classList.add('ml-bar-cat-' + cat);
			}
			var cats = document.querySelector('.ml-bar-cats');
			if (cats) {
				Array.prototype.forEach.call(cats.querySelectorAll('li'), function (li) {
					li.classList.toggle('uk-active', li.dataset.cat === cat);
				});
			}
		}
		document.addEventListener('click', function (e) {
			var catLi = e.target.closest && e.target.closest('.ml-bar-cats li');
			if (catLi && catLi.dataset.cat) {
				e.preventDefault();
				var cat = catLi.dataset.cat;
				setBarCat(cat);
				var bar = document.querySelector('.ml-bookmarks-bar');
				if (cat === 'all') {
					if (bar) bar.classList.remove('ml-bar-open');
					applyBookmarkToForm('');
					replaceFromQs('', true, true);
					syncBookmarkActive();
				} else if (bar) {
					bar.classList.toggle('ml-bar-open');
				}
				return;
			}
			// A tap that recalls a leaf item, or lands outside the bar, closes the
			// open dropdown.
			var openBar = document.querySelector('.ml-bookmarks-bar.ml-bar-open');
			if (!openBar) return;
			var leaf = e.target.closest && e.target.closest('.ml-bookmarks-tabs a.ml-bookmark');
			var hasQs = leaf && (leaf.dataset.qs || '') !== '';
			var insideBar = e.target.closest && e.target.closest('.ml-bookmarks-bar');
			if (hasQs || !insideBar) openBar.classList.remove('ml-bar-open');
		});

		function rerenderBookmarksList() {
			var ul = bookmarksContainer();
			if (!ul) return;
			// Wipe every bookmark <li>, keep "Show all" (first child)
			// and the Add button (last child). Then insert fresh
			// bookmark <li>s in memory order BEFORE the Add button so
			// it stays rightmost.
			var addLi = ul.querySelector('li.ml-bookmarks-add');
			var manageLi = ul.querySelector('li.ml-collections-manage');
			Array.from(ul.children).forEach(function (li, i) {
				if (i === 0 || li === addLi || li === manageLi) return;
				li.remove();
			});
			function makeDelBtn(label) {
				var del = document.createElement('button');
				del.type = 'button';
				del.className = 'ml-bookmark-del';
				del.setAttribute('aria-label', label);
				del.title = label;
				del.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
				return del;
			}
			// One top-level bookmark <li>. Bookmarks are team-wide now (no italic /
			// data-shared). A FILTER bookmark applies its qs on click; a FOLDER
			// (empty qs) only groups children (rendered in a hover flyout) — its
			// nested children are handled separately, so this renders top-level
			// only. The × (delete) shows for managers only.
			function makeBmLink(b) {
				var isFolder = !(b.qs || '');
				var a = document.createElement('a');
				a.className = 'ml-bookmark' + (isFolder ? ' ml-bookmark--folder' : '');
				a.href = isFolder ? '#' : (location.pathname + (b.qs || ''));
				a.dataset.qs = b.qs || '';
				a.appendChild(document.createTextNode(b.name || ''));
				return a;
			}
			// Cascading flyout of a bookmark FOLDER's direct children — mirrors the
			// collection flyout (same .ml-coll-flyout markup + CSS). Sub-folders get
			// a caret + their own nested flyout; filter-bookmark leaves apply their qs.
			function buildBmFlyout(parentId) {
				var fly = document.createElement('ul');
				fly.className = 'ml-coll-flyout';
				collChildren(bookmarks, parentId).forEach(function (d) {
					var fli = document.createElement('li');
					fli.className = 'ml-coll-flyout-item';
					fli.dataset.bookmarkId = d.id;
					var kids = collIsParent(bookmarks, d.id);
					if (kids) fli.classList.add('ml-coll-has-children', 'ml-coll-flyout-parent');
					var fa = makeBmLink(d);
					if (kids) {
						fa.appendChild(document.createTextNode(' '));
						var car = document.createElement('i');
						car.className = 'fa fa-caret-right ml-coll-tab-caret';
						car.setAttribute('aria-hidden', 'true');
						fa.appendChild(car);
					}
					fli.appendChild(fa);
					if (kids) fli.appendChild(buildBmFlyout(d.id));
					fly.appendChild(fli);
				});
				return fly;
			}
			function addBookmarkLi(b) {
				if (!b || !b.id || (b.parent || '') !== '') return;   // top-level only
				var isFolder = !(b.qs || '');
				var hasKids = collIsParent(bookmarks, b.id);
				var li = document.createElement('li');
				li.dataset.bookmarkId = b.id;
				if (hasKids) li.classList.add('ml-coll-has-children');
				var a = makeBmLink(b);
				if (hasKids) {
					a.appendChild(document.createTextNode(' '));
					var car = document.createElement('i');
					car.className = 'fa fa-caret-down ml-coll-tab-caret';
					car.setAttribute('aria-hidden', 'true');
					a.appendChild(car);
				}
				li.appendChild(a);
				// × only on a top-level FILTER bookmark (a leaf) → quick delete.
				// Folders are managed (rename / delete / nest) in the manager dialog.
				if (canManageShared && !isFolder && !hasKids) li.appendChild(makeDelBtn(labels.bookmarkDelete || 'Delete bookmark'));
				if (hasKids) li.appendChild(buildBmFlyout(b.id));
				ul.insertBefore(li, addLi);
			}
			// One collection link (icon-marked fa-clone). data-qs is the short
			// ?coll=<id> recall link the AJAX swap applies; data-coll-id on the
			// <li> drives curate + active state.
			function makeCollLink(c, isShared, withIcon) {
				var a = document.createElement('a');
				a.className = 'ml-bookmark ml-bookmark--collection' + (isShared ? ' ml-bookmark--shared' : '');
				var qs = '?coll=' + encodeURIComponent(c.id);
				a.href = location.pathname + qs;
				a.dataset.qs = qs;
				if (withIcon) a.innerHTML = '<i class="fa fa-clone" aria-hidden="true"></i> ';
				a.appendChild(document.createTextNode(c.name || ''));
				return a;
			}
			// Recursive cascading flyout: a <ul> of a collection's DIRECT children;
			// a child that itself has children gets a caret + its own nested flyout
			// (shown on hover, positioned to the side via CSS) — so the 3rd level
			// only appears once you hover into the 2nd.
			function buildCollFlyout(arr, parentId, isShared) {
				var fly = document.createElement('ul');
				fly.className = 'ml-coll-flyout';
				collChildren(arr, parentId).forEach(function (d) {
					var fli = document.createElement('li');
					fli.className = 'ml-coll-flyout-item';
					fli.dataset.collId = d.id;
					if (isShared) fli.dataset.shared = '1';
					var grand = collIsParent(arr, d.id);
					if (grand) fli.classList.add('ml-coll-has-children', 'ml-coll-flyout-parent');
					var fa = makeCollLink(d, isShared, false);
					if (grand) {
						fa.appendChild(document.createTextNode(' '));
						var car = document.createElement('i');
						car.className = 'fa fa-caret-right ml-coll-tab-caret';
						car.setAttribute('aria-hidden', 'true');
						fa.appendChild(car);
					}
					fli.appendChild(fa);
					if (grand) fli.appendChild(buildCollFlyout(arr, d.id, isShared));
					fly.appendChild(fli);
				});
				return fly;
			}
			// A top-level collection tab. Only depth-0 collections get a tab; their
			// descendants live in a hover flyout (1 level shown, the rest on hover),
			// indented by relative depth. Children carry their own data-coll-id so
			// recall / curate / delete keep working unchanged.
			function addCollectionTab(c, isShared, arr) {
				if (!c || !c.id || (c.parent || '') !== '') return;   // top-level only
				var hasKids = collIsParent(arr, c.id);
				var li = document.createElement('li');
				li.dataset.collId = c.id;
				if (isShared) li.dataset.shared = '1';
				if (hasKids) li.classList.add('ml-coll-has-children');
				var a = makeCollLink(c, isShared, false);
				if (hasKids) {
					a.appendChild(document.createTextNode(' '));
					var car = document.createElement('i');
					car.className = 'fa fa-caret-down ml-coll-tab-caret';
					car.setAttribute('aria-hidden', 'true');
					a.appendChild(car);
				}
				li.appendChild(a);
				// No × on the strip — deleting collections lives in the manager
				// dialog now (like the tag manager). The strip is for navigating.
				if (hasKids) li.appendChild(buildCollFlyout(arr, c.id, isShared));
				ul.insertBefore(li, addLi);
			}
			// Ordered by TYPE: all bookmarks first, then all collections. Both are
			// single team stores now; top-level entries get a tab, nested ones live
			// in their parent's hover flyout.
			bookmarks.forEach(function (b) { addBookmarkLi(b); });
			collections.forEach(function (c) { addCollectionTab(c, false, collections); });
			sharedCollections.forEach(function (c) { addCollectionTab(c, true, sharedCollections); });
			// "Manage" — icon-only, sitting right after the bookmarks/collections
			// and BEFORE the "New" (Add) link, not floated to the far right.
			// Manager-only.
			var wantManage = canManageShared;
			if (wantManage && !manageLi) {
				manageLi = document.createElement('li');
				manageLi.className = 'ml-collections-manage';
				var ma = document.createElement('a');
				ma.href = '#';
				ma.setAttribute('role', 'button');
				ma.title = labels.collectionsManage || 'Manage bookmarks & collections';
				ma.setAttribute('aria-label', ma.title);
				ma.innerHTML = '<i class="fa fa-sliders" aria-hidden="true"></i>';
				manageLi.appendChild(ma);
			}
			if (manageLi) {
				manageLi.hidden = !wantManage;
				ul.insertBefore(manageLi, addLi);   // directly before the "New" link
			}
			syncBookmarkActive();
		}

		// ---- Collections manager: drag-and-drop tree (up to 3 levels) + collapse ----
		// Each store (personal `collections`, team `sharedCollections`) is kept in
		// DISPLAY order (pre-order DFS: a node sits right before its descendants).
		// A collection's `parent` is the id of its immediate parent ('' = top).
		var collectionsDialog = document.querySelector('.ml-collections-dialog');
		var COLL_MAX_DEPTH = 3;        // levels 0,1,2
		var collCollapsed = {};        // "own:id" / "shared:id" -> true (manager-local, resets on reload)
		var collDelArmedBtn = null;    // delete-confirm armed button (one at a time)
		var collDelTimer = null;
		var collEditInput = null;      // inline-rename input while editing, else null
		var collEditId = null, collEditKind = 'coll', collEditBtn = null, collEditNameSpan = null;
		// The ACTIVE manager row (last acted on / just reordered). Drives the row
		// highlight + control reveal deterministically — exactly one row, no
		// reliance on hover (sticks on touch) or focus (jumps to the old slot
		// after a reorder rebuild). Stored so it survives the list re-render.
		var collActiveKind = null, collActiveId = null;
		function setMgrActive(kind, id) {
			collActiveKind = kind; collActiveId = id;
			if (!collectionsDialog) return;
			Array.prototype.forEach.call(collectionsDialog.querySelectorAll('.ml-coll-row--active'),
				function (r) { r.classList.remove('ml-coll-row--active'); });
			var list = collectionsDialog.querySelector(kind === 'bm' ? '.ml-bookmarks-list' : '.ml-collections-list');
			var row = list && list.querySelector('.ml-coll-row[data-coll-id="' + id + '"]');
			if (row) row.classList.add('ml-coll-row--active');
		}

		// The manager is generic over a "kind": 'coll' (team collections) and 'bm'
		// (team bookmarks) are both single team stores with the SAME tree machinery
		// (nest / sort / collapse / flatten). Only three things differ by kind:
		//   - which array (mgrArr),
		//   - what may be a PARENT (collections: any; bookmarks: only a folder, i.e.
		//     an empty qs — filter bookmarks are always leaves),
		//   - the row icon / "New" affordance.
		// Both persist through the one team save (saveSharedPrefs sends both arrays).
		function collStoreArr(kind) { return kind === 'bm' ? bookmarks : sharedCollections; }
		function mgrSetArr(kind, next) { if (kind === 'bm') bookmarks = next; else sharedCollections = next; }
		function mgrIsBm(kind) { return kind === 'bm'; }
		// May `item` hold children? Collections always can; a bookmark only if it's
		// a FOLDER (no qs). Used to gate nest / indent / drag-into for bookmarks.
		function mgrCanParent(kind, item) { return kind === 'bm' ? !(item && (item.qs || '')) : true; }
		function collKey(kind, id) { return kind + ':' + id; }
		function collIndexOf(arr, id) {
			for (var i = 0; i < arr.length; i++) if (arr[i] && arr[i].id === id) return i;
			return -1;
		}
		function collById(arr, id) { var i = collIndexOf(arr, id); return i < 0 ? null : arr[i]; }
		function collChildren(arr, id) { return arr.filter(function (c) { return c && (c.parent || '') === id; }); }
		function collIsParent(arr, id) { return arr.some(function (c) { return c && (c.parent || '') === id; }); }
		function collDepth(arr, id) {
			var d = 0, c = collById(arr, id), g = 0;
			while (c && (c.parent || '') !== '' && g++ < 64) { d++; c = collById(arr, c.parent); }
			return d;
		}
		function collHeight(arr, id) {
			var ch = collChildren(arr, id);
			if (!ch.length) return 0;
			var m = 0;
			ch.forEach(function (c) { m = Math.max(m, collHeight(arr, c.id)); });
			return 1 + m;
		}
		function collIsDescendant(arr, id, ofId) {
			var c = collById(arr, id), g = 0;
			while (c && (c.parent || '') !== '' && g++ < 64) { if (c.parent === ofId) return true; c = collById(arr, c.parent); }
			return false;
		}
		function collSubtreeSet(arr, id) {
			var s = {}; s[id] = true; var changed = true;
			while (changed) { changed = false; arr.forEach(function (c) { if (c && c.parent && s[c.parent] && !s[c.id]) { s[c.id] = true; changed = true; } }); }
			return s;
		}
		// DFS flatten by parent, preserving sibling array order; promote orphans / cycles.
		function collFlatten(arr) {
			var byId = {};
			arr.forEach(function (c) { if (c && c.id) byId[c.id] = c; });
			arr.forEach(function (c) { if (c && (c.parent || '') !== '' && !byId[c.parent]) c.parent = ''; });
			var roots = [], cm = {};
			arr.forEach(function (c) { if (!c) return; var p = c.parent || ''; (p === '' ? roots : (cm[p] = cm[p] || [])).push(c); });
			var out = [], seen = {};
			function walk(n) { if (seen[n.id]) return; seen[n.id] = true; out.push(n); (cm[n.id] || []).forEach(walk); }
			roots.forEach(walk);
			arr.forEach(function (c) { if (c && !seen[c.id]) { c.parent = ''; seen[c.id] = true; out.push(c); } });
			return out;
		}
		function collPersist(kind) {
			var arr = collStoreArr(kind);
			var flat = collFlatten(arr);
			arr.length = 0; Array.prototype.push.apply(arr, flat);
			saveSharedPrefs();   // both stores are team-wide; one save sends both
			renderCollectionsManager();
			rerenderBookmarksList();
		}
		// Make `id` a child of `parentId` (cycle-safe, depth-capped). For bookmarks
		// the parent must be a FOLDER (filter bookmarks can't hold children).
		function collNest(kind, id, parentId) {
			var arr = collStoreArr(kind);
			var c = collById(arr, id), p = collById(arr, parentId);
			if (!c || !p || id === parentId) return;
			if (!mgrCanParent(kind, p)) return;                                          // bookmarks: folders only
			if (collIsDescendant(arr, parentId, id)) return;                              // no cycle
			if (collDepth(arr, parentId) + 1 + collHeight(arr, id) > COLL_MAX_DEPTH - 1) return;  // depth cap
			c.parent = parentId;
			collPersist(kind);
		}
		// Indent: become a child of the previous sibling.
		function collIndent(kind, id) {
			var arr = collStoreArr(kind);
			var prev = collPrevSibling(arr, id);
			if (prev) collNest(kind, id, prev.id);
		}
		// Outdent: rise one level (become a sibling of the current parent).
		function collOutdent(kind, id) {
			var arr = collStoreArr(kind);
			var c = collById(arr, id);
			if (!c || (c.parent || '') === '') return;
			var p = collById(arr, c.parent);
			c.parent = p ? (p.parent || '') : '';
			collPersist(kind);
		}
		function collPrevSibling(arr, id) {
			var i = collIndexOf(arr, id);
			if (i <= 0) return null;
			var c = arr[i], d = collDepth(arr, id);
			for (var j = i - 1; j >= 0; j--) {
				var dj = collDepth(arr, arr[j].id);
				if (dj < d) break;
				if (dj === d && (arr[j].parent || '') === (c.parent || '')) return arr[j];
			}
			return null;
		}
		// Reorder: swap with the adjacent sibling (subtrees move together).
		function collMove(kind, id, dir) {
			var arr = collStoreArr(kind);
			var c = collById(arr, id);
			if (!c) return;
			var p = c.parent || '';
			var sibs = arr.filter(function (x) { return (x.parent || '') === p; });
			var i = sibs.indexOf(c), j = dir === 'up' ? i - 1 : i + 1;
			if (j < 0 || j >= sibs.length) return;
			var roots = [], cm = {};
			arr.forEach(function (x) { var pp = x.parent || ''; (pp === '' ? roots : (cm[pp] = cm[pp] || [])).push(x); });
			var lst = p === '' ? roots : cm[p];
			var a = lst.indexOf(c), b = lst.indexOf(sibs[j]);
			var t = lst[a]; lst[a] = lst[b]; lst[b] = t;
			var out = [];
			(function () { function walk(n) { out.push(n); (cm[n.id] || []).forEach(walk); } roots.forEach(walk); })();
			arr.length = 0; Array.prototype.push.apply(arr, out);
			collPersist(kind);
		}
		// Drag-place: make `id` a sibling of `targetId`, before / after its subtree.
		function collPlace(kind, id, targetId, after) {
			var arr = collStoreArr(kind);
			var c = collById(arr, id), t = collById(arr, targetId);
			if (!c || !t || id === targetId) return;
			if (collIsDescendant(arr, targetId, id)) return;                          // not into own subtree
			if (collDepth(arr, targetId) + collHeight(arr, id) > COLL_MAX_DEPTH - 1) return;
			var set = collSubtreeSet(arr, id);
			var blk = arr.filter(function (x) { return set[x.id]; });
			var rest = arr.filter(function (x) { return !set[x.id]; });
			c.parent = t.parent || '';
			var ti = collIndexOf(rest, targetId);
			if (ti < 0) { Array.prototype.push.apply(rest, blk); }
			else {
				var at = after ? ti + 1 : ti;
				if (after) { var td = collDepth(rest, targetId); while (at < rest.length && collDepth(rest, rest[at].id) > td) at++; }
				rest.splice.apply(rest, [at, 0].concat(blk));
			}
			arr.length = 0; Array.prototype.push.apply(arr, rest);
			collPersist(kind);
		}

		function mkCollBtn(act, icon, title) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'ml-coll-move';
			b.dataset.act = act;
			b.title = title || '';
			b.setAttribute('aria-label', title || '');
			b.innerHTML = '<i class="fa ' + icon + '" aria-hidden="true"></i>';
			// Keep focus on the inline-rename input when its ✓ (or any control) is
			// clicked, so the click commits instead of blurring → cancelling.
			b.addEventListener('mousedown', function (e) { e.preventDefault(); });
			return b;
		}
		// Inline delete-confirm for a manager row (tag-manager pattern).
		function collDelSetIcon(btn, icon, title) {
			var i = btn.querySelector('i'); if (i) i.className = 'fa ' + icon;
			btn.title = title; btn.setAttribute('aria-label', title);
		}
		function collDelArm(btn, li) {
			collDelArmedBtn = btn;
			btn.classList.add('ml-coll-armed');
			// Deleting a parent is a cascade — warn that what's nested goes too.
			var kind = li ? (li.dataset.store || 'coll') : 'coll';
			var hasKids = li && collIsParent(collStoreArr(kind), li.dataset.collId);
			var msg;
			if (kind === 'bm') {
				msg = hasKids
					? (labels.bmConfirmDeleteFolder || 'Click again — this also deletes the bookmarks inside the folder.')
					: (labels.collConfirmDelete || 'Click again to delete');
			} else {
				msg = hasKids
					? (labels.collConfirmDeleteTree || 'Click again — this also deletes its subgroups. The images stay in the library.')
					: (labels.collConfirmDelete || 'Click again to delete');
			}
			collDelSetIcon(btn, 'fa-check', msg);
			if (li) li.classList.add('ml-coll-row-deleting');
			collDelTimer = setTimeout(collDelDisarm, 4000);
		}
		function collDelDisarm() {
			if (collDelTimer) { clearTimeout(collDelTimer); collDelTimer = null; }
			if (!collDelArmedBtn) return;
			collDelArmedBtn.classList.remove('ml-coll-armed');
			collDelSetIcon(collDelArmedBtn, 'fa-times', labels.collDelete || 'Delete collection');
			var row = collDelArmedBtn.closest('.ml-coll-row');
			if (row) row.classList.remove('ml-coll-row-deleting');
			collDelArmedBtn = null;
		}
		function collDelete(kind, id) {
			collDelDisarm();
			var arr = collStoreArr(kind);
			// Cascade delete: remove the item AND everything nested under it
			// (collections → subgroups; bookmark folders → the bookmarks inside),
			// like deleting a container in Lightroom / Apple Photos / Gmail.
			var doomed = collSubtreeSet(arr, id);
			var flat = collFlatten(arr.filter(function (c) { return c && !doomed[c.id]; }));
			mgrSetArr(kind, flat);
			saveSharedPrefs();
			renderCollectionsManager();
			rerenderBookmarksList();
			announce(kind === 'bm'
				? (labels.bookmarkDeleted || 'Bookmark deleted')
				: (labels.collectionDeleted || 'Collection deleted'));
			// Collections recall by ?coll=: if we're viewing a now-deleted one,
			// drop it and reload. Bookmarks recall by qs, so syncBookmarkActive
			// just drops the active state on the next render — nothing to do here.
			if (kind !== 'bm' && doomed[currentColl()]) {
				var rest = location.search.replace(/^\?/, '').split('&')
					.filter(function (p) { return p && !/^coll=/.test(p); }).join('&');
				replaceFromQs(rest ? '?' + rest : '', true, true);
			}
		}
		// Inline rename for a manager row — same pattern as the tag manager: the
		// name turns into a text input, the ✎ becomes a ✓. Enter / ✓ commits,
		// Esc / blur cancels. Renaming a collection is just a name change.
		function collEditStart(kind, id, li, btn) {
			collDelDisarm();
			collEditCancel();   // only one edit at a time
			var c = collById(collStoreArr(kind), id);
			var nameSpan = li && li.querySelector('.ml-coll-name');
			if (!c || !nameSpan) return;
			collEditInput = document.createElement('input');
			collEditInput.type = 'text';
			collEditInput.className = 'ml-coll-edit-input uk-input';
			collEditInput.value = c.name || '';
			collEditId = id; collEditKind = kind; collEditBtn = btn; collEditNameSpan = nameSpan;
			nameSpan.style.display = 'none';
			nameSpan.parentNode.insertBefore(collEditInput, nameSpan);
			collDelSetIcon(btn, 'fa-check', labels.save || 'Save');
			btn.classList.add('ml-coll-confirm');   // green ✓, like the tag rename
			collEditInput.focus();
			collEditInput.select();
			collEditInput.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); collEditCommit(); }
				else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); collEditCancel(); }
			});
			collEditInput.addEventListener('blur', function () { collEditCancel(); });
		}
		function collEditEnd() {
			if (collEditInput) { collEditInput.remove(); collEditInput = null; }
			if (collEditNameSpan) { collEditNameSpan.style.display = ''; collEditNameSpan = null; }
			if (collEditBtn) { collDelSetIcon(collEditBtn, 'fa-pencil', labels.collRename || 'Rename collection'); collEditBtn.classList.remove('ml-coll-confirm'); collEditBtn = null; }
			collEditId = null; collEditKind = 'coll';
		}
		function collEditCancel() { collEditEnd(); }
		function collEditCommit() {
			if (!collEditInput) return;
			var nt = (collEditInput.value || '').trim();
			var kind = collEditKind, id = collEditId;
			collEditEnd();
			if (nt === '') return;
			var c = collById(collStoreArr(kind), id);
			if (!c || c.name === nt) return;
			c.name = nt;
			saveSharedPrefs();
			renderCollectionsManager();
			rerenderBookmarksList();
		}
		function collAncestorCollapsed(arr, kind, c) {
			var anc = c.parent || '', g = 0;
			while (anc && g++ < 64) {
				if (collCollapsed[collKey(kind, anc)]) return true;
				var pa = collById(arr, anc); anc = pa ? (pa.parent || '') : '';
			}
			return false;
		}
		function buildCollRow(arr, c, kind) {
			var isBm = mgrIsBm(kind);
			var isFolder = isBm && !(c.qs || '');
			var depth = collDepth(arr, c.id);
			var hasKids = collIsParent(arr, c.id);
			var li = document.createElement('li');
			li.className = 'ml-coll-row' + (isFolder ? ' ml-coll-row--folder' : '')
				+ ((kind === collActiveKind && c.id === collActiveId) ? ' ml-coll-row--active' : '');
			li.draggable = true;
			li.dataset.collId = c.id;
			li.dataset.store = kind;
			li.style.paddingLeft = (0.3 + depth * 1.4) + 'rem';
			// Collapse caret for parents; a spacer keeps leaf names aligned.
			if (hasKids) {
				var collapsed = !!collCollapsed[collKey(kind, c.id)];
				var car = document.createElement('button');
				car.type = 'button';
				car.className = 'ml-coll-caret';
				car.dataset.act = 'toggle';
				car.title = collapsed ? (labels.collExpand || 'Expand') : (labels.collCollapse || 'Collapse');
				car.setAttribute('aria-label', car.title);
				car.innerHTML = '<i class="fa ' + (collapsed ? 'fa-caret-right' : 'fa-caret-down') + '" aria-hidden="true"></i>';
				li.appendChild(car);
			} else {
				var sp = document.createElement('span');
				sp.className = 'ml-coll-caret ml-coll-caret--leaf';
				sp.setAttribute('aria-hidden', 'true');
				li.appendChild(sp);
			}
			// A small type icon so containers are obvious in the list:
			//   bookmarks    — folder (empty group) vs bookmark (a filter);
			//   collections  — folder (empty container) vs the duplicate/clone
			//                  icon (a real set that holds its own images).
			var typeIcon;
			if (isBm) {
				typeIcon = isFolder ? 'fa-folder-o' : 'fa-bookmark-o';
			} else {
				var collEmpty = !(c.keys && c.keys.length);
				typeIcon = collEmpty ? 'fa-folder-o' : 'fa-clone';
			}
			var ic = document.createElement('i');
			ic.className = 'fa ' + typeIcon + ' ml-coll-typeicon';
			ic.setAttribute('aria-hidden', 'true');
			li.appendChild(ic);
			var name = document.createElement('span');
			name.className = 'ml-coll-name';
			name.textContent = c.name || '';
			li.appendChild(name);
			var btns = document.createElement('span');
			btns.className = 'ml-coll-btns';
			btns.appendChild(mkCollBtn('edit', 'fa-pencil', labels.collRename || 'Rename'));
			var sibs = arr.filter(function (x) { return (x.parent || '') === (c.parent || ''); });
			var si = sibs.indexOf(c);
			if (si > 0) btns.appendChild(mkCollBtn('up', 'fa-chevron-up', labels.collMoveUp || 'Move up'));
			if (si >= 0 && si < sibs.length - 1) btns.appendChild(mkCollBtn('down', 'fa-chevron-down', labels.collMoveDown || 'Move down'));
			// Indent under the previous sibling — only when it can be a parent
			// (bookmarks: a folder) and the depth cap allows it.
			var prev = collPrevSibling(arr, c.id);
			if (prev && mgrCanParent(kind, prev)
				&& collDepth(arr, prev.id) + 1 + collHeight(arr, c.id) <= COLL_MAX_DEPTH - 1) {
				btns.appendChild(mkCollBtn('nest', 'fa-indent', labels.collNest || 'Indent'));
			}
			if (depth > 0) btns.appendChild(mkCollBtn('unnest', 'fa-outdent', labels.collUnnest || 'Outdent'));
			var delB = mkCollBtn('del', 'fa-times', labels.collDelete || 'Delete');
			delB.classList.add('ml-coll-del');
			btns.appendChild(delB);
			li.appendChild(btns);
			return li;
		}
		// Render ONE store's rows into a list element (collapsed subtrees hidden),
		// then (re)wire its drag-and-drop. Generic over kind.
		function renderManagerList(list, arr, kind) {
			if (!list) return;
			list.innerHTML = '';
			arr.forEach(function (c) {
				if (!c || !c.id) return;
				if (collAncestorCollapsed(arr, kind, c)) return;   // hidden under a collapsed parent
				list.appendChild(buildCollRow(arr, c, kind));
			});
			if (!arr.length) {
				var empty = document.createElement('li');
				empty.className = 'ml-coll-empty';
				empty.textContent = kind === 'bm'
					? (labels.bmManageEmpty || 'No bookmarks yet.')
					: (labels.collManageEmpty || 'No collections yet.');
				list.appendChild(empty);
			}
			wireMgrDnD(list, kind);
		}
		function renderCollectionsManager() {
			if (!collectionsDialog) return;
			collDelDisarm();           // rows are about to be replaced
			collDelArmedBtn = null;
			collEditEnd();             // drop any in-flight rename state
			renderManagerList(collectionsDialog.querySelector('.ml-collections-list'), sharedCollections, 'coll');
			renderManagerList(collectionsDialog.querySelector('.ml-bookmarks-list'), bookmarks, 'bm');
		}
		// Drag-and-drop within ONE list (one store). `into` is gated by
		// mgrCanParent so a bookmark can only ever drop INTO a folder.
		function wireMgrDnD(list, kind) {
			if (!list) return;
			var dragId = null;
			function clearMarks() {
				Array.prototype.forEach.call(list.querySelectorAll('.ml-coll-row'), function (r) {
					r.classList.remove('ml-drop-before', 'ml-drop-after', 'ml-drop-into');
				});
			}
			Array.prototype.forEach.call(list.querySelectorAll('.ml-coll-row'), function (li) {
				li.addEventListener('dragstart', function (e) {
					dragId = li.dataset.collId;
					li.classList.add('ml-dragging');
					if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
				});
				li.addEventListener('dragend', function () { li.classList.remove('ml-dragging'); clearMarks(); dragId = null; });
				li.addEventListener('dragover', function (e) {
					if (!dragId || li.dataset.collId === dragId) return;
					var arr = collStoreArr(kind);
					var targetId = li.dataset.collId;
					if (collIsDescendant(arr, targetId, dragId)) return;                 // not into own subtree
					var rect = li.getBoundingClientRect();
					var rel = (e.clientY - rect.top) / rect.height;
					var canInto = mgrCanParent(kind, collById(arr, targetId))
						&& collDepth(arr, targetId) + 1 + collHeight(arr, dragId) <= COLL_MAX_DEPTH - 1;
					var canSibling = collDepth(arr, targetId) + collHeight(arr, dragId) <= COLL_MAX_DEPTH - 1;
					var intent = (canInto && rel > 0.33 && rel < 0.67) ? 'into'
						: (rel < 0.5 ? 'before' : 'after');
					if (intent !== 'into' && !canSibling) { if (canInto) intent = 'into'; else return; }
					e.preventDefault();
					if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
					clearMarks();
					li.dataset.dropIntent = intent;
					li.classList.add(intent === 'into' ? 'ml-drop-into' : (intent === 'before' ? 'ml-drop-before' : 'ml-drop-after'));
				});
				li.addEventListener('drop', function (e) {
					if (!dragId || li.dataset.collId === dragId) return;
					e.preventDefault();
					var targetId = li.dataset.collId;
					var intent = li.dataset.dropIntent || 'after';
					clearMarks();
					if (intent === 'into') collNest(kind, dragId, targetId);
					else collPlace(kind, dragId, targetId, intent === 'after');
				});
			});
		}
		// Create an empty team container at the top level and open it straight into
		// inline rename (like making a new folder in Finder). For collections an
		// empty record is a valid container (nest subgroups / fill later); for
		// bookmarks it's a folder (empty qs) that only groups child bookmarks.
		function collNewEmpty(kind) {
			if (!canManageShared) return;
			var arr = collStoreArr(kind);
			var c = (kind === 'bm')
				? { id: newCollectionId(), name: labels.bmNewFolderName || 'New folder', qs: '', parent: '' }
				: { id: newCollectionId(), name: labels.collNewName || 'New collection', keys: [], parent: '' };
			arr.unshift(c);
			saveSharedPrefs();
			renderCollectionsManager();
			rerenderBookmarksList();
			var listSel = (kind === 'bm') ? '.ml-bookmarks-list' : '.ml-collections-list';
			var list = collectionsDialog && collectionsDialog.querySelector(listSel);
			var row = list && list.querySelector('.ml-coll-row[data-coll-id="' + c.id + '"]');
			var editBtn = row && row.querySelector('.ml-coll-move[data-act="edit"]');
			if (editBtn) collEditStart(kind, c.id, row, editBtn);
		}
		function openCollectionsManager() {
			collActiveKind = null; collActiveId = null;   // fresh open → no active row
			renderCollectionsManager();
			if (!collectionsDialog) return;
			if (typeof collectionsDialog.showModal === 'function') collectionsDialog.showModal();
			else collectionsDialog.setAttribute('open', '');
			// showModal() auto-focuses the first focusable element. Here that's a
			// reorder button (no checkbox like the tag list has), which would then
			// match :focus-visible and reveal itself on open. Drop that focus.
			if (collectionsDialog.contains(document.activeElement)
				&& document.activeElement !== collectionsDialog) document.activeElement.blur();
		}
		// Switch the manager between the Collections and Bookmarks panes.
		function mgrShowPane(pane) {
			if (!collectionsDialog) return;
			collDelDisarm(); collEditCancel();
			Array.prototype.forEach.call(collectionsDialog.querySelectorAll('.ml-mgr-tabs > li'), function (li) {
				li.classList.toggle('uk-active', li.dataset.pane === pane);
			});
			Array.prototype.forEach.call(collectionsDialog.querySelectorAll('.ml-mgr-pane'), function (p) {
				p.hidden = (p.dataset.pane !== pane);
			});
		}
		// One-time wiring: tabs, row controls (delegated over BOTH lists),
		// new-container buttons, open / close.
		if (collectionsDialog) {
			var collList = collectionsDialog.querySelector('.ml-collections-list');
			if (collList) {
				collectionsDialog.addEventListener('click', function (e) {
					var btn = e.target.closest && e.target.closest('.ml-coll-move, .ml-coll-caret');
					if (!btn) {
						// Tapping a row body (not a control, not the rename input) makes it
						// the active row, so its controls reveal — touch has no hover to do
						// that, and the controls only show for the active row there.
						if (e.target.closest && e.target.closest('.ml-coll-edit-input')) return;
						var rowEl = e.target.closest && e.target.closest('.ml-coll-row');
						if (rowEl && rowEl.dataset.collId) setMgrActive(rowEl.dataset.store || 'coll', rowEl.dataset.collId);
						return;
					}
					e.preventDefault();
					var li = btn.closest('li');
					if (!li) return;
					var kind = li.dataset.store || 'coll';
					var id = li.dataset.collId;
					var act = btn.dataset.act;
					// This row becomes the active one (highlight + controls). For
					// reorders the list re-renders right after; buildCollRow re-applies
					// the class from collActiveId, so the highlight follows the moved row.
					setMgrActive(kind, id);
					if (act === 'edit') {
						// Inline rename: first ✎ starts editing (✎ → ✓), ✓ commits.
						if (collEditInput && collEditBtn === btn) collEditCommit();
						else collEditStart(kind, id, li, btn);
						return;
					}
					if (act === 'del') {
						// Inline delete confirm — same as the tag manager: first × arms
						// (turns red ✓), second × confirms; auto-disarms after a few sec.
						if (btn === collDelArmedBtn) { collDelDisarm(); collDelete(kind, id); }
						else { collDelDisarm(); collDelArm(btn, li); }
						return;
					}
					collDelDisarm();   // any other control cancels a pending delete
					collEditCancel();  // …and a pending rename
					if (act === 'up') collMove(kind, id, 'up');
					else if (act === 'down') collMove(kind, id, 'down');
					else if (act === 'nest') collIndent(kind, id);
					else if (act === 'unnest') collOutdent(kind, id);
					else if (act === 'toggle') {
						var k = collKey(kind, id);
						if (collCollapsed[k]) delete collCollapsed[k]; else collCollapsed[k] = true;
						renderCollectionsManager();   // manager-local, no persist
					}
					// After a reorder the list is rebuilt; keep focus on the MOVED row at
					// its new position so its highlight + controls follow it (otherwise the
					// browser re-focuses by pointer position -> the row now in the old slot).
					if (act === 'up' || act === 'down' || act === 'nest' || act === 'unnest') {
						requestAnimationFrame(function () {
							var mlist = collectionsDialog.querySelector(kind === 'bm' ? '.ml-bookmarks-list' : '.ml-collections-list');
							var mrow = mlist && mlist.querySelector('.ml-coll-row[data-coll-id="' + id + '"]');
							var mbtn = mrow && (mrow.querySelector('.ml-coll-move[data-act="' + act + '"]') || mrow.querySelector('.ml-coll-move'));
							if (mbtn) mbtn.focus();
						});
					}
				});
			}
			collectionsDialog.addEventListener('click', function (e) {
				var mtab = e.target.closest && e.target.closest('.ml-mgr-tabs > li');
				if (mtab) { e.preventDefault(); mgrShowPane(mtab.dataset.pane); return; }
				var nu = e.target.closest && e.target.closest('.ml-coll-new');
				if (nu) {
					e.preventDefault();
					// "+ New" lives on the tab bar now → create in whichever store the
					// active tab shows.
					var activeTab = collectionsDialog.querySelector('.ml-mgr-tabs > li.uk-active');
					collNewEmpty(activeTab && activeTab.dataset.pane === 'bm' ? 'bm' : 'coll');
					return;
				}
				if (e.target === collectionsDialog) collectionsDialog.close();
				if (e.target.closest && e.target.closest('.ml-collections-close')) collectionsDialog.close();
			});
			document.addEventListener('click', function (e) {
				var open = e.target.closest && e.target.closest('.ml-collections-manage a, .ml-collections-manage, .ml-bar-manage a, .ml-bar-manage');
				if (!open) return;
				e.preventDefault();
				openCollectionsManager();
			});
		}

		// Short, URL-safe id for a new collection.
		function newCollectionId() {
			return Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
		}

		function openBookmarkAddDialog() {
			// A checkbox selection ALWAYS means "save as collection" — even with
			// an active filter (the filter is just how you got to the selection).
			// No selection + active filter → save the filter as a bookmark.
			var selKeys = (typeof selection !== 'undefined') ? Array.from(selection) : [];
			var isCollection = selKeys.length > 0;
			var current = canonicalFilterQs(location.search);
			if (!isCollection && current === '') {
				announce(labels.bookmarkEmpty || 'Apply some filters first.');
				return;
			}
			var dialog = document.createElement('dialog');
			dialog.className = 'ml-bookmark-dialog';

			var header = document.createElement('header');
			header.textContent = isCollection
				? (labels.collectionSave || 'Save collection')
				: (labels.bookmarkSave || 'Save bookmark');
			dialog.appendChild(header);

			var hint = document.createElement('p');
			hint.className = 'ml-popup-hint';
			hint.textContent = isCollection
				? (labels.collectionHint || 'Saves the %d selected image(s) as a named collection.')
					.replace('%d', String(selKeys.length))
				: (labels.bookmarkHint || 'Saves the active filter combination under a name.');
			dialog.appendChild(hint);

			var input = document.createElement('input');
			input.type = 'text';
			input.className = 'uk-input';
			input.required = true;
			input.maxLength = 80;
			dialog.appendChild(input);

			// No "share with the team" toggle any more — bookmarks AND collections
			// are both team-wide now, created only by managers.

			var footer = document.createElement('footer');
			var cancelBtn = document.createElement('button');
			cancelBtn.type = 'button';
			cancelBtn.className = 'uk-button uk-button-secondary';
			cancelBtn.textContent = labels.cancel || 'Cancel';
			var saveBtn = document.createElement('button');
			saveBtn.type = 'button';
			saveBtn.className = 'uk-button uk-button-primary';
			saveBtn.textContent = labels.save || 'Save';
			footer.appendChild(cancelBtn);
			footer.appendChild(saveBtn);
			dialog.appendChild(footer);

			document.body.appendChild(dialog);
			dialog.addEventListener('close', function () { dialog.remove(); });
			function cleanup() { if (dialog.open) dialog.close(); }
			cancelBtn.addEventListener('click', cleanup);

			function commit() {
				var name = input.value.trim();
				if (!name) { input.focus(); return; }
				// Both bookmarks and collections are team-wide now → managers only.
				if (!canManageShared) { cleanup(); return; }
				if (isCollection) {
					var c = { id: newCollectionId(), name: name, keys: selKeys, parent: '' };
					sharedCollections.push(c);
				} else {
					var b = { id: newCollectionId(), name: name, qs: current, parent: '' };
					bookmarks.push(b);
				}
				cleanup();
				rerenderBookmarksList();
				saveSharedPrefs();
				// A new collection consumes the selection → uncheck the boxes as
				// confirmation (also flips the bar back out of collection mode).
				if (isCollection) clearSelectionConfirm();
				announce(isCollection
					? (labels.collectionSaved || 'Collection saved')
					: (labels.bookmarkSaved || 'Bookmark saved'));
				// Jump straight into the manager (on the matching tab) so the user
				// can place / sort the just-added entry right away.
				openCollectionsManager();
				mgrShowPane(isCollection ? 'coll' : 'bm');
			}
			saveBtn.addEventListener('click', commit);
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); commit(); }
			});

			dialog.showModal();
			input.focus();
		}

		// Click delegation on the bookmarks bar — wraps Add, tab
		// activation and delete in one handler so reorder via re-
		// rendering doesn't invalidate listeners.
		// Confirmation after a curate action: uncheck every selected box, drop
		// the selection, refresh dependent UI (select-all header, cursor state).
		function clearSelectionConfirm() {
			selection.clear();
			if (results) {
				results.querySelectorAll('.ml-select-row:checked').forEach(function (cb) { cb.checked = false; });
			}
			syncSelectAllHeader();
			syncBookmarkActive();
		}

		// Adjust the visible "… N images" summary by a delta (e.g. after a remove
		// pulls rows out of the current view).
		function bumpPaginationTotal(delta) {
			if (!delta) return;
			document.querySelectorAll('.ml-pagination-summary').forEach(function (el) {
				el.textContent = el.textContent.replace(/\b(\d+)(\s+image)/, function (m, num, suf) {
					return Math.max(0, parseInt(num, 10) + delta) + suf;
				});
			});
		}

		// Add the current selection to an EXISTING collection (one you're not
		// viewing). Merge + de-dupe; confirm by clearing the selection.
		function addSelectionToCollection(collId) {
			var selKeys = Array.from(selection);
			var found = findCollection(collId);
			if (!found || !selKeys.length) return;
			if (found.shared && !canManageShared) return;   // read-only for non-managers
			var coll = found.coll;
			var before = coll.keys.length;
			coll.keys = Array.from(new Set(coll.keys.concat(selKeys)));
			var added = coll.keys.length - before;
			if (found.shared) saveSharedPrefs(); else saveUserPrefs();
			clearSelectionConfirm();
			announce((labels.collectionUpdated || 'Added %d image(s) to the collection')
				.replace('%d', String(added)));
		}

		// Remove the current selection FROM the collection you're viewing. Pulls
		// the rows out of the grid in place (no server round-trip → no race with
		// the debounced save), bumps the count, clears the selection.
		function removeSelectionFromCollection(collId) {
			var selKeys = Array.from(selection);
			var found = findCollection(collId);
			if (!found || !selKeys.length) return;
			if (found.shared && !canManageShared) return;   // read-only for non-managers
			var coll = found.coll;
			var dropSet = {};
			selKeys.forEach(function (k) { dropSet[k] = true; });
			var was = coll.keys.length;
			coll.keys = coll.keys.filter(function (k) { return !dropSet[k]; });
			var removed = was - coll.keys.length;
			selKeys.forEach(function (k) {
				// k is wrapped in quotes here, so the raw value is correct (row
				// keys never contain a double-quote).
				if (results) {
					var cb = results.querySelector('.ml-select-row[data-key="' + k + '"]');
					var row = cb && cb.closest ? cb.closest('.ml-row, .ml-card, tr') : null;
					if (row) row.remove();
				}
			});
			if (found.shared) saveSharedPrefs(); else saveUserPrefs();
			clearSelectionConfirm();
			bumpPaginationTotal(-removed);
			announce((labels.collectionRemoved || 'Removed %d image(s) from the collection')
				.replace('%d', String(removed)));
		}

		document.addEventListener('click', function (e) {
			if (!e.target.closest) return;
			var add = e.target.closest('.ml-bookmarks-add a');
			if (add) {
				e.preventDefault();
				openBookmarkAddDialog();
				return;
			}
			var del = e.target.closest('.ml-bookmark-del');
			if (del) {
				e.preventDefault();
				e.stopPropagation();
				var li = del.closest('li');
				if (!li) return;
				// Shared (team-wide) entries route their write to the shared store;
				// the × only exists for managers, but guard the write anyway.
				var isShared = li.dataset.shared === '1';
				if (isShared && !canManageShared) return;
				// Collection delete (li carries data-coll-id) vs filter-bookmark
				// delete (li carries data-bookmark-id).
				if (li.dataset.collId) {
					var cid = li.dataset.collId;
					// Drop the collection, then re-flatten so any children of a
					// deleted parent are promoted to top level (collFlatten clears
					// orphaned parent ids) instead of vanishing from the strip.
					if (isShared) {
						sharedCollections = collFlatten(sharedCollections.filter(function (c) { return c && c.id !== cid; }));
					} else {
						collections = collFlatten(collections.filter(function (c) { return c && c.id !== cid; }));
					}
					rerenderBookmarksList();
					if (isShared) saveSharedPrefs(); else saveUserPrefs();
					announce(isShared
						? (labels.sharedDeleted || 'Removed from the team')
						: (labels.collectionDeleted || 'Collection deleted'));
					// If we're currently viewing the deleted collection, drop ?coll
					// from the URL and reload (keep any other filters that were on).
					if (currentColl() === cid) {
						var u = new URLSearchParams(location.search.replace(/^\?/, ''));
						u.delete('coll');
						var rest = u.toString();
						replaceFromQs(rest ? '?' + rest : '', true, true);
					}
					return;
				}
				// Filter bookmark (team store) delete, by id. Managers only.
				if (!canManageShared) return;
				var bid = li.dataset.bookmarkId;
				if (!bid) return;
				bookmarks = bookmarks.filter(function (b) { return b && b.id !== bid; });
				rerenderBookmarksList();
				saveSharedPrefs();
				announce(labels.bookmarkDeleted || 'Bookmark deleted');
				return;
			}
			var tab = e.target.closest('a.ml-bookmark');
			if (tab) {
				e.preventDefault();
				// Curate mode: while a selection exists, clicking a collection tab
				// ADDS the selection to it (a collection you're not viewing) or
				// REMOVES it (the collection you're currently inside — its tab is
				// active). The cursor over the tab signals which. No selection →
				// normal recall.
				var tLi = tab.closest('li');
				var tCid = tLi && tLi.dataset.collId;
				var hasSelNow = (typeof selection !== 'undefined') && selection.size > 0;
				// Shared collections are read-only for non-managers: skip curate and
				// fall through to normal recall so the click still does something.
				var curatable = !(tLi && tLi.dataset.shared === '1') || canManageShared;
				if (tCid && hasSelNow && curatable) {
					// A parent collection is a read-only union of its subgroups —
					// curate the leaves, not the group.
					if (collIsParent(collections, tCid) || collIsParent(sharedCollections, tCid)) {
						announce(labels.collectionParentReadonly || 'Curate the subgroups, not the group.');
						return;
					}
					if (tLi.classList.contains('uk-active')) removeSelectionFromCollection(tCid);
					else addSelectionToCollection(tCid);
					return;
				}
				// A bookmark FOLDER (data-bookmark-id, empty qs) has no filter — its
				// only job is to reveal its children on hover, so a click does
				// nothing (clicking through to qs='' would wrongly reset to "show all").
				if (tLi && tLi.dataset.bookmarkId && (tab.dataset.qs || '') === '') return;
				var qs = tab.dataset.qs || '';
				applyBookmarkToForm(qs);
				// Bookmark switches the filter set → clear the selection.
				replaceFromQs(qs, true, true);
				syncBookmarkActive();
				return;
			}
		});

		// Push the bookmark's filter values onto the filter form so
		// what the user SEES matches what was just applied. Without
		// this, the form keeps showing the previous filter's selects
		// + checkboxes while the table reflects the bookmark — and
		// the next Apply would resurrect the stale values.
		function applyBookmarkToForm(qs) {
			var filterForm = document.querySelector('.ml-filter-bar');
			if (!filterForm) return;
			if (typeof root._mlClearFilterForm === 'function') {
				root._mlClearFilterForm();
			}
			var params = new URLSearchParams((qs || '').replace(/^\?/, ''));
			params.forEach(function (v, k) {
				if (k === 'tags' && v) {
					v.split(',').forEach(function (tag) {
						filterForm.querySelectorAll('input[name="tags[]"]').forEach(function (cb) {
							if (cb.value === tag) cb.checked = true;
						});
					});
					return;
				}
				var input = filterForm.querySelector('[name="' + k + '"]');
				if (!input) return;
				if (input.type === 'checkbox' || input.type === 'radio') {
					input.checked = (v === '1' || v === 'on' || v === input.value);
				} else {
					input.value = v;
				}
			});
			// Re-run the live narrowing + label updates so the form
			// state is internally consistent (template→field hide
			// rules, missing-X capability gates, "(N)" suffix).
			if (typeof root._mlApplyTemplateFieldFilter === 'function') root._mlApplyTemplateFieldFilter();
			if (typeof root._mlApplyFieldCapabilityFilter === 'function') root._mlApplyFieldCapabilityFilter();
			if (typeof root._mlUpdateResetVisibility === 'function') root._mlUpdateResetVisibility();
			if (typeof root._mlRecomputeFilterLabels === 'function') root._mlRecomputeFilterLabels();
		}

		// Reflect URL changes (back/forward, AJAX swap from filter
		// bar) onto the bookmark active marker.
		window.addEventListener('popstate', syncBookmarkActive);
		root._mlSyncBookmarkActive = syncBookmarkActive;
		// Build the strip from the in-memory model on load so nested collections
		// render as top-level tabs + hover flyouts (the server emits only the
		// top-level tabs to avoid a flat-children flash). Falls back to a plain
		// active-sync if the container isn't present.
		if (bookmarksContainer()) rerenderBookmarksList(); else syncBookmarkActive();

		// Sync the <li> order in the picker to match columnsOrder
		// (after init from user-meta, before drag-drop wiring).
		function syncColumnListOrder() {
			var list = document.querySelector('.ml-columns-list');
			if (!list || !columnsOrder.length) return;
			var byCol = {};
			Array.prototype.forEach.call(list.querySelectorAll('li'), function (li) {
				var cb = li.querySelector('input[type="checkbox"]');
				if (cb) byCol[cb.dataset.col] = li;
			});
			columnsOrder.forEach(function (col) {
				if (byCol[col]) list.appendChild(byCol[col]);
			});
		}

		// Native HTML5 drag-drop on the column-picker <li>s. On drop
		// we re-read the list order from the DOM into columnsOrder,
		// persist + re-sort the table to match.
		function wireColumnDragDrop() {
			var list = document.querySelector('.ml-columns-list');
			if (!list) return;
			var dragged = null;
			Array.prototype.forEach.call(list.querySelectorAll('li'), function (li) {
				li.addEventListener('dragstart', function (e) {
					dragged = li;
					li.classList.add('ml-dragging');
					if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
				});
				li.addEventListener('dragend', function () {
					li.classList.remove('ml-dragging');
					dragged = null;
					columnsOrder = Array.prototype.map.call(
						list.querySelectorAll('li input[type="checkbox"]'),
						function (cb) { return cb.dataset.col; }
					);
					saveUserPrefs();
					applyColumnOrder();
				});
				li.addEventListener('dragover', function (e) {
					if (!dragged || dragged === li) return;
					e.preventDefault();
					var rect = li.getBoundingClientRect();
					var before = (e.clientY - rect.top) < rect.height / 2;
					list.insertBefore(dragged, before ? li : li.nextSibling);
				});
			});
		}

		syncColumnListOrder();

		var colToggles = document.querySelectorAll('.ml-col-toggle');
		Array.prototype.forEach.call(colToggles, function (cb) {
			var col = cb.dataset.col;
			cb.checked = !isColumnHidden(col);
			cb.addEventListener('change', function () {
				columnsState[col] = cb.checked;
				saveUserPrefs();
				applyColumnVisibility();
			});
		});
		wireColumnDragDrop();

		// Keyboard-friendly reorder: each row in the columns dialog
		// carries ▲ / ▼ buttons that swap the row with its
		// predecessor / successor and persist + re-sort the table
		// just like the drag path. Focus stays on the moved button so
		// repeated presses keep working without re-tabbing.
		(function wireColumnReorderButtons() {
			var list = document.querySelector('.ml-columns-list');
			if (!list) return;
			list.addEventListener('click', function (e) {
				var btn = e.target.closest && e.target.closest('.ml-col-move');
				if (!btn) return;
				e.preventDefault();
				var li = btn.closest('li');
				if (!li) return;
				var dir = btn.dataset.dir;
				if (dir === 'up' && li.previousElementSibling) {
					list.insertBefore(li, li.previousElementSibling);
				} else if (dir === 'down' && li.nextElementSibling) {
					list.insertBefore(li.nextElementSibling, li);
				} else {
					return;
				}
				columnsOrder = Array.prototype.map.call(
					list.querySelectorAll('li input[type="checkbox"]'),
					function (cb) { return cb.dataset.col; }
				);
				saveUserPrefs();
				applyColumnOrder();
				btn.focus();
			});
		})();
		applyColumnVisibility();
		applyColumnOrder();

		// -- Columns dialog open / close ------------------------------
		// The "Columns…" button lives in the pagination row inside
		// .ml-results, so it's recreated on every AJAX swap — handler
		// is delegated on document to survive that. The <dialog>
		// itself is a sibling of .ml-results and stays put for the
		// life of the page, which is why the drag/toggle wiring above
		// (bound once at init) keeps working.
		var columnsDialog = document.querySelector('.ml-columns-dialog');
		if (columnsDialog) {
			document.addEventListener('click', function (e) {
				var openBtn = e.target.closest && e.target.closest('.ml-columns-toggle');
				if (openBtn) {
					e.preventDefault();
					if (typeof columnsDialog.showModal === 'function') {
						columnsDialog.showModal();
					} else {
						columnsDialog.setAttribute('open', '');
					}
					// Drop the auto-focus so the first row's controls don't reveal
					// themselves on open (the tag list's first focusable is a neutral
					// checkbox; here it'd be a reorder button).
					if (columnsDialog.contains(document.activeElement)
						&& document.activeElement !== columnsDialog) document.activeElement.blur();
					return;
				}
				if (e.target.classList && e.target.classList.contains('ml-columns-close')) {
					e.preventDefault();
					columnsDialog.close();
				}
			});
			// Click on the dialog's backdrop (outside the inner content
			// box) closes too. Native <dialog> fires the click on the
			// dialog itself for backdrop clicks; we check that the
			// event target is the dialog (not a child).
			columnsDialog.addEventListener('click', function (e) {
				if (e.target === columnsDialog) columnsDialog.close();
			});
		}

		// -- Export link ----------------------------------------------
		// Server renders the link with the filter URL it knew at
		// render time, but AJAX filter swaps push new state into
		// location.search without re-rendering this bar — so we
		// always rebuild the href from the live URL at click time.
		// Browser sees Content-Disposition: attachment on the
		// response and triggers a download without navigating away.
		var exportLinks = document.querySelectorAll('.ml-export-link');
		var exportVariantSel = document.querySelector('.ml-export-variant');
		Array.prototype.forEach.call(exportLinks, function (link) {
			link.addEventListener('click', function (e) {
				var base = link.dataset.exportBase
					|| link.href.split('?')[0];
				if (!base) return;
				e.preventDefault();
				var qs = location.search || '';
				if (link.dataset.format === 'csv') {
					qs += (qs ? '&' : '?') + 'format=csv';
				}
				// Image-URL variant — append only when the user picked
				// something other than the default so clean URLs stay
				// clean. Values are: "original" (omit) | "260" | "512"
				// | "1024".
				var variant = exportVariantSel ? exportVariantSel.value : '';
				if (variant && variant !== 'original') {
					qs += (qs ? '&' : '?') + 'urlVariant=' + encodeURIComponent(variant);
				}
				window.location.href = base + qs;
			});
		});

		// -- Import form ----------------------------------------------
		// Submit via fetch so the user stays on their current filter
		// view; the import endpoint returns JSON with succeeded /
		// skipped / failed counts. On success the table re-renders
		// from the same query string so updated values appear.
		var importForm = document.querySelector('.ml-import-form');
		var importStatus = document.querySelector('.ml-import-status');
		if (importForm) {
			importForm.addEventListener('submit', function (e) {
				e.preventDefault();
				if (!importStatus) importStatus = document.querySelector('.ml-import-status');
				if (importStatus) importStatus.textContent = labels.importing || 'Importing…';

				var fd = new FormData(importForm);
				fetch(importForm.action, {
					method: 'POST',
					body: fd,
					credentials: 'same-origin'
				}).then(function (res) {
					return res.json().then(function (data) {
						return { status: res.status, data: data };
					});
				}).then(function (result) {
					var d = (result && result.data) || {};
					if (!d.ok) {
						if (importStatus) importStatus.textContent =
							(labels.importError || 'Import failed') + ': ' + (d.error || 'Unknown error');
						return;
					}
					var parts = [
						(labels.importSaved || 'Saved') + ': ' + (d.succeeded || 0),
						(labels.importSkipped || 'Unchanged') + ': ' + (d.skipped || 0),
						(labels.importFailed || 'Failed') + ': ' + ((d.failed || []).length)
					];
					var msg = parts.join('  ·  ');
					if (d.failed && d.failed.length) {
						msg += '\n' + d.failed.join('\n');
					}
					if (importStatus) importStatus.textContent = msg;
					importForm.reset();
					if (d.succeeded > 0) replaceFromQs(location.search, false);
				}).catch(function (err) {
					if (importStatus) importStatus.textContent =
						(labels.importError || 'Import failed') + ': ' + (err && err.message || 'Network error');
				});
			});
		}

		// -- Browser back/forward --------------------------------------

		window.addEventListener('popstate', function () {
			replaceFromQs(location.search, false);
		});

		// Expose applyColumnVisibility for replaceFromQs to call after
		// it swaps results.innerHTML with freshly-rendered cells.
		root._mlApplyColumnVisibility = applyColumnVisibility;
		root._mlApplyColumnOrder      = applyColumnOrder;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
