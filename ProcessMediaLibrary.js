/* ProcessMediaLibrary — admin script.
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

		var pwCfg = (window.ProcessWire && window.ProcessWire.config && window.ProcessWire.config.ProcessMediaLibrary) || {};
		var config = {
			saveUrl:   pwCfg.saveUrl   || root.dataset.saveUrl   || '',
			renderUrl: pwCfg.renderUrl || root.dataset.renderUrl || '',
			bulkUrl:   pwCfg.bulkUrl   || root.dataset.bulkUrl   || '',
			adminUrl:  pwCfg.adminUrl  || root.dataset.adminUrl  || '',
			tplFields: pwCfg.tplFields || {},
			csrf: pwCfg.csrf || {
				name:  root.dataset.csrfName  || '',
				value: root.dataset.csrfValue || ''
			},
			labels: pwCfg.labels || {}
		};
		if (!config.saveUrl) return;

		var labels     = config.labels;
		var saveQueues = new Map();
		var results    = root.querySelector('.ml-results');
		var filterForm = root.querySelector('.ml-filter-bar');
		var isReplacing = false;
		var isBulking   = false;
		var selection   = new Set();

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
			if (config.csrf && config.csrf.name) {
				fd.append(config.csrf.name, config.csrf.value);
			}
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

			if (subfield === 'tags' && tagsMode === 2) {
				return buildPopupCheckboxes(td, original);
			}
			if (subfield === 'tags' && tagsMode === 1) {
				return buildPopupTextInput(original, td.dataset.tagsListId || '');
			}
			if (td.dataset.input === 'textarea') {
				return buildPopupTextarea(original);
			}
			return buildPopupTextInput(original, '');
		}

		function buildPopupTextarea(original) {
			var ta = document.createElement('textarea');
			ta.value = original;
			ta.rows = 12;
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

			dialog.appendChild(widget.element);

			var batchBar = null;
			function getBatchMode() {
				if (!batchBar) return 'replace';
				var cb = batchBar.querySelector('input[type="radio"]:checked');
				return cb ? cb.value : 'add';
			}
			if (batch) {
				batchBar = document.createElement('div');
				batchBar.className = 'ml-batch-mode';
				var radioName = 'mlBatchMode-' + Math.random().toString(36).slice(2, 8);
				['add', 'replace'].forEach(function (mode) {
					var lbl = document.createElement('label');
					var rb = document.createElement('input');
					rb.type = 'radio';
					rb.name = radioName;
					rb.value = mode;
					if (mode === 'add') rb.checked = true;
					lbl.appendChild(rb);
					lbl.appendChild(document.createTextNode(
						' ' + (mode === 'add' ? (labels.add || 'Add') : (labels.replace || 'Replace'))
					));
					batchBar.appendChild(lbl);
				});
				dialog.appendChild(batchBar);
			}

			var footer = document.createElement('footer');
			var cancelBtn = document.createElement('button');
			cancelBtn.type = 'button';
			// Match the rest of the admin chrome — PW admin is UIkit.
			cancelBtn.className = 'ml-popup-cancel uk-button uk-button-default uk-button-small';
			cancelBtn.textContent = labels.cancel || 'Cancel';
			var saveBtn = document.createElement('button');
			saveBtn.type = 'button';
			saveBtn.className = 'ml-popup-save uk-button uk-button-primary uk-button-small';
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
				var newValue = widget.getValue();

				if (batch) {
					var sendValue = newValue;
					if ((mode || 'replace') === 'add') {
						if (td.dataset.subfield === 'tags') {
							// Tag Add: ship only the delta vs. starting set
							// so we don't broadcast pre-existing tags back
							// to siblings. Server unions anyway, but this
							// keeps the payload honest.
							var origToks = original.split(/\s+/).filter(Boolean);
							var newToks  = newValue.split(/\s+/).filter(Boolean);
							var origSet  = Object.create(null);
							origToks.forEach(function (t) { origSet[t] = true; });
							sendValue = newToks.filter(function (t) { return !origSet[t]; }).join(' ');
						} else if (newValue.indexOf(original) === 0) {
							// Text / textarea Add: strip the editor's
							// starting prefix so only the new tail goes
							// out. If the user edited mid-string, ship
							// the full value as a safe fallback.
							sendValue = newValue.substring(original.length).replace(/^\s+/, '');
						}
						if (sendValue === '') { teardown(); return; }
					}
					teardown();
					td.textContent = '…';
					td.classList.add('ml-cell-saving');
					runBulk('set', {
						subfield: td.dataset.subfield,
						value:    sendValue,
						mode:     mode || 'replace'
					}).then(function (result) {
						var ok = reportBulk(result);
						replaceFromQs(location.search, false);
						if (!ok && td.isConnected) {
							td.textContent = original;
							flashCell(td, false);
						}
					}).catch(function (err) {
						if (!td.isConnected) return;
						td.classList.remove('ml-cell-saving');
						td.textContent = original;
						td.title = (err && err.message) || labels.error || 'Network error';
						flashCell(td, false);
					});
					return;
				}

				if (newValue === original) { teardown(); return; }

				teardown();
				td.textContent = newValue;
				td.classList.add('ml-cell-saving');
				td.title = labels.saving || 'Saving…';

				enqueueSave(td.dataset.pageId, function () {
					return postSave({
						pageId:    td.dataset.pageId,
						fieldName: td.dataset.field,
						basename:  td.dataset.basename,
						subfield:  td.dataset.subfield,
						value:     newValue
					});
				}).then(function (result) {
					if (!td.isConnected) return;
					td.classList.remove('ml-cell-saving');
					if (result && result.data && result.data.ok) {
						td.textContent = result.data.value;
						td.title = '';
						flashCell(td, true);
					} else {
						td.textContent = original;
						td.title = (result && result.data && result.data.error) || labels.error || 'Save failed';
						flashCell(td, false);
					}
				}).catch(function (err) {
					if (!td.isConnected) return;
					td.classList.remove('ml-cell-saving');
					td.textContent = original;
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
			closeBtn.className = 'ml-image-modal-close uk-button uk-button-default uk-button-small';
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
				// Always refresh — the user might have saved inside the
				// iframe before closing. A no-op refresh is cheap.
				replaceFromQs(location.search, false);
			});

			dialog.showModal();
		}

		// -- AJAX re-render --------------------------------------------

		function replaceFromHref(href) {
			var u = new URL(href, location.href);
			replaceFromQs(u.search, true);
		}

		function replaceFromQs(qs, push) {
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
				// New rows = new checkboxes; restore checked state from the
				// persistent selection Set so it survives the swap.
				syncCheckboxes();
				// New cells = lost ml-col-hidden classes; re-apply the
				// user's column visibility prefs to the swapped DOM.
				if (root._mlApplyColumnVisibility) root._mlApplyColumnVisibility();
				if (push) {
					history.pushState({ ml: qs }, '', location.pathname + qs);
				}
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
			if (extra) Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
			if (config.csrf && config.csrf.name) {
				fd.append(config.csrf.name, config.csrf.value);
			}

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

		function reportBulk(result) {
			var d = (result && result.data) || {};
			if (!d.ok) {
				alert(d.error || labels.error || 'Bulk action failed');
				return false;
			}
			var failedCount = (d.failed || []).length;
			if (failedCount) {
				var msg = (labels.bulkResult || 'Succeeded: %1$d  ·  Failed: %2$d')
					.replace('%1$d', d.succeeded)
					.replace('%2$d', failedCount);
				alert(msg + '\n\n' + d.failed.join('\n'));
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
					var thumbTd = e.target.closest('.ml-cell-thumb[data-file-hash]');
					if (thumbTd) {
						e.preventDefault();
						openImageEditor(thumbTd);
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
					var checked = t.checked;
					results.querySelectorAll('.ml-select-row').forEach(function (cb) {
						cb.checked = checked;
						if (checked) selection.add(cb.dataset.key);
						else selection.delete(cb.dataset.key);
					});
					syncSelectAllHeader();
				} else if (t.classList.contains('ml-page-size-picker')) {
					// Drop ?p — the current page number rarely makes
					// sense at the new size; landing back on page 1 is
					// the least surprising default.
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
			// PW wraps every Inputfield in a <li class="Inputfield_<name>">;
			// hiding the wrapper removes both link AND its layout cell.
			var wrap = filterForm.querySelector('.Inputfield_reset');
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

		if (filterForm) {
			filterForm.addEventListener('input', updateResetVisibility);
			filterForm.addEventListener('change', updateResetVisibility);
			filterForm.addEventListener('change', function (e) {
				if (e.target && e.target.name === 'template') applyTemplateFieldFilter();
			});
			// Sync initial state: narrow the field dropdown to the URL's
			// template, then hide the Reset button if no filters apply
			// (the wrapper renders visible by default — PW doesn't know
			// about our visibility rule).
			applyTemplateFieldFilter();
			updateResetVisibility();

			filterForm.addEventListener('submit', function (e) {
				e.preventDefault();
				var params = new URLSearchParams();
				new FormData(filterForm).forEach(function (v, k) {
					// "apply" is the submit-button name; not a filter value.
					if (k === 'apply') return;
					if (v !== '') params.append(k, v);
				});
				var qs = params.toString() ? '?' + params.toString() : '';
				replaceFromQs(qs, true);
			});

			// "Reset" is an <a href="./">; intercept so it clears via AJAX too.
			// Also wipe the form's visible state — form.reset() goes back to
			// the values present at page load, which here ARE the user's
			// active filters, so we have to clear inputs manually.
			filterForm.addEventListener('click', function (e) {
				var reset = e.target.closest && e.target.closest('a[href="./"]');
				if (!reset) return;
				e.preventDefault();
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
				updateResetVisibility();
				replaceFromQs('', true);
			});
		}

		// -- Column visibility toggle ----------------------------------
		// State lives in localStorage, scoped to the install. The
		// server renders every checkbox checked + every cell visible,
		// so on first load with no stored state the table looks the
		// same as before. Checking/unchecking a Columns-fieldset box
		// toggles every <th>/<td>[data-col="X"] in the document via a
		// single class; re-applied after each AJAX results swap so
		// the user's preference survives filter/sort/pagination.
		var COLUMNS_STORAGE_KEY = 'ml-columns-v1';
		var columnsState = {};
		try {
			var stored = localStorage.getItem(COLUMNS_STORAGE_KEY);
			if (stored) columnsState = JSON.parse(stored) || {};
		} catch (e) { columnsState = {}; }

		function applyColumnVisibility() {
			var cells = document.querySelectorAll('[data-col]');
			Array.prototype.forEach.call(cells, function (cell) {
				var hidden = columnsState[cell.dataset.col] === false;
				cell.classList.toggle('ml-col-hidden', hidden);
			});
		}

		function saveColumnsState() {
			try { localStorage.setItem(COLUMNS_STORAGE_KEY, JSON.stringify(columnsState)); }
			catch (e) {}
		}

		var colToggles = document.querySelectorAll('.ml-col-toggle');
		Array.prototype.forEach.call(colToggles, function (cb) {
			var col = cb.dataset.col;
			if (columnsState[col] === false) cb.checked = false;
			cb.addEventListener('change', function () {
				columnsState[col] = cb.checked;
				saveColumnsState();
				applyColumnVisibility();
			});
		});
		applyColumnVisibility();

		// -- Browser back/forward --------------------------------------

		window.addEventListener('popstate', function () {
			replaceFromQs(location.search, false);
		});

		// Expose applyColumnVisibility for replaceFromQs to call after
		// it swaps results.innerHTML with freshly-rendered cells.
		root._mlApplyColumnVisibility = applyColumnVisibility;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
