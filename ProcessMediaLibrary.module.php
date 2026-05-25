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
	const PAGE_SIZE_DEFAULT = 50;
	const PAGE_SIZE_OPTIONS = [25, 50, 100, 200];
	const THUMB_WIDTH_DEFAULT   = 120;
	const THUMB_HEIGHT_DEFAULT  = 80;
	const THUMB_QUALITY_DEFAULT = 80;

	/**
	 * Admin-configurable thumbnail dimensions, JPEG quality and
	 * crop behaviour, used for the per-row thumb in the table view.
	 * Each falls back to the class constant when the module config
	 * isn't set; thumbCrop defaults to true to match PW's own
	 * $img->size() behaviour and the pre-config table layout.
	 *
	 * @return array{width:int,height:int,quality:int,crop:bool}
	 */
	protected function getThumbDims(): array {
		$crop = $this->get('thumbCrop');
		return [
			'width'   => max(1, (int) ($this->get('thumbWidth')   ?: self::THUMB_WIDTH_DEFAULT)),
			'height'  => max(1, (int) ($this->get('thumbHeight')  ?: self::THUMB_HEIGHT_DEFAULT)),
			'quality' => max(1, min(100, (int) ($this->get('thumbQuality') ?: self::THUMB_QUALITY_DEFAULT))),
			'crop'    => $crop === null ? true : (bool) $crop,
		];
	}

	/**
	 * Admin-configurable per-page options (comma- or whitespace-
	 * separated list in the config). Falls back to PAGE_SIZE_OPTIONS
	 * when nothing's set; always sorted, deduped, positive ints.
	 *
	 * @return array<int,int>
	 */
	protected function getPageSizeOptions(): array {
		$raw = trim((string) $this->get('pageSizeOptions'));
		if ($raw === '') return self::PAGE_SIZE_OPTIONS;
		$parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$opts = array_values(array_unique(array_filter(
			array_map('intval', $parts),
			fn($n) => $n > 0
		)));
		sort($opts);
		return $opts ?: self::PAGE_SIZE_OPTIONS;
	}

	protected function getDefaultPageSize(): int {
		$opts = $this->getPageSizeOptions();
		$configured = (int) $this->get('defaultPageSize');
		if ($configured > 0 && in_array($configured, $opts, true)) return $configured;
		return in_array(self::PAGE_SIZE_DEFAULT, $opts, true)
			? self::PAGE_SIZE_DEFAULT
			: ($opts[0] ?? self::PAGE_SIZE_DEFAULT);
	}

	/**
	 * Admin-configured list of column keys to render hidden by
	 * default. Per-user toggles in localStorage override this.
	 *
	 * @return array<int,string>
	 */
	protected function getDefaultHiddenColumns(): array {
		$val = $this->get('defaultHiddenColumns');
		if (!is_array($val)) return [];
		return array_values(array_filter(array_map('strval', $val)));
	}

	/**
	 * Flat-row keys allowed as a sort column, mapped to compare type.
	 * Custom-fields-on-images columns are validated separately at read time
	 * and accessed via the 'custom:<name>' sort token.
	 */
	const SORTABLE_COLUMNS = [
		'pageTitle'   => 'string',
		'fieldName'   => 'string',
		'basename'    => 'string',
		'description' => 'string',
		'tags'        => 'string',
		'width'       => 'int',
		'filesize'    => 'int',
	];

	const DEFAULT_SORT = 'pageTitle';
	const DEFAULT_DIR  = 'asc';

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
	 * Module bootstrap — autoloaded on admin pages so the renderItem
	 * hook below fires while ProcessPageEdit is rendering an
	 * InputfieldImage. The hook is the heart of the "edit one image
	 * in an iframe" feature: it lets the page-edit form render only
	 * the file the user clicked instead of the whole collection,
	 * with no client-side hiding and no thumbnail-generation cost
	 * for the others.
	 */
	public function init() {
		parent::init();
		// Pull the saved module config onto this instance so the
		// runtime helpers ($this->get('thumbWidth') etc.) actually
		// see the values the admin saved. PW does this for
		// ConfigurableModule instances automatically in some load
		// paths, but Process modules loaded via autoload don't get
		// the same treatment everywhere — explicit setArray here
		// makes the table render honour the config regardless of
		// load path.
		$saved = $this->wire('modules')->getModuleConfigData($this);
		if (is_array($saved) && $saved) $this->setArray($saved);

		// renderList() is declared on InputfieldFile (InputfieldImage
		// just inherits it) and iterates its $value PARAMETER, not
		// $this->value — so we must mutate $event->arguments(0)
		// instead of the inputfield property. Hooking the parent
		// catches both File and Image fields in one shot.
		$this->addHookBefore('InputfieldFile::renderList', $this, 'filterToFocusedImage');
	}

	/**
	 * When the request URL carries ml_focus_hash=<md5(basename)>,
	 * narrow the Pagefiles passed into ___renderList() down to just
	 * the matching file so the render loop only iterates once.
	 * Pagefile::hash() is literally md5($basename), so we can compute
	 * the same key client-side without loading Pagefile objects in
	 * our table view.
	 *
	 * Cloning the WireArray keeps the page's actual image collection
	 * untouched; the clone holds the same Pageimage refs, we just
	 * drop the non-matching ones from it. Save is safe regardless:
	 * POST is a fresh request, $page->images is freshly loaded,
	 * InputfieldFile::processInput iterates the full set and only
	 * touches files whose form keys are present.
	 */
	protected function filterToFocusedImage(HookEvent $event) {
		$focus = (string) $this->wire('input')->get('ml_focus_hash');
		if (!preg_match('/^[a-f0-9]{32}$/', $focus)) return;

		$value = $event->arguments(0);
		if (!$value instanceof Pagefiles) return;

		$filtered = clone $value;
		foreach ($filtered as $pf) {
			if ($pf->hash !== $focus) $filtered->remove($pf);
		}
		$event->arguments(0, $filtered);
	}

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
		// Boot gate: the media-library-access permission is the hard
		// security check, but the module is only useful to someone
		// who can actually edit at least one image-field page. If
		// they can't, short-circuit with a tailored message instead
		// of rendering a table they can't write to.
		if (!$this->canEditAnyImagePage($eligibleTemplates)) {
			return $this->renderNoEditAccess();
		}

		$customCols   = $this->collectCustomNames();
		$filters      = $this->readFilterInput($imageFields, $eligibleTemplates, $customCols);
		$sortState    = $this->readSortInput($customCols);
		$sort         = $sortState['sort'];
		$dir          = $sortState['dir'];
		$requestedPg  = max(1, (int) $this->wire('input')->get('p'));
		// Tag pool for the filter bar: union across the entire (cached)
		// flat-row set, not scoped to other filters, so the picker shows
		// every tag the user can possibly choose from.
		$tagFilterPool = $this->flatUsedTags($this->loadRows($filters));
		$resultsHtml  = $this->renderResultsHtml($filters, $sort, $dir, $requestedPg, $customCols);

		$session   = $this->wire('session');
		$sanitizer = $this->wire('sanitizer');
		$rootAttrs = sprintf(
			' data-save-url="%s" data-render-url="%s" data-bulk-url="%s" data-csrf-name="%s" data-csrf-value="%s"',
			$sanitizer->entities($this->wire('page')->url . 'save/'),
			$sanitizer->entities($this->wire('page')->url . 'data/'),
			$sanitizer->entities($this->wire('page')->url . 'bulk/'),
			$sanitizer->entities($session->CSRF->getTokenName()),
			$sanitizer->entities($session->CSRF->getTokenValue())
		);

		$out  = '<div class="ml-root"' . $rootAttrs . '>';
		// Cog → module settings. Position-absolute via CSS so it sits
		// in the heading row instead of taking a row of its own; the
		// link target is PW's standard module-edit URL.
		$cfgUrl = $this->wire('config')->urls->admin . 'module/edit/?name=' . urlencode($this->className());
		$out .= '<a class="ml-config-link" href="' . $sanitizer->entities($cfgUrl) . '"'
			. ' title="' . $sanitizer->entities($this->_('Module settings')) . '"'
			. ' aria-label="' . $sanitizer->entities($this->_('Module settings')) . '">'
			. '<i class="fa fa-cog" aria-hidden="true"></i>'
			. '</a>';
		$out .= $this->renderFilterBar($filters, $imageFields, $eligibleTemplates, $customCols, $sort, $dir, $tagFilterPool);
		$out .= '<div class="ml-results">' . $resultsHtml . '</div>';
		$out .= $this->renderExportImportBar($filters);
		$out .= '</div>';

		return $out;
	}

	/**
	 * Reduce a filters array to just the entries that actually
	 * narrow the result set, for the export's meta.appliedFilter
	 * field. Drops empty strings, falsy booleans, empty arrays and
	 * the no_custom map when nothing is checked. Truthy entries
	 * (non-empty string, true bool, non-empty array) are kept as-is.
	 */
	protected function summarizeActiveFilters(array $filters): array {
		$active = [];
		foreach ($filters as $k => $v) {
			if (is_string($v)) {
				if ($v !== '') $active[$k] = $v;
			} elseif (is_array($v)) {
				if (!empty($v)) $active[$k] = $v;
			} elseif (is_bool($v)) {
				if ($v) $active[$k] = true;
			} elseif ($v !== null && $v !== 0) {
				$active[$k] = $v;
			}
		}
		return $active;
	}

	/**
	 * Bottom-of-page Export / Import block. Export is a plain link
	 * to the export endpoint that carries the current filter URL,
	 * so the resulting download is exactly the slice the user is
	 * looking at. Import is a small upload form — JS intercepts to
	 * post via fetch + show the result inline so the page doesn't
	 * navigate away from the user's current filter.
	 */
	protected function renderExportImportBar(array $filters): string {
		$san = $this->wire('sanitizer');
		$session = $this->wire('session');

		$exportBase = $this->wire('page')->url . 'export/';
		// Initial href reflects the filter URL at server-render time,
		// but the listing's AJAX filter swaps push new state into the
		// URL without re-rendering this block — JS hijacks the click
		// and rebuilds the URL from location.search so the download
		// always matches what the user is currently looking at.
		$initialQs = $this->buildUrl($filters, 1, '', '', $this->getDefaultPageSize());
		$exportUrl = $exportBase . ($initialQs !== './' && $initialQs !== '' ? $initialQs : '');
		$importUrl = $this->wire('page')->url . 'import/';

		$csrfName  = $session->CSRF->getTokenName();
		$csrfValue = $session->CSRF->getTokenValue();

		$exportJsonLabel = $this->_('Export JSON');
		$exportCsvLabel  = $this->_('Export CSV');
		$importLabel = $this->_('Import');
		$pickLabel   = $this->_('Choose JSON or CSV file');

		return '<div class="Inputfield InputfieldFieldset InputfieldStateCollapsed ml-export-import">'
			. '<label class="InputfieldHeader InputfieldStateToggle">'
			. $san->entities($this->_('Export / Import'))
			. '</label>'
			. '<div class="InputfieldContent">'
			. '<p class="ml-ei-help">' . $san->entities(
				$this->_('Export the current filter set as JSON or CSV — image URL, page context, current values. Edit externally, re-upload to apply changes.')
			) . '</p>'
			. '<p>'
			. '<a class="uk-button uk-button-primary uk-button-small ml-export-link"'
			. ' href="' . $san->entities($exportUrl) . '"'
			. ' data-export-base="' . $san->entities($exportBase) . '"'
			. ' data-format="json">'
			. $san->entities($exportJsonLabel) . '</a> '
			. '<a class="uk-button uk-button-default uk-button-small ml-export-link"'
			. ' href="' . $san->entities($exportUrl . (str_contains($exportUrl, '?') ? '&' : '?') . 'format=csv') . '"'
			. ' data-export-base="' . $san->entities($exportBase) . '"'
			. ' data-format="csv">'
			. $san->entities($exportCsvLabel) . '</a>'
			. '</p>'
			. '<form class="ml-import-form" enctype="multipart/form-data" method="post" action="'
			. $san->entities($importUrl) . '">'
			. '<input type="hidden" name="' . $san->entities($csrfName) . '" value="' . $san->entities($csrfValue) . '">'
			. '<label class="ml-ei-file"><span>' . $san->entities($pickLabel) . '</span> '
			. '<input type="file" name="file" accept="application/json,.json,text/csv,.csv" required></label> '
			. '<button type="submit" class="uk-button uk-button-default uk-button-small">'
			. $san->entities($importLabel) . '</button>'
			. '</form>'
			. '<div class="ml-import-status" role="status" aria-live="polite"></div>'
			. '</div></div>';
	}

	/**
	 * Just the <ul> of column-toggle checkboxes — wrapped in an
	 * InputfieldMarkup inside the "Filters & Columns" outer
	 * fieldset, so the toggle UI lives alongside the filters
	 * without polluting the form's URL state. The checkboxes
	 * deliberately have NO name= attribute, so FormData skips
	 * them and the JS visibility hook (.ml-col-toggle, localStorage
	 * -backed) is the only path that reads their state.
	 *
	 * @param array<int,string> $customCols
	 */
	protected function renderColumnsListMarkup(array $customCols): string {
		$san = $this->wire('sanitizer');
		$cols = [
			'thumb'       => $this->_('Thumb'),
			'page'        => $this->_('Page'),
			'field'       => $this->_('Field'),
			'filename'    => $this->_('Filename'),
			'description' => $this->_('Description'),
			'tags'        => $this->_('Tags'),
			'dimensions'  => $this->_('Dimensions'),
			'size'        => $this->_('Size'),
			'variations'  => $this->_('Variations'),
		];
		foreach ($customCols as $name) {
			$cols['custom:' . $name] = $name;
		}
		$hidden = array_flip($this->getDefaultHiddenColumns());
		$items = '';
		foreach ($cols as $key => $label) {
			$checked = isset($hidden[$key]) ? '' : ' checked';
			$items .= '<li class="ml-col-item" draggable="true"><label>'
				. '<input type="checkbox" class="ml-col-toggle" data-col="'
				. $san->entities($key) . '"' . $checked . '>'
				. ' ' . $san->entities($label)
				. '</label></li>';
		}
		return '<ul class="ml-columns-list">' . $items . '</ul>';
	}

	/**
	 * Render just the swappable region (table + pagination) for a given
	 * filter/sort/page state. Called by both ___execute and ___executeData
	 * so server-rendered and AJAX-rendered HTML stay in sync.
	 */
	protected function renderResultsHtml(array $filters, string $sort, string $dir, int $requestedPage, array $customCols): string {
		$rows = $this->loadRows($filters);
		$rows = $this->applyRowFilters($rows, $filters);

		$tagsConfig = $this->getTagsConfig();
		// Autocomplete + filter pool come from the *non-tag-filtered* set so
		// selecting a tag in the picker doesn't shrink the picker itself.
		$usedTags = $this->collectUsedTagsByField($rows);

		$rows = $this->applyTagFilter($rows, $filters['tags'] ?? []);
		$this->applySort($rows, $sort, $dir);

		$pageSize   = $this->readPageSize();
		$total      = count($rows);
		$totalPages = max(1, (int) ceil($total / $pageSize));
		$page       = min(max(1, $requestedPage), $totalPages);
		$offset     = ($page - 1) * $pageSize;
		$slice      = array_slice($rows, $offset, $pageSize);
		$slice      = $this->hydrateSlice($slice);

		$pager = $this->renderPagination($total, $page, $totalPages, $filters, $sort, $dir, $pageSize);
		return $pager
			. $this->renderTable($slice, $customCols, $filters, $sort, $dir, $tagsConfig)
			. $this->renderTagDatalists($usedTags)
			. $pager;
	}

	/**
	 * Walk the (filtered) flat rows, collecting distinct tags per field for
	 * use as native <datalist> autocomplete on free-form tag inputs.
	 *
	 * @return array<string,array<int,string>> fieldName => sorted unique tags
	 */
	/**
	 * Flat sorted-unique tag list across rows. Used by the filter bar's
	 * tag picker. (collectUsedTagsByField keeps per-field grouping for
	 * the editor autocomplete — different consumers, different shapes.)
	 *
	 * @return array<int,string>
	 */
	protected function flatUsedTags(array $rows): array {
		$set = [];
		foreach ($rows as $row) {
			$tags = preg_split('/\s+/', (string) ($row['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
			foreach ($tags as $t) $set[$t] = true;
		}
		$keys = array_keys($set);
		sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
		return $keys;
	}

	protected function collectUsedTagsByField(array $rows): array {
		$byField = [];
		foreach ($rows as $row) {
			$tags = preg_split('/\s+/', (string) ($row['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
			if (!$tags) continue;
			$f = (string) $row['fieldName'];
			if (!isset($byField[$f])) $byField[$f] = [];
			foreach ($tags as $t) $byField[$f][$t] = true;
		}
		$out = [];
		foreach ($byField as $f => $set) {
			$keys = array_keys($set);
			sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
			$out[$f] = $keys;
		}
		return $out;
	}

	/**
	 * Render one <datalist> per image field whose tags are free-form
	 * (useTags=1). The text input in the editor references it via
	 * list="ml-tags-used-<field>" for native browser autocomplete.
	 */
	protected function renderTagDatalists(array $usedTags): string {
		if (!$usedTags) return '';
		$san = $this->wire('sanitizer');
		$out = '';
		foreach ($usedTags as $field => $tags) {
			$out .= '<datalist id="ml-tags-used-' . $san->entities($field) . '">';
			foreach ($tags as $t) {
				$out .= '<option value="' . $san->entities($t) . '">';
			}
			$out .= '</datalist>';
		}
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

		// Show what getTagsConfig() actually detects per field, plus the raw
		// useTags / tagsList values straight off the Field, so we can tell
		// whether the discovery or the parsing is wrong when the editor
		// widget doesn't switch.
		$tagsCfg = $this->getTagsConfig();
		$rawTags = [];
		foreach ($imageFields as $name) {
			$f = $this->wire('fields')->get($name);
			if (!$f) continue;
			$rawTags[$name] = [
				'useTags'          => $f->useTags,
				'useTags (type)'   => gettype($f->useTags),
				'tagsList'         => $f->tagsList,
				'tagsList (type)'  => gettype($f->tagsList),
			];
		}
		$out .= '<dt>getTagsConfig()</dt><dd><pre>'
			. $sanitizer->entities(json_encode($tagsCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></dd>';
		$out .= '<dt>Raw $field->useTags / tagsList</dt><dd><pre>'
			. $sanitizer->entities(json_encode($rawTags, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></dd>';
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
	 * AJAX re-render endpoint: returns the rendered table+pagination HTML
	 * for the current GET params (q, template, field, no_*, sort, dir, p).
	 * The browser JS swaps it into .ml-results without a full reload.
	 *
	 * Returns text/html, not JSON — the response goes straight into innerHTML.
	 */
	public function ___executeData() {
		$config = $this->wire('config');
		$config->ajax = true;
		header('Content-Type: text/html; charset=utf-8');

		$imageFields = $this->discoverImageFields();
		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);
		if (!$imageFields || !$eligibleTemplates) {
			return '<p class="ml-empty">'
				. $this->wire('sanitizer')->entities($this->_('No images.')) . '</p>';
		}

		$customCols  = $this->collectCustomNames();
		$filters     = $this->readFilterInput($imageFields, $eligibleTemplates, $customCols);
		$sortState   = $this->readSortInput($customCols);
		$requestedPg = max(1, (int) $this->wire('input')->get('p'));

		return $this->renderResultsHtml(
			$filters,
			$sortState['sort'],
			$sortState['dir'],
			$requestedPg,
			$customCols
		);
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

		// useTags=2 (whitelist): reject any token that isn't in the configured
		// tagsList. Splits on whitespace + commas to match PW's own parsing.
		if ($subfield === 'tags') {
			$tagsCfg = $this->getTagsConfig()[$fieldName] ?? ['mode' => 0, 'allowed' => []];
			if ($tagsCfg['mode'] === 2) {
				$tokens = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
				$disallowed = array_diff($tokens, $tagsCfg['allowed']);
				if ($disallowed) {
					return $this->jsonError(
						'Tag(s) not in whitelist: ' . implode(', ', $disallowed)
					);
				}
				// Normalize separator to single space for consistency.
				$value = implode(' ', $tokens);
			}
		}

		// Output formatting off before mutating: setters work on the raw value
		// and avoid double-encoding for fields like description.
		$page->of(false);
		$img->set($subfield, $value);
		if (!$page->save($fieldName)) {
			return $this->jsonError('Save failed');
		}
		// Belt + suspenders: ensure the cache is gone before any subsequent
		// re-fetch reads it. Matches the behavior in executeBulk.
		$this->wire('cache')->deleteFor($this);

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
	 * AJAX endpoint: apply one action to a batch of selected images.
	 *
	 * Action:
	 *   set — same as a single-cell save (subfield + value), but applied
	 *         to every item in the selection. Used by the "selection is a
	 *         paintbrush" inline edit: when the user edits a cell on a
	 *         selected row, the change is broadcast to all selected rows.
	 *
	 * POST: action, items (JSON array of {pageId,fieldName,basename}),
	 * value (string), subfield (string), plus CSRF token.
	 *
	 * Items grouped by pageId → each page is loaded and saved at most once
	 * per field touched. $page->editable() enforced per page; failures
	 * accumulate in the response instead of aborting the batch.
	 *
	 * Returns JSON: { ok, succeeded:int, failed:string[] }.
	 */
	public function ___executeBulk() {
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

		$action    = (string) $input->post('action');
		$value     = (string) $input->post('value');
		$subfield  = $sanitizer->fieldName((string) $input->post('subfield'));
		$mode      = (string) $input->post('mode'); // 'replace' (default) | 'add'
		if ($mode !== 'add') $mode = 'replace';
		$itemsJson = (string) $input->post('items');

		if ($action !== 'set') {
			return $this->jsonError('Unknown action');
		}
		if (!$subfield) {
			return $this->jsonError('Subfield required');
		}

		$items = json_decode($itemsJson, true);
		if (!is_array($items) || !$items) {
			return $this->jsonError('No items selected');
		}

		// Group by pageId so we save each page at most once per field.
		$byPage = [];
		foreach ($items as $item) {
			$pid = (int) ($item['pageId'] ?? 0);
			$fn  = $sanitizer->fieldName((string) ($item['fieldName'] ?? ''));
			$bn  = basename((string) ($item['basename'] ?? ''));
			if (!$pid || !$fn || !$bn) continue;
			$byPage[$pid][] = ['fieldName' => $fn, 'basename' => $bn];
		}
		if (!$byPage) return $this->jsonError('No valid items');

		$succeeded   = 0;
		$failed      = [];
		$tagsCfg     = $this->getTagsConfig();
		$imageFields = $this->discoverImageFields();

		foreach ($byPage as $pid => $pageItems) {
			$page = $this->wire('pages')->get($pid);
			if (!$page->id) {
				$failed[] = sprintf('Page %d not found', $pid);
				continue;
			}
			if (!$page->editable()) {
				$failed[] = sprintf('Page %d not editable', $pid);
				continue;
			}
			$page->of(false);
			$fieldsTouched = [];

			foreach ($pageItems as $it) {
				$fn = $it['fieldName'];
				$bn = $it['basename'];

				if (!in_array($fn, $imageFields, true)) {
					$failed[] = sprintf('Field %s not managed', $fn);
					continue;
				}
				if (!in_array($subfield, $this->editableSubfields($fn), true)) {
					$failed[] = sprintf('Subfield %s not editable on %s', $subfield, $fn);
					continue;
				}

				$fieldValue = $page->getUnformatted($fn);
				$img = null;
				if ($fieldValue instanceof Pageimages) {
					$img = $fieldValue->getFile($bn);
				} elseif ($fieldValue instanceof Pageimage) {
					$img = $fieldValue;
				}
				if (!$img instanceof Pageimage) {
					$failed[] = sprintf('Image %s not found in %d.%s', $bn, $pid, $fn);
					continue;
				}

				$itemValue = $value;

				if ($subfield === 'tags') {
					$tokens = preg_split('/[\s,]+/', $itemValue, -1, PREG_SPLIT_NO_EMPTY) ?: [];
					// Whitelist gate per item: tag whitelist can differ per
					// field, so we check per item rather than rejecting the
					// whole batch up front.
					$tagCfg = $tagsCfg[$fn] ?? ['mode' => 0, 'allowed' => []];
					if ($tagCfg['mode'] === 2) {
						$disallowed = array_diff($tokens, $tagCfg['allowed']);
						if ($disallowed) {
							$failed[] = sprintf('Tag(s) not in whitelist for %s: %s', $fn, implode(', ', $disallowed));
							continue;
						}
					}
					if ($mode === 'add') {
						// Union with the row's existing tags, dedup.
						$existing = preg_split('/\s+/', (string) $img->tags, -1, PREG_SPLIT_NO_EMPTY) ?: [];
						$tokens   = array_values(array_unique(array_merge($existing, $tokens)));
					}
					$itemValue = implode(' ', $tokens);
				} elseif ($mode === 'add') {
					// Add of an empty delta is a no-op — don't append a
					// trailing space to every selected row.
					if ($itemValue === '') {
						$succeeded++;
						continue;
					}
					// Description / custom text: append with a single space
					// to the row's existing value. Empty existing → just
					// the new value.
					$existing = (string) $img->get($subfield);
					if ($existing !== '') {
						$itemValue = $existing . ' ' . $itemValue;
					}
				}

				$img->set($subfield, $itemValue);
				$fieldsTouched[$fn] = true;
				$succeeded++;
			}

			foreach (array_keys($fieldsTouched) as $fn) {
				if (!$page->save($fn)) {
					$failed[] = sprintf('Save failed: page %d field %s', $pid, $fn);
				}
			}
		}

		// Belt + suspenders: nuke our cache after the batch so the
		// subsequent /data/ re-fetch definitely sees fresh rows.
		// (PW's selector-based invalidation should already handle this
		// for $page->save($fn), but bulk has bitten us with stale reads.)
		if ($succeeded > 0) {
			$this->wire('cache')->deleteFor($this);
		}

		return json_encode([
			'ok'        => true,
			'succeeded' => $succeeded,
			'failed'    => $failed,
		]);
	}

	/**
	 * Streams the currently-filtered row set as a JSON file download.
	 * Each image carries everything an external editor / processor
	 * needs to (a) see the picture (absolute URL, page context) and
	 * (b) round-trip the payload back through ___executeImport
	 * without us needing to trust anything but the identity triple.
	 *
	 * Filter state is read from the same GET params as the main
	 * listing — so the user just builds the filter they care about
	 * ("no_custom_summary=1") and the Export button hands that
	 * exact slice over.
	 */
	public function ___executeExport() {
		$imageFields = $this->discoverImageFields();
		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);
		if (!$imageFields || !$eligibleTemplates) {
			header('Content-Type: application/json');
			echo json_encode(['error' => 'No image fields configured']);
			exit;
		}

		$customCols = $this->collectCustomNames();
		$filters    = $this->readFilterInput($imageFields, $eligibleTemplates, $customCols);

		$rows = $this->loadRows($filters);
		$rows = $this->applyRowFilters($rows, $filters);
		$rows = $this->applyTagFilter($rows, $filters['tags'] ?? []);

		// Full hydration — no pagination. The export deliberately
		// produces the entire filtered set so the operator can hand
		// it all to an external editor at once; users with very large
		// libraries should narrow the filter before exporting.
		$pageIds = array_values(array_unique(array_column($rows, 'pageId')));
		$pagesById = [];
		foreach ($this->wire('pages')->getById($pageIds) as $p) {
			$pagesById[$p->id] = $p;
		}
		$customByField = $this->getCustomByField();

		$images = [];
		foreach ($rows as $row) {
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

			$customs = [];
			foreach ($customByField[$row['fieldName']] ?? [] as $name) {
				$val = $img->get($name);
				if ($val === null) $val = '';
				if (is_object($val)) $val = (string) $val;
				if (is_array($val)) $val = $this->normalizeDescription($val);
				$customs[$name] = (string) $val;
			}

			$images[] = [
				'id'          => sprintf('%d:%s:%s',
					(int) $row['pageId'],
					(string) $row['fieldName'],
					(string) $row['basename']
				),
				'pageId'      => (int) $row['pageId'],
				'fieldName'   => (string) $row['fieldName'],
				'basename'    => (string) $row['basename'],
				'url'         => $img->httpUrl,
				'pageTitle'   => (string) $row['pageTitle'],
				'pageUrl'     => $page->httpUrl,
				'dimensions'  => ($row['width'] && $row['height'])
					? ((int) $row['width']) . 'x' . ((int) $row['height'])
					: '',
				'filesize'    => (int) $row['filesize'],
				'description' => $this->normalizeDescription($row['description']),
				'tags'        => (string) $row['tags'],
				'custom'      => (object) $customs, // force {} when empty so the shape is consistent
			];
		}

		$config = $this->wire('config');
		$siteUrl = ($config->https ? 'https://' : 'http://') . $config->httpHost;

		$payload = [
			'meta' => [
				'exportedAt'     => date('c'),
				'siteUrl'        => $siteUrl,
				'imageCount'     => count($images),
				'appliedFilter'  => (object) $this->summarizeActiveFilters($filters),
				'editableFields' => ['description', 'tags', 'custom.*'],
				'readOnlyFields' => ['id', 'pageId', 'fieldName', 'basename', 'url', 'dimensions', 'filesize', 'pageTitle', 'pageUrl'],
			],
			'images' => $images,
		];

		$format = (string) $this->wire('input')->get('format');
		if ($format === 'csv') {
			$this->streamExportCsv($images);
			exit;
		}

		$filename = sprintf('media-library-export-%s.json', date('Ymd-His'));
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		echo json_encode(
			$payload,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		exit;
	}

	/**
	 * Parse a CSV import body into the same {images: [...]} shape
	 * the JSON import path produces. Columns prefixed "custom_" fold
	 * back into a custom object per row. Returns null when the
	 * header row is missing or unusable; missing optional columns
	 * (description, tags, etc.) are tolerated and emitted as empty
	 * strings so downstream "if value changed" checks still work.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	protected function parseImportCsv(string $raw): ?array {
		// Strip UTF-8 BOM if present so the first header isn't garbled.
		if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $raw);
		rewind($stream);

		$headers = fgetcsv($stream);
		if (!is_array($headers) || !$headers) { fclose($stream); return null; }
		$headers = array_map(fn($h) => trim((string) $h), $headers);

		$out = [];
		while (($row = fgetcsv($stream)) !== false) {
			// Skip blank lines fgetcsv yields as [null].
			if (count($row) === 1 && ($row[0] === null || $row[0] === '')) continue;
			$entry = ['custom' => []];
			foreach ($headers as $i => $h) {
				if ($h === '') continue;
				$val = (string) ($row[$i] ?? '');
				if (strncmp($h, 'custom_', 7) === 0) {
					$entry['custom'][substr($h, 7)] = $val;
				} else {
					$entry[$h] = $val;
				}
			}
			$out[] = $entry;
		}
		fclose($stream);
		return $out;
	}

	/**
	 * CSV variant of the export. Same row content as the JSON path,
	 * flattened — one row per image, with each Custom subfield
	 * promoted to its own "custom_<name>" column (union across the
	 * export, sorted, so the column layout stays stable across
	 * rows). UTF-8 BOM prepended so Excel reads umlauts correctly;
	 * fputcsv handles quoting + newlines-in-fields.
	 *
	 * @param array<int,array<string,mixed>> $images
	 */
	protected function streamExportCsv(array $images): void {
		$customCols = [];
		foreach ($images as $img) {
			foreach ((array) $img['custom'] as $k => $_) {
				$customCols[$k] = true;
			}
		}
		ksort($customCols);
		$customCols = array_keys($customCols);

		$headers = [
			'id', 'pageId', 'fieldName', 'basename', 'url',
			'pageTitle', 'pageUrl', 'dimensions', 'filesize',
			'description', 'tags',
		];
		foreach ($customCols as $c) $headers[] = 'custom_' . $c;

		$filename = sprintf('media-library-export-%s.csv', date('Ymd-His'));
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		// UTF-8 BOM — keeps Excel from mangling umlauts.
		echo "\xEF\xBB\xBF";
		$out = fopen('php://output', 'w');
		fputcsv($out, $headers);
		foreach ($images as $img) {
			$row = [];
			foreach ($headers as $h) {
				if (strncmp($h, 'custom_', 7) === 0) {
					$key = substr($h, 7);
					$customs = (array) $img['custom'];
					$row[] = (string) ($customs[$key] ?? '');
				} else {
					$row[] = (string) ($img[$h] ?? '');
				}
			}
			fputcsv($out, $row);
		}
		fclose($out);
	}

	/**
	 * Apply a previously-exported (and externally edited)
	 * JSON file back to the live pages. Every value goes through the
	 * same whitelist gates as the inline-edit save endpoint:
	 * per-page editable() check, image-field whitelist, subfield
	 * whitelist (built-ins + per-field declared customs), tag
	 * whitelist when useTags=2. Items whose values match the page's
	 * current state are skipped so we don't pile up empty saves.
	 */
	public function ___executeImport() {
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

		// Accept either an uploaded "file" or pasted text via "payload".
		$raw = '';
		$uploadName = '';
		if (!empty($_FILES['file']['tmp_name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
			$raw = (string) file_get_contents($_FILES['file']['tmp_name']);
			$uploadName = (string) ($_FILES['file']['name'] ?? '');
		} elseif ($this->wire('input')->post('payload')) {
			$raw = (string) $this->wire('input')->post('payload');
		}
		if ($raw === '') return $this->jsonError('No file or payload provided');

		// Format detection: filename extension wins, then peek the
		// first non-whitespace byte ({/[ ⇒ JSON, anything else ⇒ CSV).
		$isCsv = false;
		$lowerName = strtolower($uploadName);
		if (str_ends_with($lowerName, '.csv')) {
			$isCsv = true;
		} elseif (str_ends_with($lowerName, '.json')) {
			$isCsv = false;
		} else {
			$peek = ltrim($raw);
			$isCsv = !($peek !== '' && ($peek[0] === '{' || $peek[0] === '['));
		}

		if ($isCsv) {
			$images = $this->parseImportCsv($raw);
			if ($images === null) {
				return $this->jsonError('Invalid CSV — could not parse header row.');
			}
			$data = ['images' => $images];
		} else {
			$data = json_decode($raw, true);
			if (!is_array($data) || empty($data['images']) || !is_array($data['images'])) {
				return $this->jsonError('Invalid JSON shape — expected {"images": [...]}.');
			}
		}

		$sanitizer     = $this->wire('sanitizer');
		$imageFields   = $this->discoverImageFields();
		$customByField = $this->getCustomByField();
		$tagsConfig    = $this->getTagsConfig();

		$succeeded = 0;
		$skipped   = 0;
		$failed    = [];

		// Group by pageId so each affected page is saved once per field.
		$byPage = [];
		foreach ($data['images'] as $item) {
			if (!is_array($item)) continue;
			$pid = (int) ($item['pageId'] ?? 0);
			$fn  = $sanitizer->fieldName((string) ($item['fieldName'] ?? ''));
			$bn  = basename((string) ($item['basename'] ?? ''));
			if (!$pid || !$fn || !$bn) {
				$failed[] = 'Missing pageId / fieldName / basename';
				continue;
			}
			$byPage[$pid][] = ['fn' => $fn, 'bn' => $bn, 'item' => $item];
		}

		foreach ($byPage as $pid => $items) {
			$page = $this->wire('pages')->get($pid);
			if (!$page->id) { $failed[] = "Page $pid not found"; continue; }
			if (!$page->editable()) { $failed[] = "Page $pid not editable"; continue; }
			$page->of(false);
			$fieldsTouched = [];

			foreach ($items as $entry) {
				$fn = $entry['fn'];
				$bn = $entry['bn'];
				$item = $entry['item'];

				if (!in_array($fn, $imageFields, true)) {
					$failed[] = "Field $fn not managed";
					continue;
				}

				$fieldValue = $page->getUnformatted($fn);
				$img = null;
				if ($fieldValue instanceof Pageimages) {
					$img = $fieldValue->getFile($bn);
				} elseif ($fieldValue instanceof Pageimage) {
					$img = $fieldValue;
				}
				if (!$img instanceof Pageimage) {
					$failed[] = "Image $bn not in $pid.$fn";
					continue;
				}

				$dirty = false;

				if (array_key_exists('description', $item)) {
					$new = (string) $item['description'];
					if ($new !== (string) $img->description) {
						$img->set('description', $new);
						$dirty = true;
					}
				}

				if (array_key_exists('tags', $item)) {
					$new = (string) $item['tags'];
					$cfg = $tagsConfig[$fn] ?? ['mode' => 0, 'allowed' => []];
					if ($cfg['mode'] === 2) {
						$tokens = preg_split('/[\s,]+/', $new, -1, PREG_SPLIT_NO_EMPTY) ?: [];
						$bad = array_diff($tokens, $cfg['allowed']);
						if ($bad) {
							$failed[] = "Tag(s) not in whitelist for $fn: " . implode(', ', $bad);
							continue;
						}
						$new = implode(' ', $tokens);
					}
					if ($new !== (string) $img->tags) {
						$img->set('tags', $new);
						$dirty = true;
					}
				}

				if (!empty($item['custom']) && (is_array($item['custom']) || is_object($item['custom']))) {
					$allowedCustoms = $customByField[$fn] ?? [];
					foreach ((array) $item['custom'] as $name => $val) {
						$name = $sanitizer->fieldName((string) $name);
						if (!in_array($name, $allowedCustoms, true)) continue;
						$new = is_scalar($val) ? (string) $val : '';
						if ($new !== (string) $img->get($name)) {
							$img->set($name, $new);
							$dirty = true;
						}
					}
				}

				if ($dirty) {
					$fieldsTouched[$fn] = true;
					$succeeded++;
				} else {
					$skipped++;
				}
			}

			foreach (array_keys($fieldsTouched) as $fn) {
				if (!$page->save($fn)) {
					$failed[] = "Save failed: page $pid field $fn";
				}
			}
		}

		if ($succeeded > 0) {
			$this->wire('cache')->deleteFor($this);
		}

		return json_encode([
			'ok'        => true,
			'succeeded' => $succeeded,
			'skipped'   => $skipped,
			'failed'    => $failed,
		]);
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

		// Bulk-hydrate custom-field values onto every row whenever a
		// filter actually reads them — "missing X" toggles and free-
		// text search both need customs in scope. Hydration is not
		// cached so the cache stays generic (one entry per discovery
		// state) and any custom-field edits show up immediately.
		$qNeedsCustoms = ($filters['q'] ?? '') !== '';
		if (($qNeedsCustoms || !empty($filters['no_custom'])) && $this->hasAnyCustomFields()) {
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
	 * Per-image-field tag configuration so the inline editor can render the
	 * right widget (whitelist checkbox group vs. free-text + autocomplete)
	 * and the save endpoint can validate against the whitelist.
	 *
	 * Effective mode for our editor (NOT the raw PW useTags value):
	 *   0 = tags disabled
	 *   1 = free-form (useTags set but no tagsList content)
	 *   2 = whitelist (tagsList has parseable content)
	 *
	 * Why we don't trust $field->useTags directly: modern PW stores useTags
	 * as a bit-mask of feature flags (1=manual, 2=list, 4=…, 8=…), so the
	 * value can be e.g. 8 when the user enabled a whitelist. Our editor only
	 * cares whether a list is present, so we key off tagsList content.
	 *
	 * @return array<string,array{mode:int,allowed:array<int,string>}>
	 */
	protected function getTagsConfig(): array {
		$out = [];
		foreach ($this->wire('fields') as $field) {
			if (!($field->type instanceof FieldtypeImage)) continue;

			$useTagsRaw = $field->useTags;
			$rawList    = (string) $field->tagsList;
			$allowed    = preg_split('/[\s,]+/', $rawList, -1, PREG_SPLIT_NO_EMPTY) ?: [];

			$effective = 0;
			if ($useTagsRaw) {
				$effective = $allowed ? 2 : 1;
			}

			$out[$field->name] = ['mode' => $effective, 'allowed' => $allowed];
		}
		return $out;
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
	 * For each eligible template, which managed image fields it actually
	 * contains. The filter-bar JS uses this to narrow the field dropdown
	 * live when the user picks a template — without a server round-trip.
	 *
	 * @param array<int,string> $imageFields
	 * @param array<int,string> $eligibleTemplates
	 * @return array<string,array<int,string>> templateName => [fieldName, …]
	 */
	protected function getTemplateFieldsMap(array $imageFields, array $eligibleTemplates): array {
		$fieldSet = array_flip($imageFields);
		$map = [];
		foreach ($eligibleTemplates as $tname) {
			$tpl = $this->wire('templates')->get($tname);
			if (!$tpl) continue;
			$fields = [];
			foreach ($tpl->fieldgroup as $f) {
				if (isset($fieldSet[$f->name])) $fields[] = $f->name;
			}
			$map[$tname] = $fields;
		}
		return $map;
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

		// tags filter: ?tags[]=foo&tags[]=bar — AND-match against row tags.
		$rawTags = $input->get('tags');
		$tags = [];
		if (is_array($rawTags)) {
			foreach ($rawTags as $t) {
				$t = trim((string) $t);
				if ($t !== '') $tags[] = $t;
			}
		} elseif (is_string($rawTags) && $rawTags !== '') {
			foreach (preg_split('/[\s,]+/', $rawTags, -1, PREG_SPLIT_NO_EMPTY) as $t) {
				$tags[] = $t;
			}
		}
		$tags = array_values(array_unique($tags));

		return [
			'q'              => trim((string) $input->get('q')),
			'template'       => in_array($template, $eligibleTemplates, true) ? $template : '',
			'field'          => in_array($field, $imageFields, true) ? $field : '',
			'no_desc'        => (bool) $input->get('no_desc'),
			'no_tags'        => (bool) $input->get('no_tags'),
			'only_galleries' => (bool) $input->get('only_galleries'),
			'no_custom'      => $noCustom,
			'tags'           => $tags,
		];
	}

	/**
	 * Read and validate the sort/dir GET params. Invalid sort keys fall back
	 * to the default; this prevents users from injecting arbitrary keys into
	 * the row arrays via the URL.
	 *
	 * @return array{sort:string,dir:string}
	 */
	protected function readSortInput(array $customCols): array {
		$input = $this->wire('input');
		$sort  = (string) $input->get('sort');
		$dir   = (string) $input->get('dir');

		$whitelist = array_keys(self::SORTABLE_COLUMNS);
		foreach ($customCols as $name) $whitelist[] = 'custom:' . $name;

		if (!in_array($sort, $whitelist, true)) {
			$sort = self::DEFAULT_SORT;
			$dir  = self::DEFAULT_DIR;
		}
		if ($dir !== 'desc') $dir = 'asc';

		return ['sort' => $sort, 'dir' => $dir];
	}

	/**
	 * Whitelist-validate the ps GET param against PAGE_SIZE_OPTIONS,
	 * falling back to PAGE_SIZE_DEFAULT for missing / out-of-range
	 * values. URL-driven (not stored) so it stays bookmarkable
	 * alongside the other filter / sort / page state.
	 */
	protected function readPageSize(): int {
		$ps = (int) $this->wire('input')->get('ps');
		$opts = $this->getPageSizeOptions();
		return in_array($ps, $opts, true) ? $ps : $this->getDefaultPageSize();
	}

	/**
	 * Sort flat rows in place by the given column. Custom-fields-on-images
	 * are addressed via the 'custom:<name>' token. Ties break on
	 * "pageId:basename" so output is deterministic across requests.
	 */
	protected function applySort(array &$rows, string $sort, string $dir): void {
		$isCustom = strncmp($sort, 'custom:', 7) === 0;
		$type     = $isCustom ? 'string' : (self::SORTABLE_COLUMNS[$sort] ?? 'string');
		$custom   = $isCustom ? substr($sort, 7) : '';

		usort($rows, function ($a, $b) use ($sort, $dir, $type, $isCustom, $custom) {
			if ($isCustom) {
				$va = $a['custom'][$custom] ?? '';
				$vb = $b['custom'][$custom] ?? '';
				if (is_array($va)) $va = json_encode($va);
				if (is_array($vb)) $vb = json_encode($vb);
			} else {
				$va = $a[$sort] ?? '';
				$vb = $b[$sort] ?? '';
			}
			$cmp = $type === 'int'
				? ((int) $va <=> (int) $vb)
				: strcasecmp((string) $va, (string) $vb);
			if ($cmp === 0) {
				$cmp = strcmp(
					$a['pageId'] . ':' . $a['basename'],
					$b['pageId'] . ':' . $b['basename']
				);
				return $cmp; // tiebreaker is direction-agnostic for stability
			}
			return $dir === 'desc' ? -$cmp : $cmp;
		});
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
				$customHay = '';
				if (!empty($r['custom']) && is_array($r['custom'])) {
					foreach ($r['custom'] as $v) {
						// Customs are pre-stringified by bulkHydrateCustomFields
						// (object->__toString, array->json), so a defensive cast
						// is enough here.
						$customHay .= ' ' . (string) $v;
					}
				}
				$hay = mb_strtolower(
					$desc . ' '
					. $tags . ' '
					. ((string) $r['basename']) . ' '
					. ((string) ($r['pageTitle'] ?? '')) . ' '
					. $customHay
				);
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
		$thumb = $this->getThumbDims();

		foreach ($slice as &$row) {
			$row['thumbUrl']        = '';
			$row['pageUrl']         = '';
			$row['pageEditUrl']     = '';
			$row['variationsCount'] = 0;

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

			$thumbImg = $img->size($thumb['width'], $thumb['height'], [
				'upscaling' => false,
				'quality'   => $thumb['quality'],
				'cropping'  => $thumb['crop'],
			]);
			$row['thumbUrl']    = $thumbImg->url;
			$row['thumbWidth']  = (int) $thumbImg->width;
			$row['thumbHeight'] = (int) $thumbImg->height;
			// Variations count — Phase 2 column from the concept,
			// useful for pre-warm diagnosis and cleanup. getVariations()
			// does a filesystem scan per image, but only for the 50-ish
			// rows in the visible slice, so the cost is bounded.
			$variations = $img->getVariations();
			$row['variationsCount'] = $variations ? $variations->count() : 0;

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
	public function collectCustomNames(): array {
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

		$imageFields = $this->discoverImageFields();
		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);

		$config->js('ProcessMediaLibrary', [
			'saveUrl'   => $this->wire('page')->url . 'save/',
			'renderUrl' => $this->wire('page')->url . 'data/',
			'bulkUrl'   => $this->wire('page')->url . 'bulk/',
			// Used to build the page-edit URL for the thumbnail-click
			// modal — wraps PW's native image editor in an iframe.
			'adminUrl'  => $config->urls->admin,
			'tplFields' => $this->getTemplateFieldsMap($imageFields, $eligibleTemplates),
			'defaultPageSize'      => $this->getDefaultPageSize(),
			'defaultHiddenColumns' => $this->getDefaultHiddenColumns(),
			'csrf' => [
				'name'  => $session->CSRF->getTokenName(),
				'value' => $session->CSRF->getTokenValue(),
			],
			'labels' => [
				'saving'           => $this->_('Saving…'),
				'saved'            => $this->_('Saved'),
				'error'            => $this->_('Save failed'),
				'done'             => $this->_('Done'),
				'add'              => $this->_('Add'),
				'replace'          => $this->_('Replace'),
				'save'             => $this->_('Save'),
				'cancel'           => $this->_('Cancel'),
				'close'            => $this->_('Close'),
				'imageEditorTitle' => $this->_('Edit image: %s'),
				'importing'        => $this->_('Importing…'),
				'importSaved'      => $this->_('Saved'),
				'importSkipped'    => $this->_('Unchanged'),
				'importFailed'    => $this->_('Failed'),
				'importError'      => $this->_('Import failed'),
				'batching'         => $this->_('Applying to %d selected…'),
				'bulkResult'       => $this->_('Succeeded: %1$d  ·  Failed: %2$d'),
				// Field-dropdown label when a template is active — %s is
				// the template name. The JS swaps "All image fields" for
				// this so the user isn't told "all" while non-template
				// fields are greyed out below.
				'fieldEmptyScoped' => $this->_('All fields of %s'),
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

	/**
	 * True if the current user can edit at least one page that uses
	 * one of the eligible image-field templates. Superusers always
	 * pass; users without any page-edit permission short-circuit to
	 * false. Otherwise we lazy-iterate (findMany doesn't load all
	 * pages at once) and bail on the first match — typically one
	 * page in for users with broad edit access, bounded by total
	 * matching-page count for the worst case.
	 */
	protected function canEditAnyImagePage(array $eligibleTemplates): bool {
		if (!$eligibleTemplates) return false;
		$user = $this->wire('user');
		if ($user->isSuperuser()) return true;
		if (!$user->hasPermission('page-edit')) return false;
		$selector = 'template=' . implode('|', $eligibleTemplates) . ', include=hidden';
		foreach ($this->wire('pages')->findMany($selector) as $p) {
			if ($p->editable()) return true;
		}
		return false;
	}

	protected function renderNoEditAccess(): string {
		$san = $this->wire('sanitizer');
		return '<p class="ml-empty">' . $san->entities(
			$this->_('You don\'t have edit access to any page with an image field. Ask an admin to grant the relevant page-edit permission.')
		) . '</p>';
	}

	protected function renderFilterBar(array $filters, array $imageFields, array $eligibleTemplates, array $customCols = [], string $sort = '', string $dir = '', array $tagFilterPool = []): string {
		$modules = $this->wire('modules');

		// When the user has filtered to one image field, only show Missing-X
		// checkboxes for subfields that field actually declares — otherwise
		// the checkbox is dead UI (would always return 0 results).
		if (!empty($filters['field'])) {
			$customCols = $this->getCustomByField()[$filters['field']] ?? [];
		}

		$active = $this->hasActiveFilter($filters);

		/** @var \ProcessWire\InputfieldForm $form */
		$form = $modules->get('InputfieldForm');
		$form->method = 'get';
		$form->action = './';
		// Keep .ml-filter-bar so the JS submit interceptor + reset clearer
		// + live reset-visibility find the form.
		$form->attr('class', trim($form->attr('class') . ' ml-filter-bar'));

		// Hidden sort/dir — at form level so they're never inside the
		// (collapsible) outer fieldset and always submit cleanly.
		if ($sort !== '' && $sort !== self::DEFAULT_SORT) {
			$h = $modules->get('InputfieldHidden');
			$h->name  = 'sort';
			$h->value = $sort;
			$form->add($h);
		}
		if ($dir === 'desc') {
			$h = $modules->get('InputfieldHidden');
			$h->name  = 'dir';
			$h->value = 'desc';
			$form->add($h);
		}

		// Outer "Filters" fieldset wraps everything. Collapsed by default;
		// auto-opens on initial render when any filter is active so the
		// user immediately sees what's filtering the view.
		/** @var \ProcessWire\InputfieldFieldset $outer */
		$outer = $modules->get('InputfieldFieldset');
		$outer->name      = 'mlFilters';
		$outer->label     = $this->_('Filters & Columns');
		$outer->collapsed = $active ? Inputfield::collapsedNo : Inputfield::collapsedYes;

		// Columns sub-fieldset first — checkboxes have no name= so they
		// don't enter the filter form's URL params, and the JS toggle
		// hook (.ml-col-toggle, localStorage-backed) works regardless
		// of where in the DOM the checkboxes live.
		/** @var \ProcessWire\InputfieldFieldset $columnsFs */
		$columnsFs = $modules->get('InputfieldFieldset');
		$columnsFs->name      = 'mlColumns';
		$columnsFs->label     = $this->_('Columns');
		$columnsFs->collapsed = Inputfield::collapsedYes;

		$columnsMarkup = $modules->get('InputfieldMarkup');
		$columnsMarkup->skipLabel = Inputfield::skipLabelHeader;
		// InputfieldMarkup picks up "value" first, then "markup" as a
		// legacy fallback — setting both is harmless but value is what
		// the modern render path actually reads.
		$columnsMarkup->value = $this->renderColumnsListMarkup($customCols);
		$columnsFs->add($columnsMarkup);

		$outer->add($columnsFs);

		// Row 1: q + template + field, 33% each.
		$q = $modules->get('InputfieldText');
		$q->name        = 'q';
		$q->label       = $this->_('Search');
		$q->placeholder = $this->_('Page title, description, tags, filename, customs');
		$q->value       = $filters['q'];
		$q->columnWidth = 33;
		$outer->add($q);

		$tpl = $modules->get('InputfieldSelect');
		$tpl->name        = 'template';
		$tpl->label       = $this->_('Template');
		$tpl->addOption('', $this->_('All templates'));
		foreach ($eligibleTemplates as $t) $tpl->addOption($t, $t);
		$tpl->value       = $filters['template'];
		$tpl->columnWidth = 33;
		$outer->add($tpl);

		$fld = $modules->get('InputfieldSelect');
		$fld->name        = 'field';
		$fld->label       = $this->_('Image field');
		$fld->addOption('', $this->_('All image fields'));
		foreach ($imageFields as $f) $fld->addOption($f, $f);
		$fld->value       = $filters['field'];
		$fld->columnWidth = 34; // 33+33+34=100
		$outer->add($fld);

		// Tags fieldset (full width, collapsed unless something is active).
		if ($tagFilterPool) {
			$selectedTags = $filters['tags'] ?? [];
			/** @var \ProcessWire\InputfieldFieldset $tagsFs */
			$tagsFs = $modules->get('InputfieldFieldset');
			$tagsFs->label = $selectedTags
				? sprintf($this->_('Tags (%d)'), count($selectedTags))
				: $this->_('Tags');
			$tagsFs->collapsed   = !empty($selectedTags) ? Inputfield::collapsedNo : Inputfield::collapsedYes;
			$tagsFs->columnWidth = 100;

			$cbs = $modules->get('InputfieldCheckboxes');
			$cbs->name        = 'tags';
			$cbs->label       = $this->_('Active tags');
			$cbs->skipLabel   = Inputfield::skipLabelHeader;
			$cbs->optionColumns = 4;
			foreach ($tagFilterPool as $t) $cbs->addOption($t, $t);
			$cbs->value = $selectedTags;
			$tagsFs->add($cbs);

			$outer->add($tagsFs);
		}

		// Missing-X + galleries-only — each as InputfieldCheckbox, 25% wide.
		$missingDef = [
			'no_desc' => $this->_('Missing description'),
			'no_tags' => $this->_('Missing tags'),
		];
		foreach ($customCols as $name) {
			$missingDef['no_custom_' . $name] = sprintf($this->_('Missing %s'), $name);
		}
		$missingDef['only_galleries'] = $this->_('Galleries only');

		foreach ($missingDef as $name => $label) {
			$cb = $modules->get('InputfieldCheckbox');
			$cb->name        = $name;
			$cb->label       = $label;
			$cb->columnWidth = 25;
			if ($name === 'no_desc'        && !empty($filters['no_desc']))        $cb->attr('checked', 'checked');
			if ($name === 'no_tags'        && !empty($filters['no_tags']))        $cb->attr('checked', 'checked');
			if ($name === 'only_galleries' && !empty($filters['only_galleries'])) $cb->attr('checked', 'checked');
			if (strpos($name, 'no_custom_') === 0) {
				$key = substr($name, strlen('no_custom_'));
				if (!empty($filters['no_custom'][$key])) $cb->attr('checked', 'checked');
			}
			$outer->add($cb);
		}

		// Apply + Reset, side by side. Reset is a real <a> via
		// InputfieldButton(href=…) so graceful degradation works; JS
		// intercepts the click for AJAX reset. .ml-reset stays a hook for
		// the JS visibility toggle and click handler.
		$apply = $modules->get('InputfieldSubmit');
		$apply->name        = 'apply';
		$apply->value       = $this->_('Apply');
		$apply->columnWidth = 50;
		$outer->add($apply);

		$reset = $modules->get('InputfieldButton');
		$reset->name        = 'reset';
		$reset->value       = $this->_('Reset');
		$reset->attr('href', './');
		$reset->addClass('ml-reset');
		$reset->columnWidth = 50;
		$outer->add($reset);

		$form->add($outer);

		return $form->render();
	}

	protected function hasActiveFilter(array $filters): bool {
		return $filters['q'] !== ''
			|| $filters['template'] !== ''
			|| $filters['field'] !== ''
			|| $filters['no_desc']
			|| $filters['no_tags']
			|| $filters['only_galleries']
			|| !empty($filters['no_custom'])
			|| !empty($filters['tags']);
	}

	/**
	 * Filter rows down to those whose tag set includes ALL of the requested
	 * tag tokens (AND semantics). Called separately from applyRowFilters so
	 * the filter UI can build its tag pool from "rows after non-tag filters"
	 * — selecting a tag then doesn't shrink the picker's options.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<int,string> $tags required tags (case-sensitive match)
	 */
	protected function applyTagFilter(array $rows, array $tags): array {
		if (!$tags) return $rows;
		$needed = array_fill_keys($tags, true);
		return array_values(array_filter($rows, function ($row) use ($needed) {
			$rowTags = preg_split('/\s+/', (string) ($row['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
			if (count($rowTags) < count($needed)) return false;
			$rowSet = array_fill_keys($rowTags, true);
			foreach ($needed as $t => $_) {
				if (!isset($rowSet[$t])) return false;
			}
			return true;
		}));
	}

	/**
	 * @param array<int,array<string,mixed>> $slice hydrated slice
	 * @param array<int,string> $customCols custom-field column names
	 * @param array<string,array{mode:int,allowed:array<int,string>}> $tagsConfig per-field tag mode + whitelist
	 */
	protected function renderTable(array $slice, array $customCols, array $filters = [], string $sort = '', string $dir = '', array $tagsConfig = []): string {
		$san = $this->wire('sanitizer');
		$thumb = $this->getThumbDims();

		// Per-subfield editor type — Textarea-backed customs render a
		// <textarea> in the inline editor, everything else falls back to
		// <input type="text">. Built once across every image field's
		// declared subfields so the per-row loop is just a lookup.
		$fieldsApi  = $this->wire('fields');
		$customByField = $this->getCustomByField();
		$customInputTypes = [];
		foreach ($customByField as $names) {
			foreach ($names as $n) {
				if (isset($customInputTypes[$n])) continue;
				$f = $fieldsApi->get($n);
				$customInputTypes[$n] = ($f && $f->type instanceof FieldtypeTextarea)
					? 'textarea' : 'text';
			}
		}

		if (!$slice) {
			return '<p class="ml-empty">'
				. $san->entities($this->_('No images match the current filters.')) . '</p>';
		}

		// When the user has filtered to one image field, hide columns the
		// field can't populate: drop the Tags column if useTags is off, and
		// narrow custom-cols to just that field's declared subfields. With
		// no field filter, show the full union (default behavior).
		$showTagsCol = true;
		if (!empty($filters['field'])) {
			$fn = $filters['field'];
			$customCols  = $this->getCustomByField()[$fn] ?? [];
			$showTagsCol = (($tagsConfig[$fn]['mode'] ?? 0) > 0);
		}

		// col-key, label, sort-key (null = not sortable). The col-key
		// drives the data-col attribute on <th> + every matching <td>,
		// which the Columns toggles in the filter bar use to show /
		// hide whole columns client-side.
		$headers = [
			['thumb',       $this->_('Thumb'),       null],
			['page',        $this->_('Page'),        'pageTitle'],
			['field',       $this->_('Field'),       'fieldName'],
			['filename',    $this->_('Filename'),    'basename'],
			['description', $this->_('Description'), 'description'],
			['tags',        $this->_('Tags'),        'tags'],
			['dimensions',  $this->_('Dimensions'),  'width'],
			['size',        $this->_('Size'),        'filesize'],
			['variations',  $this->_('Variations'),  null],
		];
		if (!$showTagsCol) {
			$headers = array_values(array_filter($headers, fn($h) => $h[0] !== 'tags'));
		}

		// Outer scroller so the wide table can overflow horizontally
		// on narrow viewports without breaking the table layout.
		$out  = '<div class="ml-table-scroll">';
		$out .= '<table class="ml-table uk-table uk-table-divider uk-table-small">';
		$out .= '<thead><tr>';
		$out .= '<th class="ml-cell-select">'
			. '<input type="checkbox" class="ml-select-all" title="'
			. $san->entities($this->_('Select all on page')) . '"></th>';
		foreach ($headers as [$colKey, $label, $sortKey]) {
			$out .= $this->renderSortableHeader($colKey, $label, $sortKey, $sort, $dir, $filters, false);
		}
		foreach ($customCols as $name) {
			$out .= $this->renderSortableHeader('custom:' . $name, $name, 'custom:' . $name, $sort, $dir, $filters, true);
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

			$selKey = sprintf('%d:%s:%s',
				(int) $row['pageId'],
				(string) $row['fieldName'],
				(string) $row['basename']
			);

			$out .= '<tr>';

			$out .= '<td class="ml-cell-select">'
				. '<input type="checkbox" class="ml-select-row" data-key="'
				. $san->entities($selKey) . '"></td>';

			// Thumbnail cell becomes clickable when the host page is
			// editable — JS opens the PW page editor for just this
			// image field in a modal iframe so the user gets the
			// native crop / focus / variations UI. The file-hash
			// (md5 of basename, matching Pagefile::hash()) lets the
			// iframe filter find the matching gridImage via id
			// selector instead of string-matching URLs.
			$thumbAttrs = '';
			if (!empty($row['pageEditUrl'])) {
				$thumbAttrs = ' ' . $editAttrs
					. ' data-file-hash="' . md5((string) $row['basename']) . '"';
			}
			$out .= '<td class="ml-cell-thumb" data-col="thumb"' . $thumbAttrs . '>';
			if (!empty($row['thumbUrl'])) {
				$out .= '<img src="' . $san->entities($row['thumbUrl']) . '"'
					. ' alt="' . $san->entities($row['basename']) . '"'
					. ' loading="lazy"'
					. ' width="' . ((int) ($row['thumbWidth'] ?? $thumb['width'])) . '"'
					. ' height="' . ((int) ($row['thumbHeight'] ?? $thumb['height'])) . '">';
			}
			$out .= '</td>';

			$out .= '<td class="ml-cell-page" data-col="page">';
			if (!empty($row['pageEditUrl'])) {
				$out .= '<a href="' . $san->entities($row['pageEditUrl']) . '">'
					. $san->entities((string) $row['pageTitle']) . '</a>';
			} else {
				$out .= $san->entities((string) $row['pageTitle']);
			}
			$out .= '</td>';

			$out .= '<td data-col="field"><code>' . $san->entities((string) $row['fieldName']) . '</code></td>';
			$out .= '<td data-col="filename"><code>' . $san->entities((string) $row['basename']) . '</code></td>';
			$out .= '<td class="ml-cell-desc ml-cell-editable" data-col="description" ' . $editAttrs
				. ' data-subfield="description" data-input="textarea">'
				. $san->entities($desc) . '</td>';
			if ($showTagsCol) {
				$tagCfg = $tagsConfig[$row['fieldName']] ?? ['mode' => 0, 'allowed' => []];
				if ((int) $tagCfg['mode'] === 0) {
					// Image field has useTags=0 — tags column shows because
					// some OTHER image field in the union has tags on. This
					// row's cell can't be edited; render as N/A to match
					// the custom-field "not configured" treatment.
					$out .= '<td class="ml-cell-na" data-col="tags" title="'
						. $san->entities(sprintf(
							$this->_('tags is not configured on %s'),
							(string) $row['fieldName']
						)) . '">—</td>';
				} else {
					$tagAttrs = ' data-tags-mode="' . (int) $tagCfg['mode'] . '"';
					if ($tagCfg['mode'] === 2) {
						$tagAttrs .= " data-tags-allowed='" . $san->entities(
							json_encode(array_values($tagCfg['allowed']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
						) . "'";
					} elseif ($tagCfg['mode'] === 1) {
						$tagAttrs .= ' data-tags-list-id="ml-tags-used-'
							. $san->entities((string) $row['fieldName']) . '"';
					}
					$out .= '<td class="ml-cell-tags ml-cell-editable" data-col="tags" ' . $editAttrs
						. ' data-subfield="tags" data-input="text"' . $tagAttrs . '>'
						. $san->entities($tags) . '</td>';
				}
			}
			$out .= '<td class="ml-cell-nowrap" data-col="dimensions">' . $san->entities($dims) . '</td>';
			$out .= '<td class="ml-cell-nowrap" data-col="size">' . $san->entities($size) . '</td>';
			$out .= '<td class="ml-cell-nowrap ml-cell-variations" data-col="variations">'
				. (int) ($row['variationsCount'] ?? 0) . '</td>';

			$rowCustoms = $customByField[$row['fieldName']] ?? [];
			foreach ($customCols as $name) {
				$colAttr = ' data-col="custom:' . $san->entities($name) . '"';
				// When the customCols list is the union across image
				// fields (no field filter), some rows won't host every
				// listed subfield — render those as visually disabled
				// instead of editable so a click can't trigger an editor
				// for a field the server would reject anyway.
				if (!in_array($name, $rowCustoms, true)) {
					$out .= '<td class="ml-cell-na"' . $colAttr . ' title="'
						. $san->entities(sprintf(
							$this->_('%1$s is not configured on %2$s'),
							$name,
							(string) $row['fieldName']
						)) . '">—</td>';
					continue;
				}
				$val = $row['custom'][$name] ?? '';
				if (is_array($val)) $val = json_encode($val);
				$inputType = $customInputTypes[$name] ?? 'text';
				$out .= '<td class="ml-cell-editable"' . $colAttr . ' ' . $editAttrs
					. ' data-subfield="' . $san->entities($name) . '"'
					. ' data-input="' . $san->entities($inputType) . '">'
					. $san->entities((string) $val) . '</td>';
			}

			$out .= '</tr>';
		}

		$out .= '</tbody></table></div>';
		return $out;
	}

	/**
	 * Render one <th>. If $sortKey is null the header is plain text; otherwise
	 * it becomes a link that, when clicked, sets sort=$sortKey and toggles dir
	 * (asc → desc → asc) while preserving the current filters. Custom-column
	 * headers wrap the label in <code> like before.
	 */
	protected function renderSortableHeader(string $colKey, string $label, ?string $sortKey, string $currentSort, string $currentDir, array $filters, bool $codeLabel): string {
		$san = $this->wire('sanitizer');
		$colAttr = ' data-col="' . $san->entities($colKey) . '"';
		$labelHtml = $codeLabel
			? '<code>' . $san->entities($label) . '</code>'
			: $san->entities($label);

		if ($sortKey === null) {
			return '<th' . $colAttr . '>' . $labelHtml . '</th>';
		}

		$isActive = $currentSort === $sortKey;
		$nextDir  = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
		// Reset to page 1 — page numbers don't map across sort changes.
		$href     = $this->buildUrl($filters, 1, $sortKey, $nextDir);
		$arrow    = $isActive ? ($currentDir === 'asc' ? '▲' : '▼') : '';
		$cls      = 'ml-th-sortable' . ($isActive ? ' ml-th-sort-active' : '');

		$inner = $labelHtml;
		if ($arrow !== '') {
			$inner .= ' <span class="ml-sort-arrow">' . $arrow . '</span>';
		}

		return '<th class="' . $cls . '"' . $colAttr . '>'
			. '<a href="' . $san->entities($href) . '">' . $inner . '</a>'
			. '</th>';
	}

	protected function renderPagination(int $total, int $page, int $totalPages, array $filters, string $sort = '', string $dir = '', ?int $pageSize = null): string {
		$pageSize = $pageSize ?? $this->getDefaultPageSize();
		$san = $this->wire('sanitizer');

		$summary = sprintf(
			$this->_('Page %1$d of %2$d — %3$d image%4$s'),
			$page, $totalPages, $total, $total === 1 ? '' : 's'
		);

		$out  = '<div class="ml-pagination">';
		$out .= '<span class="ml-pagination-summary">' . $san->entities($summary) . '</span>';

		if ($totalPages > 1) {
			if ($page > 1) {
				$out .= '<a class="ml-pagination-link" href="' . $san->entities($this->buildUrl($filters, $page - 1, $sort, $dir, $pageSize)) . '">'
					. $san->entities($this->_('← Previous')) . '</a>';
			}
			if ($page < $totalPages) {
				$out .= '<a class="ml-pagination-link" href="' . $san->entities($this->buildUrl($filters, $page + 1, $sort, $dir, $pageSize)) . '">'
					. $san->entities($this->_('Next →')) . '</a>';
			}
		}

		// Per-page picker. Client-side JS intercepts the change event,
		// rewrites the URL and triggers the AJAX refresh; non-JS users
		// see the picker but it requires a manual reload to take effect.
		$out .= '<label class="ml-page-size">'
			. $san->entities($this->_('per page')) . ' '
			. '<select class="ml-page-size-picker">';
		foreach ($this->getPageSizeOptions() as $opt) {
			$sel = $opt === $pageSize ? ' selected' : '';
			$out .= '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
		}
		$out .= '</select></label>';

		$out .= '</div>';
		return $out;
	}

	protected function buildUrl(array $filters, int $page, string $sort = '', string $dir = '', ?int $pageSize = null): string {
		$pageSize = $pageSize ?? $this->getDefaultPageSize();
		$params = [
			'q'              => $filters['q'],
			'template'       => $filters['template'],
			'field'          => $filters['field'],
			'no_desc'        => $filters['no_desc'] ? '1' : '',
			'no_tags'        => $filters['no_tags'] ? '1' : '',
			'only_galleries' => $filters['only_galleries'] ? '1' : '',
			'p'              => $page > 1 ? (string) $page : '',
			'sort'           => ($sort !== '' && $sort !== self::DEFAULT_SORT) ? $sort : '',
			'dir'            => $dir === 'desc' ? 'desc' : '',
			'ps'             => $pageSize !== $this->getDefaultPageSize() ? (string) $pageSize : '',
		];
		foreach ($filters['no_custom'] ?? [] as $name => $on) {
			if ($on) $params['no_custom_' . $name] = '1';
		}
		if (!empty($filters['tags'])) {
			$params['tags'] = array_values($filters['tags']);
		}
		// Filter out empty scalars but keep non-empty arrays.
		$params = array_filter($params, fn($v) =>
			!(is_string($v) && $v === '') && $v !== null && !(is_array($v) && empty($v))
		);
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
