<?php namespace ProcessWire;

require_once __DIR__ . '/src/MediaLibraryDiscovery.php';
require_once __DIR__ . '/src/MediaLibraryMultilang.php';
require_once __DIR__ . '/src/MediaLibraryExportImport.php';

/**
 * Process Media Library
 *
 * Central table view of all images across all pages and image fields.
 * Editors can filter and inline-edit image metadata (description, tags,
 * custom subfields) without navigating per page.
 *
 * The module is sliced into small composable traits under src/ to keep
 * this file scannable:
 *   - MediaLibraryDiscovery    — image-field / template / tags-config /
 *     custom-subfield introspection (read-only).
 *   - MediaLibraryMultilang    — per-language read / write helpers and
 *     name⇄id mapping for export / import.
 *   - MediaLibraryExportImport — JSON + CSV emit, CSV parse, the
 *     idempotent re-apply path, and the Export / Import UI block at
 *     the bottom of the table.
 *
 * What stays in this file: the AJAX endpoints (executeSave, executeBulk,
 * executeData, executeUserPrefs), the table / filter / pagination
 * renders, the row-cache pipeline, install / uninstall, and the small
 * primitives (resolvePageimage, splitTags, blacklist parsers).
 *
 * See MediaLibrary-Konzept.md for the architecture overview.
 */
class ProcessMediaLibrary extends Process {

	use MediaLibraryDiscovery;
	use MediaLibraryMultilang;
	use MediaLibraryExportImport;

	const ADMIN_PAGE_NAME = 'media-library';
	const PERMISSION_NAME = 'media-library-access';
	const CACHE_PREFIX = 'media-library-';
	const PAGE_SIZE_DEFAULT = 50;
	const PAGE_SIZE_OPTIONS = [25, 50, 100, 200];
	// THUMB_LONGER_SIDE_DEFAULT is the keep-ratio display target —
	// the longer-axis cap for the proportionally-rendered thumb. At
	// 100 it sits well below PW's admin-variation size (260 on the
	// shorter axis, ≥260 on the longer), so the admin variation is
	// reused as the source byte-for-byte. Bump above 260 and the
	// module starts producing its own size($longer, 0) /
	// size(0, $longer) variations.
	// THUMB_WIDTH_DEFAULT / THUMB_HEIGHT_DEFAULT only kick in when
	// keep-ratio is off (crop mode); 120 × 80 mirrors the historic
	// table layout. Quality 90 matches $config->imageSizerOptions so
	// the admin's variation filenames hash identically.
	const THUMB_WIDTH_DEFAULT       = 120;
	const THUMB_HEIGHT_DEFAULT      = 80;
	const THUMB_LONGER_SIDE_DEFAULT = 100;
	const THUMB_QUALITY_DEFAULT     = 90;

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
		// Storage migrated from "thumbCrop" (true = crop) to
		// "thumbKeepRatio" (true = keep aspect, no crop). Both keys
		// are still read so installs that saved the old name keep
		// behaving as before until the admin re-saves the config.
		// Fresh installs (nothing saved either way) default to
		// keep-ratio on — that's the mode that lets the runtime
		// reuse PW's lazily-generated admin variations.
		$keepRatio = $this->get('thumbKeepRatio');
		if ($keepRatio === null) {
			$oldCrop = $this->get('thumbCrop');
			$keepRatio = $oldCrop === null ? true : !$oldCrop;
		} else {
			$keepRatio = (bool) $keepRatio;
		}
		return [
			'width'      => max(1, (int) ($this->get('thumbWidth')      ?: self::THUMB_WIDTH_DEFAULT)),
			'height'     => max(1, (int) ($this->get('thumbHeight')     ?: self::THUMB_HEIGHT_DEFAULT)),
			'longerSide' => max(1, (int) ($this->get('thumbLongerSide') ?: self::THUMB_LONGER_SIDE_DEFAULT)),
			'quality'    => max(1, min(100, (int) ($this->get('thumbQuality') ?: self::THUMB_QUALITY_DEFAULT))),
			'keepRatio'  => $keepRatio,
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
	 * default. The per-user prefs in $user->meta('mediaLibraryPrefs')
	 * override this on a column-by-column basis.
	 *
	 * @return array<int,string>
	 */
	/**
	 * Read the user's saved view preferences from their meta
	 * (per-user, cross-device). Normalized shape so JS + render
	 * code don't need to defend against partial / legacy payloads.
	 *
	 * pageSize is validated against the admin's PAGE_SIZE_OPTIONS;
	 * an unknown saved value falls through to null so the URL +
	 * admin-default path can decide.
	 *
	 * @return array{columns:array{visible:array<string,bool>,order:array<int,string>},pageSize:int|null}
	 */
	protected function getUserPrefs(): array {
		$raw = $this->wire('user')->meta('mediaLibraryPrefs');
		$visible = [];
		$order   = [];
		$pageSize = null;
		if (is_array($raw)) {
			$cols = isset($raw['columns']) && is_array($raw['columns']) ? $raw['columns'] : [];
			if (isset($cols['visible']) && is_array($cols['visible'])) {
				foreach ($cols['visible'] as $col => $vis) {
					$visible[(string) $col] = (bool) $vis;
				}
			}
			if (isset($cols['order']) && is_array($cols['order'])) {
				foreach ($cols['order'] as $col) {
					if (is_string($col) && $col !== '') $order[] = $col;
				}
			}
			if (isset($raw['pageSize'])) {
				$ps = (int) $raw['pageSize'];
				if ($ps > 0 && in_array($ps, $this->getPageSizeOptions(), true)) {
					$pageSize = $ps;
				}
			}
		}
		return [
			'columns'  => ['visible' => $visible, 'order' => $order],
			'pageSize' => $pageSize,
		];
	}

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

		// Cross-context cache invalidation: every Page save invalidates
		// our flat-row cache when the saved page carries at least one
		// managed image field. Without this, editing a description in
		// the native page-edit UI would leave the media-library table
		// showing stale values until the cache expired naturally.
		$this->addHookAfter('Pages::saved', $this, 'invalidateRowCacheOnPageSave');
	}

