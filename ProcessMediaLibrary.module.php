<?php namespace ProcessWire;

/**
 * Process Media Library
 *
 * Central table view of all images across all pages and image fields.
 * Editors can filter and inline-edit image metadata (description, tags,
 * custom subfields) without navigating per page.
 *
 * Phase 1: module skeleton.
 * Phase 2: read-pipeline (field-discovery, findRaw, flatten).
 * Phase 3: server-rendered table + filter bar + pagination.
 * See MediaLibrary-Konzept.md for the full plan.
 */
class ProcessMediaLibrary extends Process {

	const ADMIN_PAGE_NAME = 'media-library';
	const PERMISSION_NAME = 'media-library-access';
	const CACHE_PREFIX = 'media-library-';
	const PAGE_SIZE = 50;

	/**
	 * Image subfields requested from every FieldtypeImage field via findRaw.
	 *
	 * Note PW exposes the basename as the underlying DB column `data`, not
	 * `basename` — the Pageimage API alias only exists on hydrated objects.
	 * `ext` is derived from the basename in PHP since it isn't a column.
	 * `tags` is only present when the field has `useTags` enabled; flatten
	 * defaults to an empty string when missing.
	 */
	const STANDARD_SUBFIELDS = [
		'data',
		'description',
		'tags',
		'filesize',
		'width',
		'height',
	];

	/**
	 * Lazily-built cache of custom-fields-on-images discovery results,
	 * keyed by image-field name. Built once per request to avoid repeated
	 * template lookups across execute() and hydrateSlice().
	 * @var array<string,array<int,string>>|null
	 */
	protected $customByFieldCache = null;

	/**
	 * Render the main media library admin page.
	 */
	public function ___execute() {
		if ($this->wire('input')->get('debug')) {
			return $this->renderDebug();
		}

		$this->loadAssets();

		$imageFields = $this->discoverImageFields();
		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);
		if (!$imageFields || !$eligibleTemplates) {
			return $this->renderEmptyState($imageFields, $eligibleTemplates);
		}

		$customCols = $this->collectCustomNames();
		$filters    = $this->readFilterInput($imageFields, $eligibleTemplates, $customCols);
		$rows       = $this->loadRows($filters);
		$rows       = $this->applyRowFilters($rows, $filters);
		usort($rows, fn($a, $b) => strcasecmp($a['pageTitle'], $b['pageTitle']));

