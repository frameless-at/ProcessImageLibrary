/* ProcessMediaLibrary — admin script.
 *
 * Inline-edit for table cells marked with .ml-cell-editable. One save in
 * flight per page (saveQueues) to avoid concurrent $page->save() races
 * on the server. Optimistic UI: cell flips to the new value immediately
 * and reverts on error.
 */
(function () {
	'use strict';

	function init() {
		var root = document.querySelector('.ml-root');
		if (!root) return;

		// Bootstrapped marker — inspectable in DevTools if behavior regresses.
		root.classList.add('ml-js-loaded');

		// Two config sources: $config->js() output, falling back to data-*
		// attributes on .ml-root. The fallback exists because some admin theme
		// or load order issue can leave ProcessWire.config.ProcessMediaLibrary
		// undefined while the script still loads fine.
		var pwCfg = (window.ProcessWire && window.ProcessWire.config && window.ProcessWire.config.ProcessMediaLibrary) || {};
		var config = {
			saveUrl: pwCfg.saveUrl || root.dataset.saveUrl || '',
			csrf: pwCfg.csrf || {
				name:  root.dataset.csrfName  || '',
				value: root.dataset.csrfValue || ''
			},
			labels: pwCfg.labels || {}
		};
		if (!config.saveUrl) return;

		var labels = config.labels;
		var saveQueues = new Map();

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

		function activateEditor(td) {
			if (td.classList.contains('ml-editing')) return;
			td.classList.add('ml-editing');

			var original  = td.textContent;
			var inputType = td.dataset.input === 'textarea' ? 'textarea' : 'text';
			var editor    = document.createElement(inputType === 'textarea' ? 'textarea' : 'input');
			if (inputType === 'text') editor.type = 'text';
			if (inputType === 'textarea') editor.rows = 3;
			editor.value = original;
			editor.className = 'ml-cell-input';

			td.textContent = '';
			td.appendChild(editor);
			// iOS Safari sometimes drops focus() if called synchronously inside
			// the click handler that just mutated the DOM; defer one tick.
			setTimeout(function () {
				editor.focus();
				editor.select();
			}, 0);

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
				var newValue = editor.value;
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
					td.classList.remove('ml-cell-saving');
					td.textContent = original;
					td.title = (err && err.message) || labels.error || 'Network error';
					flashCell(td, false);
				});
			}

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

		function handleCellTap(e) {
			var td = this;
			if (td.classList.contains('ml-editing')) return;
			if (e.target && e.target !== td) {
				if (e.target.tagName === 'A' || (e.target.closest && e.target.closest('a'))) return;
			}
			activateEditor(td);
		}

		// Direct binding (not delegated) — iOS Safari fires click on first tap
		// only when the tapped element itself is "interactive": has a click
		// listener, is a native control, or has cursor:pointer. Delegation via
		// the root sometimes misses the first tap; per-cell binding doesn't.
		root.querySelectorAll('.ml-cell-editable').forEach(function (td) {
			td.addEventListener('click', handleCellTap);
		});
	}

	// PW admin loads <script> in <head>, so the IIFE runs before <body>
	// is parsed and .ml-root doesn't exist yet. Wait for DOM.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