	/**
	 * Pages::saved listener. Drops the module's row-cache entries
	 * whenever a saved page hosts one of the managed image fields,
	 * so the table picks up changes made outside the module (e.g.
	 * in the native ProcessPageEdit UI). Discovery results are
	 * pulled per-call: cheap enough at save time, and avoids
	 * caching a stale field list across config edits.
	 */
	public function invalidateRowCacheOnPageSave(HookEvent $event): void {
		$page = $event->arguments(0);
		if (!$page instanceof Page || !$page->id) return;
		$imageFields = $this->discoverImageFields();
		if (!$imageFields) return;
		$fieldSet = array_flip($imageFields);
		foreach ($page->template->fieldgroup as $f) {
			if (isset($fieldSet[$f->name])) {
				$this->wire('cache')->deleteFor($this);
				return;
			}
		}
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
		$thumbDims = $this->getThumbDims();
		// CSS custom properties carry the configured thumb dims so the
		// stylesheet's --ml-thumb-w / --ml-thumb-h / --ml-thumb-longer
		// references reflect the user's settings without inline width
		// / height per image. The "longer" variable drives keep-ratio
		// display (proportional, capped to that side), the W / H pair
		// drives the crop variant (exact box with object-fit: cover).
		$rootStyle = sprintf(
			'--ml-thumb-w:%dpx;--ml-thumb-h:%dpx;--ml-thumb-longer:%dpx;',
			(int) $thumbDims['width'], (int) $thumbDims['height'],
			(int) $thumbDims['longerSide']
		);
		$rootAttrs = sprintf(
			' data-save-url="%s" data-render-url="%s" data-bulk-url="%s" data-csrf-name="%s" data-csrf-value="%s" style="%s"',
			$sanitizer->entities($this->wire('page')->url . 'save/'),
			$sanitizer->entities($this->wire('page')->url . 'data/'),
			$sanitizer->entities($this->wire('page')->url . 'bulk/'),
			$sanitizer->entities($session->CSRF->getTokenName()),
			$sanitizer->entities($session->CSRF->getTokenValue()),
			$sanitizer->entities($rootStyle)
		);

		$out  = '<div class="ml-root"' . $rootAttrs . '>';
		// Visually-hidden status region for JS to announce inline-edit
		// save outcomes to assistive tech ("Saved", "Save failed: …").
		// aria-live=polite ⇒ won't interrupt other speech.
		$out .= '<div class="ml-live-region" role="status" aria-live="polite" aria-atomic="true"></div>';
		// Module-settings link. Position-absolute via CSS so it sits
		// in the heading row instead of taking a row of its own.
		// collapse_info=1 asks PW to render the edit screen with the
		// upper info panel pre-collapsed so the actual config inputs
		// are above the fold.
		$cfgUrl = $this->wire('config')->urls->admin . 'module/edit/?name='
			. urlencode($this->className()) . '&collapse_info=1';
		$cfgTitle = $this->_('Module settings');
		$cfgLabel = $this->_('Config');
		$out .= '<a class="ml-config-link" href="' . $sanitizer->entities($cfgUrl) . '"'
			. ' title="' . $sanitizer->entities($cfgTitle) . '"'
			. ' aria-label="' . $sanitizer->entities($cfgTitle) . '">'
			. $sanitizer->entities($cfgLabel)
			. '</a>';
		$out .= $this->renderFilterBar($filters, $imageFields, $eligibleTemplates, $customCols, $sort, $dir, $tagFilterPool);
		$out .= '<div class="ml-results">' . $resultsHtml . '</div>';
		// Column picker lives in a sibling <dialog> so it survives
		// AJAX re-renders of .ml-results — the drag/toggle handlers
		// stay bound to the same DOM nodes for the life of the page.
		// Full $customCols set (not the field-narrowed one) so the
		// picker shows every column regardless of the active filter.
		$out .= $this->renderColumnsDialog($customCols);
		$out .= $this->renderExportImportBar($filters);
		$out .= '</div>';

		return $out;
	}