		$total      = count($rows);
		$totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));
		$page       = min(max(1, (int) $this->wire('input')->get('p')), $totalPages);
		$offset     = ($page - 1) * self::PAGE_SIZE;
		$slice      = array_slice($rows, $offset, self::PAGE_SIZE);
		$slice      = $this->hydrateSlice($slice);

		$session   = $this->wire('session');
		$sanitizer = $this->wire('sanitizer');
		$rootAttrs = sprintf(
			' data-save-url="%s" data-csrf-name="%s" data-csrf-value="%s"',
			$sanitizer->entities($this->wire('page')->url . 'save/'),
			$sanitizer->entities($session->CSRF->getTokenName()),
			$sanitizer->entities($session->CSRF->getTokenValue())
		);

		$out  = '<div class="ml-root"' . $rootAttrs . '>';
		$out .= $this->renderFilterBar($filters, $imageFields, $eligibleTemplates, $customCols);
		$out .= $this->renderTable($slice, $customCols);
		$out .= $this->renderPagination($total, $page, $totalPages, $filters);
		$out .= '</div>';

		return $out;
	}

	/**
	 * Render a verbose dump of every pipeline intermediate for diagnostics.
	 *
	 * Hit /processwire/setup/media-library/?debug=1.
	 */
	protected function renderDebug(): string {
		$sanitizer = $this->wire('sanitizer');
		$pages = $this->wire('pages');
		$input = $this->wire('input');

		$imageFields = $this->discoverImageFields();
		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);
		$customByField = $this->getCustomByField();
		$rawFields = $this->buildRawFields($imageFields);
		$selector  = $eligibleTemplates ? $this->buildSelector($eligibleTemplates) : '';
		$pageCount = $eligibleTemplates ? $pages->count($selector) : 0;
		$cacheKey  = $eligibleTemplates ? $this->rowCacheKey($imageFields, $eligibleTemplates) : '';
		$cacheHit  = $cacheKey !== '' && is_array($this->wire('cache')->getFor($this, $cacheKey));
		$rawData   = $eligibleTemplates ? $pages->findRaw($selector, $rawFields) : [];
		$rows      = $this->flattenRows($rawData, $imageFields);

		$out  = '<div class="ml-debug">';
		$out .= '<h2>' . $sanitizer->entities($this->_('Pipeline debug')) . '</h2>';
		$out .= '<dl class="uk-description-list">';
		$out .= '<dt>Image fields</dt><dd><code>' . $sanitizer->entities(implode(', ', $imageFields)) . '</code></dd>';
		$out .= '<dt>Eligible templates</dt><dd><code>' . $sanitizer->entities(implode(', ', $eligibleTemplates)) . '</code></dd>';
		$out .= '<dt>Custom fields per image field</dt><dd><pre>'
			. $sanitizer->entities(json_encode($customByField, JSON_PRETTY_PRINT)) . '</pre></dd>';
		$out .= '<dt>Selector</dt><dd><code>' . $sanitizer->entities($selector) . '</code></dd>';
		$out .= '<dt>$pages->count($selector)</dt><dd>' . (int) $pageCount . '</dd>';
		$out .= '<dt>findRaw fields requested</dt><dd><code>'
			. $sanitizer->entities(implode(', ', $rawFields)) . '</code></dd>';
		$out .= '<dt>findRaw result — pages keyed</dt><dd>' . count($rawData) . '</dd>';
		$out .= '<dt>flattenRows result — rows</dt><dd>' . count($rows) . '</dd>';
		$out .= '<dt>Row cache key</dt><dd><code>' . $sanitizer->entities($cacheKey) . '</code></dd>';
		$out .= '<dt>Row cache</dt><dd>' . ($cacheHit
			? '<strong style="color:#2c8c2c">HIT</strong>'
			: '<strong style="color:#c0392b">MISS</strong>') . '</dd>';

		if ($rawData) {
			$firstId = array_key_first($rawData);
			$out .= '<dt>First findRaw entry (page ' . (int) $firstId . ')</dt>';
			$out .= '<dd><pre>'
				. $sanitizer->entities(json_encode(
					$rawData[$firstId],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				))
				. '</pre></dd>';
		}

		// Optionally dump a specific page id (?pid=N) for targeted inspection.
		$requestedPid = (int) $input->get('pid');
		if ($requestedPid > 0 && isset($rawData[$requestedPid])) {
			$out .= '<dt>Requested page ' . $requestedPid . '</dt>';
			$out .= '<dd><pre>'
				. $sanitizer->entities(json_encode(
					$rawData[$requestedPid],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				))
				. '</pre></dd>';
		}

		// For each image field that has custom subfields, dump the first page
		// where that field is populated. This is where we can actually see if
		// findRaw is returning custom-subfield values.
		foreach ($imageFields as $fieldName) {
			if (empty($customByField[$fieldName])) continue;
			$firstPopulated = null;
			$firstPopulatedId = null;
			foreach ($rawData as $pid => $pageData) {
				$payload = $pageData[$fieldName] ?? null;
				if (is_array($payload) && $payload) {
					$firstPopulated = $pageData;
					$firstPopulatedId = $pid;
					break;
				}
			}
			$label = sprintf(
				$this->_('First page with %s populated (custom subfields: %s)'),
				$fieldName, implode(', ', $customByField[$fieldName])
			);
			$out .= '<dt>' . $sanitizer->entities($label) . '</dt>';
			if ($firstPopulated === null) {
				$out .= '<dd><em>' . $sanitizer->entities($this->_('— none in current dataset —')) . '</em></dd>';
			} else {
				$out .= '<dd><strong>page ' . (int) $firstPopulatedId . '</strong><pre>'
					. $sanitizer->entities(json_encode(
						$firstPopulated,
						JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
					))
					. '</pre></dd>';
			}
		}

		if ($rows) {
			$out .= '<dt>First flattened row</dt>';
			$out .= '<dd><pre>'
				. $sanitizer->entities(json_encode(
					$rows[0],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				))
				. '</pre></dd>';
			// Also dump the first row whose `custom` key has any value.
			foreach ($rows as $r) {
				if (!empty($r['custom'])) {
					$out .= '<dt>First flattened row with non-empty `custom`</dt>';
					$out .= '<dd><pre>'
						. $sanitizer->entities(json_encode(
							$r,
							JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
						))
						. '</pre></dd>';
					break;
				}
			}
		}

		$out .= '</dl>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * AJAX endpoint: returns paginated rows + total count as JSON.
	 * Filters, sort, AJAX-driven re-render arrive in Phase 4.
	 */
	public function ___executeData() {
		$this->wire('config')->ajax = true;
		header('Content-Type: application/json');
		$rows = $this->loadRows();
		return json_encode([
			'total' => count($rows),
			'rows'  => array_slice($rows, 0, self::PAGE_SIZE),
		]);
	}

	/**
	 * AJAX endpoint: validates and persists a single cell change.
	 *
	 * Expects POST with: pageId, fieldName, basename, subfield, value,
	 * plus PW's CSRF token. Returns JSON: { ok, value, error? }.
	 *
	 * Permission: $page->editable() on the target page. Subfield must be
	 * `description`, `tags`, or one of the custom-fields-on-images declared
	 * for the field.
	 */
	public function ___executeSave() {
		$config = $this->wire('config');
		$config->ajax = true;
		header('Content-Type: application/json');

		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			return $this->jsonError('POST required', 405);
		}

		$session = $this->wire('session');
		if (!$session->CSRF->hasValidToken()) {
			return $this->jsonError('Invalid CSRF token', 403);
		}

		$input     = $this->wire('input');
		$sanitizer = $this->wire('sanitizer');

		$pageId    = (int) $input->post('pageId');
		$fieldName = $sanitizer->fieldName((string) $input->post('fieldName'));
		$basename  = basename((string) $input->post('basename'));
		$subfield  = $sanitizer->fieldName((string) $input->post('subfield'));
		$value     = (string) $input->post('value');

		if (!$pageId || !$fieldName || !$basename || !$subfield) {
			return $this->jsonError('Missing required parameter');
		}

		if (!in_array($fieldName, $this->discoverImageFields(), true)) {
			return $this->jsonError('Field is not a managed image field');
		}

		if (!in_array($subfield, $this->editableSubfields($fieldName), true)) {
			return $this->jsonError('Subfield not editable');
		}

		$page = $this->wire('pages')->get($pageId);
		if (!$page->id) return $this->jsonError('Page not found', 404);
		if (!$page->editable()) return $this->jsonError('Page not editable', 403);

		$fieldValue = $page->getUnformatted($fieldName);
		$img = null;
		if ($fieldValue instanceof Pageimages) {
			$img = $fieldValue->getFile($basename);
		} elseif ($fieldValue instanceof Pageimage) {
			$img = $fieldValue;
		}
		if (!$img instanceof Pageimage) {
			return $this->jsonError('Image not found in field', 404);
		}

		// Output formatting off before mutating: setters work on the raw value
		// and avoid double-encoding for fields like description.
		$page->of(false);
		$img->set($subfield, $value);
		if (!$page->save($fieldName)) {
			return $this->jsonError('Save failed');
		}

		// Return the value PW actually stored — may differ from input after
		// sanitization (e.g. tags lowercased, whitespace normalized, etc.).
		$stored = $img->get($subfield);
		if (is_object($stored)) $stored = (string) $stored;
		if (is_array($stored)) $stored = json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return json_encode([
			'ok'    => true,
			'value' => (string) $stored,
		]);
	}

	/**
	 * @return array<int,string> subfield names that the inline editor accepts
	 *   for the given image field. Whitelist enforced server-side.
	 */
	protected function editableSubfields(string $fieldName): array {
		$list = ['description', 'tags'];
		foreach ($this->getCustomByField()[$fieldName] ?? [] as $custom) {
			$list[] = $custom;
		}
		return $list;
	}

	/**
	 * Render an error response with an HTTP status code that JS callers can
	 * branch on. Returned from executeSave; safe to use in any JSON endpoint.
	 */
	protected function jsonError(string $msg, int $status = 400): string {
		http_response_code($status);
		return json_encode(['ok' => false, 'error' => $msg]);
	}

	/**
	 * Load the full flat image-row list across all pages.
	 *
	 * Orchestrates field-discovery → eligible-templates → custom-field-discovery
	 * → findRaw → flatten. Row-level filtering (q, missing-X, field, galleries)
	 * happens in applyRowFilters after this returns; only $filters['template']
	 * narrows the page-level selector here.
	 *
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function loadRows(array $filters = []): array {
		$imageFields = $this->discoverImageFields();
		if (!$imageFields) return [];

		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);
		if (!$eligibleTemplates) return [];

		$cache    = $this->wire('cache');
		$cacheKey = $this->rowCacheKey($imageFields, $eligibleTemplates);
		$rows     = $cache->getFor($this, $cacheKey);

		if (!is_array($rows)) {
			// Custom-field subfields aren't returned by findRaw (they live in a
			// separate per-field data table that findRaw doesn't join), so we
			// don't request them here. hydrateSlice fetches them per-image for
			// the visible slice via the Pageimage API.
			$rawFields = $this->buildRawFields($imageFields);
			$selector  = $this->buildSelector($eligibleTemplates);
			$rawData   = $this->wire('pages')->findRaw($selector, $rawFields);
			$rows      = $this->flattenRows($rawData, $imageFields);
			// Selector-based invalidation: PW expires this entry whenever a
			// page matching the eligible templates is saved — including our
			// own inline-edit endpoint that wraps $page->save().
			$cache->saveFor(
				$this,
				$cacheKey,
				$rows,
				'template=' . implode('|', $eligibleTemplates)
			);
		}

		// Bulk-hydrate custom-field values onto every row only when a custom
		// "missing X" filter is active. Hydration is not cached so the cache
		// stays generic (one entry per discovery state) and any custom-field
		// edits show up immediately on the next request.
		if (!empty($filters['no_custom']) && $this->hasAnyCustomFields()) {
			$rows = $this->bulkHydrateCustomFields($rows);
		}

		return $rows;
	}

	/**
	 * Cache key for the post-flatten row list.
	 *
	 * Includes the discovery state (image fields, eligible templates, the
	 * custom-fields-on-images map) so that adding a field, enabling a
	 * custom-field template, or blacklisting a template all yield a new key
	 * — no stale cache after schema changes.
	 *
	 * @param array<int,string> $imageFields
	 * @param array<int,string> $eligibleTemplates
	 */
	protected function rowCacheKey(array $imageFields, array $eligibleTemplates): string {
		$keyData = [
			'tmpls' => $eligibleTemplates,
			'imgs'  => $imageFields,
			'cust'  => $this->getCustomByField(),
		];
		return 'rows-' . substr(md5((string) json_encode($keyData)), 0, 16);
	}

	/**
	 * @return array<int,string> names of every FieldtypeImage field in the system
	 */
	protected function discoverImageFields(): array {
		$names = [];
		foreach ($this->wire('fields') as $field) {
			if ($field->type instanceof FieldtypeImage) {
				$names[] = $field->name;
			}
		}
		return $names;
	}

	/**
	 * Returns the names of templates that host at least one of the given image
	 * fields, minus any names listed in the module's blacklist setting.
	 *
	 * @param array<int,string> $imageFields
	 * @return array<int,string>
	 */
	protected function discoverEligibleTemplates(array $imageFields): array {
		if (!$imageFields) return [];
		$fieldSet = array_flip($imageFields);
		$blacklistSet = array_flip($this->getBlacklistedTemplates());
		$eligible = [];
		foreach ($this->wire('templates') as $tpl) {
			if (isset($blacklistSet[$tpl->name])) continue;
			foreach ($tpl->fieldgroup as $f) {
				if (isset($fieldSet[$f->name])) {
					$eligible[] = $tpl->name;
					break;
				}
			}
		}
		return $eligible;
	}

	/**
	 * Returns the subfield names defined on the field-{name} custom template,
	 * empty if no custom fields are configured for the given image field.
	 *
	 * @return array<int,string>
	 */
	protected function discoverCustomFields(string $fieldName): array {
		$tpl = $this->wire('templates')->get("field-$fieldName");
		if (!$tpl || !$tpl->id) return [];
		$names = [];
		foreach ($tpl->fieldgroup as $f) {
			$names[] = $f->name;
		}
		return $names;
	}

	/**
	 * @return array<int,string> names of image fields with maxFiles != 1 (galleries)
	 */
	protected function galleryFieldNames(): array {
		$names = [];
		foreach ($this->wire('fields') as $field) {
			if ($field->type instanceof FieldtypeImage && (int) $field->maxFiles !== 1) {
				$names[] = $field->name;
			}
		}
		return $names;
	}

	/**
	 * Build the field list passed to $pages->findRaw().
	 *
	 * Standard subfields only — custom-fields-on-images live in a separate
	 * table that findRaw doesn't join through dotted notation. The visible
	 * slice picks up custom values via the Pageimage API in hydrateSlice.
	 *
	 * @param array<int,string> $imageFields
	 * @return array<int,string>
	 */
	protected function buildRawFields(array $imageFields): array {
		$fields = ['id', 'title', 'templates_id'];
		foreach ($imageFields as $f) {
			foreach (self::STANDARD_SUBFIELDS as $sub) {
				$fields[] = "$f.$sub";
			}
		}
		return $fields;
	}

	/**
	 * Lazily compute the customByField map: for each image field, the list of
	 * custom-field subfield names defined on its field-{name} template.
	 *
	 * @return array<string,array<int,string>>
	 */
	protected function getCustomByField(): array {
		if ($this->customByFieldCache === null) {
			$cache = [];
			foreach ($this->discoverImageFields() as $f) {
				$cache[$f] = $this->discoverCustomFields($f);
			}
			$this->customByFieldCache = $cache;
		}
		return $this->customByFieldCache;
	}

	/**
	 * Build the page-level selector for findRaw.
	 *
	 * Always loads the full eligible-templates set so the WireCache entry is
	 * filter-agnostic — template narrowing happens in applyRowFilters at
	 * the PHP level, against the cached row list.
	 *
	 * @param array<int,string> $eligibleTemplates
	 */
	protected function buildSelector(array $eligibleTemplates): string {
		if (!$eligibleTemplates) return 'id=0';
		// include=hidden returns published + hidden, excludes unpublished and trash.
		return 'template=' . implode('|', $eligibleTemplates) . ', include=hidden';
	}

	/**
	 * Flatten the findRaw result into one row per (pageId, fieldName, basename).
	 *
	 * @param array<int|string,mixed> $rawData
	 * @param array<int,string> $imageFields
	 * @return array<int,array<string,mixed>>
	 */
	protected function flattenRows(array $rawData, array $imageFields): array {
		$standardKeys = array_flip(self::STANDARD_SUBFIELDS);
		$rows = [];
		foreach ($rawData as $pageId => $pageData) {
			if (!is_array($pageData)) continue;
			$pageTitle  = $pageData['title'] ?? '';
			$templateId = (int) ($pageData['templates_id'] ?? 0);
			foreach ($imageFields as $fieldName) {
				$payload = $pageData[$fieldName] ?? null;
				if (!is_array($payload) || !$payload) continue;
				// Single-image fields (maxFiles=1) arrive as an assoc record with a
				// `data` key. Multi-image fields arrive as a numeric list of records.
				$items = isset($payload['data']) ? [$payload] : $payload;
				foreach ($items as $img) {
					if (!is_array($img) || empty($img['data'])) continue;
					$basename = (string) $img['data'];
					$rows[] = [
						'pageId'      => (int) $pageId,
						'pageTitle'   => $pageTitle,
						'templateId'  => $templateId,
						'fieldName'   => $fieldName,
						'basename'    => $basename,
						'description' => $img['description'] ?? '',
						'tags'        => $img['tags'] ?? '',
						'filesize'    => (int) ($img['filesize'] ?? 0),
						'width'       => (int) ($img['width'] ?? 0),
						'height'      => (int) ($img['height'] ?? 0),
						'ext'         => pathinfo($basename, PATHINFO_EXTENSION),
						'custom'      => array_diff_key($img, $standardKeys),
					];
				}
			}
		}
		return $rows;
	}

	/**
	 * Read and validate filter input from GET params.
	 *
	 * @param array<int,string> $imageFields
	 * @param array<int,string> $eligibleTemplates
	 * @param array<int,string> $customCols custom-field column names (whitelist for no_custom_*)
	 * @return array<string,mixed>
	 */
	protected function readFilterInput(array $imageFields, array $eligibleTemplates, array $customCols = []): array {
		$input    = $this->wire('input');
		$template = (string) $input->get('template');
		$field    = (string) $input->get('field');

		$noCustom = [];
		foreach ($customCols as $name) {
			if ($input->get('no_custom_' . $name)) {
				$noCustom[$name] = true;
			}
		}

		return [
			'q'              => trim((string) $input->get('q')),
			'template'       => in_array($template, $eligibleTemplates, true) ? $template : '',
			'field'          => in_array($field, $imageFields, true) ? $field : '',
			'no_desc'        => (bool) $input->get('no_desc'),
			'no_tags'        => (bool) $input->get('no_tags'),
			'only_galleries' => (bool) $input->get('only_galleries'),
			'no_custom'      => $noCustom,
		];
	}

	/**
	 * Apply PHP-level row filters that PW's findRaw selector can't (or shouldn't)
	 * express. Template narrowing is already done at the selector level in
	 * loadRows; here we handle the per-image-row filters.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	protected function applyRowFilters(array $rows, array $filters): array {
		$q        = mb_strtolower($filters['q']);
		$hasQ     = $q !== '';
		$tplName  = (string) ($filters['template'] ?? '');
		$field    = $filters['field'];
		$noDesc   = $filters['no_desc'];
		$noTags   = $filters['no_tags'];
		$onlyGal  = $filters['only_galleries'];
		$noCustom = $filters['no_custom'] ?? [];

		if (!$hasQ && $tplName === '' && $field === '' && !$noDesc && !$noTags && !$onlyGal && !$noCustom) {
			return $rows;
		}

		// Template filter operates at PHP level now (was SQL before caching),
		// so we resolve the name → id once and compare to row['templateId'].
		$tplId = 0;
		if ($tplName !== '') {
			$tpl = $this->wire('templates')->get($tplName);
			if ($tpl && $tpl->id) $tplId = (int) $tpl->id;
		}

		$galleryFields = $onlyGal ? array_flip($this->galleryFieldNames()) : [];

		return array_values(array_filter($rows, function ($r) use (
			$hasQ, $q, $tplId, $field, $noDesc, $noTags, $onlyGal, $galleryFields, $noCustom
		) {
			if ($tplId && (int) $r['templateId'] !== $tplId) return false;
			if ($field !== '' && $r['fieldName'] !== $field) return false;
			if ($onlyGal && !isset($galleryFields[$r['fieldName']])) return false;

			$desc = $this->normalizeDescription($r['description']);
			$tags = (string) $r['tags'];

			if ($noDesc && trim($desc) !== '') return false;
			if ($noTags && trim($tags) !== '') return false;

			foreach ($noCustom as $name => $_) {
				$val = $r['custom'][$name] ?? '';
				if (is_array($val)) $val = json_encode($val);
				if (trim((string) $val) !== '') return false;
			}

			if ($hasQ) {
				$hay = mb_strtolower($desc . ' ' . $tags . ' ' . ((string) $r['basename']));
				if (mb_strpos($hay, $q) === false) return false;
			}
			return true;
		}));
	}

	/**
	 * Hydrate the visible row slice with thumbnail URLs and page links.
	 *
	 * Only this slice triggers Pageimage hydration — the bulk row list stays
	 * raw arrays. Pages are loaded in one batch via $pages->getById().
	 *
	 * @param array<int,array<string,mixed>> $slice
	 * @return array<int,array<string,mixed>>
	 */
	protected function hydrateSlice(array $slice): array {
		if (!$slice) return [];

		$pageIds = array_values(array_unique(array_column($slice, 'pageId')));
		// Build an explicit id => Page map. PageArray inherits WireArray::get(),
		// which treats integer keys as array indexes (0, 1, 2…), not page IDs,
		// so $pages->get($pageId) would silently return the wrong page.
		$pagesById = [];
		foreach ($this->wire('pages')->getById($pageIds) as $p) {
			$pagesById[$p->id] = $p;
		}
		$customByField = $this->getCustomByField();

		foreach ($slice as &$row) {
			$row['thumbUrl']    = '';
			$row['pageUrl']     = '';
			$row['pageEditUrl'] = '';

			$page = $pagesById[$row['pageId']] ?? null;
			if (!$page || !$page->id) continue;

			$row['pageUrl']     = $page->url;
			$row['pageEditUrl'] = $page->editUrl;

			$fieldValue = $page->getUnformatted($row['fieldName']);
			$img = null;
			if ($fieldValue instanceof Pageimages) {
				$img = $fieldValue->getFile($row['basename']);
			} elseif ($fieldValue instanceof Pageimage) {
				$img = $fieldValue;
			}
			if (!$img instanceof Pageimage) continue;

			$row['thumbUrl'] = $img->size(120, 80, ['upscaling' => false, 'quality' => 80])->url;

			// Custom-field hydration: read each declared custom subfield off
			// the Pageimage. Phase 6 will handle type-specific edit semantics;
			// here we normalize to a displayable scalar.
			foreach ($customByField[$row['fieldName']] ?? [] as $customName) {
				if (isset($row['custom'][$customName])) continue; // already filled by bulk pass
				$val = $img->get($customName);
				if ($val === null || $val === '') continue;
				if (is_object($val)) $val = (string) $val;
				if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				$row['custom'][$customName] = $val;
			}
		}
		unset($row);

		return $slice;
	}

	/**
	 * @return bool true if any image field has at least one custom subfield declared
	 */
	protected function hasAnyCustomFields(): bool {
		foreach ($this->getCustomByField() as $list) {
			if (!empty($list)) return true;
		}
		return false;
	}

	/**
	 * Hydrate custom-field values onto every row in $rows.
	 *
	 * Used when a "missing X" filter targets a custom field — we can't filter
	 * without values, and findRaw doesn't expose them. Loads all referenced
	 * pages once via $pages->getById (single batched query), then reads each
	 * row's image and pulls the declared custom subfields off it.
	 *
	 * Pages are cached in the PW Pages cache after this call, so the
	 * subsequent hydrateSlice() pass reuses them at no extra DB cost.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	protected function bulkHydrateCustomFields(array $rows): array {
		if (!$rows) return $rows;
		$customByField = $this->getCustomByField();

		$pageIds = array_values(array_unique(array_column($rows, 'pageId')));
		$pagesById = [];
		foreach ($this->wire('pages')->getById($pageIds) as $p) {
			$pagesById[$p->id] = $p;
		}

		foreach ($rows as &$row) {
			$customNames = $customByField[$row['fieldName']] ?? [];
			if (!$customNames) continue;
			$page = $pagesById[$row['pageId']] ?? null;
			if (!$page || !$page->id) continue;

			$fieldValue = $page->getUnformatted($row['fieldName']);
			$img = null;
			if ($fieldValue instanceof Pageimages) {
				$img = $fieldValue->getFile($row['basename']);
			} elseif ($fieldValue instanceof Pageimage) {
				$img = $fieldValue;
			}
			if (!$img instanceof Pageimage) continue;

			foreach ($customNames as $name) {
				$val = $img->get($name);
				if ($val === null) continue;
				if (is_object($val)) $val = (string) $val;
				if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				$row['custom'][$name] = $val;
			}
		}
		unset($row);

		return $rows;
	}

	/**
	 * Sorted unique custom-field column names across all image fields.
	 *
	 * Sourced from discovery (field-{name} templates), not from row data —
	 * columns appear even if the current slice happens not to populate them.
	 *
	 * @return array<int,string>
	 */
	protected function collectCustomNames(): array {
		$names = [];
		foreach ($this->getCustomByField() as $list) {
			foreach ($list as $n) {
				$names[$n] = true;
			}
		}
		ksort($names);
		return array_keys($names);
	}

	/**
	 * Multilingual descriptions arrive as assoc arrays keyed by language id.
	 * Take the first non-empty value as the display string for now.
	 */
	protected function normalizeDescription($desc): string {
		if (is_string($desc)) return $desc;
		if (is_array($desc)) {
			foreach ($desc as $v) {
				if (is_string($v) && $v !== '') return $v;
			}
			return '';
		}
		return (string) $desc;
	}

	protected function formatFilesize(int $bytes): string {
		if ($bytes <= 0) return '';
		$units = ['B', 'KB', 'MB', 'GB'];
		$i = 0;
		$size = (float) $bytes;
		while ($size >= 1024 && $i < count($units) - 1) {
			$size /= 1024;
			$i++;
		}
		$rounded = $i === 0 ? (string) $bytes : number_format($size, $size >= 10 ? 0 : 1);
		return $rounded . ' ' . $units[$i];
	}

	protected function loadAssets(): void {
		$config = $this->wire('config');
		$session = $this->wire('session');
		$baseUrl = $config->urls($this);
		$version = $this->wire('modules')->getModuleInfoProperty($this, 'version');
		$config->styles->add($baseUrl . 'ProcessMediaLibrary.css?v=' . $version);
		$config->scripts->add($baseUrl . 'ProcessMediaLibrary.js?v=' . $version);
		$config->js('ProcessMediaLibrary', [
			'saveUrl' => $this->wire('page')->url . 'save/',
			'csrf' => [
				'name'  => $session->CSRF->getTokenName(),
				'value' => $session->CSRF->getTokenValue(),
			],
			'labels' => [
				'saving' => $this->_('Saving…'),
				'saved'  => $this->_('Saved'),
				'error'  => $this->_('Save failed'),
			],
		]);
	}

	protected function renderEmptyState(array $imageFields, array $eligibleTemplates): string {
		$san = $this->wire('sanitizer');
		if (!$imageFields) {
			$msg = $this->_('No image fields found. Create at least one FieldtypeImage field, add it to a template, and reload.');
		} else {
			$msg = $this->_('No template currently uses an image field. Add an image field to a template and reload.');
		}
		return '<p class="ml-empty">' . $san->entities($msg) . '</p>';
	}

	protected function renderFilterBar(array $filters, array $imageFields, array $eligibleTemplates, array $customCols = []): string {
		$san = $this->wire('sanitizer');
		$checked = fn($k) => $filters[$k] ? ' checked' : '';

		$out  = '<form method="get" class="ml-filter-bar">';

		$out .= '<input type="search" name="q" value="' . $san->entities($filters['q']) . '"'
			. ' placeholder="' . $san->entities($this->_('Search description, tags, filename')) . '"'
			. ' class="uk-input uk-form-small ml-filter-q">';

		$out .= '<select name="template" class="uk-select uk-form-small">';
		$out .= '<option value="">' . $san->entities($this->_('All templates')) . '</option>';
		foreach ($eligibleTemplates as $t) {
			$sel = $filters['template'] === $t ? ' selected' : '';
			$out .= '<option value="' . $san->entities($t) . '"' . $sel . '>'
				. $san->entities($t) . '</option>';
		}
		$out .= '</select>';

		$out .= '<select name="field" class="uk-select uk-form-small">';
		$out .= '<option value="">' . $san->entities($this->_('All image fields')) . '</option>';
		foreach ($imageFields as $f) {
			$sel = $filters['field'] === $f ? ' selected' : '';
			$out .= '<option value="' . $san->entities($f) . '"' . $sel . '>'
				. $san->entities($f) . '</option>';
		}
		$out .= '</select>';

		$out .= '<label class="ml-filter-check">'
			. '<input type="checkbox" name="no_desc" value="1"' . $checked('no_desc') . '> '
			. $san->entities($this->_('Missing description')) . '</label>';
		$out .= '<label class="ml-filter-check">'
			. '<input type="checkbox" name="no_tags" value="1"' . $checked('no_tags') . '> '
			. $san->entities($this->_('Missing tags')) . '</label>';
		foreach ($customCols as $name) {
			$key = 'no_custom_' . $name;
			$on  = !empty($filters['no_custom'][$name]) ? ' checked' : '';
			$out .= '<label class="ml-filter-check">'
				. '<input type="checkbox" name="' . $san->entities($key) . '" value="1"' . $on . '> '
				. $san->entities(sprintf($this->_('Missing %s'), $name)) . '</label>';
		}
		$out .= '<label class="ml-filter-check">'
			. '<input type="checkbox" name="only_galleries" value="1"' . $checked('only_galleries') . '> '
			. $san->entities($this->_('Galleries only')) . '</label>';

		$out .= '<button type="submit" class="uk-button uk-button-primary uk-button-small">'
			. $san->entities($this->_('Apply')) . '</button>';

		if ($this->hasActiveFilter($filters)) {
			$out .= ' <a href="./" class="uk-button uk-button-default uk-button-small">'
				. $san->entities($this->_('Reset')) . '</a>';
		}

		$out .= '</form>';
		return $out;
	}

	protected function hasActiveFilter(array $filters): bool {
		return $filters['q'] !== ''
			|| $filters['template'] !== ''
			|| $filters['field'] !== ''
			|| $filters['no_desc']
			|| $filters['no_tags']
			|| $filters['only_galleries']
			|| !empty($filters['no_custom']);
	}

	/**
	 * @param array<int,array<string,mixed>> $slice hydrated slice
	 * @param array<int,string> $customCols custom-field column names
	 */
	protected function renderTable(array $slice, array $customCols): string {
		$san = $this->wire('sanitizer');

		if (!$slice) {
			return '<p class="ml-empty">'
				. $san->entities($this->_('No images match the current filters.')) . '</p>';
		}

		$headers = [
			$this->_('Thumb'),
			$this->_('Page'),
			$this->_('Field'),
			$this->_('Filename'),
			$this->_('Description'),
			$this->_('Tags'),
			$this->_('Dimensions'),
			$this->_('Size'),
		];

		$out  = '<table class="ml-table uk-table uk-table-divider uk-table-small">';
		$out .= '<thead><tr>';
		foreach ($headers as $h) $out .= '<th>' . $san->entities($h) . '</th>';
		foreach ($customCols as $name) {
			$out .= '<th><code>' . $san->entities($name) . '</code></th>';
		}
		$out .= '</tr></thead><tbody>';

		foreach ($slice as $row) {
			$desc = $this->normalizeDescription($row['description']);
			$tags = (string) $row['tags'];
			$dims = ($row['width'] && $row['height'])
				? $row['width'] . '×' . $row['height']
				: '';
			$size = $this->formatFilesize((int) $row['filesize']);

			$editAttrs = sprintf(
				'data-page-id="%d" data-field="%s" data-basename="%s"',
				(int) $row['pageId'],
				$san->entities((string) $row['fieldName']),
				$san->entities((string) $row['basename'])
			);

			$out .= '<tr>';

			$out .= '<td class="ml-cell-thumb">';
			if (!empty($row['thumbUrl'])) {
				$out .= '<img src="' . $san->entities($row['thumbUrl']) . '"'
					. ' alt="' . $san->entities($row['basename']) . '"'
					. ' loading="lazy" width="120" height="80">';
			}
			$out .= '</td>';

			$out .= '<td class="ml-cell-page">';
			if (!empty($row['pageEditUrl'])) {
				$out .= '<a href="' . $san->entities($row['pageEditUrl']) . '">'
					. $san->entities((string) $row['pageTitle']) . '</a>';
			} else {
				$out .= $san->entities((string) $row['pageTitle']);
			}
			$out .= '</td>';

			$out .= '<td><code>' . $san->entities((string) $row['fieldName']) . '</code></td>';
			$out .= '<td><code>' . $san->entities((string) $row['basename']) . '</code></td>';
			$out .= '<td class="ml-cell-desc ml-cell-editable" ' . $editAttrs
				. ' data-subfield="description" data-input="textarea">'
				. $san->entities($desc) . '</td>';
			$out .= '<td class="ml-cell-tags ml-cell-editable" ' . $editAttrs
				. ' data-subfield="tags" data-input="text">'
				. $san->entities($tags) . '</td>';
			$out .= '<td class="ml-cell-nowrap">' . $san->entities($dims) . '</td>';
			$out .= '<td class="ml-cell-nowrap">' . $san->entities($size) . '</td>';

			foreach ($customCols as $name) {
				$val = $row['custom'][$name] ?? '';
				if (is_array($val)) $val = json_encode($val);
				$out .= '<td class="ml-cell-editable" ' . $editAttrs
					. ' data-subfield="' . $san->entities($name) . '" data-input="text">'
					. $san->entities((string) $val) . '</td>';
			}

			$out .= '</tr>';
		}

		$out .= '</tbody></table>';
		return $out;
	}

	protected function renderPagination(int $total, int $page, int $totalPages, array $filters): string {
		$san = $this->wire('sanitizer');

		$summary = sprintf(
			$this->_('Page %1$d of %2$d — %3$d image%4$s'),
			$page, $totalPages, $total, $total === 1 ? '' : 's'
		);

		$out  = '<div class="ml-pagination">';
		$out .= '<span class="ml-pagination-summary">' . $san->entities($summary) . '</span>';

		if ($totalPages > 1) {
			if ($page > 1) {
				$out .= '<a class="ml-pagination-link" href="' . $san->entities($this->buildUrl($filters, $page - 1)) . '">'
					. $san->entities($this->_('← Previous')) . '</a>';
			}
			if ($page < $totalPages) {
				$out .= '<a class="ml-pagination-link" href="' . $san->entities($this->buildUrl($filters, $page + 1)) . '">'
					. $san->entities($this->_('Next →')) . '</a>';
			}
		}

		$out .= '</div>';
		return $out;
	}

	protected function buildUrl(array $filters, int $page): string {
		$params = [
			'q'              => $filters['q'],
			'template'       => $filters['template'],
			'field'          => $filters['field'],
			'no_desc'        => $filters['no_desc'] ? '1' : '',
			'no_tags'        => $filters['no_tags'] ? '1' : '',
			'only_galleries' => $filters['only_galleries'] ? '1' : '',
			'p'              => $page > 1 ? (string) $page : '',
		];
		foreach ($filters['no_custom'] ?? [] as $name => $on) {
			if ($on) $params['no_custom_' . $name] = '1';
		}
		$params = array_filter($params, fn($v) => $v !== '' && $v !== null);
		return $params ? '?' . http_build_query($params) : './';
	}

	/**
	 * Returns the template-name blacklist from module settings.
	 * Accepts an array (modern field) or comma/whitespace string (legacy).
	 *
	 * @return array<int,string>
	 */
	protected function getBlacklistedTemplates(): array {
		$raw = $this->get('blacklistedTemplates');
		if (!$raw) return [];
		if (is_array($raw)) return array_values(array_filter(array_map('trim', $raw)));
		return preg_split('/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
	}

	/**
	 * Install: create admin page under Setup and the access permission.
	 */
	public function ___install() {
		parent::___install();

		$permissions = $this->wire('permissions');
		if (!$permissions->get(self::PERMISSION_NAME)->id) {
			$p = $permissions->add(self::PERMISSION_NAME);
			$p->title = $this->_('Access the Media Library admin page');
			$p->save();
			$this->message("Created permission: " . self::PERMISSION_NAME);
		}
	}

	/**
	 * Uninstall: remove admin page and clear module cache entries.
	 */
	public function ___uninstall() {
		$cache = $this->wire('cache');
		$cache->deleteFor($this, '*');

		parent::___uninstall();
	}
}
