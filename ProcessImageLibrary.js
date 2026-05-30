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
				credentials: 'same-origin'
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
			results.querySelectorAll('.ml-table tbody tr').forEach(function (tr) {
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

			if (td.dataset.input === 'textarea') {
				return buildPopupTextarea(original);
			}
			return buildPopupTextInput(original, '');
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

		function buildPopupCheckboxes(td, original) {
			var allowed = [];
			try { allowed = JSON.parse(td.dataset.tagsAllowed || '[]'); }
			catch (e) { allowed = []; }

			var currentSet = Object.create(null);
			original.split(/\s+/).filter(Boolean).forEach(function (t) {
				currentSet[t] = true;
			});

			var wrap = document.createElement('div');
			wrap.className = 'ml-popup-tag-list';
			allowed.forEach(function (tag) {
				var label = document.createElement('label');
				var cb = document.createElement('input');
				cb.type = 'checkbox';
				cb.className = 'uk-checkbox';
				cb.value = tag;
				cb.checked = !!currentSet[tag];
				label.appendChild(cb);
				label.appendChild(document.createTextNode(' ' + tag));
				wrap.appendChild(label);
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

		// All cell edits run through one popup. The native <dialog>
		// gives a roomy editing canvas regardless of subfield type
		// (textarea, single-line text, whitelisted-tag checkboxes) and
		// keeps the table row from shifting under the user. Esc and
		// backdrop click dismiss; Save commits.
		function activateEditor(td) {
			if (td.classList.contains('ml-editing')) return;
			td.classList.add('ml-editing');

			var original = td.textContent;
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
			if (batch && td.dataset.input !== 'filename') {
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
				setCellText(td, (td.dataset.subfield === 'tags')
					? primaryValue
					: resolveTemplateClient(primaryValue, singleCtx));
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
						setCellText(td, original);
						var reason = (result && result.data && result.data.error)
							|| ('HTTP ' + (result && result.status));
						console.error('[ImageLibrary] save failed:', result);
						td.title = reason;
						flashCell(td, false);
					}
				}).catch(function (err) {
					if (!td.isConnected) return;
					td.classList.remove('ml-cell-saving');
					setCellText(td, original);
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

		// -- Replace image (click icon + drag-and-drop) ----------------
		// Two entry paths share the same /replace/ endpoint:
		//   1) The per-row Replace icon opens a hidden file picker.
		//   2) Dragging a file from the OS onto a row drops it onto
		//      that row's (pageId, field, basename) target.
		// Constraints: single file per drop, extension must match the
		// existing image's (server enforces too; client guards for a
		// nicer error). Editable rows only — non-editable rows have
		// no data-page-id, so the handlers never resolve a target.

		function isEditableRow(tr) {
			return tr && tr.matches && tr.matches('.ml-table tbody tr[data-page-id]');
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
			var tr = btn.closest('tr');
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
				var tr = e.target.closest && e.target.closest('tr');
				if (e.dataTransfer) {
					e.dataTransfer.dropEffect = isEditableRow(tr) ? 'copy' : 'none';
				}
			});
			results.addEventListener('dragenter', function (e) {
				if (!dragHasFiles(e)) return;
				var tr = e.target.closest && e.target.closest('tr');
				if (!isEditableRow(tr)) return;
				tr.classList.add('ml-row-drop-target');
			});
			results.addEventListener('dragleave', function (e) {
				var tr = e.target.closest && e.target.closest('tr');
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
				var tr = e.target.closest && e.target.closest('tr');
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

			var list = document.createElement('ul');
			list.className = 'ml-delete-confirm-list';
			var show = Math.min(items.length, 8);
			for (var i = 0; i < show; i++) {
				var li = document.createElement('li');
				li.textContent = items[i].basename;
				list.appendChild(li);
			}
			if (items.length > show) {
				var more = document.createElement('li');
				more.textContent = '… +' + (items.length - show) + ' more';
				list.appendChild(more);
			}
			dialog.appendChild(list);

			var warn = document.createElement('p');
			warn.className = 'ml-delete-confirm-warn';
			warn.textContent = labels.deleteWarn || 'This cannot be undone.';
			dialog.appendChild(warn);

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
		}

		// Build a (data-key → <tr>) map once so we don't have to escape
		// arbitrary basenames into CSS attribute selectors. Refreshed
		// per call since AJAX swaps can replace .ml-results contents.
		function rowsByKey() {
			var map = {};
			if (!results) return map;
			results.querySelectorAll('.ml-select-row').forEach(function (cb) {
				var tr = cb.closest('tr');
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
			var tr = btn.closest('tr');
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
					// Bulk-selection checkboxes handle their own state via the
					// change listener below — don't open an editor for them.
					if (e.target.classList && (
						e.target.classList.contains('ml-select-row') ||
						e.target.classList.contains('ml-select-all')
					)) return;
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
				// Reset clears the filter → clear the selection too.
				replaceFromQs('', true, true);
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
		var bookmarks = (userPrefs.bookmarks && Array.isArray(userPrefs.bookmarks))
			? userPrefs.bookmarks.slice()
			: [];

		var savePrefsTimer = null;
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
					bookmarks: bookmarks
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
			var allow = ['q', 'template', 'field', 'tags', 'no_desc', 'no_tags'];
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

		function syncBookmarkActive() {
			var tabs = document.querySelectorAll('.ml-bookmarks-tabs > li');
			if (!tabs.length) return;
			var current = canonicalFilterQs(location.search);
			tabs.forEach(function (li) {
				li.classList.remove('uk-active');
			});
			// Walk every tab with a .ml-bookmark anchor — that's both
			// "Show all" (qs="") and the saved bookmarks. First match
			// wins the active marker.
			var bookmarkMatched = false;
			tabs.forEach(function (li) {
				var a = li.querySelector('a.ml-bookmark');
				if (!a) return;
				var qs = a.dataset.qs || '';
				if (qs === current && !li.classList.contains('uk-active')) {
					li.classList.add('uk-active');
					// A non-empty match means the current URL state
					// IS a saved bookmark — gates the Add button.
					if (qs !== '') bookmarkMatched = true;
				}
			});
			// Add button visible only when a filter is active that's
			// NOT already a saved bookmark; otherwise hidden.
			var addLi = document.querySelector('.ml-bookmarks-add');
			if (addLi) addLi.hidden = (current === '' || bookmarkMatched);
		}

		function bookmarksContainer() {
			return document.querySelector('.ml-bookmarks-tabs');
		}

		function rerenderBookmarksList() {
			var ul = bookmarksContainer();
			if (!ul) return;
			// Wipe every bookmark <li>, keep "Show all" (first child)
			// and the Add button (last child). Then insert fresh
			// bookmark <li>s in memory order BEFORE the Add button so
			// it stays rightmost.
			var addLi = ul.querySelector('li.ml-bookmarks-add');
			Array.from(ul.children).forEach(function (li, i) {
				if (i === 0 || li === addLi) return;
				li.remove();
			});
			bookmarks.forEach(function (b, idx) {
				var li = document.createElement('li');
				li.dataset.bookmarkIdx = String(idx);
				var a = document.createElement('a');
				a.className = 'ml-bookmark';
				a.href = location.pathname + (b.qs || '');
				a.dataset.qs = b.qs || '';
				a.textContent = b.name;
				li.appendChild(a);
				var del = document.createElement('button');
				del.type = 'button';
				del.className = 'ml-bookmark-del';
				del.setAttribute('aria-label', labels.bookmarkDelete || 'Delete bookmark');
				del.title = labels.bookmarkDelete || 'Delete bookmark';
				del.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
				li.appendChild(del);
				ul.insertBefore(li, addLi);
			});
			syncBookmarkActive();
		}

		function openBookmarkAddDialog() {
			var current = canonicalFilterQs(location.search);
			if (current === '') {
				announce(labels.bookmarkEmpty || 'Apply some filters first.');
				return;
			}
			var dialog = document.createElement('dialog');
			dialog.className = 'ml-bookmark-dialog';

			var header = document.createElement('header');
			header.textContent = labels.bookmarkSave || 'Save bookmark';
			dialog.appendChild(header);

			var hint = document.createElement('p');
			hint.className = 'ml-popup-hint';
			hint.textContent = labels.bookmarkHint || 'Saves the active filter combination under a name.';
			dialog.appendChild(hint);

			var input = document.createElement('input');
			input.type = 'text';
			input.className = 'uk-input';
			input.required = true;
			input.maxLength = 80;
			dialog.appendChild(input);

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
				bookmarks.push({ name: name, qs: current });
				cleanup();
				rerenderBookmarksList();
				saveUserPrefs();
				announce(labels.bookmarkSaved || 'Bookmark saved');
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
				var idx = parseInt(li.dataset.bookmarkIdx, 10);
				if (isNaN(idx) || idx < 0 || idx >= bookmarks.length) return;
				bookmarks.splice(idx, 1);
				rerenderBookmarksList();
				saveUserPrefs();
				announce(labels.bookmarkDeleted || 'Bookmark deleted');
				return;
			}
			var tab = e.target.closest('a.ml-bookmark');
			if (tab) {
				e.preventDefault();
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
		syncBookmarkActive();

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