	/**
	 * The <ul> of column-toggle checkboxes consumed by the columns
	 * dialog (renderColumnsDialog) above the table. Checkboxes have
	 * NO name= attribute so they never enter any form's URL state;
	 * the JS visibility hook (.ml-col-toggle, $user->meta-backed)
	 * is the only path that reads them. Initial checked + sort order
	 * are server-rendered from $user->meta so the picker matches the
	 * table the user sees before JS even runs.
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

		// User-saved column prefs override the admin defaults. When
		// nothing's saved for a column, fall back to the admin's
		// "hidden by default" config so a fresh user lands on the
		// site-wide preset before they touch a checkbox.
		$pref = $this->getUserPrefs()['columns'];
		$visible = $pref['visible'];
		$order   = $pref['order'];
		$defaultHidden = array_flip($this->getDefaultHiddenColumns());

		// Re-order according to user pref: known cols in pref order
		// come first; any cols not in pref keep their built-in order
		// at the tail.
		if ($order) {
			$ordered = [];
			foreach ($order as $key) {
				if (isset($cols[$key])) {
					$ordered[$key] = $cols[$key];
				}
			}
			foreach ($cols as $key => $label) {
				if (!isset($ordered[$key])) $ordered[$key] = $label;
			}
			$cols = $ordered;
		}

		$upLabel   = $san->entities($this->_('Move up'));
		$downLabel = $san->entities($this->_('Move down'));
		$items = '';
		foreach ($cols as $key => $label) {
			$isVisible = array_key_exists($key, $visible)
				? $visible[$key]
				: !isset($defaultHidden[$key]);
			$checked = $isVisible ? ' checked' : '';
			$colKey = $san->entities($key);
			$items .= '<li class="ml-col-item" draggable="true">'
				. '<label><input type="checkbox" class="ml-col-toggle" data-col="'
				. $colKey . '"' . $checked . '> '
				. $san->entities($label) . '</label>'
				// Up / Down buttons for keyboard users — same effect as
				// drag-reorder. JS wires them; the icons stay decorative.
				. '<button type="button" class="ml-col-move ml-col-move-up"'
				. ' data-dir="up" aria-label="' . $upLabel . '" title="' . $upLabel . '">'
				. '<i class="fa fa-chevron-up" aria-hidden="true"></i></button>'
				. '<button type="button" class="ml-col-move ml-col-move-down"'
				. ' data-dir="down" aria-label="' . $downLabel . '" title="' . $downLabel . '">'
				. '<i class="fa fa-chevron-down" aria-hidden="true"></i></button>'
				. '</li>';
		}
		return '<ul class="ml-columns-list">' . $items . '</ul>';
	}

	/**
	 * Native <dialog> housing the column visibility + reorder picker.
	 * Sits outside .ml-results so AJAX refreshes don't destroy the
	 * already-wired drag/toggle handlers, and the dialog itself can
	 * stay open across a re-render. Opened from the "Columns…" link
	 * in the pagination row.
	 *
	 * @param array<int,string> $customCols
	 */
	protected function renderColumnsDialog(array $customCols): string {
		$san = $this->wire('sanitizer');
		$title = $san->entities($this->_('Columns'));
		$hint  = $san->entities($this->_('Toggle to show / hide the column. Drag or use the arrow buttons to reorder.'));
		$close = $san->entities($this->_('Close'));
		$out  = '<dialog class="ml-columns-dialog">';
		$out .= '<header>' . $title . '</header>';
		$out .= '<p class="ml-columns-hint">' . $hint . '</p>';
		$out .= $this->renderColumnsListMarkup($customCols);
		$out .= '<footer>';
		$out .= '<button type="button" class="ml-columns-close">' . $close . '</button>';
		$out .= '</footer>';
		$out .= '</dialog>';
		return $out;
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
		// Buffer everything — any stray notice or PW startup warning
		// would otherwise land before our json_encode in the response
		// body and break the client's JSON.parse.
		ob_start();

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

		$img = $this->resolvePageimage($page, $fieldName, $basename);
		if (!$img) return $this->jsonError('Image not found in field', 404);

		// useTags=2 (whitelist): reject any token that isn't in the configured
		// tagsList. Splits on whitespace + commas to match PW's own parsing.
		if ($subfield === 'tags') {
			$tagsCfg = $this->getTagsConfig()[$fieldName] ?? ['mode' => 0, 'allowed' => []];
			if ($tagsCfg['mode'] === 2) {
				$tokens = $this->splitTags($value);
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

		// Multilang popup ships a per-language map alongside the
		// primary value. When that's present, write each language
		// directly instead of letting writeLangValue() pick one.
		$langValuesJson = (string) $this->wire('input')->post('langValues');
		$langValues = null;
		if ($langValuesJson !== '') {
			$decoded = json_decode($langValuesJson, true);
			if (is_array($decoded)) $langValues = $decoded;
		}

		try {
			if ($langValues !== null) {
				$this->applyLangValues($img, $subfield, $langValues);
			} else {
				$this->writeLangValue($img, $subfield, $value);
			}
			$saved = $page->save($fieldName);
		} catch (\Throwable $e) {
			return $this->jsonError('Save error: ' . $e->getMessage());
		}
		if (!$saved) {
			return $this->jsonError('Save returned false — value may not have persisted');
		}
		// Belt + suspenders: ensure the cache is gone before any subsequent
		// re-fetch reads it. Matches the behavior in executeBulk.
		$this->wire('cache')->deleteFor($this);

		// Return the value PW actually stored — may differ from input after
		// sanitization (e.g. tags lowercased, whitespace normalized, etc.).
		// Multilang values get reduced to the current-user-language string
		// so the inline cell display matches what the editor sees.
		$stored = $this->normalizeDescription($img->get($subfield));

		$response = [
			'ok'    => true,
			'value' => (string) $stored,
		];
		// Multilang fields: also hand back every language's value so
		// the client can refresh the cell's data-lang-<id> attrs in
		// place. Without this the next popup-open reads stale
		// pre-save attrs and shows the old text in every tab.
		$langValues = $this->readLangValues($img, $subfield);
		if ($langValues !== null) {
			$response['langValues'] = $langValues;
		}
		return $this->jsonResponse($response);
	}

	/**
	 * File rename. POSTed from the filename cell editor (single-image
	 * path) and — once wired — from the batch rename path. The client
	 * sends just the new stem; the server preserves the original
	 * extension, resolves any placeholder tokens, sanitises and
	 * persists. Variation files are removed first because their
	 * names embed the OLD basename and would otherwise become orphan
	 * disk junk after the rename.
	 *
	 * Placeholder syntax (resolveRenamePattern):
	 *   (n)         counter (integer)
	 *   (n2)…(n5)   zero-padded counter, N digits
	 *   (slug)      page name (PW URL slug)
	 *   (field)     image field name
	 *
	 * For single-image rename n is always 1; batch will iterate.
	 * Unrecognised placeholders survive into the sanitiser, which
	 * usually strips the parens — so a literal "(foo)" lands as
	 * "foo" in the resulting filename.
	 *
	 * Response carries the resulting basename / stem / ext so the
	 * client can either replace the row in-place or re-render the
	 * results region.
	 */
	public function ___executeRename() {
		$config = $this->wire('config');
		$config->ajax = true;
		header('Content-Type: application/json');
		ob_start();

		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			return $this->jsonError('POST required', 405);
		}
		$session = $this->wire('session');
		if (!$session->CSRF->hasValidToken()) {
			return $this->jsonError('Invalid CSRF token', 403);
		}

		$input     = $this->wire('input');
		$sanitizer = $this->wire('sanitizer');

		$pageId      = (int) $input->post('pageId');
		$fieldName   = $sanitizer->fieldName((string) $input->post('fieldName'));
		$oldBasename = basename((string) $input->post('basename'));
		$newStemRaw  = (string) $input->post('value');

		if (!$pageId || !$fieldName || !$oldBasename) {
			return $this->jsonError('Missing required parameter');
		}
		if (!in_array($fieldName, $this->discoverImageFields(), true)) {
			return $this->jsonError('Field is not a managed image field');
		}

		$page = $this->wire('pages')->get($pageId);
		if (!$page->id) return $this->jsonError('Page not found', 404);
		if (!$page->editable()) return $this->jsonError('Page not editable', 403);

		$img = $this->resolvePageimage($page, $fieldName, $oldBasename);
		if (!$img) return $this->jsonError('Image not found in field', 404);

		// Resolve placeholders BEFORE the filename sanitiser runs —
		// the sanitiser strips parens and would otherwise mangle the
		// "(n)" / "(slug)" tokens before they could be expanded.
		$resolved = $this->resolveRenamePattern($newStemRaw, [
			'n'     => 1,
			'page'  => $page,
			'field' => $fieldName,
		]);

		// Sanitize the stem: PW's filename sanitizer normalizes case,
		// strips invalid chars, applies basename rules. We only want
		// the stem so any dot the user typed gets dropped — the
		// extension is preserved from the original file.
		$newStem = $sanitizer->filename($resolved, true);
		$newStem = preg_replace('/\.+/', '', $newStem) ?? '';
		if ($newStem === '') {
			return $this->jsonError('New filename is empty');
		}

		$dotPos = strrpos($oldBasename, '.');
		$ext    = $dotPos === false ? '' : substr($oldBasename, $dotPos);
		$newBasename = $newStem . $ext;

		if ($newBasename === $oldBasename) {
			return $this->jsonResponse([
				'ok'        => true,
				'basename'  => $oldBasename,
				'unchanged' => true,
			]);
		}

		// Collision check inside the same Pageimages collection. Single-
		// image fields can't collide with themselves; multi-image fields
		// need an explicit lookup.
		$fieldValue = $page->getUnformatted($fieldName);
		if ($fieldValue instanceof Pageimages) {
			$existing = $fieldValue->getFile($newBasename);
			if ($existing && $existing !== $img) {
				return $this->jsonError(
					'A file named "' . $newBasename . '" already exists in this field'
				);
			}
		}

		// Drop variations before the rename — the cached files (e.g.
		// basename.0x260.jpg) are keyed by the OLD basename and would
		// be unreachable after rename. PW regenerates them on demand.
		if (method_exists($img, 'removeVariations')) {
			$img->removeVariations();
		}

		if (!method_exists($img, 'rename')) {
			return $this->jsonError('Rename API not available on this ProcessWire version');
		}

		try {
			$renameResult = $img->rename($newBasename);
		} catch (\Throwable $e) {
			return $this->jsonError('Rename error: ' . $e->getMessage());
		}
		if ($renameResult === false) {
			return $this->jsonError('Rename failed');
		}

		$page->of(false);
		if (!$page->save($fieldName)) {
			return $this->jsonError('Save failed after rename');
		}

		$this->wire('cache')->deleteFor($this);

		// $img->basename reflects the new name in-place after rename();
		// echo that back so the client can rewrite cell attrs without
		// guessing what the sanitizer did to the stem.
		$finalBasename = (string) $img->basename;
		$finalDot      = strrpos($finalBasename, '.');
		return $this->jsonResponse([
			'ok'        => true,
			'basename'  => $finalBasename,
			'stem'      => $finalDot === false ? $finalBasename : substr($finalBasename, 0, $finalDot),
			'ext'       => $finalDot === false ? '' : substr($finalBasename, $finalDot),
			'unchanged' => false,
		]);
	}

	/**
	 * Expand the rename pattern into a concrete stem for a given
	 * image's context. Used by ___executeRename() and (future)
	 * batch-rename so a pattern like "(slug)-(n2)" produces e.g.
	 * "summer-festival-03" on the third row of a batch.
	 *
	 * Supported tokens:
	 *   (n)              counter — $ctx['n']
	 *   (n2) … (n5)      zero-padded counter, N digits
	 *   (slug)           page name (PW URL slug) — $ctx['page']->name
	 *   (field)          image field name — $ctx['field']
	 *
	 * Unknown tokens are passed through verbatim; the sanitiser
	 * downstream usually strips the parens. The helper itself does
	 * NOT sanitise — that's the caller's job, so future paths can
	 * apply additional rules (uniqueness, collision suffix, …)
	 * before the sanitiser runs.
	 *
	 * @param array{n?:int,page?:Page,field?:string} $ctx
	 */
	protected function resolveRenamePattern(string $pattern, array $ctx): string {
		$n     = (int) ($ctx['n']     ?? 0);
		$page  = $ctx['page']  ?? null;
		$field = (string) ($ctx['field'] ?? '');

		return preg_replace_callback(
			'/\((n[2-5]?|slug|field)\)/',
			function ($m) use ($n, $page, $field) {
				$key = $m[1];
				if ($key === 'n')     return (string) $n;
				if ($key === 'slug')  return ($page instanceof Page && $page->id) ? (string) $page->name : '';
				if ($key === 'field') return $field;
				// n2 … n5 — zero-padded counter
				$digits = (int) substr($key, 1);
				return str_pad((string) $n, $digits, '0', STR_PAD_LEFT);
			},
			$pattern
		) ?? $pattern;
	}

	/**
	 * Render an error response with an HTTP status code that JS callers can
	 * branch on. Returned from executeSave; safe to use in any JSON endpoint.
	 */
	protected function jsonError(string $msg, int $status = 400): string {
		http_response_code($status);
		$this->emitJson(['ok' => false, 'error' => $msg]);
		return ''; // unreachable; emitJson exits
	}

	/**
	 * Success-side mirror of jsonError. Both go through emitJson()
	 * so the response body is delivered the same way regardless of
	 * outcome.
	 */
	protected function jsonResponse(array $payload): string {
		$this->emitJson($payload);
		return ''; // unreachable; emitJson exits
	}

	/**
	 * Hard-exit the request with a clean JSON body. Drops every
	 * active output buffer (ours + anything PW stacked) so PHP
	 * notices, debug prints, or admin chrome can't end up between
	 * the Content-Type header and the JSON. exit() guarantees no
	 * later PW code re-adds output and reverses what we set up
	 * here.
	 */
	protected function emitJson(array $payload): void {
		while (ob_get_level() > 0) ob_end_clean();
		if (!headers_sent()) header('Content-Type: application/json');
		echo json_encode($payload);
		exit;
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
		ob_start();

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

		// Multilang batch: same {langId: value} JSON shape as the
		// single-row save endpoint. When present, the bulk loop
		// writes each language directly instead of going through the
		// add/replace heuristics on a single value.
		$langValuesJson = (string) $input->post('langValues');
		$langValues = null;
		if ($langValuesJson !== '') {
			$decoded = json_decode($langValuesJson, true);
			if (is_array($decoded)) $langValues = $decoded;
		}
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

				$img = $this->resolvePageimage($page, $fn, $bn);
				if (!$img) {
					$failed[] = sprintf('Image %s not found in %d.%s', $bn, $pid, $fn);
					continue;
				}

				$itemValue = $value;

				if ($subfield === 'tags') {
					$tokens = $this->splitTags($itemValue);
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

				if ($langValues !== null) {
					$this->applyLangValues($img, $subfield, $langValues);
				} else {
					$this->writeLangValue($img, $subfield, $itemValue);
				}
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

		return $this->jsonResponse([
			'ok'        => true,
			'succeeded' => $succeeded,
			'failed'    => $failed,
		]);
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
	/**
	 * Persist the user's view preferences (column visibility / order
	 * and chosen page size) to $user->meta('mediaLibraryPrefs'). JS
	 * debounces calls here whenever the user toggles a checkbox,
	 * drag-reorders a column, or picks a different page size, and
	 * always sends the full state so the saved record stays
	 * consistent. Cross-device by design — the same user on a
	 * different browser sees the same layout + page size on next
	 * load.
	 */
	public function ___executeUserPrefs() {
		$config = $this->wire('config');
		$config->ajax = true;
		ob_start();

		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			return $this->jsonError('POST required', 405);
		}
		$session = $this->wire('session');
		if (!$session->CSRF->hasValidToken()) {
			return $this->jsonError('Invalid CSRF token', 403);
		}

		$raw = (string) $this->wire('input')->post('prefs');
		$data = $raw !== '' ? json_decode($raw, true) : null;
		if (!is_array($data)) {
			return $this->jsonError('Invalid payload');
		}

		$clean = [
			'columns'  => ['visible' => [], 'order' => []],
			'pageSize' => null,
		];
		if (isset($data['columns']) && is_array($data['columns'])) {
			$cols = $data['columns'];
			if (isset($cols['visible']) && is_array($cols['visible'])) {
				foreach ($cols['visible'] as $col => $vis) {
					if (!is_string($col) || $col === '') continue;
					$clean['columns']['visible'][$col] = (bool) $vis;
				}
			}
			if (isset($cols['order']) && is_array($cols['order'])) {
				foreach ($cols['order'] as $col) {
					if (is_string($col) && $col !== '') $clean['columns']['order'][] = $col;
				}
			}
		}
		if (isset($data['pageSize'])) {
			$ps = (int) $data['pageSize'];
			if ($ps > 0 && in_array($ps, $this->getPageSizeOptions(), true)) {
				$clean['pageSize'] = $ps;
			}
		}

		$this->wire('user')->meta('mediaLibraryPrefs', $clean);
		return $this->jsonResponse(['ok' => true]);
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
		// Bump suffix when the cached row shape changes — older
		// entries stored multilang values as JSON-encoded strings;
		// the v2 path keeps them as raw {langId: value} arrays so
		// normalizeDescription() can pick the editor's language at
		// display time.
		return 'rows-v3-' . substr(md5((string) json_encode($keyData)), 0, 16);
	}

	/**
	 * Resolve a Page + field-name + basename triple into the underlying
	 * Pageimage, regardless of whether the field carries a Pageimages
	 * collection (maxFiles != 1) or a single Pageimage (maxFiles=1).
	 * Returns null when the field has no value or the basename doesn't
	 * exist in the collection — callers translate that into the
	 * appropriate "image not found" outcome (404 in AJAX endpoints,
	 * skip-row in render / export).
	 */
	/**
	 * Split a raw tag string into an ordered list of non-empty tokens.
	 * Matches PW's own tag parser semantics: whitespace and commas are
	 * the separators, runs of separators collapse, empty fragments
	 * are dropped. Used wherever the module reads a "tag-shaped"
	 * value out of a config field, a form post, a URL parameter or
	 * a whitelist string.
	 *
	 * @return array<int,string>
	 */
	protected function splitTags(string $raw): array {
		return preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
	}

	protected function resolvePageimage(Page $page, string $fieldName, string $basename): ?Pageimage {
		$value = $page->getUnformatted($fieldName);
		if ($value instanceof Pageimages) {
			$img = $value->getFile($basename);
		} elseif ($value instanceof Pageimage) {
			$img = $value;
		} else {
			return null;
		}
		return $img instanceof Pageimage ? $img : null;
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

		// Tags filter — AND-match against row tags. Accepts either the
		// comma-separated form (?tags=foo,bar) that buildUrl emits, or
		// the legacy PHP-array bracket form (?tags[]=foo&tags[]=bar)
		// from older bookmarks / direct form submissions.
		$rawTags = $input->get('tags');
		$tags = [];
		if (is_array($rawTags)) {
			foreach ($rawTags as $t) {
				$t = trim((string) $t);
				if ($t !== '') $tags[] = $t;
			}
		} elseif (is_string($rawTags) && $rawTags !== '') {
			foreach ($this->splitTags($rawTags) as $t) {
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
			'no_custom'      => $noCustom,
			'tags'           => $tags,
		];
	}

	/**
	 * Read and validate the sort/dir GET params. Invalid sort keys fall back
	 * to the admin-configured default (or the class constant if nothing's
	 * been saved); this prevents users from injecting arbitrary keys into
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
			$sort = $this->getDefaultSort();
			$dir  = $this->getDefaultSortDir();
		}
		if ($dir !== 'desc') $dir = 'asc';

		return ['sort' => $sort, 'dir' => $dir];
	}

	/**
	 * Admin-configured default sort column, validated against the
	 * SORTABLE_COLUMNS whitelist so a stale config entry can't smuggle
	 * an arbitrary key into the row-lookup later.
	 */
	protected function getDefaultSort(): string {
		$val = (string) $this->get('defaultSort');
		return array_key_exists($val, self::SORTABLE_COLUMNS) ? $val : self::DEFAULT_SORT;
	}

	/**
	 * Admin-configured default sort direction — only 'asc' / 'desc'
	 * are accepted; anything else maps to 'asc'.
	 */
	protected function getDefaultSortDir(): string {
		return ((string) $this->get('defaultSortDir') === 'desc') ? 'desc' : 'asc';
	}

	/**
	 * Resolve the effective page size for this request, in priority
	 * order: ?ps= URL param (whitelist-validated) → user's saved
	 * preference in $user->meta → admin-configured default. URL
	 * wins so explicit links stay bookmarkable; user pref kicks in
	 * for clean URLs so cross-device pagination matches the user's
	 * last choice.
	 */
	protected function readPageSize(): int {
		$opts = $this->getPageSizeOptions();
		$ps = (int) $this->wire('input')->get('ps');
		if (in_array($ps, $opts, true)) return $ps;
		$saved = $this->getUserPrefs()['pageSize'];
		if ($saved !== null) return $saved;
		return $this->getDefaultPageSize();
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
				$va = $this->normalizeDescription($a['custom'][$custom] ?? '');
				$vb = $this->normalizeDescription($b['custom'][$custom] ?? '');
			} else {
				// Multilang values (pageTitle, description) arrive as
				// {langId: value} arrays from findRaw — collapse to the
				// editor's current language so strcasecmp doesn't blow up.
				$va = $type === 'string'
					? $this->normalizeDescription($a[$sort] ?? '')
					: ($a[$sort] ?? '');
				$vb = $type === 'string'
					? $this->normalizeDescription($b[$sort] ?? '')
					: ($b[$sort] ?? '');
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
		$noCustom = $filters['no_custom'] ?? [];

		if (!$hasQ && $tplName === '' && $field === '' && !$noDesc && !$noTags && !$noCustom) {
			return $rows;
		}

		// Template filter operates at PHP level now (was SQL before caching),
		// so we resolve the name → id once and compare to row['templateId'].
		$tplId = 0;
		if ($tplName !== '') {
			$tpl = $this->wire('templates')->get($tplName);
			if ($tpl && $tpl->id) $tplId = (int) $tpl->id;
		}

		return array_values(array_filter($rows, function ($r) use (
			$hasQ, $q, $tplId, $field, $noDesc, $noTags, $noCustom
		) {
			if ($tplId && (int) $r['templateId'] !== $tplId) return false;
			if ($field !== '' && $r['fieldName'] !== $field) return false;

			$desc = $this->normalizeDescription($r['description']);
			$tags = (string) $r['tags'];

			if ($noDesc && trim($desc) !== '') return false;
			if ($noTags && trim($tags) !== '') return false;

			foreach ($noCustom as $name => $_) {
				$val = $r['custom'][$name] ?? '';
				if (trim($this->normalizeDescription($val)) !== '') return false;
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
					. $this->normalizeDescription($r['pageTitle'] ?? '') . ' '
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

			$img = $this->resolvePageimage($page, (string) $row['fieldName'], (string) $row['basename']);
			if (!$img) continue;

			// Non-rasterisable / animation-preserving formats: serve
			// the original instead of running it through ImageSizer.
			// SVG loses its vector nature on size(); GIF loses its
			// animation when re-encoded — and browsers render
			// animated GIFs in <img> tags natively, so the original
			// played at CSS-constrained size is exactly what the user
			// wants to see. CSS still keeps the cell compact via
			// max-width / object-fit.
			$ext = strtolower((string) $img->ext);
			$skipResize = $ext === 'svg' || $ext === 'gif';

			// Source-file decision: try to ride PW's lazily-generated
			// admin variation (260 px on the shorter axis — same call
			// signature InputfieldImage::getAdminThumb uses) whenever
			// the configured display target fits inside it. The admin
			// variation has shorter axis = 260 and longer axis ≥ 260,
			// so display targets ≤ 260 on every relevant axis are
			// safely covered. Above the threshold we generate a
			// dedicated variation so the requested pixels actually
			// exist on disk.
			$opts = ['upscaling' => false, 'quality' => $thumb['quality']];

			if ($skipResize) {
				$thumbImg = $img;
			} elseif ($thumb['keepRatio']) {
				// Keep-ratio mode — single "longer-side" cap from
				// the user's config drives display. ≤ 260 ⇒ admin
				// variation (the longer axis is always ≥ 260, so
				// it's never the binding constraint at this point).
				// > 260 ⇒ produce a matching size($longer, 0) /
				// size(0, $longer) variation so the longer-axis
				// display target is met without browser upscaling.
				$longer = (int) $thumb['longerSide'];
				if ($longer <= 260) {
					if ($img->width >= $img->height) {
						$thumbImg = ($img->height > 260) ? $img->size(0, 260, $opts) : $img;
					} else {
						$thumbImg = ($img->width > 260) ? $img->size(260, 0, $opts) : $img;
					}
				} else {
					if ($img->width >= $img->height) {
						$thumbImg = ($img->width > $longer) ? $img->size($longer, 0, $opts) : $img;
					} else {
						$thumbImg = ($img->height > $longer) ? $img->size(0, $longer, $opts) : $img;
					}
				}
			} else {
				// Crop mode — compare W × H against what the admin
				// variation can carry. Fits ⇒ reuse it and let CSS
				// object-fit: cover handle the visible crop. Doesn't
				// fit (e.g. 500 × 500 super-thumb) ⇒ produce a
				// dedicated cropping=true variation.
				$tW = (int) $thumb['width'];
				$tH = (int) $thumb['height'];
				if ($img->width >= $img->height) {
					$adminVar = ($img->height > 260) ? $img->size(0, 260, $opts) : $img;
				} else {
					$adminVar = ($img->width > 260) ? $img->size(260, 0, $opts) : $img;
				}
				if ($tW <= (int) $adminVar->width && $tH <= (int) $adminVar->height) {
					$thumbImg = $adminVar;
				} else {
					$thumbImg = $img->size($tW, $tH, $opts + ['cropping' => true]);
				}
			}
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
				$row['custom'][$customName] = $this->langValueToStorable($val);
			}
		}
		unset($row);

		return $slice;
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

			$img = $this->resolvePageimage($page, (string) $row['fieldName'], (string) $row['basename']);
			if (!$img) continue;

			foreach ($customNames as $name) {
				$val = $img->get($name);
				if ($val === null) continue;
				// Keep the raw shape — multilang LanguagesPageFieldValue
				// objects get flattened to a cacheable {langId: value}
				// array so the popup's tabs UI can read every language
				// straight off the row without a follow-up fetch.
				$row['custom'][$name] = $this->langValueToStorable($val);
			}
		}
		unset($row);

		return $rows;
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
			'renameUrl' => $this->wire('page')->url . 'rename/',
			// Used to build the page-edit URL for the thumbnail-click
			// modal — wraps PW's native image editor in an iframe.
			'adminUrl'  => $config->urls->admin,
			'tplFields' => $this->getTemplateFieldsMap($imageFields, $eligibleTemplates),
			'defaultPageSize'      => $this->getDefaultPageSize(),
			'defaultHiddenColumns' => $this->getDefaultHiddenColumns(),
			// Languages list for the popup's multilang tabs. Each
			// entry: { id: <storage key>, name: <api name>,
			// title: <display title> }. The "id" is the key PW uses
			// in the multilang value array — 0 for the default
			// language, the language page id otherwise.
			'languages'            => $this->buildLanguagesPayload(),
			// Editor's current admin language, expressed in the same
			// "0 = default lang, else page id" key scheme that
			// buildLanguagesPayload uses. JS pre-activates the
			// matching popup tab so multilang edits open straight on
			// the user's working language.
			'currentLangId'        => $this->getCurrentLangKey(),
			// Cross-device view prefs from $user->meta. JS reads this
			// for its initial in-memory state (columns + page size)
			// and POSTs the full state back to the user-prefs endpoint
			// on any change.
			'userPrefs'            => $this->getUserPrefs(),
			'userPrefsUrl'         => $this->wire('page')->url . 'user-prefs/',
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
				'rename'           => $this->_('New filename'),
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
				// Base labels for the two count-decorated filter
				// fieldsets; JS appends "(N)" client-side after each
				// Apply / Reset so the labels stay in sync with the
				// view without re-rendering the whole filter form.
				'filtersLabel'     => $this->_('Filters'),
				'tagsLabel'        => $this->_('Tags'),
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

		/** @var \ProcessWire\InputfieldForm $form */
		$form = $modules->get('InputfieldForm');
		$form->method = 'get';
		$form->action = './';
		// Keep .ml-filter-bar so the JS submit interceptor + reset clearer
		// + live reset-visibility find the form.
		$form->attr('class', trim($form->attr('class') . ' ml-filter-bar'));

		// Hidden sort/dir — at form level so they're never inside the
		// (collapsible) outer fieldset and always submit cleanly. Only
		// emitted when the current state differs from the configured
		// default so clean URLs stay clean for the no-override case.
		$defSort = $this->getDefaultSort();
		$defDir  = $this->getDefaultSortDir();
		if ($sort !== '' && $sort !== $defSort) {
			$h = $modules->get('InputfieldHidden');
			$h->name  = 'sort';
			$h->value = $sort;
			$form->add($h);
		}
		if ($dir !== '' && $dir !== $defDir) {
			$h = $modules->get('InputfieldHidden');
			$h->name  = 'dir';
			$h->value = $dir;
			$form->add($h);
		}

		// Outer "Filters" fieldset wraps everything. Always rendered
		// collapsed — the user opens it when they want to narrow, and
		// the JS submit handler re-collapses it after Apply so it
		// doesn't occlude results. The "(N)" suffix on the label
		// keeps the active-filter count visible while collapsed, so
		// the closed state isn't information-hiding. Column visibility
		// / order moved into the pagination-row "Columns…" dialog
		// (renderColumnsDialog), out of the filter form entirely.
		$activeCount = $this->countActiveFilters($filters);
		/** @var \ProcessWire\InputfieldFieldset $outer */
		$outer = $modules->get('InputfieldFieldset');
		$outer->name      = 'mlFilters';
		$outer->icon      = 'filter';
		$outer->label     = $activeCount > 0
			? sprintf($this->_('Filters (%d)'), $activeCount)
			: $this->_('Filters');
		$outer->collapsed = Inputfield::collapsedYes;

		// Row 1: Search + Template + Image field, 33/33/34.
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
		$fld->columnWidth = 34;
		$outer->add($fld);

		// Tags fieldset (full width, always open when present so the
		// available tag set is visible alongside the rest of the
		// filter UI).
		if ($tagFilterPool) {
			$selectedTags = $filters['tags'] ?? [];
			/** @var \ProcessWire\InputfieldFieldset $tagsFs */
			$tagsFs = $modules->get('InputfieldFieldset');
			$tagsFs->label = $selectedTags
				? sprintf($this->_('Tags (%d)'), count($selectedTags))
				: $this->_('Tags');
			$tagsFs->collapsed   = Inputfield::collapsedNo;
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

		// Missing-X checkboxes inline, each 25% wide — fixed
		// description/tags first, then one per custom field.
		$missingDef = [
			'no_desc' => $this->_('Missing description'),
			'no_tags' => $this->_('Missing tags'),
		];
		foreach ($customCols as $name) {
			$missingDef['no_custom_' . $name] = sprintf($this->_('Missing %s'), $name);
		}
		foreach ($missingDef as $name => $label) {
			$cb = $modules->get('InputfieldCheckbox');
			$cb->name        = $name;
			$cb->label       = $label;
			$cb->columnWidth = 25;
			if ($name === 'no_desc' && !empty($filters['no_desc'])) $cb->attr('checked', 'checked');
			if ($name === 'no_tags' && !empty($filters['no_tags'])) $cb->attr('checked', 'checked');
			if (strpos($name, 'no_custom_') === 0) {
				$key = substr($name, strlen('no_custom_'));
				if (!empty($filters['no_custom'][$key])) $cb->attr('checked', 'checked');
			}
			$outer->add($cb);
		}

		// Apply + Reset wrapped in their own sub-fieldset so they
		// always sit on a dedicated row (independent of how the
		// missing-X grid wrapped above). Flex-left via .ml-filter-bar
		// CSS pulls them to the row's left edge, with Apply first
		// (the primary action reads first in a left-aligned cluster).
		// Reset stays a real <a>; JS intercepts it for AJAX reset.
		/** @var \ProcessWire\InputfieldFieldset $actionsFs */
		$actionsFs = $modules->get('InputfieldFieldset');
		$actionsFs->name        = 'mlActions';
		$actionsFs->skipLabel   = Inputfield::skipLabelHeader;
		$actionsFs->columnWidth = 100;

		$apply = $modules->get('InputfieldSubmit');
		$apply->name  = 'apply';
		$apply->value = $this->_('Apply');
		$actionsFs->add($apply);

		$reset = $modules->get('InputfieldButton');
		$reset->name = 'reset';
		$reset->value = $this->_('Reset');
		$reset->attr('href', './');
		$reset->addClass('ml-reset');
		$actionsFs->add($reset);

		$outer->add($actionsFs);

		$form->add($outer);

		return $form->render();
	}

	protected function hasActiveFilter(array $filters): bool {
		return $this->countActiveFilters($filters) > 0;
	}

	/**
	 * Total count of active filter items — what the outer fieldset
	 * label displays as "(N)". Multi-value filters (no_custom, tags)
	 * contribute one per selection so the number matches the user's
	 * mental model of "how many things am I narrowing by"; scalar
	 * filters contribute one when populated.
	 */
	protected function countActiveFilters(array $filters): int {
		$count = 0;
		if ($filters['q'] !== '')        $count++;
		if ($filters['template'] !== '') $count++;
		if ($filters['field'] !== '')    $count++;
		if (!empty($filters['no_desc'])) $count++;
		if (!empty($filters['no_tags'])) $count++;
		if (!empty($filters['no_custom']) && is_array($filters['no_custom'])) {
			$count += count($filters['no_custom']);
		}
		if (!empty($filters['tags']) && is_array($filters['tags'])) {
			$count += count($filters['tags']);
		}
		return $count;
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
				'data-page-id="%d" data-field="%s" data-basename="%s" data-file-hash="%s"',
				(int) $row['pageId'],
				$san->entities((string) $row['fieldName']),
				$san->entities((string) $row['basename']),
				md5((string) $row['basename'])
			);
			// A11y: editable cells expose themselves as buttons so
			// keyboard users can Tab to them and Enter / Space to
			// open the inline editor (handled in JS). Per-cell labels
			// are added at the call sites since each subfield needs
			// its own descriptive name.
			$editA11y = ' role="button" tabindex="0"';

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
			// Thumb td only carries the hash + identity attrs when the
			// host page is editable — that's what gates the click-
			// through to the per-image editor iframe.
			// Thumb td picks up the edit attrs (and a button role) only
			// when the host page is editable — the click / keyboard
			// activator opens the per-image editor modal.
			if (!empty($row['pageEditUrl'])) {
				$thumbAria = sprintf(' aria-label="%s"', $san->entities(sprintf(
					$this->_('Open editor for %s'), (string) $row['basename']
				)));
				$thumbAttrs = ' ' . $editAttrs . $editA11y . $thumbAria;
			} else {
				$thumbAttrs = '';
			}
			$out .= '<td class="ml-cell-thumb" data-col="thumb"' . $thumbAttrs . '>';
			if (!empty($row['thumbUrl'])) {
				// Display dimensions are derived from the user's
				// configured target, NOT the source file. In keep-
				// ratio mode the longer axis is capped to the
				// configured longerSide; the other axis follows the
				// source's aspect. In crop mode the visible box is
				// exactly W × H and CSS object-fit: cover handles
				// any overflow from the admin-variation source.
				// Pre-computed here so the <img> width / height
				// attributes prevent layout shift before the bytes
				// land.
				$srcW = (int) ($row['thumbWidth']  ?? 0);
				$srcH = (int) ($row['thumbHeight'] ?? 0);
				if ($thumb['keepRatio']) {
					$longer = (int) $thumb['longerSide'];
					if ($srcW >= $srcH) {
						$dispW = $srcW > 0 ? min($longer, $srcW) : $longer;
						$dispH = $srcW > 0 ? (int) round($srcH * $dispW / $srcW) : $srcH;
					} else {
						$dispH = $srcH > 0 ? min($longer, $srcH) : $longer;
						$dispW = $srcH > 0 ? (int) round($srcW * $dispH / $srcH) : $srcW;
					}
					$cls = 'ml-thumb';
				} else {
					$dispW = (int) $thumb['width'];
					$dispH = (int) $thumb['height'];
					$cls   = 'ml-thumb ml-thumb-crop';
				}
				$out .= '<img class="' . $cls . '"'
					. ' src="' . $san->entities($row['thumbUrl']) . '"'
					. ' alt="' . $san->entities($row['basename']) . '"'
					. ' loading="lazy"'
					. ' width="' . $dispW . '"'
					. ' height="' . $dispH . '">';
			}
			$out .= '</td>';

			$pageTitle = $this->normalizeDescription($row['pageTitle']);
			$out .= '<td class="ml-cell-page" data-col="page">';
			if (!empty($row['pageEditUrl'])) {
				$out .= '<a href="' . $san->entities($row['pageEditUrl']) . '">'
					. $san->entities($pageTitle) . '</a>';
			} else {
				$out .= $san->entities($pageTitle);
			}
			$out .= '</td>';

			$out .= '<td data-col="field"><code>' . $san->entities((string) $row['fieldName']) . '</code></td>';
			// Filename cell — inline-editable, but only the stem; the
			// extension stays locked and rides along with the rename
			// on the server. Stem + ext are split server-side so the JS
			// editor doesn't have to re-parse the basename.
			$bn      = (string) $row['basename'];
			$dotPos  = strrpos($bn, '.');
			$stem    = $dotPos === false ? $bn : substr($bn, 0, $dotPos);
			$extPart = $dotPos === false ? '' : substr($bn, $dotPos); // includes the dot
			$renameAria = sprintf(' aria-label="%s"', $san->entities(sprintf(
				$this->_('Rename %s'), $bn
			)));
			$out .= '<td class="ml-cell-filename ml-cell-editable" data-col="filename" ' . $editAttrs . $editA11y . $renameAria
				. ' data-subfield="basename" data-input="filename"'
				. ' data-stem="' . $san->entities($stem) . '"'
				. ' data-ext="' . $san->entities($extPart) . '">'
				. '<code><span class="ml-fn-stem">' . $san->entities($stem) . '</span>'
				. '<span class="ml-fn-ext">' . $san->entities($extPart) . '</span></code></td>';
			$descAria = sprintf(' aria-label="%s"', $san->entities(sprintf(
				$this->_('Edit description of %s'), (string) $row['basename']
			)));
			$out .= '<td class="ml-cell-desc ml-cell-editable" data-col="description" ' . $editAttrs . $editA11y . $descAria
				. ' data-subfield="description" data-input="textarea"' . $this->buildLangAttrs($row['description']) . '>'
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
					$tagsAria = sprintf(' aria-label="%s"', $san->entities(sprintf(
						$this->_('Edit tags of %s'), (string) $row['basename']
					)));
					$out .= '<td class="ml-cell-tags ml-cell-editable" data-col="tags" ' . $editAttrs . $editA11y . $tagsAria
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
				$raw = $row['custom'][$name] ?? '';
				$val = $this->normalizeDescription($raw);
				$inputType = $customInputTypes[$name] ?? 'text';
				$customAria = sprintf(' aria-label="%s"', $san->entities(sprintf(
					$this->_('Edit %1$s of %2$s'), $name, (string) $row['basename']
				)));
				$out .= '<td class="ml-cell-editable"' . $colAttr . ' ' . $editAttrs . $editA11y . $customAria
					. ' data-subfield="' . $san->entities($name) . '"'
					. ' data-input="' . $san->entities($inputType) . '"'
					. $this->buildLangAttrs($raw) . '>'
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

		// A11y: aria-sort on the <th> tells assistive tech which
		// column is sorted and in which direction; aria-label on the
		// <a> announces what clicking will do (toggle to the OTHER
		// direction). Both update on every render so navigation
		// stays announced.
		$ariaSort = $isActive
			? ' aria-sort="' . ($currentDir === 'asc' ? 'ascending' : 'descending') . '"'
			: ' aria-sort="none"';
		$labelText = trim(strip_tags($labelHtml));
		$linkAria = sprintf(
			$nextDir === 'asc'
				? $this->_('Sort by %s, ascending')
				: $this->_('Sort by %s, descending'),
			$labelText
		);

		$inner = $labelHtml;
		if ($arrow !== '') {
			$inner .= ' <span class="ml-sort-arrow" aria-hidden="true">' . $arrow . '</span>';
		}

		return '<th class="' . $cls . '"' . $colAttr . $ariaSort . '>'
			. '<a href="' . $san->entities($href) . '" aria-label="'
			. $san->entities($linkAria) . '">' . $inner . '</a>'
			. '</th>';
	}

	protected function renderPagination(int $total, int $page, int $totalPages, array $filters, string $sort = '', string $dir = '', ?int $pageSize = null): string {
		$pageSize = $pageSize ?? $this->getDefaultPageSize();
		$san = $this->wire('sanitizer');

		$summary = sprintf(
			$this->_('Page %1$d of %2$d — %3$d image%4$s'),
			$page, $totalPages, $total, $total === 1 ? '' : 's'
		);

		// Two-zone flex layout: left = summary + prev/next, right =
		// per-page picker followed by the columns-dialog icon (with
		// a touch of breathing room between them). margin-left:auto
		// on the right group keeps it pinned to the far right.
		$out  = '<div class="ml-pagination">';

		$out .= '<div class="ml-pagination-left">';
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
		$out .= '</div>';

		$out .= '<div class="ml-pagination-right">';
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

		// Icon-only opener for the column-visibility dialog (rendered
		// as a sibling of .ml-results). <button> since there's no
		// fallback URL — without JS the picker is unavailable. The
		// visible <i> stays decorative; the button itself carries
		// the accessible name via aria-label / title.
		$colsLabel = $san->entities($this->_('Columns'));
		$out .= '<button type="button" class="ml-columns-toggle"'
			. ' title="' . $colsLabel . '"'
			. ' aria-label="' . $colsLabel . '">'
			. '<i class="fa fa-columns" aria-hidden="true"></i>'
			. '</button>';
		$out .= '</div>';

		$out .= '</div>';
		return $out;
	}

	protected function buildUrl(array $filters, int $page, string $sort = '', string $dir = '', ?int $pageSize = null): string {
		$pageSize = $pageSize ?? $this->getDefaultPageSize();
		// Omit sort / dir when they match the admin-configured defaults
		// so the URL stays clean for the no-override case. If the
		// configured default is "basename desc", that URL has no
		// ?sort= or ?dir= at all; explicit overrides remain visible.
		$defSort = $this->getDefaultSort();
		$defDir  = $this->getDefaultSortDir();
		$params = [
			'q'              => $filters['q'],
			'template'       => $filters['template'],
			'field'          => $filters['field'],
			'no_desc'        => $filters['no_desc'] ? '1' : '',
			'no_tags'        => $filters['no_tags'] ? '1' : '',
			'p'              => $page > 1 ? (string) $page : '',
			'sort'           => ($sort !== '' && $sort !== $defSort) ? $sort : '',
			'dir'            => ($dir !== '' && $dir !== $defDir) ? $dir : '',
			'ps'             => $pageSize !== $this->getDefaultPageSize() ? (string) $pageSize : '',
		];
		foreach ($filters['no_custom'] ?? [] as $name => $on) {
			if ($on) $params['no_custom_' . $name] = '1';
		}
		// Tags as a single comma-separated value (?tags=foo,bar) so
		// the URL stays readable. The PHP-array bracket form
		// (?tags[]=foo&tags[]=bar) is still accepted by readFilterInput
		// for older bookmarks, but new URLs we emit avoid the
		// %5B%5D-encoded brackets entirely.
		if (!empty($filters['tags'])) {
			$params['tags'] = implode(',', array_values($filters['tags']));
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
		return $this->normalizeBlacklist($this->get('blacklistedTemplates'));
	}

	/**
	 * Returns the image-field-name blacklist from module settings —
	 * lets the admin exclude a specific FieldtypeImage field regardless
	 * of which template hosts it.
	 *
	 * @return array<int,string>
	 */
	protected function getBlacklistedFields(): array {
		return $this->normalizeBlacklist($this->get('blacklistedFields'));
	}

	/**
	 * Shared parser for the two AsmSelect-backed blacklists. Accepts
	 * the modern array shape and the legacy comma/space string so a
	 * pre-AsmSelect upgrade path still resolves correctly.
	 *
	 * @return array<int,string>
	 */
	protected function normalizeBlacklist($raw): array {
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
