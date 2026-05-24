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
				bindCommit: function (commit, cancel) {
					editor.addEventListener('keydown', function (e) {
						if (e.key === 'Escape') {
							e.preventDefault();
							cancel();
						} else if (e.key === 'Enter') {
							if (inputType === 'text' || e.ctrlKey || e.metaKey) {
								e.preventDefault();
								commit();
							}
						}
					});
					editor.addEventListener('blur', commit);
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
				bindCommit: function (commit, cancel) {
					done.addEventListener('click', function (e) {
						e.preventDefault();
						commit();
					});
					wrap.addEventListener('keydown', function (e) {
						if (e.key === 'Escape') { e.preventDefault(); cancel(); }
						else if (e.key === 'Enter') { e.preventDefault(); commit(); }
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
			};
		}

		function activateEditor(td) {
			if (td.classList.contains('ml-editing')) return;
			td.classList.add('ml-editing');

			var original = td.textContent;
			var widget   = buildEditorWidget(td, original);

			td.textContent = '';
			td.appendChild(widget.element);
			setTimeout(widget.focus, 0);

			var committed = false;

			function cancel() {
				if (committed) return;
				committed = true;
				td.classList.remove('ml-editing');
				td.textContent = original;
			}

			function commit() {
				if (committed) return;
				committed = true;
				var newValue = widget.getValue();
				td.classList.remove('ml-editing');

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

			widget.bindCommit(commit, cancel);
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
					// Editable cell — but ignore clicks that landed on an
					// internal anchor (e.g. Page-link in the Page column).
					if (e.target.tagName === 'A' || e.target.closest('a')) return;
				}
				var td = e.target.closest && e.target.closest('.ml-cell-editable');
				if (!td) return;
				if (td.classList.contains('ml-editing')) return;
				activateEditor(td);
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
			filterForm.addEventListener('click', function (e) {
				var reset = e.target.closest && e.target.closest('a[href="./"]');
				if (!reset) return;
				e.preventDefault();
				replaceFromQs('', true);
			});
		}

		// -- Browser back/forward --------------------------------------

		window.addEventListener('popstate', function () {
			replaceFromQs(location.search, false);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
