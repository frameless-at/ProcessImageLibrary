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
		var actionBar  = root.querySelector('.ml-action-bar');
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

		// Build the editor widget for a cell based on its subfield and tags
		// mode. Returns { element, getValue, focus, bindCommit }.
		function buildEditorWidget(td, original) {
			var subfield = td.dataset.subfield;
			var tagsMode = parseInt(td.dataset.tagsMode || '0', 10);

			if (subfield === 'tags' && tagsMode === 2) {
				return buildTagCheckboxEditor(td, original);
			}
			if (subfield === 'tags' && tagsMode === 1) {
				return buildTextEditor(td, original, 'text', td.dataset.tagsListId || '');
			}
			var inputType = td.dataset.input === 'textarea' ? 'textarea' : 'text';
			return buildTextEditor(td, original, inputType, '');
		}

		function buildTextEditor(td, original, inputType, datalistId) {
			var editor = document.createElement(inputType === 'textarea' ? 'textarea' : 'input');
			if (inputType === 'text') editor.type = 'text';
			if (inputType === 'textarea') editor.rows = 3;
			editor.value = original;
			editor.className = 'ml-cell-input';
			if (datalistId) editor.setAttribute('list', datalistId);

			return {
				element: editor,
				getValue: function () { return editor.value; },
				focus: function () { editor.focus(); editor.select(); },
				bindCommit: function (commit, cancel, batch) {
					editor.addEventListener('keydown', function (e) {
						if (e.key === 'Escape') {
							e.preventDefault();
							cancel();
						} else if (!batch && e.key === 'Enter') {
							if (inputType === 'text' || e.ctrlKey || e.metaKey) {
								e.preventDefault();
								commit();
							}
						}
					});
					// In batch mode the explicit Add/Replace/Cancel buttons
					// drive commit — blur must not auto-fire.
					if (!batch) editor.addEventListener('blur', commit);
				}
			};
		}

		function buildTagCheckboxEditor(td, original) {
			var allowed = [];
			try { allowed = JSON.parse(td.dataset.tagsAllowed || '[]'); }
			catch (e) { allowed = []; }

			var current = original.split(/\s+/).filter(Boolean);
			var currentSet = {};
			current.forEach(function (t) { currentSet[t] = true; });

			var wrap = document.createElement('div');
			wrap.className = 'ml-tag-editor';

			var list = document.createElement('div');
			list.className = 'ml-tag-editor-list';
			wrap.appendChild(list);

			allowed.forEach(function (tag) {
				var label = document.createElement('label');
				var cb = document.createElement('input');
				cb.type = 'checkbox';
				cb.value = tag;
				cb.checked = !!currentSet[tag];
				label.appendChild(cb);
				label.appendChild(document.createTextNode(' ' + tag));
				list.appendChild(label);
			});

			var done = document.createElement('button');
			done.type = 'button';
			done.className = 'ml-tag-editor-done';
			done.textContent = labels.done || 'Done';
			wrap.appendChild(done);

			return {
				element: wrap,
				getValue: function () {
					var sel = wrap.querySelectorAll('input[type="checkbox"]:checked');
					return Array.prototype.map.call(sel, function (cb) { return cb.value; }).join(' ');
				},
				focus: function () {
					var first = wrap.querySelector('input[type="checkbox"]');
					if (first) first.focus();
				},
				bindCommit: function (commit, cancel, batch) {
					wrap.addEventListener('keydown', function (e) {
						if (e.key === 'Escape') { e.preventDefault(); cancel(); }
						else if (!batch && e.key === 'Enter') { e.preventDefault(); commit(); }
					});
					if (batch) {
						// Batch mode: hide Done; Add/Replace buttons outside
						// the widget drive commit, click-outside doesn't.
						done.style.display = 'none';
					} else {
						done.addEventListener('click', function (e) {
							e.preventDefault();
							commit();
						});
						// Click outside the editor commits. Capture phase + microtask
						// so the click that opened the editor doesn't fire this.
						setTimeout(function () {
							document.addEventListener('click', function onOutside(e) {
								if (!wrap.contains(e.target)) {
									document.removeEventListener('click', onOutside, true);
									commit();
								}
							}, true);
						}, 0);
					}
				}
			};
		}

		function activateEditor(td) {
			if (td.classList.contains('ml-editing')) return;
			td.classList.add('ml-editing');

			var original = td.textContent;
			var widget   = buildEditorWidget(td, original);
			var batch    = isBatchEdit(td);

			td.textContent = '';
			td.appendChild(widget.element);

			// In batch mode, surface explicit Add / Replace / Cancel buttons
			// so the user picks the merge semantics deliberately rather
			// than auto-committing on blur/Enter.
			var batchBar = null;
			if (batch) {
				batchBar = document.createElement('div');
				batchBar.className = 'ml-batch-buttons';
				var rb = document.createElement('button');
				rb.type = 'button';
				rb.className = 'uk-button uk-button-small uk-button-primary';
				rb.textContent = (labels.replaceN || 'Replace in %d').replace('%d', selection.size);
				var ab = document.createElement('button');
				ab.type = 'button';
				ab.className = 'uk-button uk-button-small uk-button-default';
				ab.textContent = (labels.addN || 'Add to %d').replace('%d', selection.size);
				var xb = document.createElement('button');
				xb.type = 'button';
				xb.className = 'uk-button uk-button-small uk-button-default';
				xb.textContent = labels.cancel || 'Cancel';
				batchBar.appendChild(rb);
				batchBar.appendChild(ab);
				batchBar.appendChild(xb);
				td.appendChild(batchBar);
			}

			setTimeout(widget.focus, 0);

			var committed = false;

			function cancel() {
				if (committed) return;
				committed = true;
				td.classList.remove('ml-editing');
				td.textContent = original;
			}

			function commit(mode) {
				if (committed) return;
				committed = true;
				var newValue = widget.getValue();
				td.classList.remove('ml-editing');

				if (batch) {
					td.textContent = (labels.batching || 'Applying to %d…').replace('%d', selection.size);
					td.classList.add('ml-cell-saving');
					runBulk('set', {
						subfield: td.dataset.subfield,
						value:    newValue,
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

				if (newValue === original) {
					td.textContent = original;
					return;
				}

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

			if (batch) {
				rb.addEventListener('click', function () { commit('replace'); });
				ab.addEventListener('click', function () { commit('add'); });
				xb.addEventListener('click', cancel);
				widget.bindCommit(function () { commit('replace'); }, cancel, true);
			} else {
				widget.bindCommit(function () { commit(); }, cancel, false);
			}
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

		function updateActionBar() {
			if (!actionBar) return;
			var count = selection.size;
			var counter = actionBar.querySelector('.ml-selection-count');
			if (counter) counter.textContent = String(count);
			actionBar.classList.toggle('ml-active', count > 0);
			// Sync select-all header checkbox state.
			var head = results && results.querySelector('.ml-select-all');
			if (head) {
				var rows = results.querySelectorAll('.ml-select-row');
				var checkedRows = results.querySelectorAll('.ml-select-row:checked');
				head.checked = rows.length > 0 && rows.length === checkedRows.length;
				head.indeterminate = checkedRows.length > 0 && checkedRows.length < rows.length;
			}
		}

		function syncCheckboxes() {
			if (!results) return;
			results.querySelectorAll('.ml-select-row').forEach(function (cb) {
				cb.checked = selection.has(cb.dataset.key);
			});
			updateActionBar();
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
			actionBar && actionBar.classList.add('ml-busy');

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
				actionBar && actionBar.classList.remove('ml-busy');
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

		if (actionBar) {
			actionBar.addEventListener('click', function (e) {
				var btn = e.target.closest && e.target.closest('button[data-action]');
				if (!btn) return;
				if (btn.dataset.action === 'clear') {
					selection.clear();
					syncCheckboxes();
				}
			});
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
					updateActionBar();
				} else if (t.classList.contains('ml-select-all')) {
					var checked = t.checked;
					results.querySelectorAll('.ml-select-row').forEach(function (cb) {
						cb.checked = checked;
						if (checked) selection.add(cb.dataset.key);
						else selection.delete(cb.dataset.key);
					});
					updateActionBar();
				}
			});
		}

		// -- Filter form + reset link ----------------------------------

		if (filterForm) {
			filterForm.addEventListener('submit', function (e) {
				e.preventDefault();
				var params = new URLSearchParams();
				new FormData(filterForm).forEach(function (v, k) {
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
					s.selectedIndex = 0;
				});
				replaceFromQs('', true);
			});
		}

		// -- Browser back/forward --------------------------------------

		window.addEventListener('popstate', function () {
			replaceFromQs(location.search, false);
		});

		// Initial state: hide action bar (0 selected) on page load.
		updateActionBar();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
