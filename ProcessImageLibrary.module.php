<?php namespace ProcessWire;

require_once __DIR__ . '/src/ImageLibraryDiscovery.php';
require_once __DIR__ . '/src/ImageLibraryMultilang.php';
require_once __DIR__ . '/src/ImageLibraryExportImport.php';
require_once __DIR__ . '/src/ImageLibraryHashing.php';
require_once __DIR__ . '/src/ImageLibraryUsage.php';

/**
 * Process Image Library
 *
 * Central table view of all images across all pages and image fields.
 * Editors can filter and inline-edit image metadata (description, tags,
 * custom subfields) without navigating per page.
 *
 * The module is sliced into small composable traits under src/ to keep
 * this file scannable:
 *   - ImageLibraryDiscovery    — image-field / template / tags-config /
 *     custom-subfield introspection (read-only).
 *   - ImageLibraryMultilang    — per-language read / write helpers and
 *     name⇄id mapping for export / import.
 *   - ImageLibraryExportImport — JSON + CSV emit, CSV parse, the
 *     idempotent re-apply path, and the Export / Import UI block at
 *     the bottom of the table.
 *
 * What stays in this file: the AJAX endpoints (executeSave, executeBulk,
 * executeData, executeUserPrefs), the table / filter / pagination
 * renders, the row-cache pipeline, install / uninstall, and the small
 * primitives (resolvePageimage, splitTags, blacklist parsers).
 *
 * See docs/ImageLibrary-Concept_EN.md for the architecture overview.
 */
class ProcessImageLibrary extends Process {

	/*
	 * Host contract for the composed traits.
	 *
	 * The five traits below are read-/write-side slices that lean on the host
	 * class (this one) for shared services. The contract is by convention, not
	 * a PHP interface — interfaces can only carry PUBLIC methods, but every one
	 * of these is `protected`, so it can't be expressed (or enforced) as one.
	 * Documented here so the dependency is explicit rather than only failing at
	 * runtime. The host (or an earlier trait) must provide:
	 *
	 *   Filtering / sorting  : readFilterInput(), readSortInput(),
	 *                          applyRowFilters(), applyTagFilter(), applySort(),
	 *                          fieldValueMatches()
	 *   Row loading / hydrate: loadRows(), loadImageRowsAll(),
	 *                          bulkHydrateCustomFields(), resolvePageimage()
	 *   Identity / blacklist : hashKey(), getBlacklistedFields(),
	 *                          getBlacklistedTemplates(), splitTags()
	 *   Usage / paging / URLs: usageRefForPage(), getDefaultPageSize(), buildUrl()
	 *   JSON responses       : jsonResponse(), jsonError()
	 *
	 * Plus the instance property $customByFieldCache (used by ImageLibraryDiscovery).
	 */
	use ImageLibraryDiscovery;
	use ImageLibraryMultilang;
	use ImageLibraryExportImport;
	use ImageLibraryHashing;
	use ImageLibraryUsage;

	// Picker mode: the view is embedded (modal iframe) to pick an existing
	// image to assign to a page's image field. Set from ?picker + target_*.
	protected bool $pickerMode = false;
	protected int $pickerTargetPage = 0;
	protected string $pickerTargetField = '';
	// When the editor is working in a page version (PagesVersions), the assign
	// must target that version (its own v<n>/ files folder), not the live page.
	protected int $pickerTargetVersion = 0;
	// Insert mode: the picker is opened from a rich-text (TinyMCE) editor to
	// embed a library image. No target field — it returns the image URL to the
	// opener instead of assigning to an InputfieldImage.
	protected bool $pickerInsertMode = false;

	const ADMIN_PAGE_NAME = 'image-library';
	const PERMISSION_NAME = 'image-library-access';
	// Gates create/edit/delete of the SHARED (team-wide) bookmarks and
	// collections. Everyone with PERMISSION_NAME sees and uses them; only
	// holders of this permission may change them.
	const PERMISSION_MANAGE_SHARED = 'image-library-manage-shared';
	const CACHE_PREFIX = 'image-library-';
	const PAGE_SIZE_DEFAULT = 50;
	const PAGE_SIZE_OPTIONS = [25, 50, 100, 200];
	// Initial page size when the library opens as a picker (first load only —
	// the user can still switch via the page-size picker).
	const PICKER_DEFAULT_PAGE_SIZE = 25;
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

	// Per-user thumbnail display scale (the in-view size slider). A
	// multiplier on the admin-configured thumb dimensions: 1.0 = the
	// config default, applied purely via the --ml-thumb-scale CSS var.
	// The generated variation stays the config size; scaling well above
	// it softens (admins can raise the config base for crisp large
	// thumbs). Persisted in $user->meta alongside columns / page size.
	const THUMB_SCALE_MIN     = 0.5;
	const THUMB_SCALE_MAX     = 2.5;
	const THUMB_SCALE_DEFAULT = 1.0;

	// Result layout. 'table' is the default data-grid; 'masonry' is a
	// natural-ratio thumbnail gallery; 'grid' is a uniform square-tile
	// gallery (both click a tile to open the per-image editor). Mirrors
	// ProcessWire's own grid / list file views. 'duplicates' groups
	// byte-identical (and, later, near-identical) copies into clusters from
	// the fingerprint store. Persisted per-user in $user->meta alongside the
	// other view prefs.
	const VIEW_TABLE      = 'table';
	const VIEW_MASONRY    = 'masonry';
	const VIEW_GRID       = 'grid';

	// Masonry base column width in px at zoom 1. The gallery is now laid
	// out client-side (layoutGallery distributes natural-ratio tiles into
	// equal flex columns), so this is only the square-ish fallback used
	// for an <img>'s width/height attrs when the source dimensions are
	// unknown — it no longer drives any server-side row-span packing.
	const MASONRY_COL = 220;

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
	 * Pick (and lazily generate) the thumbnail variation for a single
	 * Pageimage following the same rules used by the row hydrator:
	 *
	 *  - svg / gif → original (no resize)
	 *  - keep-ratio + longerSide ≤ 260 → PW admin variation (0×260 or 260×0)
	 *  - keep-ratio + longerSide  > 260 → dedicated longer-axis variation
	 *  - crop + box fits admin variation → reuse admin variation
	 *  - crop + box doesn't fit          → dedicated cropping=true variation
	 *
	 * Both hydrateSlice and ___executeReplace call this — the replace
	 * endpoint needs it to materialise the variation file on disk right
	 * after removeVariations() wiped the old one, so the cache-busted
	 * thumb URL the client gets back actually resolves.
	 *
	 * @param array $thumb Output of getThumbDims().
	 * @return array{url:string,width:int,height:int}
	 */
	protected function resolveThumbForImage(Pageimage $img, array $thumb): array {
		$ext = strtolower((string) $img->ext);
		$skipResize = $ext === 'svg' || $ext === 'gif';
		$opts = ['upscaling' => false, 'quality' => $thumb['quality']];

		if ($skipResize) {
			$thumbImg = $img;
		} elseif ($thumb['keepRatio']) {
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

		return [
			'url'    => (string) $thumbImg->url,
			'width'  => (int) $thumbImg->width,
			'height' => (int) $thumbImg->height,
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
	 * default. The per-user prefs in $user->meta('imageLibraryPrefs')
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
		// Read the current key, fall back to the pre-rename
		// "mediaLibraryPrefs" so an upgrade from the previous module
		// name picks up the saved column / page-size prefs without
		// the user noticing. First write through executeUserPrefs
		// migrates the value to the new key.
		$raw = $this->wire('user')->meta('imageLibraryPrefs');
		if ($raw === null || $raw === '') {
			$raw = $this->wire('user')->meta('mediaLibraryPrefs');
		}
		$visible = [];
		$order   = [];
		$pageSize = null;
		$bookmarks = [];
		$collections = [];
		$thumbScale = null;
		$viewMode = null;
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
			// Bookmarks are TEAM-wide now too (one store, getSharedPrefs), just
			// like collections — there's no personal-vs-shared split any more, so
			// we don't read personal bookmarks here. Any legacy personal ones are
			// folded into the team store once by migrateLegacyBookmarks().
			// Collections are TEAM-wide now (one store, getSharedPrefs); there is
			// no personal collections store any more, so we don't read them here.
			// Any legacy personal collections in $user->meta are simply ignored.
			if (isset($raw['thumbScale'])) {
				$ts = (float) $raw['thumbScale'];
				if ($ts >= self::THUMB_SCALE_MIN && $ts <= self::THUMB_SCALE_MAX) {
					$thumbScale = $ts;
				}
			}
			if (isset($raw['viewMode']) && in_array($raw['viewMode'], $this->viewModes(), true)) {
				$viewMode = (string) $raw['viewMode'];
			}
		}
		return [
			'columns'     => ['visible' => $visible, 'order' => $order],
			'pageSize'    => $pageSize,
			'bookmarks'   => $bookmarks,
			'collections' => $collections,
			'thumbScale'  => $thumbScale,
			'viewMode'    => $viewMode,
		];
	}

	/**
	 * May the current user create/edit/delete the SHARED (team-wide)
	 * bookmarks and collections? Superusers always; otherwise the
	 * PERMISSION_MANAGE_SHARED permission. Everyone with library access
	 * still SEES and USES the shared entries — this gates writes only.
	 */
	protected function canManageShared(): bool {
		$user = $this->wire('user');
		return $user->isSuperuser() || $user->hasPermission(self::PERMISSION_MANAGE_SHARED);
	}

	/**
	 * The team-wide shared store, read from module config. Same shape as
	 * the per-user prefs for bookmarks/collections, but visible to every
	 * user with library access and editable only by canManageShared().
	 *
	 * @return array{bookmarks:array<int,array{id:string,name:string,qs:string,parent:string}>,collections:array<int,array{id:string,name:string,keys:array<int,string>}>}
	 */
	protected function getSharedPrefs(): array {
		$cfg = $this->wire('modules')->getConfig($this);
		$bookmarks = [];
		$collections = [];
		if (is_array($cfg)) {
			if (isset($cfg['sharedBookmarks']) && is_array($cfg['sharedBookmarks'])) {
				foreach ($cfg['sharedBookmarks'] as $b) {
					$clean = $this->sanitizeBookmark($b);
					if ($clean !== null) $bookmarks[] = $clean;
				}
			}
			if (isset($cfg['sharedCollections']) && is_array($cfg['sharedCollections'])) {
				foreach ($cfg['sharedCollections'] as $c) {
					$clean = $this->sanitizeCollection($c);
					if ($clean !== null) $collections[] = $clean;
				}
			}
		}
		return ['bookmarks' => $bookmarks, 'collections' => $collections];
	}

	/**
	 * One-time fold of a user's legacy PERSONAL bookmarks into the team-wide
	 * shared store (bookmarks lost their personal/shared split). Runs on render,
	 * guarded by a per-user meta flag so it happens exactly once. De-duped by
	 * name+canonical-qs against what's already shared; each migrated bookmark
	 * gets a fresh id + empty parent. Afterwards the user's personal bookmarks
	 * are cleared so they don't linger or re-migrate.
	 */
	protected function migrateLegacyBookmarks(): void {
		$user = $this->wire('user');
		if ($user->meta('imageLibraryBmMigrated')) return;

		$raw = $user->meta('imageLibraryPrefs');
		if (!is_array($raw)) $raw = $user->meta('mediaLibraryPrefs');
		$personal = (is_array($raw) && isset($raw['bookmarks']) && is_array($raw['bookmarks']))
			? $raw['bookmarks'] : [];
		if (!$personal) { $user->meta('imageLibraryBmMigrated', 1); return; }

		$cfg = $this->wire('modules')->getConfig($this);
		if (!is_array($cfg)) $cfg = [];
		$shared = (isset($cfg['sharedBookmarks']) && is_array($cfg['sharedBookmarks']))
			? $cfg['sharedBookmarks'] : [];

		$seen = [];
		foreach ($shared as $b) {
			if (!is_array($b)) continue;
			$seen[((string) ($b['name'] ?? '')) . '|' . $this->canonicalizeBookmarkQs((string) ($b['qs'] ?? ''))] = true;
		}
		$added = false;
		foreach ($personal as $b) {
			$clean = $this->sanitizeBookmark($b);
			if ($clean === null) continue;
			$k = $clean['name'] . '|' . $clean['qs'];
			if (isset($seen[$k])) continue;
			$seen[$k] = true;
			$shared[] = $clean;
			$added = true;
		}
		if ($added) {
			$cfg['sharedBookmarks'] = $shared;
			$this->wire('modules')->saveConfig($this, $cfg);
		}
		if (is_array($raw)) { $raw['bookmarks'] = []; $user->meta('imageLibraryPrefs', $raw); }
		$user->meta('imageLibraryBmMigrated', 1);
	}

	/** The whitelist of persisted result-layout modes (table, masonry, grid).
	 *  Duplicates is NOT here — it's a transient filtered view (?view=
	 *  duplicates), entered from the bookmarks bar and left by picking any
	 *  other tab, never persisted as the user's default layout. */
	protected function viewModes(): array {
		return [self::VIEW_TABLE, self::VIEW_MASONRY, self::VIEW_GRID];
	}

	/**
	 * Effective result layout. An explicit ?view= request param wins and
	 * is persisted to the user's prefs (so the choice survives a clean
	 * reload on any device); otherwise the stored pref, else the table
	 * default. Reading the param here keeps the JS toggle race-free — it
	 * just navigates the AJAX endpoint with ?view=… and the server both
	 * honours and remembers it in one round trip.
	 */
	protected function getViewMode(): string {
		// The picker is a gallery for choosing an image — the table view (inline
		// editing, columns, sorting) has no place there. Force masonry.
		if ($this->pickerMode) return self::VIEW_MASONRY;
		$req = (string) $this->wire('input')->get('view');
		if (in_array($req, $this->viewModes(), true)) {
			$prefs = $this->getUserPrefs();
			if (($prefs['viewMode'] ?? null) !== $req) {
				$this->persistViewMode($req);
			}
			return $req;
		}
		$stored = $this->getUserPrefs()['viewMode'];
		return in_array($stored, $this->viewModes(), true) ? $stored : self::VIEW_TABLE;
	}

	/**
	 * Write just the view mode back into the merged prefs blob without
	 * disturbing the other keys. Mirrors the read/merge/write the
	 * executeUserPrefs endpoint does, but for the single in-request
	 * ?view= toggle.
	 */
	protected function persistViewMode(string $mode): void {
		if (!in_array($mode, $this->viewModes(), true)) return;
		$raw = $this->wire('user')->meta('imageLibraryPrefs');
		if (!is_array($raw)) $raw = [];
		$raw['viewMode'] = $mode;
		$this->wire('user')->meta('imageLibraryPrefs', $raw);
	}

	/**
	 * Effective per-user thumbnail display scale (the size-slider value),
	 * clamped to [THUMB_SCALE_MIN, THUMB_SCALE_MAX]; falls back to the
	 * default when the user has no saved value.
	 */
	protected function getThumbScale(): float {
		$ts = $this->getUserPrefs()['thumbScale'];
		return ($ts === null) ? self::THUMB_SCALE_DEFAULT : (float) $ts;
	}

	protected function getDefaultHiddenColumns(): array {
		$val = $this->get('defaultHiddenColumns');
		$cfg = is_array($val) ? array_filter(array_map('strval', $val)) : [];
		// 'usedIn' (the where-used column) is intrinsically hidden by default —
		// it's an opt-in audit column, and keeping it off costs nothing on every
		// render. The user's own toggle still wins once they enable it (saved
		// column visibility takes precedence over this default everywhere).
		$cfg[] = 'usedIn';
		return array_values(array_unique($cfg));
	}

	/**
	 * Is the (default-hidden) "Used in" column actually enabled for this user?
	 * Gates the per-render usage lookup so a table whose column is off pays
	 * nothing. User pref wins; otherwise it follows the hidden-by-default.
	 */
	protected function usedInColumnVisible(): bool {
		$visible = $this->getUserPrefs()['columns']['visible'] ?? [];
		if (array_key_exists('usedIn', $visible)) return (bool) $visible['usedIn'];
		return !in_array('usedIn', $this->getDefaultHiddenColumns(), true);
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
		// PW stores Pagefile created / modified as MySQL DATETIME
		// strings; ISO-shaped, so string sort = chronological sort.
		'created'     => 'string',
		'modified'    => 'string',
		// Integer count columns. Not present in the cached findRaw rows —
		// hydrated onto the full filtered set just-in-time before the sort
		// (see renderResultsHtml), the same way custom-subfield sorts are.
		'variationsCount' => 'int',
		'usageCount'      => 'int',
		// Collections column — text sort by the (comma-joined) collection names a
		// row belongs to, populated on the full set just-in-time like the counts.
		'collectionNames' => 'string',
	];

	const DEFAULT_SORT = 'pageTitle';
	const DEFAULT_DIR  = 'asc';

	/**
	 * Lazily-built cache of custom-fields-on-images discovery results,
	 * keyed by image-field name. Built once per request to avoid repeated
	 * template lookups across execute() and hydrateSlice().
	 * @var array<string,array<int,string>>|null
	 */
	protected $customByFieldCache = null;

	/**
	 * Lazily-built map of custom subfield name => editor type
	 * (text / textarea / checkbox / date / number / select / page).
	 * Built once per request; see getCustomTypes().
	 * @var array<string,string>|null
	 */
	protected $customTypesCache = null;

	/**
	 * Per-field cache of resolved Page-reference inline-select config
	 * (or null when the field's selectable set can't be a bounded
	 * inline select). Keyed by subfield name; see getPageRefConfig().
	 * @var array<string,array{multiple:bool,options:array}|null>
	 */
	protected $pageRefConfigCache = [];

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
		// the native page-edit UI would leave the image-library table
		// showing stale values until the cache expired naturally.
		$this->addHookAfter('Pages::saved', $this, 'invalidateRowCacheOnPageSave');

		// Automatic deduplication. On every save, fingerprint the saved
		// page's images and immediately collapse any byte-identical twins
		// to a shared inode (instant space reclaim when the twin already
		// exists). LazyCron is the safety net: it fingerprints copies whose
		// twin is uploaded later and links any group the save-time pass
		// missed. Both are no-ops once everything is hashed + linked.
		$this->addHookAfter('Pages::saved', $this, 'autoHashOnPageSave');

		// Where-used index maintenance. On every save, re-scan the saved
		// page's rich-text (textarea) fields and refresh its rows in the
		// usage index (prune-then-add) so the "where is this image embedded?"
		// lookup stays current. Cheap: one page, its textarea fields only.
		// The hook fires on whichever page hosts the field — top-level pages
		// AND repeater item pages — so embeds inside repeaters are covered.
		$this->addHookAfter('Pages::saved', $this, 'autoIndexUsageOnPageSave');

		$this->addHook('LazyCron::everyHour', $this, 'autoMaintenance');

		// Picker add-ons — OFF by default, toggled per-feature in the module
		// config (see ProcessImageLibraryConfig). When off we simply don't attach
		// the hooks, so the core library has zero add-on overhead (including the
		// only per-front-end-request hook).
		if ($this->get('addonPicker')) {
			// "Choose from library" button on every image field in the page editor
			// — opens the library as a picker to assign an existing image without
			// re-uploading.
			$this->addHookAfter('InputfieldImage::render', $this, 'addLibraryPickButton');
		}

		if ($this->get('addonRichtext')) {
			// "Insert from library" in rich-text fields. We can't add the
			// toolbar/plugin server-side reliably (TinyMCE's renderReady isn't
			// hookable and its settings are cached), so the render hook injects an
			// inline script that wires it up client-side. Both editors: TinyMCE
			// (current default) and CKEditor 4 (legacy, still on older sites).
			$this->addHookAfter('InputfieldTinyMCE::render', $this, 'addLibraryTinyMceButton');
			$this->addHookAfter('InputfieldCKEditor::render', $this, 'addLibraryCkEditorButton');

			// Front-end editing renders the editor client-side (PageFrontEdit →
			// InputfieldTinyMCE.init / CKEDITOR.inline on click), bypassing the
			// render hooks above. So inject the matching glue into the front-end
			// page output when it carries an editable rich-text region.
			$this->addHookAfter('Page::render', $this, 'injectRichTextGlueForFrontEdit');
		}
	}

	/**
	 * The "Insert from library" glue: an inline <script> that registers PW's
	 * InputfieldTinyMCE.onConfig() callback (polling until that API exists,
	 * before editors init) to add our external plugin + toolbar button + Insert-
	 * menu item. The plugin file itself is fetched by TinyMCE via
	 * external_plugins, so it needs no enqueue. Returned as a string so both the
	 * admin path (per-field render) and the front-end path (Page::render) can
	 * emit the SAME script — we deliberately avoid $config->scripts/$config->js
	 * because the front-end editor doesn't reliably echo them.
	 */
	protected function tinyMceGlueScript(): string {
		$libUrl = $this->libraryPageUrl();
		if ($libUrl === '') return '';

		$cfg = json_encode([
			'pickerUrl' => $libUrl . '?picker=1&modal=1&pick_mode=insert',
			'pluginUrl' => $this->assetUrl('assets/insert-mce.js'),
			'label'     => $this->_('Insert from library'),
		], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

		return $this->richtextCommonLoader()
			. "\n<script>(function(){var C=$cfg;"
			. "function reg(){var I=window.InputfieldTinyMCE;if(!I||typeof I.onConfig!=='function')return false;"
			. "window.ProcessWire=window.ProcessWire||{};ProcessWire.config=ProcessWire.config||{};"
			// MERGE, don't overwrite: the CKEditor glue puts iconUrl on this same
			// shared object, and on a page with BOTH editors a blind reassignment
			// here would wipe it (racey — the CKE button then renders icon-less).
			. "var T=ProcessWire.config.ImageLibraryInsert||{};T.pickerUrl=C.pickerUrl;T.pluginUrl=C.pluginUrl;T.label=C.label;ProcessWire.config.ImageLibraryInsert=T;"
			. "if(I._mlLibReg)return true;I._mlLibReg=true;"
			. "I.onConfig(function(s){s.external_plugins=s.external_plugins||{};s.external_plugins.mllibrary=C.pluginUrl;"
			// Place the button right AFTER the native image button (pwimage), to
			// match CKEditor; fall back to a plain 'image' button, then the end.
			. "if(typeof s.toolbar==='string'&&s.toolbar.indexOf('mllibrary')===-1){"
			. "if(s.toolbar.indexOf('pwimage')!==-1)s.toolbar=s.toolbar.replace('pwimage','pwimage mllibrary');"
			. "else if(/(^|\\s)image(\\s|$)/.test(s.toolbar))s.toolbar=s.toolbar.replace(/(^|\\s)image(\\s|$)/,'$1image mllibrary$2');"
			. "else s.toolbar=s.toolbar+' mllibrary';}"
			. "if(s.menu&&s.menu.insert&&typeof s.menu.insert.items==='string'&&s.menu.insert.items.indexOf('mllibrary')===-1){"
			. "var mi=s.menu.insert.items;s.menu.insert.items=mi.indexOf('pwimage')!==-1?mi.replace('pwimage','pwimage mllibrary'):mi+' mllibrary';}"
			. "});return true;}"
			. "if(!reg()){var n=0,iv=setInterval(function(){if(reg()||++n>200)clearInterval(iv);},25);}})();</script>";
	}

	/**
	 * The same idea as tinyMceGlueScript(), for CKEditor 4 (legacy editor still
	 * used by older sites). CKEditor has no PW onConfig hook, so instead of
	 * racing an init event we patch its config DATA directly (see the inline
	 * comment below): register the external plugin and add 'mllibrary' to
	 * extraPlugins + the "PWImageLibrary" button to the toolbar, next to the
	 * native PWImage button. The plugin file behaves like the TinyMCE one:
	 * opens the picker, inserts the image, hands off to PW's image dialog.
	 */
	protected function ckEditorGlueScript(): string {
		$libUrl = $this->libraryPageUrl();
		if ($libUrl === '') return '';

		$cfg = json_encode([
			'pickerUrl' => $libUrl . '?picker=1&modal=1&pick_mode=insert',
			'pluginUrl' => $this->assetUrl('assets/insert-cke.js'),
			'iconUrl'   => $this->assetUrl('assets/insert-icon.png'),
			'label'     => $this->_('Insert from library'),
		], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

		// CKEditor has no onConfig-style queue and stores each field's config in
		// TWO places (ProcessWire.config.InputfieldCKEditor_<field> AND a
		// data-configdata attribute), read at init time. Rather than race the
		// instanceCreated event, we patch that DATA directly (idempotently, a few
		// times to catch late output): add our plugin to the global plugins map
		// (InputfieldCKEditor.js addExternal-loads it), and add 'mllibrary' to
		// extraPlugins + the 'PWImageLibrary' button to the toolbar in every
		// per-field config + every data-configdata attribute. CKEditor reads the
		// patched config whenever it inits (admin on ready, front-end on click).
		return $this->richtextCommonLoader()
			. "\n<script>(function(){var C=$cfg;"
			// Display the @2x (32px) icon PNG at 16px so it's crisp on retina AND
			// normal screens. Injected once (guarded id); CKE sets the image via
			// btn.icon, this only pins the render size + centring.
			. "if(!document.getElementById('ml-cke-icon')){var st=document.createElement('style');st.id='ml-cke-icon';"
			. "st.textContent='.cke_button__pwimagelibrary_icon{background-size:16px 16px!important;background-position:center!important}';"
			. "(document.head||document.documentElement).appendChild(st);}"
			. "function patch(f){if(!f||typeof f!=='object')return;var ep=f.extraPlugins||'';"
			. "if((','+ep+',').indexOf(',mllibrary,')===-1)f.extraPlugins=ep?ep+',mllibrary':'mllibrary';"
			. "var tb=f.toolbar;if(Object.prototype.toString.call(tb)==='[object Array]'){var d=false;"
			. "for(var i=0;i<tb.length;i++){var g=tb[i];if(g&&g.splice){if(g.indexOf('PWImageLibrary')!==-1){d=true;break;}"
			. "var ix=g.indexOf('PWImage');if(ix!==-1){g.splice(ix+1,0,'PWImageLibrary');d=true;break;}}}if(!d)tb.push(['PWImageLibrary']);}}"
			. "function run(){var PW=window.ProcessWire;if(!PW||!PW.config)return;"
			. "if(!PW.config.ImageLibraryInsert)PW.config.ImageLibraryInsert={pickerUrl:C.pickerUrl,label:C.label};"
			. "if(!PW.config.ImageLibraryInsert.iconUrl)PW.config.ImageLibraryInsert.iconUrl=C.iconUrl;"
			. "var g=PW.config.InputfieldCKEditor;if(g){g.plugins=g.plugins||{};if(!g.plugins.mllibrary)g.plugins.mllibrary=C.pluginUrl;}"
			. "if(typeof CKEDITOR!=='undefined'){try{CKEDITOR.plugins.addExternal('mllibrary',C.pluginUrl,'');}catch(e){}}"
			. "for(var k in PW.config){if(k.indexOf('InputfieldCKEditor_')===0)patch(PW.config[k]);}"
			. "var els=document.querySelectorAll('[data-configdata]');for(var j=0;j<els.length;j++){"
			. "try{var o=JSON.parse(els[j].getAttribute('data-configdata'));patch(o);els[j].setAttribute('data-configdata',JSON.stringify(o));}catch(e){}}}"
			. "run();if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run);"
			. "var n=0,iv=setInterval(function(){run();if(++n>15)clearInterval(iv);},40);})();</script>";
	}

	/** Admin path: append the glue to each rich-text field's render output. */
	public function addLibraryTinyMceButton(HookEvent $event): void {
		$event->return .= $this->tinyMceGlueScript();
	}

	/** Admin path (CKEditor). */
	public function addLibraryCkEditorButton(HookEvent $event): void {
		$event->return .= $this->ckEditorGlueScript();
	}

	/**
	 * Front-end path: PW's PageFrontEdit creates the inline editor CLIENT-SIDE on
	 * click (InputfieldTinyMCE.init / CKEDITOR.inline), so our per-field render
	 * hooks never run there. Instead, when a front-end page carries an editable
	 * rich-text region, inject the matching glue before </body> — it registers
	 * the button before any editor inits. Handles both editors.
	 */
	public function injectRichTextGlueForFrontEdit(HookEvent $event): void {
		$out = $event->return;
		if (!is_string($out) || $out === '') return;
		$pos = stripos($out, '</body>');
		if ($pos === false) return;                                        // full-page output only
		$inject = '';
		if (strpos($out, 'pw-edit-InputfieldTinyMCE') !== false) $inject .= $this->tinyMceGlueScript();
		if (strpos($out, 'pw-edit-InputfieldCKEditor') !== false) $inject .= $this->ckEditorGlueScript();
		if ($inject === '') return;
		$event->return = substr($out, 0, $pos) . $inject . substr($out, $pos);
	}

	/**
	 * Append a "Choose from library" button to an InputfieldImage in the page
	 * editor. The button opens the library in picker mode (modal iframe) scoped
	 * to this page + field; the small assets/library-pick.js glue handles the
	 * modal and refreshes the field after an image is assigned.
	 */
	public function addLibraryPickButton(HookEvent $event): void {
		$inputfield = $event->object;
		$page  = $inputfield->hasPage;
		$field = $inputfield->hasField;
		if (!$page instanceof Page || !$page->id || !$field) return;
		if (!$page->editable()) return;

		$libUrl = $this->libraryPageUrl();
		if ($libUrl === '') return;

		$this->wire('config')->scripts->add($this->assetUrl('assets/library-pick.js'));

		$fname     = (string) $field->name;
		$pickerUrl = $libUrl . '?picker=1&modal=1&target_page=' . (int) $page->id
			. '&target_field=' . urlencode($fname);
		// If the page editor is working in a version, carry it through so the
		// assign lands in that version's files folder, not the live page.
		$version = $this->activePageVersion($page);
		if ($version > 0) $pickerUrl .= '&target_version=' . $version;
		$label = $this->_('Choose from library');

		// Built from PW's own InputfieldButton so it matches the native
		// "Choose File" button (size, theme). type=button + the JS handler's
		// preventDefault keep it from submitting the page-edit form.
		$btn = $this->wire('modules')->get('InputfieldButton');
		$btn->attr('value', $label);
		$btn->attr('type', 'button');
		$btn->icon = 'image';
		$btn->addClass('ml-lib-pick');
		$btn->attr('data-picker-url', $pickerUrl);
		$btn->attr('data-field', $fname);
		$btn->attr('data-title', $label);

		$event->return .= '<div class="ml-lib-pick-wrap" style="margin-top:.4rem">'
			. $btn->render() . '</div>';
	}

	/**
	 * Cache-busted URL for one of this module's own asset files: the public URL
	 * plus a ?v=<filemtime> query so browsers refetch on change without a version
	 * bump. Falls back to ?v=1 if the file can't be stat'd.
	 */
	protected function assetUrl(string $file): string {
		$config = $this->wire('config');
		$ver = @filemtime($config->paths($this) . $file) ?: '1';
		return $config->urls($this) . $file . '?v=' . $ver;
	}

	/**
	 * Inline loader that pulls in assets/insert-common.js once per page (guarded by a
	 * marker id), before any editor inits. Both rich-text glues emit it; the
	 * shared MLImageLibrary it defines is only used on user click, long after the
	 * async fetch resolves, so plain head-append loading is safe.
	 */
	protected function richtextCommonLoader(): string {
		$url = json_encode($this->assetUrl('assets/insert-common.js'),
			JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
		return "\n<script>(function(){if(document.getElementById('ml-richtext-common'))return;"
			. "var s=document.createElement('script');s.id='ml-richtext-common';s.src=$url;"
			. "(document.head||document.documentElement).appendChild(s);})();</script>";
	}

	/** URL of the module's own admin page (for building picker links). */
	protected function libraryPageUrl(): string {
		$p = $this->wire('pages')->get('template=admin, name=' . self::ADMIN_PAGE_NAME . ', include=all');
		return ($p && $p->id) ? $p->url : '';
	}

	/**
	 * Version number the given page is currently loaded as (PagesVersions), or 0
	 * for the live page / when the module isn't installed. Used to route a
	 * library pick into the active version instead of the live page.
	 */
	protected function activePageVersion(Page $page): int {
		$modules = $this->wire('modules');
		if (!$modules->isInstalled('PagesVersions')) return 0;
		try {
			return (int) $modules->get('PagesVersions')->pageVersionNumber($page);
		} catch (\Throwable $e) {
			return 0;
		}
	}

	/**
	 * Pages::saved listener: auto-fingerprint the saved page's images and
	 * link any byte-identical twins right away. Scoped to the one page, so
	 * it's cheap; skips entirely when the page hosts no managed image field.
	 */
	public function autoHashOnPageSave(HookEvent $event): void {
		$page = $event->arguments(0);
		if (!$page instanceof Page || !$page->id || !$page->template) return;
		$fields = $this->discoverImageFields();
		if (!$fields) return;
		$hosts = false;
		foreach ($fields as $fn) {
			if ($page->template->hasField($fn)) { $hosts = true; break; }
		}
		if (!$hosts) return;

		try {
			foreach ($this->hashPageImages((int) $page->id) as $hash) {
				$members = $this->loadClusterMembers($hash);
				if (count($members) >= 2) $this->reclaimMembers($members);
			}
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: auto-hash on save failed: ' . $e->getMessage());
		}
	}

	/**
	 * Refresh the where-used index for the saved page. Re-scans only this
	 * page's textarea fields and replaces its rows in the usage index
	 * (prune-then-add), so an edit that adds or removes an embedded library
	 * image is reflected immediately. Skips pages with no textarea field.
	 */
	public function autoIndexUsageOnPageSave(HookEvent $event): void {
		$page = $event->arguments(0);
		if (!$page instanceof Page || !$page->id || !$page->template) return;
		$textareaFields = $this->discoverTextareaFields();
		if (!$textareaFields) return;
		$hosts = false;
		foreach ($textareaFields as $fn) {
			if ($page->template->hasField($fn)) { $hosts = true; break; }
		}
		if (!$hosts) return;

		try {
			$this->reindexPageUsage((int) $page->id);
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: auto-index usage on save failed: ' . $e->getMessage());
		}
	}

	/**
	 * LazyCron hourly safety net: a budgeted fingerprint + reclaim pass over
	 * the whole site, catching copies whose twin arrived later and any group
	 * the on-save pass didn't cover. No-op once everything is hashed + linked.
	 */
	public function autoMaintenance(HookEvent $event): void {
		$this->runMaintenancePass(8);
	}

	// ------------------------------------------------------------------
	// Public hooks for the module config page (status + manual triggers).
	// ------------------------------------------------------------------

	/**
	 * Deduplication status for the config page: reclaimed disk space (raw +
	 * human-readable), how many copies are currently sharing an inode, and how
	 * many exact-duplicate clusters exist right now.
	 *
	 * @return array{reclaimedBytes:int,reclaimedHuman:string,linkedCount:int,clusterCount:int}
	 */
	public function dedupStats(): array {
		// Both figures are read from the FILESYSTEM (cached): the OS link-counts
		// are the single source of truth, so nothing can drift the way a separate
		// bookkeeping table did.
		$s = $this->cachedDiskStats();
		return [
			'reclaimedBytes' => $s['saved'],
			'reclaimedHuman' => $this->formatFilesize($s['saved']),
			'linkedCount'    => $s['shared'],
			'clusterCount'   => count($this->loadExactClusters()['clusters']),
		];
	}

	/**
	 * Image-level library figures, computed from the SAME live row set the
	 * table ("Show all") and the Duplicates view use — so these numbers can
	 * never disagree with what those views show. Distinct from dedupStats(),
	 * which reports DISK facts (every hardlinked file, incl. thumbnail
	 * variations and page-version copies — a much larger, lower-level count
	 * that must not be read as "duplicate images").
	 *
	 *   placements      every live image placement (each copy counted once)
	 *   images          distinct images after collapsing exact duplicates
	 *                   = the "Show all" total (placements − duplicateCopies)
	 *   duplicateSets   images that have ≥2 byte-identical live copies
	 *                   = the "Duplicates" view total
	 *   duplicateCopies redundant placements (Σ over sets of members−1)
	 *
	 * @return array{placements:int,images:int,duplicateSets:int,duplicateCopies:int}
	 */
	public function libraryStats(): array {
		$rows    = $this->loadRows();                 // live placements, no version items
		$keyHash = $this->loadDuplicateKeyHashes();   // identity => content_hash (dup members only)

		$placements = count($rows);
		$liveByHash = [];
		foreach ($rows as $r) {
			$h = $keyHash[$this->hashKey((int) $r['pageId'], (string) $r['fieldName'], (string) $r['basename'])] ?? null;
			if ($h !== null) $liveByHash[$h] = ($liveByHash[$h] ?? 0) + 1;
		}
		$duplicateSets = 0;
		$duplicateCopies = 0;
		foreach ($liveByHash as $n) {
			if ($n >= 2) { $duplicateSets++; $duplicateCopies += $n - 1; }
		}
		return [
			'placements'      => $placements,
			'images'          => $placements - $duplicateCopies,
			'duplicateSets'   => $duplicateSets,
			'duplicateCopies' => $duplicateCopies,
		];
	}

	/**
	 * Manual "revert" trigger: un-share every collapsed copy (give each its own
	 * independent file again) and clear the manifest — the reverse of reclaim.
	 * Time-budgeted; loops the chunked un-share until done or the budget runs
	 * out. Returns the total number of files expanded.
	 */
	public function revertAllNow(int $seconds = 20): int {
		@set_time_limit($seconds + 30);
		$stop = microtime(true) + max(1, $seconds);
		$expanded = 0;
		// Filesystem-level un-share (see expandAllSharedStep): authoritative — it
		// un-shares every inode the OS reports as shared, no bookkeeping needed.
		do {
			$r = $this->expandAllSharedStep();
			$expanded += (int) ($r['expanded'] ?? 0);
		} while (empty($r['complete']) && microtime(true) < $stop);
		$this->cachedDiskStats(true);   // re-measure so the Status isn't stale
		return $expanded;
	}

	/**
	 * AJAX endpoint: ONE budgeted fingerprint chunk, for the config page's live
	 * progress UI. POST + CSRF. Re-POST with the returned nextOffset until
	 * complete.
	 */
	public function ___executeScanStep() {
		$this->beginJsonPost();
		@set_time_limit(60);
		$offset = (int) $this->wire('input')->post('offset');
		if ($offset === 0) {
			// Fresh start: drop the cached row list so loadRows re-enumerates
			// without page-version items, then clean orphaned fingerprint /
			// manifest rows so the counts reflect the live set.
			$this->wire('cache')->deleteFor($this);
			$this->pruneOrphanedRows();
		}
		$r = $this->scanHashes($offset);
		$r['ok'] = true;
		return $this->jsonResponse($r);
	}

	/**
	 * AJAX endpoint: reclaim a small CHUNK of clusters with a per-cluster
	 * breakdown, for the config page's live progress UI. POST + CSRF. Re-POST
	 * with the returned nextOffset until complete.
	 */
	public function ___executeReclaimStep() {
		$this->beginJsonPost();
		@set_time_limit(60);
		$r = $this->reclaimStep((int) $this->wire('input')->post('offset'), 4);
		$r['ok']             = true;
		// While the run is in progress the manifest sum is a fine live indicator;
		// once complete, collapse the page-version files too, then report the REAL
		// saving measured from disk (and refresh the cache so the Status block
		// above is immediately correct too).
		if (!empty($r['complete'])) {
			$v = $this->reclaimVersionFiles();
			$r['versionResult'] = $v;
			if (!empty($v['linked'])) {
				$r['details'][] = [
					'label'      => $this->_('Page-version files'),
					'members'    => (int) $v['versionFiles'],
					'originals'  => 0,
					'variations' => (int) $v['linked'],
					'already'    => (int) $v['already'],
					'bytes'      => (int) $v['bytes'],
				];
			}
			$s = $this->cachedDiskStats(true);   // re-measure once, at the end
		} else {
			// Don't re-walk the tree every chunk; the per-cluster log + bar show
			// live progress and the final figure is measured on completion.
			$s = $this->cachedDiskStats(false);
		}
		$r['reclaimedBytes'] = $s['saved'];
		$r['reclaimedHuman'] = $this->formatFilesize($s['saved']);
		$r['linkedTotal']    = $s['shared'];
		return $this->jsonResponse($r);
	}

	/**
	 * AJAX endpoint: un-share a CHUNK of the manifest (the reverse of reclaim),
	 * for the config page's live UI. The old single-request revert was time-
	 * budgeted and silently stopped half-way on a large manifest, leaving the
	 * status wrong; driving it in chunks from the browser guarantees it runs to
	 * completion regardless of the host's max_execution_time. POST + CSRF;
	 * re-POST with the returned nextOffset until complete. On completion the
	 * cached disk-saved figure is refreshed so the Status block is correct.
	 */
	public function ___executeRevertStep() {
		$this->beginJsonPost();
		@set_time_limit(60);
		// Revert works at the FILESYSTEM level, not from the manifest: it un-shares
		// every file the OS reports as shared (link-count ≥ 2), so it also clears
		// hardlinks any stale bookkeeping would have missed — "un-share all" really
		// means all. Re-POST until complete; then re-measure the saving from disk.
		$r = $this->expandAllSharedStep();
		$r['ok'] = true;
		if (!empty($r['complete'])) {
			$r['reclaimedBytes'] = $this->cachedDiskStats(true)['saved'];   // re-measure once, at the end
		} else {
			// Don't re-walk the tree for a figure mid-run (the step already walked
			// it); the driver shows live counts and only needs the MB at the end.
			$r['reclaimedBytes'] = $this->cachedDiskStats(false)['saved'];
		}
		$r['reclaimedHuman'] = $this->formatFilesize($r['reclaimedBytes']);
		return $this->jsonResponse($r);
	}

	/**
	 * AJAX endpoint: ground-truth disk audit for the config page. Walks the real
	 * assets/files tree and reports what `du` would report (apparent size vs.
	 * actual inode-deduplicated size = space saved by hardlinks), plus a
	 * page-version breakdown. This is the measurement the user can't run on
	 * shared hosting (no shell): it proves, in the browser, whether the reclaimed
	 * number is the true on-disk saving and whether version files are still
	 * standalone copies. POST + CSRF, returns the audit numbers (raw + human).
	 */
	public function ___executeDiskAudit() {
		$this->beginJsonPost();
		@set_time_limit(120);
		$a = $this->diskAudit();
		$a['ok'] = true;
		// Refresh the cached figures so the Status block matches the audit.
		$this->wire('cache')->saveFor($this, 'ml_disk_stats',
			json_encode(['saved' => (int) $a['saved'], 'shared' => (int) $a['sharedFiles']]), 3600);
		$a['apparentHuman']        = $this->formatFilesize($a['apparent']);
		$a['actualHuman']          = $this->formatFilesize($a['actual']);
		$a['savedHuman']           = $this->formatFilesize($a['saved']);
		$a['versionStandaloneHuman'] = $this->formatFilesize($a['versionStandaloneBytes']);
		return $this->jsonResponse($a);
	}

	/**
	 * AJAX endpoint: assign an EXISTING library image to a target page's image
	 * field — "use this image here" — without the user re-uploading it. Native
	 * FieldtypeImage can only reference files in its own page's folder, so the
	 * only way is to copy the bytes in; we do that for the user and save just
	 * that one field (which is form-safe: a later page save won't drop it). The
	 * on-save auto-hash hook then hardlinks the fresh copy to the byte-identical
	 * original, so it costs ~no extra disk. POST + CSRF. Returns { ok, basename }.
	 */
	public function ___executeAssign() {
		$this->beginJsonPost();

		$input = $this->wire('input');
		$san   = $this->wire('sanitizer');
		$srcPid   = (int) $input->post('srcPageId');
		$srcField = $san->fieldName((string) $input->post('srcField'));
		$srcBase  = basename((string) $input->post('srcBasename'));
		$tgtPid   = (int) $input->post('targetPageId');
		$tgtField = $san->fieldName((string) $input->post('targetField'));
		if (!$srcPid || $srcField === '' || $srcBase === '' || !$tgtPid || $tgtField === '') {
			return $this->jsonError('Missing parameter');
		}

		// Target: an editable page whose template carries this image field.
		$tgtPage = $this->wire('pages')->get($tgtPid);
		if (!$tgtPage->id) return $this->jsonError('Target page not found', 404);
		if (!$tgtPage->editable()) return $this->jsonError('Target page not editable', 403);
		$field = $this->wire('fields')->get($tgtField);
		if (!$field || !($field->type instanceof FieldtypeImage)) {
			return $this->jsonError('Target field is not an image field');
		}
		if (!$tgtPage->template->hasField($tgtField)) {
			return $this->jsonError('Target page has no such field');
		}

		// Source image file (must exist on disk to copy).
		$srcPage = $this->wire('pages')->get($srcPid);
		if (!$srcPage->id) return $this->jsonError('Source page not found', 404);
		$srcImg = $this->resolvePageimage($srcPage, $srcField, $srcBase);
		if (!$srcImg) return $this->jsonError('Source image not found', 404);
		$srcPath = (string) $srcImg->filename;
		if ($srcPath === '' || !is_file($srcPath)) return $this->jsonError('Source file missing', 404);

		// Version context: when the editor is working in a page version, the
		// chosen image must land in THAT version (its own v<n>/ files folder),
		// not the live page. getPageVersion() returns the page carrying the
		// version property, so PagesVersions' path hook redirects its files to
		// v<n>/. A normal save() is blocked on a version page, so $persist()
		// writes the field's version record + flushes files explicitly instead.
		$version       = max(0, (int) $input->post('targetVersion'));
		$pagesVersions = null;
		if ($version > 0 && $this->wire('modules')->isInstalled('PagesVersions')) {
			$pagesVersions = $this->wire('modules')->get('PagesVersions');
			$verPage = $pagesVersions->getPageVersion($tgtPage, $version);
			if (!$verPage || !$verPage->id) {
				return $this->jsonError('Target version not found', 404);
			}
			$tgtPage = $verPage;
		}
		$persist = function () use ($pagesVersions, &$tgtPage, $tgtField, $field, $version) {
			if ($pagesVersions) {
				$tgtPage->filesManager->save();                       // flush new file into v<n>/
				$pagesVersions->savePageFieldVersion($tgtPage, $field, $version);
			} else {
				$tgtPage->save($tgtField);
			}
		};

		$tgtPage->of(false);
		$images   = $tgtPage->get($tgtField);
		$maxFiles = (int) $field->maxFiles;
		if ($maxFiles === 1) {
			$images->removeAll();                         // single-image field → replace
		} elseif ($maxFiles > 0 && count($images) >= $maxFiles) {
			return $this->jsonError(sprintf(
				$this->_('Field already holds its maximum of %d image(s)'), $maxFiles
			));
		}

		try {
			$images->add($srcPath);
			$persist();
		} catch (\Throwable $e) {
			return $this->jsonError('Add failed: ' . $e->getMessage());
		}

		// Carry the source metadata over: caption, tags, AND every custom
		// subfield the target field shares with the source (by name). Each is
		// copied language-aware (all languages on a multilang install). Best
		// effort — never fail the assign over this.
		$new = $images->last();
		if ($new) {
			try {
				$copy = ['description', 'tags'];
				$srcCustoms = $this->getCustomByField()[$srcField] ?? [];
				foreach (($this->getCustomByField()[$tgtField] ?? []) as $cName) {
					if (in_array($cName, $srcCustoms, true)) $copy[] = $cName;
				}
				$touched = false;
				foreach ($copy as $sf) {
					$langs = $this->readLangValues($srcImg, $sf);   // null on single-lang
					if ($langs !== null) {
						$this->applyLangValues($new, $sf, $langs);
						$touched = true;
					} else {
						$val = $srcImg->get($sf);
						if ($val !== null && $val !== '') {
							$new->set($sf, $val);
							$touched = true;
						}
					}
				}
				if ($touched) $persist();
			} catch (\Throwable $e) {
				$this->wire('log')->error('ImageLibrary: version-assign subfield copy failed: ' . $e->getMessage());
			}
		}

		// Immediate dedup. The live path gets this for free via the Pages::saved
		// auto-hash hook, but savePageFieldVersion() doesn't fire it — so for a
		// version assign, hardlink the fresh copy to its byte-identical source
		// right here (we literally just copied $srcPath into v<n>/, so they ARE
		// identical). Version files don't fit the (page,field,basename)-keyed
		// hash store, so this is a targeted link rather than a full rescan.
		// Best effort — never fail the assign over a dedup miss.
		if ($pagesVersions && $new) {
			try {
				$newPath = (string) $new->filename;
				if ($newPath !== '' && is_file($newPath)
					&& !$this->sameInode($srcPath, $newPath)
					&& $this->filesByteIdentical($srcPath, $newPath)) {
					$this->hardlinkReplace($srcPath, $newPath);
				}
			} catch (\Throwable $e) {
				$this->wire('log')->error('ImageLibrary: version dedup link failed: ' . $e->getMessage());
			}
		}

		$this->wire('cache')->deleteFor($this);
		return $this->jsonResponse([
			'ok'       => true,
			'basename' => $new ? (string) $new->basename : '',
		]);
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
	/**
	 * Detect picker mode (embedded image-chooser) from ?picker + target_page /
	 * target_field. Validates the target is an editable page carrying that
	 * image field. Runs on BOTH the full page render and the AJAX results
	 * endpoint, so checkboxes survive view switches / pagination in the picker.
	 */
	protected function detectPickerMode(): void {
		$in = $this->wire('input');
		if (!$in->get('picker')) return;
		// Insert mode (rich-text embed): no target field to validate — the picker
		// just hands the chosen image's URL back to the editor.
		if ((string) $in->get('pick_mode') === 'insert') {
			$this->pickerMode       = true;
			$this->pickerInsertMode = true;
			return;
		}
		$tp = (int) $in->get('target_page');
		$tf = $this->wire('sanitizer')->fieldName((string) $in->get('target_field'));
		$tpPage = $tp ? $this->wire('pages')->get($tp) : null;
		$tFld   = $tf !== '' ? $this->wire('fields')->get($tf) : null;
		if ($tpPage && $tpPage->id && $tpPage->editable()
			&& $tFld && $tFld->type instanceof FieldtypeImage
			&& $tpPage->template->hasField($tf)) {
			$this->pickerMode          = true;
			$this->pickerTargetPage    = $tp;
			$this->pickerTargetField   = $tf;
			$this->pickerTargetVersion = max(0, (int) $in->get('target_version'));
		}
	}

	public function ___execute() {
		if ($this->wire('input')->get('debug')) {
			return $this->renderDebug();
		}

		$this->loadAssets();

		$this->detectPickerMode();

		$imageFields = $this->discoverImageFields();
		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);
		if (!$imageFields || !$eligibleTemplates) {
			return $this->renderEmptyState($imageFields, $eligibleTemplates);
		}
		// Boot gate: the image-library-access permission is the hard
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
		// flat-row set. Field-specific visibility is handled by JS via
		// config.fieldCaps (applyFieldCapabilityFilter) — the picker DOM
		// is rendered unconditionally so JS can toggle .hidden as the
		// user changes the field filter, same shape as template→field.
		$tagFilterPool = $this->flatUsedTags($this->loadRows($filters));
		$resultsHtml  = $this->renderResultsHtml($filters, $sort, $dir, $requestedPg, $customCols);

		$sanitizer = $this->wire('sanitizer');
		$rootStyle = $this->buildRootStyleVars();
		$rootAttrs = $this->buildRootAttrs($rootStyle);

		$out  = '<div class="ml-root' . ($this->pickerMode ? ' ml-root--picker' : '') . '"' . $rootAttrs . '>';
		// Visually-hidden status region for JS to announce inline-edit
		// save outcomes to assistive tech ("Saved", "Save failed: …").
		// aria-live=polite ⇒ won't interrupt other speech.
		$out .= '<div class="ml-live-region" role="status" aria-live="polite" aria-atomic="true"></div>';

		if ($this->pickerMode) {
			// Picker chrome: just the filter bar + results. No config link /
			// export-import — this view exists only to pick an image to drop
			// into the target field.
		} else {
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
		}
		// Load PW's native tab module — same one Page Edit etc. use,
		// so the WireTabs uk-tab markup picks up the admin's tab
		// styling without us shipping new CSS.
		$this->wire('modules')->get('JqueryWireTabs');
		// jQuery UI (incl. the slider widget + its theme CSS) so the
		// thumbnail-size slider renders as a native PW jQuery-UI slider
		// — visually identical to InputfieldImage's own size slider —
		// instead of a browser-styled <input type=range>.
		$this->wire('modules')->get('JqueryUI');
		// Bookmarks stay in the picker too — saved filter sets are the fastest
		// way into a large library when you're hunting for one image to insert.
		$this->migrateLegacyBookmarks();   // one-time fold of personal bookmarks → team store
		$prefs = $this->getUserPrefs();
		$shared = $this->getSharedPrefs();
		$out .= $this->renderBookmarksBar($filters, $prefs['bookmarks'], $prefs['collections'], $shared['bookmarks'], $shared['collections']);
		$out .= $this->renderFilterBar($filters, $imageFields, $eligibleTemplates, $customCols, $sort, $dir, $tagFilterPool);
		$out .= $this->renderPickerBar('top');   // sticky — stays in the viewport
		$out .= '<div class="ml-results">' . $resultsHtml . '</div>';
		// Column picker lives in a sibling <dialog> so it survives
		// AJAX re-renders of .ml-results — the drag/toggle handlers
		// stay bound to the same DOM nodes for the life of the page.
		// Full $customCols set (not the field-narrowed one) so the
		// picker shows every column regardless of the active filter.
		// Table-only concern → skip it in the (masonry-only) picker.
		if (!$this->pickerMode) {
			$out .= $this->renderColumnsDialog($customCols);
		}
		// Collections manager dialog — sibling of .ml-results like the column
		// picker, so it survives AJAX swaps; the JS builds its tree on open.
		if (!$this->pickerMode) {
			$out .= $this->renderCollectionsDialog();
		}
		if (!$this->pickerMode) {
			$out .= $this->renderExportImportBar($filters);
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * Build the root element's CSS custom-property string (--ml-thumb-* dims +
	 * the per-user size-slider scale), so the initial paint reflects the saved
	 * thumbnail config without a flash. See the inline notes for each var.
	 */
	protected function buildRootStyleVars(): string {
		$thumbDims = $this->getThumbDims();
		// CSS custom properties carry the configured thumb dims so the
		// stylesheet's --ml-thumb-w / --ml-thumb-h / --ml-thumb-longer
		// references reflect the user's settings without inline width
		// / height per image. The "longer" variable drives keep-ratio
		// display (proportional, capped to that side), the W / H pair
		// drives the crop variant (exact box with object-fit: cover).
		// --ml-thumb-cell-width pins the THUMB column width to the
		// configured maximum so cells stay uniform regardless of
		// each image's actual orientation. Keep-ratio mode caps both
		// axes at longerSide (bounding square); crop mode is exactly
		// width × height. Without this pin, the table's auto layout
		// stretches the column to the widest single row.
		$cellWidth = $thumbDims['keepRatio']
			? (int) $thumbDims['longerSide']
			: (int) $thumbDims['width'];
		// --ml-thumb-scale is the per-user size-slider multiplier on top
		// of the configured dims; server-rendered here so the initial
		// paint already reflects the saved scale (no flash), then updated
		// live by the slider.
		$rootStyle = sprintf(
			'--ml-thumb-w:%dpx;--ml-thumb-h:%dpx;--ml-thumb-longer:%dpx;--ml-thumb-cell-width:%dpx;--ml-thumb-scale:%s;',
			(int) $thumbDims['width'], (int) $thumbDims['height'],
			(int) $thumbDims['longerSide'], $cellWidth,
			rtrim(rtrim(number_format($this->getThumbScale(), 2, '.', ''), '0'), '.')
		);
		return $rootStyle;
	}

	/**
	 * Build the .ml-root data-* attributes (endpoint URLs, CSRF token, picker
	 * target, and the style vars) the JS reads to bootstrap. $rootStyle comes
	 * from buildRootStyleVars().
	 */
	protected function buildRootAttrs(string $rootStyle): string {
		$session = $this->wire('session');
		$sanitizer = $this->wire('sanitizer');
		$pickerAttrs = '';
		if ($this->pickerMode) {
			$pickerAttrs = sprintf(
				' data-picker="1" data-target-page="%d" data-target-field="%s"',
				$this->pickerTargetPage, $sanitizer->entities($this->pickerTargetField)
			);
			if ($this->pickerTargetVersion > 0) {
				$pickerAttrs .= ' data-target-version="' . $this->pickerTargetVersion . '"';
			}
			if ($this->pickerInsertMode) $pickerAttrs .= ' data-pick-mode="insert"';
		}
		$rootAttrs = sprintf(
			' data-save-url="%s" data-render-url="%s" data-bulk-url="%s" data-assign-url="%s"'
			. ' data-cluster-url="%s" data-csrf-name="%s" data-csrf-value="%s"%s style="%s"',
			$sanitizer->entities($this->wire('page')->url . 'save/'),
			$sanitizer->entities($this->wire('page')->url . 'data/'),
			$sanitizer->entities($this->wire('page')->url . 'bulk/'),
			$sanitizer->entities($this->wire('page')->url . 'assign/'),
			$sanitizer->entities($this->wire('page')->url . 'cluster-table/'),
			$sanitizer->entities($session->CSRF->getTokenName()),
			$sanitizer->entities($session->CSRF->getTokenValue()),
			$pickerAttrs,
			$sanitizer->entities($rootStyle)
		);
		return $rootAttrs;
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
			'created'     => $this->_('Uploaded'),
			'modified'    => $this->_('Modified'),
			'variations'  => $this->_('Variations'),
			'usedIn'      => $this->_('Used in'),
			'collections' => $this->_('Collections'),
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
				. '<label><input type="checkbox" class="uk-checkbox ml-col-toggle" data-col="'
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
		$out .= '<button type="button" class="ml-columns-close uk-button uk-button-secondary">' . $close . '</button>';
		$out .= '</footer>';
		$out .= '</dialog>';
		return $out;
	}

	/**
	 * Collections manager dialog shell. The list (.ml-collections-list) is
	 * built client-side from the in-memory collections so it always reflects
	 * the current set; the JS wires drag-reorder + nesting and the arrow /
	 * indent buttons, then persists via the prefs endpoints. Same chrome as
	 * the column picker (shared dialog block).
	 */
	protected function renderCollectionsDialog(): string {
		$san = $this->wire('sanitizer');
		// Literal "&" — these static UI strings are NOT run through entities();
		// the output pipeline already encodes once, so pre-encoding here would
		// double it into "&amp;". Same convention as the usedInTitle label.
		$title = $this->_('Manage bookmarks & collections');
		$close = $san->entities($this->_('Close'));
		$tabColl = $san->entities($this->_('Collections'));
		$tabBm   = $san->entities($this->_('Bookmarks'));
		$newLabel = $san->entities($this->_('New'));
		$out  = '<dialog class="ml-collections-dialog">';
		$out .= '<header>' . $title . '</header>';
		// Tab bar: the two tabs (reusing the admin's own uk-tab markup, same as the
		// bookmarks bar) plus a single right-aligned "+ New" link. New creates a
		// collection or a folder depending on which tab is active (the JS reads the
		// active tab); the panes below just hold the lists.
		$out .= '<div class="ml-mgr-tabbar">';
		$out .= '<ul class="uk-tab ml-mgr-tabs">';
		$out .= '<li class="uk-active" data-pane="coll"><a href="#">' . $tabColl . '</a></li>';
		$out .= '<li data-pane="bm"><a href="#">' . $tabBm . '</a></li>';
		$out .= '</ul>';
		$out .= '<a href="#" role="button" class="ml-coll-new">'
			. '<i class="fa fa-plus" aria-hidden="true"></i> ' . $newLabel . '</a>';
		$out .= '</div>';
		$out .= '<div class="ml-mgr-pane" data-pane="coll">';
		$out .= '<ul class="ml-collections-list"></ul>';
		$out .= '</div>';
		$out .= '<div class="ml-mgr-pane" data-pane="bm" hidden>';
		$out .= '<ul class="ml-bookmarks-list"></ul>';
		$out .= '</div>';
		$out .= '<footer>';
		$out .= '<button type="button" class="ml-collections-close uk-button uk-button-secondary">' . $close . '</button>';
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

		// Sorting by a custom subfield needs the value on every row,
		// not just the visible slice — applySort reads $row['custom']
		// and otherwise compares empty strings, leaving the list in
		// pageId:basename tiebreaker order. loadRows() only hydrates
		// when filters (q / no_custom) require it; cover the sort
		// case here so the full row set carries the column.
		if (strncmp($sort, 'custom:', 7) === 0 && $this->hasAnyCustomFields()) {
			$rows = $this->bulkHydrateCustomFields($rows);
		}
		// Integer-count sorts (Used in / Variations): these values aren't in the
		// cached findRaw rows, so populate them on the FULL filtered set before
		// sorting — only when that sort is active, so normal browsing pays
		// nothing. usageCount is an in-memory index pass; variationsCount needs
		// a per-image filesystem scan, hence strictly opt-in via this sort.
		if ($sort === 'usageCount') {
			$counts = $this->usagePageCountsForRows($rows);
			foreach ($rows as &$r) {
				$key = $this->hashKey((int) $r['pageId'], (string) $r['fieldName'], (string) $r['basename']);
				$r['usageCount'] = $counts[$key] ?? 0;
			}
			unset($r);
		} elseif ($sort === 'variationsCount') {
			$this->hydrateVariationCounts($rows);
		} elseif ($sort === 'collectionNames') {
			$byKey = $this->collectionNamesByKey();
			foreach ($rows as &$r) {
				$key = $this->rowKey((int) $r['pageId'], (string) $r['fieldName'], (string) $r['basename']);
				$r['collectionNames'] = $byKey[$key] ?? '';
			}
			unset($r);
		}
		$this->applySort($rows, $sort, $dir);

		// CONTEXTUAL duplicate detection: an image counts as a duplicate only
		// when ≥2 of its byte-identical copies are present in THIS filtered
		// result set. A copy whose twins are filtered out (other field /
		// template / search) is not a duplicate here — no indicator, no
		// toggle, no collapse. $keyHash maps each row to its global content
		// hash (only images that are global duplicates appear); $ctxCount is
		// how many of each hash survived the current filters.
		$keyHash  = $this->loadDuplicateKeyHashes();
		$ctxCount = [];
		foreach ($rows as $r) {
			$h = $keyHash[$this->hashKey((int) $r['pageId'], (string) $r['fieldName'], (string) $r['basename'])] ?? null;
			if ($h !== null) $ctxCount[$h] = ($ctxCount[$h] ?? 0) + 1;
		}

		$pageSize = $this->readPageSize();
		// Picker: default to a small page on first load (no explicit, valid
		// ?ps in the request) so the modal opens fast. The page-size picker
		// still lets the user switch to a larger page.
		if ($this->pickerMode
			&& !in_array((int) $this->wire('input')->get('ps'), $this->getPageSizeOptions(), true)) {
			$pageSize = self::PICKER_DEFAULT_PAGE_SIZE;
		}

		if ($this->getViewMode() === self::VIEW_TABLE) {
			// Table view ALWAYS collapses (contextual) duplicates: every image
			// shows one row (the head, with the expand toggle); its other
			// copies become hidden rows revealed on click. Paginate by display
			// unit (unique images + cluster heads) so pageSize counts images
			// and a cluster never straddles a page break.
			$units      = $this->buildDisplayUnits($rows, $keyHash, $ctxCount);
			$total      = count($units);
			$totalPages = max(1, (int) ceil($total / $pageSize));
			$page       = min(max(1, $requestedPage), $totalPages);
			$offset     = ($page - 1) * $pageSize;
			$slice      = [];
			foreach (array_slice($units, $offset, $pageSize) as $unit) {
				foreach ($unit as $r) $slice[] = $r;
			}
		} else {
			// Tile views (masonry / grid) collapse (contextual) duplicates the
			// SAME way the table does: one tile per image (the cluster head), the
			// "N×" badge marking the copies — otherwise a duplicated image showed
			// once per copy (20 unique images rendered as 40 tiles). Paginate by
			// unit so the count matches the table.
			$units      = $this->buildDisplayUnits($rows, $keyHash, $ctxCount);
			$total      = count($units);
			$totalPages = max(1, (int) ceil($total / $pageSize));
			$page       = min(max(1, $requestedPage), $totalPages);
			$offset     = ($page - 1) * $pageSize;
			$slice      = [];
			foreach (array_slice($units, $offset, $pageSize) as $unit) {
				$slice[] = $unit[0];   // head row only; badge shows it's duplicated
			}
		}
		$slice = $this->hydrateSlice($slice);
		// Annotate each visible row with its contextual duplicate count / hash
		// (replaces the old global attachDuplicateCounts).
		foreach ($slice as &$row) {
			$h = $keyHash[$this->hashKey((int) $row['pageId'], (string) $row['fieldName'], (string) $row['basename'])] ?? null;
			$c = ($h !== null) ? ($ctxCount[$h] ?? 0) : 0;
			$row['dupCount'] = $c >= 2 ? $c : 0;
			$row['dupHash']  = $c >= 2 ? (string) $h : '';
		}
		unset($row);

		$pager = $this->renderPagination($total, $page, $totalPages, $filters, $sort, $dir, $pageSize);

		// Tile views (masonry / grid) are thumbnail-only galleries — no
		// inline-editable cells, so they skip the tag datalists (autocomplete
		// is an editor-only concern) and the per-column custom config the
		// table needs.
		if ($this->getViewMode() !== self::VIEW_TABLE) {
			return $pager
				. $this->renderTiles($slice)
				. $pager;
		}

		return $pager
			. $this->renderTable($slice, $customCols, $filters, $sort, $dir, $tagsConfig)
			. $this->renderTagDatalists($usedTags, $tagsConfig)
			. $pager;
	}

	/**
	 * The picker confirm bar (rendered above AND below the results in picker
	 * mode): a "Use selected" button that assigns every checkbox-selected
	 * image to the target field. JS keeps the count + enabled state in sync.
	 */
	protected function renderPickerBar(string $pos): string {
		if (!$this->pickerMode) return '';
		$san = $this->wire('sanitizer');
		return '<div class="ml-picker-bar ml-picker-bar--' . $san->entities($pos) . '">'
			. '<button type="button" class="ml-pick-confirm uk-button uk-button-primary" disabled>'
			. $san->entities($this->_('Use selected')) . ' <span class="ml-pick-count">(0)</span>'
			. '</button>'
			. '<button type="button" class="ml-pick-cancel uk-button uk-button-secondary">'
			. $san->entities($this->_('Cancel'))
			. '</button>'
			. '</div>';
	}

	/**
	 * The duplicate-count badge — the SAME ".ml-dup-count" used on the
	 * cluster thumbnails in the duplicates view ("N×"). Reused on the regular
	 * table / gallery thumbnails (positioned bottom-right there via CSS).
	 * A pure indicator: it marks an image as having twins. Managing the
	 * duplicate (apply-to-all / where-used) happens in the normal edit popup.
	 * Empty string for non-duplicates (count < 2).
	 */
	protected function renderDupBadge(int $count, string $hash = ''): string {
		if ($count < 2) return '';
		$san = $this->wire('sanitizer');
		$label = $san->entities(sprintf(
			$this->_('%d identical copies'), $count
		));
		return '<span class="ml-dup-count" title="' . $label . '" aria-label="' . $label . '">'
			. (int) $count . '</span>';
	}

	/**
	 * The duplicate indicator as an expand/collapse toggle, shown on the head
	 * row of every collapsed cluster in the table. Clicking it shows / hides
	 * the cluster's other copies (the hidden `tr.ml-dup-member` rows sharing
	 * this content hash). Same ".ml-dup-count" pill, plus button semantics +
	 * a caret. The "×" is dropped — just the count + caret.
	 */
	protected function renderDupToggle(int $count, string $hash): string {
		if ($count < 2 || $hash === '') return '';
		$san = $this->wire('sanitizer');
		$label = $san->entities(sprintf(
			$this->_('%d copies — click to show / hide'), $count
		));
		return '<span class="ml-dup-count ml-dup-toggle" data-dup-hash="' . $san->entities($hash) . '"'
			. ' role="button" tabindex="0" aria-expanded="false"'
			. ' title="' . $label . '" aria-label="' . $label . '">'
			. (int) $count . '</span>';
	}

	/**
	 * Collapse a sorted row set into display units for the table accordion:
	 * one unit per image. A unique image is a single-row unit; a duplicated
	 * image is a unit holding its head row (first occurrence, kept in sort
	 * position) followed by its other copies (pulled out of their own
	 * positions to sit beneath the head). Units stay in the head's sort order.
	 * Paginating by unit keeps each cluster intact on one page.
	 *
	 * Only CONTEXTUAL duplicates are grouped: a hash present fewer than twice
	 * in the current result set ($ctxCount) is treated as a unique single-row
	 * unit, so a copy whose twins were filtered out stays a plain row.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,string> $keyHash  row-key => content hash
	 * @param array<string,int>    $ctxCount content hash => count in this set
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	protected function buildDisplayUnits(array $rows, array $keyHash, array $ctxCount): array {
		$units  = [];
		$byHash = [];   // content hash => index of its unit in $units
		foreach ($rows as $r) {
			$h = $keyHash[$this->hashKey((int) $r['pageId'], (string) $r['fieldName'], (string) $r['basename'])] ?? null;
			if ($h === null || ($ctxCount[$h] ?? 0) < 2) {  // unique in context
				$units[] = [$r];
				continue;
			}
			if (!isset($byHash[$h])) {     // first copy → head of a new unit
				$units[] = [$r];
				$byHash[$h] = count($units) - 1;
			} else {                       // further copy → attach under head
				$units[$byHash[$h]][] = $r;
			}
		}
		return $units;
	}

	/**
	 * Thumbnail-tile gallery, shared by the masonry and grid views. The two
	 * differ only in the wrapper class (and therefore the CSS that lays the
	 * tiles out): `.ml-masonry` keeps each thumbnail at its natural aspect
	 * ratio and JS packs the flat tiles into equal columns; `.ml-grid` is a
	 * uniform square-tile CSS grid (no JS, thumbs cropped square). The tile
	 * markup is identical for both. Each editable tile carries the SAME
	 * identity attrs + .ml-cell-thumb element as a table row, so the existing
	 * JS handlers (thumb-click → image editor, replace, delete, drag-drop,
	 * bulk selection) work unchanged via their shared .ml-row / class-based
	 * delegation. Tiles for non-editable host pages are display-only.
	 */
	protected function renderTiles(array $slice): string {
		$san = $this->wire('sanitizer');

		if (!$slice) {
			return '<p class="ml-empty">'
				. $san->entities($this->_('No images match the current filters.')) . '</p>';
		}

		$wrapClass = $this->getViewMode() === self::VIEW_GRID ? 'ml-grid' : 'ml-masonry';
		$out = '<div class="' . $wrapClass . '">';
		foreach ($slice as $row) {
			$editable = !empty($row['pageEditUrl']);

			// Natural source dims drive the <img> width/height attrs below,
			// so the browser reserves the correct (uncropped) box before the
			// bytes land. Layout is done client-side by layoutGallery(),
			// which distributes these flat cards into equal columns — no
			// server-computed row span any more.
			$srcW = (int) ($row['thumbWidth']  ?? 0);
			$srcH = (int) ($row['thumbHeight'] ?? 0);

			$editAttrs = sprintf(
				'data-page-id="%d" data-field="%s" data-basename="%s" data-file-hash="%s" data-edit-base="%s"',
				(int) $row['pageId'],
				$san->entities((string) $row['fieldName']),
				$san->entities((string) $row['basename']),
				md5((string) $row['basename']),
				$san->entities((string) ($row['pageEditBase'] ?? ''))
			);

			// Tile identity attrs mirror the table <tr> so replace /
			// delete / drag-drop resolve the same target.
			$rowAttrs = '';
			if ($editable) {
				$rowAttrs = sprintf(
					' data-page-id="%d" data-field="%s" data-basename="%s"'
					. ' data-page-title="%s" data-page-name="%s"',
					(int) $row['pageId'],
					$san->entities((string) $row['fieldName']),
					$san->entities((string) $row['basename']),
					$san->entities((string) ($row['pageTitle'] ?? '')),
					$san->entities((string) ($row['pageName']  ?? ''))
				);
			}
			// Insert mode (rich-text embed): carry the original image URL + its
			// description (used as alt) so the picker JS can hand them back to the
			// editor. Resolved via the Pageimage so extended/secure file paths
			// are honoured rather than hand-built.
			if ($this->pickerInsertMode) {
				$pi = $this->resolvePageimage(
					$this->wire('pages')->get((int) $row['pageId']),
					(string) $row['fieldName'],
					(string) $row['basename']
				);
				$insertUrl = $pi ? (string) $pi->url : '';
				if ($insertUrl !== '') {
					$rowAttrs .= ' data-insert-url="' . $san->entities($insertUrl) . '"'
						. ' data-insert-alt="' . $san->entities((string) ($row['description'] ?? '')) . '"';
				}
			}
			$out .= '<div class="ml-row ml-card"' . $rowAttrs . '>';

			// Selection checkbox per tile — the same .ml-select-row the table
			// uses, so the shared selection logic applies in every view. Always
			// rendered; in the picker it's always visible, in the normal masonry
			// view it hover-reveals like the replace/delete buttons and stays
			// visible once checked (CSS).
			$selKey = $this->rowKey(
				(int) $row['pageId'], (string) $row['fieldName'], (string) $row['basename']
			);
			$out .= '<label class="ml-card-select">'
				. '<input type="checkbox" class="uk-checkbox ml-select-row" data-key="'
				. $san->entities($selKey) . '"></label>';

			// A duplicated image is a single representative tile: NO inline editor
			// / replace / delete on it. Clicking opens a modal with the table view
			// of this image's copies (where each copy is edited individually).
			// In the picker, duplicates are just images to pick once — no badge,
			// no cluster modal, no replace/delete. Treat every tile as a plain,
			// selectable tile there.
			$isDup    = !$this->pickerMode && ((int) ($row['dupCount'] ?? 0)) >= 2;
			$thumbCls = 'ml-cell-thumb';
			if ($isDup) {
				$thumbCls .= ' ml-dup-cluster';
				$clusterAria = sprintf(' aria-label="%s"', $san->entities(sprintf(
					$this->_('Show the %d copies of %s'), (int) $row['dupCount'], (string) $row['basename']
				)));
				$thumbAttrs = sprintf(
					' data-cluster-pid="%d" data-cluster-field="%s" data-cluster-base="%s" role="button" tabindex="0"',
					(int) $row['pageId'],
					$san->entities((string) $row['fieldName']),
					$san->entities((string) $row['basename'])
				) . $clusterAria;
			} elseif ($editable) {
				$thumbAria = sprintf(' aria-label="%s"', $san->entities(sprintf(
					$this->_('Open editor for %s'), (string) $row['basename']
				)));
				$thumbAttrs = ' ' . $editAttrs . ' role="button" tabindex="0"' . $thumbAria;
			} else {
				$thumbAttrs = '';
			}
			$out .= '<div class="' . $thumbCls . '"' . $thumbAttrs . '>';
			if (!empty($row['thumbUrl'])) {
				// Natural-ratio width/height attrs reserve the right box
				// before the bytes land (CSS sizes the tile to the grid
				// cell; object-fit: cover absorbs the small rounding).
				$attrW = $srcW > 0 ? $srcW : self::MASONRY_COL;
				$attrH = $srcH > 0 ? $srcH : self::MASONRY_COL;
				$out .= '<img class="ml-thumb"'
					. ' src="' . $san->entities($row['thumbUrl']) . '"'
					. ' alt="' . $san->entities($row['basename']) . '"'
					. ' loading="lazy"'
					. ' width="' . $attrW . '"'
					. ' height="' . $attrH . '">';
			}
			// Replace / delete only on non-duplicate tiles, and never in the
			// picker (you don't manage source images while inserting one).
			if ($editable && !$isDup && !$this->pickerMode) {
				$replaceLabel = $san->entities(sprintf($this->_('Replace %s'), (string) $row['basename']));
				$deleteLabel  = $san->entities(sprintf($this->_('Delete %s'),  (string) $row['basename']));
				$out .= '<button type="button" class="ml-replace-btn"'
					. ' title="' . $replaceLabel . '" aria-label="' . $replaceLabel . '">'
					. '<i class="fa fa-upload" aria-hidden="true"></i></button>';
				$out .= '<button type="button" class="ml-delete-btn"'
					. ' title="' . $deleteLabel . '" aria-label="' . $deleteLabel . '">'
					. '<i class="fa fa-trash-o" aria-hidden="true"></i></button>';
			}
			if (!$this->pickerMode) {
				$out .= $this->renderDupBadge((int) ($row['dupCount'] ?? 0), (string) ($row['dupHash'] ?? ''));
			}
			$out .= $this->renderDownloadButton($row);
			$out .= '</div>'; // .ml-cell-thumb
			$out .= '</div>'; // .ml-card
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * The view-mode toggle (table / masonry / duplicates) as standalone HTML
	 * so it can sit both in the pagination row and atop the duplicates view
	 * (which renders no pager). Anchors carry a ?view= URL — bookmark- and
	 * reload-safe, degrades without JS; the JS intercepts and AJAX-swaps.
	 */
	protected function renderViewToggle(array $filters, int $page, string $sort, string $dir, int $pageSize): string {
		$san = $this->wire('sanitizer');
		$currentView = $this->getViewMode();
		$viewBase = $this->buildUrl($filters, $page, $sort, $dir, $pageSize);
		$viewSep  = (strpos($viewBase, '?') !== false) ? '&' : '?';
		if ($viewBase === './') { $viewBase = ''; $viewSep = '?'; }
		// grid (square tiles) / masonry (natural ratio) / table (data grid).
		// All three icons are painted from CSS masks bundling the official
		// FontAwesome 6 glyphs (.ml-vicon-* in our stylesheet), NOT the core
		// font — so they render identically on every core (stable cores ship
		// FA4.7, the dev core FA6) instead of mixing FA4.7 and FA6 styles.
		$views = [
			[self::VIEW_GRID,    'ml-vicon ml-vicon-grid',    $this->_('Grid view')],
			[self::VIEW_MASONRY, 'ml-vicon ml-vicon-masonry', $this->_('Masonry view')],
			[self::VIEW_TABLE,   'ml-vicon ml-vicon-table',   $this->_('Table view')],
		];
		$out = '<span class="ml-view-toggle" role="group" aria-label="'
			. $san->entities($this->_('Result layout')) . '">';
		foreach ($views as [$mode, $icon, $label]) {
			$active = ($mode === $currentView);
			$lbl = $san->entities($label);
			$out .= '<a class="ml-view-btn' . ($active ? ' ml-view-active' : '') . '"'
				. ' href="' . $san->entities($viewBase . $viewSep . 'view=' . $mode) . '"'
				. ' data-view="' . $mode . '"'
				. ' title="' . $lbl . '" aria-label="' . $lbl . '"'
				. ($active ? ' aria-current="true"' : '') . '>'
				. '<i class="' . $icon . '" aria-hidden="true"></i></a>';
		}
		$out .= '</span>';
		return $out;
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
			$tags = $this->splitImageTags((string) ($row['tags'] ?? ''));
			foreach ($tags as $t) $set[$t] = true;
		}
		$keys = array_keys($set);
		sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
		return $keys;
	}

	protected function collectUsedTagsByField(array $rows): array {
		$byField = [];
		foreach ($rows as $row) {
			$tags = $this->splitImageTags((string) ($row['tags'] ?? ''));
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
	 * Render one <datalist> per image field that needs tag autocomplete: the
	 * free-form (mode 1) and predefined-plus-own (mode 3) fields. The editor's
	 * text input references it via list="ml-tags-used-<field>". Suggestions are
	 * the tags already used in the library, plus — for mode-3 fields — the
	 * field's predefined tags (so they're offered even before first use).
	 *
	 * @param array<string,array<int,string>> $usedTags
	 * @param array<string,array{mode:int,allowed:array<int,string>}> $tagsConfig
	 */
	protected function renderTagDatalists(array $usedTags, array $tagsConfig = []): string {
		$san = $this->wire('sanitizer');
		$byField = [];   // field => set of suggestion strings
		foreach ($usedTags as $field => $tags) {
			foreach ($tags as $t) $byField[$field][$t] = true;
		}
		// Mode-3 fields suggest their predefined tags too, even if unused yet.
		foreach ($tagsConfig as $field => $cfg) {
			if (($cfg['mode'] ?? 0) === 3) {
				foreach ($cfg['allowed'] as $t) $byField[$field][$t] = true;
			}
		}
		if (!$byField) return '';

		$out = '';
		foreach ($byField as $field => $set) {
			$tags = array_keys($set);
			usort($tags, 'strcasecmp');   // alphabetical, case-insensitive
			$out .= '<datalist id="ml-tags-used-' . $san->entities($field) . '">';
			foreach ($tags as $t) {
				$out .= '<option value="' . $san->entities((string) $t) . '">';
			}
			$out .= '</datalist>';
		}
		return $out;
	}

	/**
	 * Render a verbose dump of every pipeline intermediate for diagnostics.
	 *
	 * Hit /processwire/setup/image-library/?debug=1.
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

		// Page-reference resolution diagnostics — why a page-ref custom
		// subfield renders inline (select) vs falls back to the native
		// editor. Shows the field config + the resolved option count.
		$pageRefDiag = [];
		foreach ($this->getCustomTypes() as $cname => $ctype) {
			if ($ctype !== 'page') continue;
			$cf  = $this->wire('fields')->get($cname);
			$cfg = $this->getPageRefConfig($cname);
			$selPa = ($cf instanceof Field) ? $this->resolvePageRefPages($cf) : null;
			$pageRefDiag[$cname] = [
				'fieldtype'         => ($cf instanceof Field) ? (string) $cf->type : null,
				'inputfield'        => ($cf instanceof Field) ? (string) $cf->get('inputfield') : null,
				'derefAsPage'       => ($cf instanceof Field) ? (int) $cf->get('derefAsPage') : null,
				'parent_id'         => ($cf instanceof Field) ? (int) $cf->get('parent_id') : null,
				'template_id'       => ($cf instanceof Field) ? (int) $cf->get('template_id') : null,
				'template_ids'      => ($cf instanceof Field) ? $cf->get('template_ids') : null,
				'findPagesSelector' => ($cf instanceof Field) ? (string) $cf->get('findPagesSelector') : null,
				'findPagesCode'     => ($cf instanceof Field) ? ((string) $cf->get('findPagesCode') !== '' ? '(set)' : '') : null,
				'configSelector'    => ($cf instanceof Field) ? $this->pageRefSelector($cf) : '',
				'resolvedPages'     => $selPa instanceof PageArray ? $selPa->count() : 'null',
				'inlineCap'         => self::PAGEREF_INLINE_CAP,
				'decision'          => $cfg
					? ('inline select, ' . count($cfg['options']) . ' options' . ($cfg['multiple'] ? ', multiple' : ', single'))
					: 'NULL → native editor',
			];
		}
		if ($pageRefDiag) {
			$out .= '<dt>Page-reference resolution</dt><dd><pre>'
				. $sanitizer->entities(json_encode($pageRefDiag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
				. '</pre></dd>';
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

		$this->detectPickerMode();

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
	 * AJAX endpoint: the table for ONE duplicate cluster, for the masonry modal.
	 *
	 * In the masonry view a duplicated image is a single tile (no inline editing
	 * there); clicking it opens a modal whose body is THIS endpoint's output —
	 * the normal editable table, but limited to that one image's copies, all
	 * shown as plain rows (no accordion). Identified by the clicked tile's
	 * (pageId, field, basename); we resolve its content hash and gather every
	 * copy in the current filter context. Returns text/html for innerHTML.
	 */
	public function ___executeClusterTable() {
		$this->wire('config')->ajax = true;
		header('Content-Type: text/html; charset=utf-8');
		$san = $this->wire('sanitizer');

		$imageFields = $this->discoverImageFields();
		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);
		if (!$imageFields || !$eligibleTemplates) return '';

		$customCols = $this->collectCustomNames();
		$filters    = $this->readFilterInput($imageFields, $eligibleTemplates, $customCols);
		$sortState  = $this->readSortInput($customCols);

		$input = $this->wire('input');
		$cpid  = (int) $input->get('cpid');
		$cfield = $san->fieldName((string) $input->get('cfield'));
		$cbase  = basename((string) $input->get('cbase'));
		if (!$cpid || $cfield === '' || $cbase === '') return '';

		// Same row pipeline as the results view, up to the per-row content hash.
		$rows = $this->loadRows($filters);
		$rows = $this->applyRowFilters($rows, $filters);
		$rows = $this->applyTagFilter($rows, $filters['tags'] ?? []);
		$this->applySort($rows, $sortState['sort'], $sortState['dir']);

		$keyHash    = $this->loadDuplicateKeyHashes();
		$targetHash = $keyHash[$this->hashKey($cpid, $cfield, $cbase)] ?? null;
		if ($targetHash === null) {
			return '<p class="ml-empty">' . $san->entities($this->_('No duplicates for this image.')) . '</p>';
		}

		$cluster = array_values(array_filter($rows, function ($r) use ($keyHash, $targetHash) {
			return ($keyHash[$this->hashKey((int) $r['pageId'], (string) $r['fieldName'], (string) $r['basename'])] ?? null) === $targetHash;
		}));
		$cluster = $this->hydrateSlice($cluster);
		// Show every copy as a plain, visible, editable row — clear the dup
		// annotation so renderTable doesn't collapse them into an accordion.
		foreach ($cluster as &$r) { $r['dupCount'] = 0; $r['dupHash'] = ''; }
		unset($r);

		$tagsConfig = $this->getTagsConfig();
		return $this->renderTable($cluster, $customCols, $filters, '', '', $tagsConfig)
			. $this->renderTagDatalists($this->collectUsedTagsByField($cluster), $tagsConfig);
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
		$this->beginJsonPost();

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
			return $this->jsonError(sprintf(
				"Image field '%s' has no subfield '%s'", $fieldName, $subfield
			));
		}

		$page = $this->wire('pages')->get($pageId);
		if (!$page->id) return $this->jsonError('Page not found', 404);
		if (!$page->editable()) return $this->jsonError('Page not editable', 403);

		$img = $this->resolvePageimage($page, $fieldName, $basename);
		if (!$img) return $this->jsonError('Image not found in field', 404);

		// Output formatting off before mutating: setters work on the raw value
		// and avoid double-encoding for fields like description.
		$page->of(false);

		// Typed custom subfields (checkbox / date / select): coerce to
		// the Fieldtype's stored shape, set directly, and return the
		// typed display + editor-raw value. No placeholder / multilang
		// / tag handling applies to these.
		$customType = $this->getCustomTypes()[$subfield] ?? null;
		if (in_array($customType, ['checkbox', 'date', 'number', 'select', 'page'], true)) {
			return $this->saveTypedCustom($page, $img, $fieldName, $subfield, $customType, $value);
		}

		// Multilang popup ships a per-language map alongside the
		// primary value. When that's present, write each language
		// directly instead of letting writeLangValue() pick one.
		$langValuesJson = (string) $this->wire('input')->post('langValues');
		$langValues = null;
		if ($langValuesJson !== '') {
			$decoded = json_decode($langValuesJson, true);
			if (is_array($decoded)) $langValues = $decoded;
		}

		// Placeholder resolution — same token grammar as filename.
		// Single-cell save → n = 1, total = 1. Skipped for tags
		// (any mode): tags are token sets where "(d)" → date would
		// land as a literal tag, which is editorial noise.
		if ($subfield !== 'tags') {
			$ctx = $this->buildPlaceholderCtx($page, $fieldName, 1, 1);
			$value = $this->resolveRenamePattern($value, $ctx);
			if ($langValues !== null) {
				foreach ($langValues as $lk => $lv) {
					$langValues[$lk] = $this->resolveRenamePattern((string) $lv, $ctx);
				}
			}
		}

		// Tag modes: whitelist (2) rejects any token not in the configured
		// tagsList; predefined-plus-own (3) accepts anything but still
		// normalises separators. Splits on whitespace + commas to match PW.
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
			} elseif ($tagsCfg['mode'] === 3) {
				$value = implode(' ', $this->splitTags($value));
			}
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

		$tagsMode = $subfield === 'tags' ? (int) ($tagsCfg['mode'] ?? 0) : 0;
		return $this->jsonResponse(
			$this->buildSaveResponse($img, $subfield, $fieldName, $pageId, $basename, $tagsMode)
		);
	}

	/**
	 * Save a typed custom subfield (checkbox / date / number / select / page):
	 * coerce to the Fieldtype's stored shape, set + save, and return the typed
	 * display + editor-raw value. No placeholder / multilang / tag handling
	 * applies to these. Returns the JSON response string.
	 */
	protected function saveTypedCustom(Page $page, Pageimage $img, string $fieldName, string $subfield, string $customType, string $value): string {
		$field   = $this->wire('fields')->get($subfield);
		$coerced = $this->coerceCustomValue($page, $field, $customType, $value);
		try {
			$img->set($subfield, $coerced);
			$saved = $page->save($fieldName);
		} catch (\Throwable $e) {
			return $this->jsonError('Save error: ' . $e->getMessage());
		}
		if (!$saved) {
			return $this->jsonError('Save returned false — value may not have persisted');
		}
		$this->wire('cache')->deleteFor($this);

		$key   = $this->rowKey((int) $page->id, $fieldName, (string) $img->basename);
		$match = $this->matchTouchedRows([$key]);
		return $this->jsonResponse([
			'ok'           => true,
			'value'        => (string) $this->readCustomValue($img, $subfield),
			'rawValue'     => $this->readCustomRaw($img, $subfield),
			'stillMatches' => !in_array($key, $match['vanished'], true),
			'newTotal'     => $match['newTotal'],
		]);
	}

	/**
	 * Assemble the JSON response payload for a (non-typed) inline save: the
	 * stored value, plus — when relevant — the refreshed predefined-tag list
	 * (tags mode 3), every-language values for multilang cells, and the match-
	 * aware stillMatches / newTotal so the client can fade rows that dropped
	 * out of the active filter.
	 *
	 * @return array<string,mixed>
	 */
	protected function buildSaveResponse(Pageimage $img, string $subfield, string $fieldName, int $pageId, string $basename, int $tagsMode): array {
		// The value PW actually stored — may differ from input after
		// sanitization (tags lowercased / whitespace normalized, etc.).
		// Multilang values reduce to the current-user-language string.
		$stored = $this->normalizeDescription($img->get($subfield));

		$response = [
			'ok'    => true,
			'value' => (string) $stored,
		];
		// Mode 3 ("predefined + can input their own"): promote any new tag into
		// the field's predefined list and hand the updated list back so open
		// cells can refresh without a reload.
		if ($subfield === 'tags' && $tagsMode === 3) {
			$response['tagsAllowed'] = array_values($this->registerFieldTags($fieldName, (string) $stored));
			$response['field']       = $fieldName;
		}
		// Multilang fields: hand back every language's value so the client can
		// refresh the cell's data-lang-<id> attrs in place (else the next popup
		// reads stale pre-save attrs).
		$langValues = $this->readLangValues($img, $subfield);
		if ($langValues !== null) {
			$response['langValues'] = $langValues;
		}
		// Match-aware UX: does the saved row still pass the active filter set?
		$key   = $this->rowKey($pageId, $fieldName, $basename);
		$match = $this->matchTouchedRows([$key]);
		$response['stillMatches'] = !in_array($key, $match['vanished'], true);
		$response['newTotal']     = $match['newTotal'];
		return $response;
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
	 * Placeholder syntax (see resolveRenamePattern for the full
	 * grammar — same tokens work in description / tags / customs):
	 *   (n)         counter
	 *   (n2)…(n5)   zero-padded counter
	 *   (N)         total
	 *   (t)         page title
	 *   (d)         date YYYY-MM-DD
	 *   (p)         page name (PW URL slug)
	 *   (f)         image field name
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
	/**
	 * AJAX endpoint: render PW's configured Inputfield for a custom
	 * subfield (currently used for page-references so PageAutocomplete
	 * / PageListSelect / ASMSelect / etc. work natively in our popup
	 * instead of being approximated by a checkbox list).
	 *
	 * Returns { html, scripts, styles, name, id } — the client injects
	 * the HTML, loads any new scripts / styles, fires the 'reloaded'
	 * DOM event so the inputfield's own JS initialises on the new
	 * nodes. On save the client collects every input[name^="<subfield>"]
	 * value from inside the widget container and submits to the
	 * existing /save/ endpoint as a comma-joined string — coerceCustom-
	 * Value already shapes that into a Page / PageArray for the
	 * Fieldtype.
	 */
	public function ___executeWidget() {
		$config = $this->wire('config');
		header('Content-Type: application/json');
		ob_start();

		$input     = $this->wire('input');
		$sanitizer = $this->wire('sanitizer');

		$pageId    = (int) $input->get('pageId');
		$fieldName = $sanitizer->fieldName((string) $input->get('fieldName'));
		$basename  = basename((string) $input->get('basename'));
		$subfield  = $sanitizer->fieldName((string) $input->get('subfield'));

		if (!$pageId || !$fieldName || !$basename || !$subfield) {
			return $this->jsonError('Missing required parameter');
		}
		if (!in_array($fieldName, $this->discoverImageFields(), true)) {
			return $this->jsonError('Field is not a managed image field');
		}
		if (!in_array($subfield, $this->editableSubfields($fieldName), true)) {
			return $this->jsonError(sprintf(
				"Image field '%s' has no subfield '%s'", $fieldName, $subfield
			));
		}

		$page = $this->wire('pages')->get($pageId);
		if (!$page->id) return $this->jsonError('Page not found', 404);
		if (!$page->editable()) return $this->jsonError('Page not editable', 403);

		$img = $this->resolvePageimage($page, $fieldName, $basename);
		if (!$img) return $this->jsonError('Image not found in field', 404);

		$field = $this->wire('fields')->get($subfield);
		if (!($field instanceof Field)) {
			return $this->jsonError(sprintf("PW field '%s' does not exist", $subfield));
		}

		// getFieldsPage() returns a SHARED template Page used as the
		// context for custom-field machinery — it doesn't carry the
		// per-image value. The actual stored value lives on the
		// Pagefile itself; $img->get($subfield) returns it. Use the
		// shared customsPage as the Inputfield's selector context
		// (parent_id / find code resolve against it), but pull the
		// value from the image so the widget reflects the existing
		// selection on open.
		$customsPage = method_exists($img->pagefiles, 'getFieldsPage')
			? $img->pagefiles->getFieldsPage()
			: $page;
		if (!($customsPage instanceof Page) || !$customsPage->id) {
			$customsPage = $page;
		}

		// Capture scripts / styles BEFORE rendering so we can hand the
		// client only the NEW files this widget pulls in (PageAutocomplete
		// loads jquery-ui, etc.). The compare is by URL string.
		$scriptsBefore = [];
		foreach ($config->scripts as $url) $scriptsBefore[] = (string) $url;
		$stylesBefore = [];
		foreach ($config->styles  as $url) $stylesBefore[]  = (string) $url;

		$inputfield = $field->getInputfield($customsPage);
		if (!$inputfield) {
			return $this->jsonError('No inputfield resolved for ' . $subfield);
		}
		$inputfield->attr('name', $subfield);
		$inputfield->attr('id',   'ml_widget_' . $subfield);
		// Per-image value — the shared customsPage doesn't carry this,
		// only the Pagefile itself does. Goes through the Inputfield's
		// own value setter so it converts Page / PageArray / id-array
		// into whatever internal shape it needs.
		$inputfield->setAttribute('value', $img->get($subfield));

		// Render via an InputfieldWrapper so the .Inputfield <li>
		// wrapping (which inputfield JS expects to scan for) is in
		// place, then drop the <ul> wrapper and keep just the
		// rendered field block.
		$wrap = $this->wire(new InputfieldWrapper());
		$wrap->add($inputfield);
		$html = $wrap->render();

		$scriptsAfter = [];
		foreach ($config->scripts as $url) $scriptsAfter[] = (string) $url;
		$stylesAfter = [];
		foreach ($config->styles  as $url) $stylesAfter[]  = (string) $url;

		$newScripts = array_values(array_diff($scriptsAfter, $scriptsBefore));
		$newStyles  = array_values(array_diff($stylesAfter,  $stylesBefore));

		return $this->jsonResponse([
			'ok'      => true,
			'html'    => $html,
			'scripts' => $newScripts,
			'styles'  => $newStyles,
			'name'    => $inputfield->attr('name'),
			'id'      => $inputfield->attr('id'),
		]);
	}

	public function ___executeRename() {
		$this->beginJsonPost();

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

		$page->of(false);
		$result = $this->performRename(
			$img, $page, $fieldName, $newStemRaw,
			$this->buildPlaceholderCtx($page, $fieldName, 1, 1)
		);
		if (!$result['ok']) {
			return $this->jsonError((string) $result['error']);
		}

		if (!empty($result['unchanged'])) {
			return $this->jsonResponse([
				'ok'        => true,
				'basename'  => $result['basename'],
				'unchanged' => true,
			]);
		}

		if (!$page->save($fieldName)) {
			return $this->jsonError('Save failed after rename');
		}
		$this->wire('cache')->deleteFor($this);

		// The embed rewrite saved the referencing pages with a field-only save
		// (no Pages::saved hook), so refresh their where-used rows now — after the
		// cache clear above, so the stem index sees the new basename.
		$this->reindexUsageForRefPages($result['embedsRefPageIds'] ?? []);

		$finalBasename = (string) $result['basename'];
		$finalDot      = strrpos($finalBasename, '.');

		// Same match-aware UX as ___executeSave: report whether the
		// renamed row still belongs in the active filter view. Match
		// against the NEW basename — the row key follows the new
		// filename, which is what the client's DOM also uses now.
		$key   = $this->rowKey($pageId, $fieldName, $finalBasename);
		$match = $this->matchTouchedRows([$key]);

		return $this->jsonResponse([
			'ok'              => true,
			'basename'        => $finalBasename,
			'stem'            => $finalDot === false ? $finalBasename : substr($finalBasename, 0, $finalDot),
			'ext'             => $finalDot === false ? '' : substr($finalBasename, $finalDot),
			'unchanged'       => false,
			'stillMatches'    => !in_array($key, $match['vanished'], true),
			'newTotal'        => $match['newTotal'],
			'embedsRewritten' => (int) ($result['embedsRewritten'] ?? 0),
			'embedsRefs'      => $result['embedsRefs'] ?? [],
		]);
	}

	/**
	 * AJAX endpoint: swap an image's file bytes while keeping basename,
	 * Pagefile metadata (description, tags, customs, multilang) and every
	 * URL pointing at it intact. Triggered by the in-row Replace icon AND
	 * by drag-and-drop of a file onto the row.
	 *
	 * Expects POST + CSRF + file upload "file" and string fields pageId,
	 * fieldName, basename. Extension of the uploaded file MUST match the
	 * existing image's extension — Replace explicitly does not allow
	 * format changes (jpg → png would break references). Editors who
	 * want a different format should delete + upload instead.
	 *
	 * Process: validate → move tmp upload onto the image's filename →
	 * removeVariations() so cached renders get regenerated → save the
	 * page (Pages::saved hook drops the row cache). Returns the new
	 * filemtime so JS can cache-bust the thumbnail.
	 */
	public function ___executeReplace() {
		$this->beginJsonPost();

		$input     = $this->wire('input');
		$sanitizer = $this->wire('sanitizer');

		$pageId    = (int) $input->post('pageId');
		$fieldName = $sanitizer->fieldName((string) $input->post('fieldName'));
		$basename  = basename((string) $input->post('basename'));

		if (!$pageId || !$fieldName || !$basename) {
			return $this->jsonError('Missing required parameter');
		}
		if (!in_array($fieldName, $this->discoverImageFields(), true)) {
			return $this->jsonError('Field is not a managed image field');
		}

		if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
			return $this->jsonError('No file uploaded');
		}
		$tmpPath = (string) $_FILES['file']['tmp_name'];
		$uploadName = (string) ($_FILES['file']['name'] ?? '');

		$page = $this->wire('pages')->get($pageId);
		if (!$page->id) return $this->jsonError('Page not found', 404);
		if (!$page->editable()) return $this->jsonError('Page not editable', 403);

		$img = $this->resolvePageimage($page, $fieldName, $basename);
		if (!$img) return $this->jsonError('Image not found in field', 404);

		// Extension match enforcement — Replace preserves the basename
		// (and therefore the URL); changing the extension would break
		// every reference that points at the old URL. jpg/jpeg and tif/tiff
		// are the SAME format though (the bytes land on the original filename
		// either way), so normalise those equivalences rather than rejecting
		// a .jpeg over a .jpg. The client mirrors this normalisation.
		$equivExt = ['jpeg' => 'jpg', 'tiff' => 'tif'];
		$oldExt = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
		$newExt = strtolower(pathinfo($uploadName, PATHINFO_EXTENSION));
		if ($newExt === '' || $oldExt === '') {
			return $this->jsonError('Both files must have an extension');
		}
		if (($equivExt[$oldExt] ?? $oldExt) !== ($equivExt[$newExt] ?? $newExt)) {
			return $this->jsonError(sprintf(
				'Extension mismatch: existing is .%s, upload is .%s. Replace keeps the original format.',
				$oldExt, $newExt
			));
		}

		// Content validation: the upload must actually BE an image of the
		// claimed type, not arbitrary bytes under an image extension. Replace
		// already pins the extension to the original, so matching the bytes to
		// that extension is enough. getimagesize() doesn't recognise SVG, so
		// that one format is sniffed for an <svg root instead.
		$imgInfo = @getimagesize($tmpPath);
		if ($imgInfo === false) {
			if ($newExt === 'svg') {
				$head = (string) @file_get_contents($tmpPath, false, null, 0, 512);
				if (stripos($head, '<svg') === false) {
					return $this->jsonError('Upload is not a valid SVG image');
				}
			} else {
				return $this->jsonError('Upload is not a valid image');
			}
		} else {
			$extsByType = [
				IMAGETYPE_JPEG    => ['jpg', 'jpeg'],
				IMAGETYPE_PNG     => ['png'],
				IMAGETYPE_GIF     => ['gif'],
				IMAGETYPE_WEBP    => ['webp'],
				IMAGETYPE_BMP     => ['bmp'],
				IMAGETYPE_TIFF_II => ['tif', 'tiff'],
				IMAGETYPE_TIFF_MM => ['tif', 'tiff'],
			];
			$validExts = $extsByType[(int) $imgInfo[2]] ?? [];
			if ($validExts && !in_array($newExt, $validExts, true)) {
				return $this->jsonError(sprintf(
					'Upload content does not match the .%s extension', $newExt
				));
			}
		}

		$targetPath = (string) $img->filename;
		if ($targetPath === '' || !is_file($targetPath)) {
			return $this->jsonError('Original file not found on disk');
		}

		// Land the upload on a temp name in the target's OWN directory, then
		// atomically rename it into place — do NOT move_uploaded_file()
		// straight onto $targetPath. When PHP's upload tmp dir sits on a
		// different filesystem than the assets dir (common in containers / a
		// separate /tmp mount), a direct move can't rename across filesystems
		// and falls back to copying bytes INTO the existing file, which needs
		// that file itself to be writable by the web user — and fails
		// ("Could not write…") when the original was deployed with a different
		// owner or mode 644. Moving to a fresh temp name needs only DIRECTORY
		// write (which uploads already rely on), and rename() over an existing
		// same-filesystem path is an atomic directory-entry swap unaffected by
		// the old file's own permissions. move_uploaded_file keeps PHP's
		// safe-upload check intact (refuses tmp paths that aren't real uploads).
		$files   = $this->wire('files');
		$tmpDest = $targetPath . '.mlupload';
		if (!@move_uploaded_file($tmpPath, $tmpDest)) {
			return $this->jsonError('Could not write replacement file');
		}
		if (!@rename($tmpDest, $targetPath)) {
			@unlink($tmpDest);
			return $this->jsonError('Could not write replacement file');
		}
		$files->chmod($targetPath);

		$page->of(false);
		try {
			// Old variations were rendered from the old bytes — they
			// have to go regardless of whether anyone re-requests them
			// immediately.
			$img->removeVariations();
			if (!$page->save($fieldName)) {
				return $this->jsonError('Save failed after replace');
			}
			$this->wire('cache')->deleteFor($this);
		} catch (\Exception $e) {
			return $this->jsonError('Replace error: ' . $e->getMessage());
		}

		// Pre-generate the same thumbnail variation hydrateSlice would
		// produce, so the cache-busted URL the JS swaps in actually
		// resolves immediately — without this the browser would 404
		// against the just-removed variation file until the next page
		// render lazily recreates it.
		$img2 = $this->resolvePageimage($page, $fieldName, $basename) ?: $img;
		$thumbInfo = $this->resolveThumbForImage($img2, $this->getThumbDims());

		clearstatcache(true, $targetPath);
		$mtime    = @filemtime($targetPath);
		$filesize = (int) @filesize($targetPath);
		$cacheBust = $mtime ? (string) $mtime : (string) time();

		// Re-derive the row's metadata fields so the table can patch
		// them in place. Width / height come from the fresh Pageimage
		// (which lazy-reads getimagesize off the new file). The
		// variations counter is 1 — removeVariations() wiped the lot
		// and we just generated one fresh thumb.
		$dims = ($img2->width && $img2->height)
			? ((int) $img2->width) . '×' . ((int) $img2->height)
			: '';

		// Modified column comes from MySQL DATETIME on the file row;
		// PW's $page->save() writes NOW() to that column for every
		// Pagefile in the saved field, so the value is already fresh.
		// We pass it back through the same formatTimestamp the table
		// uses so the patched cell matches a server-rendered one.
		$modifiedRaw = (string) ($img2->modified ?? '');
		if ($modifiedRaw === '' || strtotime($modifiedRaw) === false) {
			// Defensive — if PW didn't bump it (older PW versions,
			// missing column), fall back to the filesystem mtime.
			$modifiedRaw = $mtime ? date('Y-m-d H:i:s', $mtime) : '';
		}

		return $this->jsonResponse([
			'ok'          => true,
			'basename'    => $basename,
			'cacheBust'   => $cacheBust,
			'thumbUrl'    => $thumbInfo['url'] . '?v=' . $cacheBust,
			'thumbWidth'  => $thumbInfo['width'],
			'thumbHeight' => $thumbInfo['height'],
			// Cell-level updates for the JS to patch in place — these
			// are pre-formatted on the server so the patched cells
			// look identical to a freshly-rendered row.
			'dimensions'       => $dims,
			'filesize'         => $filesize,
			'filesizeFormatted'=> $this->formatFilesize($filesize),
			'modifiedFormatted'=> $modifiedRaw ? $this->formatTimestamp($modifiedRaw) : '',
			'variationsCount'  => 1,
		]);
	}

	/**
	 * AJAX endpoint: delete one or more images (single + batch share
	 * the same code path — JS always sends an `items` JSON array).
	 *
	 * Items: [{pageId, fieldName, basename}, ...]. Per-page editable()
	 * is enforced; failures land in the result list so the UI can
	 * report them via the existing bulk-result dialog pattern.
	 *
	 * Process per page: $pageimages->delete($img) removes the file
	 * and its row, $page->save($field) persists. removeVariations()
	 * happens implicitly through PW's file delete. Cache is dropped
	 * via Pages::saved hook + an explicit deleteFor for symmetry
	 * with the other endpoints.
	 */
	public function ___executeDelete() {
		$this->beginJsonPost();

		$input     = $this->wire('input');
		$sanitizer = $this->wire('sanitizer');
		$itemsJson = (string) $input->post('items');
		$items     = json_decode($itemsJson, true);
		if (!is_array($items) || !$items) {
			return $this->jsonError('No items provided');
		}

		// Group by pageId so each page is saved once.
		$byPage = [];
		foreach ($items as $item) {
			if (!is_array($item)) continue;
			$pid = (int) ($item['pageId'] ?? 0);
			$fn  = $sanitizer->fieldName((string) ($item['fieldName'] ?? ''));
			$bn  = basename((string) ($item['basename'] ?? ''));
			if (!$pid || !$fn || !$bn) continue;
			$byPage[$pid][] = ['fieldName' => $fn, 'basename' => $bn];
		}
		if (!$byPage) return $this->jsonError('No valid items');

		$imageFields = $this->discoverImageFields();
		$succeeded   = [];
		$failed      = [];

		foreach ($byPage as $pid => $pageItems) {
			$page = $this->wire('pages')->get($pid);
			if (!$page->id) {
				foreach ($pageItems as $i) $failed[] = sprintf('Page %d not found', $pid);
				continue;
			}
			if (!$page->editable()) {
				foreach ($pageItems as $i) $failed[] = sprintf('Page %d not editable', $pid);
				continue;
			}
			$page->of(false);
			$fieldsTouched = [];
			foreach ($pageItems as $i) {
				$fn = $i['fieldName'];
				$bn = $i['basename'];
				if (!in_array($fn, $imageFields, true)) {
					$failed[] = sprintf('%s on page %d is not a managed image field', $fn, $pid);
					continue;
				}
				$pageimages = $page->getUnformatted($fn);
				if (!($pageimages instanceof Pagefiles)) {
					$failed[] = sprintf('%s on page %d has no files', $fn, $pid);
					continue;
				}
				$img = $pageimages->getFile($bn);
				if (!$img) {
					$failed[] = sprintf('%s/%s on page %d not found', $fn, $bn, $pid);
					continue;
				}
				try {
					$pageimages->delete($img);
					$fieldsTouched[$fn] = true;
					$succeeded[] = $this->rowKey($pid, $fn, $bn);
				} catch (\Throwable $e) {
					$failed[] = sprintf('%s/%s on page %d: %s', $fn, $bn, $pid, $e->getMessage());
				}
			}
			if ($fieldsTouched) {
				try {
					foreach (array_keys($fieldsTouched) as $fn) {
						$page->save($fn);
					}
				} catch (\Throwable $e) {
					$failed[] = sprintf('Save on page %d failed: %s', $pid, $e->getMessage());
				}
			}
		}

		$this->wire('cache')->deleteFor($this);

		return $this->jsonResponse([
			'ok'        => true,
			'succeeded' => $succeeded,
			'failed'    => $failed,
		]);
	}

	/**
	 * Where-used preflight for the delete confirm dialog.
	 *
	 * Both pwimage plugins (CKEditor + TinyMCE) insert images via the
	 * same backend selector and the same URL shape:
	 *   {assets}/files/{pageId}/{stem}.{ext}     (original)
	 *   {assets}/files/{pageId}/{stem}.WxH.{ext} (resized variation)
	 *   {assets}/files/{pageId}/{stem}.WxH-…{ext} (cropped / hidpi)
	 * which makes the reverse query an editor-agnostic substring
	 * search on every FieldtypeTextarea field. We match on the stem
	 * prefix `/{pageId}/{stem}.` so a single needle catches the
	 * original AND every PW-derived variation — pwimage picks a
	 * user-selected size at insert time, so requiring the basename to
	 * match exactly would miss the typical case.
	 *
	 * Returns { ok, usage: { "pid:basename": [ { pageId, pageTitle,
	 * editUrl, fieldName }, … ] } } so the client can render a
	 * grouped reference list. Empty keys are omitted; the caller
	 * treats absence as "no references found".
	 */
	public function ___executeUsage() {
		$this->beginJsonPost();

		$input     = $this->wire('input');
		$itemsJson = (string) $input->post('items');
		$items     = json_decode($itemsJson, true);
		if (!is_array($items) || !$items) {
			return $this->jsonError('No items provided');
		}

		$clean = [];
		foreach ($items as $item) {
			if (!is_array($item)) continue;
			$pid = (int) ($item['pageId'] ?? 0);
			$bn  = basename((string) ($item['basename'] ?? ''));
			if ($pid && $bn) $clean[] = ['pageId' => $pid, 'basename' => $bn];
		}
		if (!$clean) return $this->jsonError('No valid items');

		$usage = $this->findImageReferences($clean);
		return $this->jsonResponse(['ok' => true, 'usage' => $usage]);
	}

	/**
	 * Content-based where-used for ONE image — backs the "Used in" column's
	 * click-through. Unlike ___executeUsage (a live per-placement scan used by
	 * the delete/rename preflight), this reads the prebuilt usage index and
	 * aggregates across the image's whole dedup cluster, so the answer matches
	 * the column's badge count regardless of which placement was clicked.
	 *
	 * Returns { ok, pages: [ { pageId, pageTitle, editUrl, fieldName }, … ] }.
	 */
	public function ___executeUsageDetail() {
		$this->beginJsonPost();

		$input    = $this->wire('input');
		$pageId   = (int) $input->post('pageId');
		$field    = $this->wire('sanitizer')->fieldName((string) $input->post('field'));
		$basename = basename((string) $input->post('basename'));
		if (!$pageId || $basename === '') {
			return $this->jsonError('Missing required parameter');
		}

		$pages = $this->usagePagesForImage($pageId, $field, $basename);
		return $this->jsonResponse(['ok' => true, 'pages' => $pages]);
	}

	/**
	 * Reverse-lookup: which rich-text fields reference each of the
	 * given (pageId, basename) pairs.
	 *
	 * Goes through PW's selector API (`%=` substring-LIKE) rather than
	 * raw SQL — selectors are multilang-, repeater- and access-aware,
	 * which a hand-rolled `LIKE` over `field_*.data` is not. One
	 * `findIDs()` call per (textarea field × needle) pair.
	 *
	 * Page titles + edit URLs are resolved once per ref-page-id via a
	 * tiny in-method cache. We gate on existence + editability of the
	 * referencing page — NOT on viewable(), because an admin user with
	 * image-library-access plus per-page edit rights legitimately
	 * needs to know about embeds even in pages they can't see on the
	 * front-end (unpublished / hidden / access-restricted templates).
	 *
	 * @param array<int, array{pageId:int, basename:string}> $items
	 * @return array<string, array<int, array{pageId:int,pageTitle:string,editUrl:string,fieldName:string}>>
	 */
	protected function findImageReferences(array $items): array {
		if (!$items) return [];

		$specs = [];   // key => ['needle' => string, 'regex' => string]
		$byKey = [];
		foreach ($items as $item) {
			$pid = (int) $item['pageId'];
			$bn  = (string) $item['basename'];
			if (!$pid || $bn === '') continue;
			$stem = $this->basenameStem($bn);
			if ($stem === '') continue;
			$key = $pid . ':' . $bn;
			$byKey[$key] = [];
			$qs = preg_quote($stem, '#');
			// pwimage stores an inserted variation in the SOURCE image's own files
			// folder, so its URL is /files/<sourcePid>/<stem>.<variation>[-is][-pid<editorPid>].<ext>
			// for BOTH same-page and cross-page inserts. The optional -pid<N> marker
			// records the EDITING page that uses the variation, NOT the source (see
			// PW core ProcessPageEditImageSelect; the grammar is documented on the
			// ImageLibraryUsage trait). So the DIRECT branch (/<pid>/<stem>.) already
			// catches every standard embed of this image. The second branch is a
			// defensive fallback for the rarer setup where a copy lands in another
			// page's folder tagged with this image's id; it is a no-op for standard
			// pwimage data. A loose "/<stem>." needle finds candidates of either
			// form cheaply; the regex then verifies it is really THIS image.
			$specs[$key] = [
				'needle' => '/' . $stem . '.',
				'regex'  => '#/(?:' . $pid . '/' . $qs . '\.'
					. '|\d+/' . $qs . '\.[^"\'\s/]*-pid' . $pid . '\b)#i',
			];
		}
		if (!$specs) return [];

		$pages     = $this->wire('pages');
		$sanitizer = $this->wire('sanitizer');

		$textareaFields = [];
		foreach ($this->wire('fields') as $field) {
			if ($field->type instanceof FieldtypeTextarea) {
				$textareaFields[] = $field->name;
			}
		}
		if (!$textareaFields) return $byKey;

		foreach ($specs as $key => $spec) {
			$escaped = $sanitizer->selectorValue($spec['needle']);
			foreach ($textareaFields as $name) {
				// %= is PW's substring-LIKE operator. include=all so hidden /
				// unpublished pages are reached too — embeds live in admin
				// content regardless of front-end state.
				try {
					$ids = $pages->findIDs($name . '%=' . $escaped . ', include=all');
				} catch (\Throwable $e) {
					continue;
				}
				foreach ($ids as $refId) {
					$rp = $pages->get((int) $refId);
					// Verify the field actually references THIS image (the loose
					// needle also matches a same-stem image on another page).
					if (!$rp->id || !$this->fieldValueMatches($rp, $name, $spec['regex'])) continue;
					$byKey[$key][] = [
						'refPageId'    => (int) $refId,
						'refFieldName' => $name,
					];
				}
			}
		}

		$pageCache = [];
		foreach ($byKey as $key => $refs) {
			$out  = [];
			$seen = [];
			foreach ($refs as $r) {
				$pid = $r['refPageId'];
				$fn  = $r['refFieldName'];

				if (!array_key_exists($pid, $pageCache)) {
					$p = $pages->get($pid);
					$pageCache[$pid] = $p->id ? $p : null;
				}
				if (!$pageCache[$pid]) continue;

				$ref = $this->usageRefForPage($pageCache[$pid], $fn);
				// De-dupe on the RESOLVED target so multiple repeater items of the
				// same owner + field collapse to one actionable entry.
				$dedup = $ref['pageId'] . ':' . $ref['fieldName'];
				if (isset($seen[$dedup])) continue;
				$seen[$dedup] = true;
				$out[] = $ref;
			}
			$byKey[$key] = $out;
		}

		return $byKey;
	}

	/**
	 * Resolve a page that embeds an image (in a rich-text field) to an
	 * ACTIONABLE where-used entry. When the embed lives on a (Matrix)Repeater
	 * item page — whose name is an internal id like "1705154010-188-1" and whose
	 * editUrl opens nowhere useful — walk up to the owning content page and link
	 * there instead, tagging the field with the repeater field for context
	 * ("blocks › left_col"). Non-repeater pages pass through unchanged.
	 *
	 * @return array{pageId:int,pageTitle:string,editUrl:string,fieldName:string}
	 */
	protected function usageRefForPage(Page $refPage, string $fieldName): array {
		$owner  = method_exists($refPage, 'getForPageRoot') ? $refPage->getForPageRoot() : null;
		$rfield = method_exists($refPage, 'getForField') ? $refPage->getForField() : null;
		$useOwner = ($owner && $owner->id && $owner->id !== $refPage->id);
		$target = $useOwner ? $owner : $refPage;
		return [
			'pageId'    => (int) $target->id,
			'pageTitle' => (string) $target->get('title|name'),
			'editUrl'   => $target->editable() ? (string) $target->editUrl() : '',
			'fieldName' => ($useOwner && $rfield) ? ($rfield->name . ' › ' . $fieldName) : $fieldName,
		];
	}

	/**
	 * Core rename routine — shared by ___executeRename (single) and
	 * ___executeBulk's basename branch (batch). Resolves placeholders
	 * in $pattern using $n + the image's page / field context,
	 * sanitises the result, runs the collision check inside the host
	 * field's Pageimages, drops the OLD basename's variation files
	 * (their names embed the old stem and would orphan on disk after
	 * rename), then calls Pagefile::rename(). Page::save() stays
	 * with the caller so multi-image batches save each page only once.
	 *
	 * Returns:
	 *   ['ok' => true,  'basename' => '…', 'unchanged' => false|true]
	 *   ['ok' => false, 'basename' => '<old>', 'error' => '<msg>']
	 *
	 * @param array{n?:int} $ctx
	 * @return array<string,mixed>
	 */
	protected function performRename(Pageimage $img, Page $page, string $fieldName, string $pattern, array $ctx = []): array {
		$sanitizer = $this->wire('sanitizer');
		$oldBasename = (string) $img->basename;

		// Pass the caller's full ctx through (so total / date / etc.
		// reach resolveRenamePattern) but ensure n / page / field have
		// sane defaults. The previous version built a fresh dict with
		// only those three keys, which dropped 'total' on the floor —
		// (N) → 0 in every batch rename and (d) → empty string.
		$resolved = $this->resolveRenamePattern($pattern, array_merge([
			'n'     => 1,
			'page'  => $page,
			'field' => $fieldName,
		], $ctx));
		$newStem = $sanitizer->filename($resolved, true);
		$newStem = preg_replace('/\.+/', '', $newStem) ?? '';
		if ($newStem === '') {
			return ['ok' => false, 'basename' => $oldBasename, 'error' => 'New filename is empty'];
		}

		$dotPos = strrpos($oldBasename, '.');
		$ext    = $dotPos === false ? '' : substr($oldBasename, $dotPos);
		$newBasename = $newStem . $ext;

		if ($newBasename === $oldBasename) {
			return ['ok' => true, 'basename' => $oldBasename, 'unchanged' => true];
		}

		$fieldValue = $page->getUnformatted($fieldName);
		if ($fieldValue instanceof Pageimages) {
			$existing = $fieldValue->getFile($newBasename);
			if ($existing && $existing !== $img) {
				return [
					'ok' => false,
					'basename' => $oldBasename,
					'error' => 'A file named "' . $newBasename . '" already exists in this field',
				];
			}
		}

		// PW's Pagefile::rename() (3.0.172+) renames the variation
		// files alongside the original — the old explicit
		// removeVariations() call here was both redundant AND a major
		// hit: it deleted variations that rename would have moved,
		// forcing every thumb to regenerate on the next render. Skipping
		// it makes batch rename roughly 2x faster because the post-
		// rename listing reuses the renamed variations.
		if (!method_exists($img, 'rename')) {
			return ['ok' => false, 'basename' => $oldBasename, 'error' => 'Rename API not available on this ProcessWire version'];
		}

		try {
			$renameResult = $img->rename($newBasename);
		} catch (\Throwable $e) {
			return ['ok' => false, 'basename' => $oldBasename, 'error' => 'Rename error: ' . $e->getMessage()];
		}
		if ($renameResult === false) {
			return ['ok' => false, 'basename' => $oldBasename, 'error' => 'Rename failed'];
		}

		// Re-key the fingerprint (+ metalock) row from the old basename to the
		// new one, preserving the content_hash. The file's bytes didn't change,
		// so its hash is still valid — but the rename's field-only save fires
		// Pages::savedField (not Pages::saved), so the auto-hash hook won't do
		// this for us. Without it the renamed image's row stays under the old
		// name and it silently drops out of its duplicate cluster.
		$this->renameFingerprintRows((int) $page->id, $fieldName, $oldBasename, (string) $img->basename);

		// The file (and its variations) moved to a new on-disk name, but
		// rich-text embeds reference it by URL and still point at the old
		// stem. Rewrite them so they survive the rename. A rewrite failure
		// must never roll back a rename that already succeeded on disk.
		$embedRefs = [];
		$refPageIds = [];
		try {
			$embedRefs = $this->rewriteEmbeddedReferences(
				$img, $page, $oldBasename, (string) $img->basename, $refPageIds
			);
		} catch (\Throwable $e) {
			$this->wire('log')->error(
				'ImageLibrary: embed rewrite after rename failed for '
				. $oldBasename . ' → ' . $img->basename . ': ' . $e->getMessage()
			);
		}

		return [
			'ok'               => true,
			'basename'         => (string) $img->basename,
			'unchanged'        => false,
			'embedsRewritten'  => count($embedRefs),
			'embedsRefs'       => $embedRefs,
			'embedsRefPageIds' => array_keys($refPageIds),
		];
	}

	/**
	 * After a successful file rename, rewrite every rich-text embed that
	 * still points at the OLD basename so it points at the new one.
	 *
	 * Both pwimage plugins (CKEditor + TinyMCE) embed an image by URL:
	 *   {assets}/files/{pid}/{stem}.{ext}              (original)
	 *   {assets}/files/{pid}/{stem}.WxH.{ext}          (resized)
	 *   {assets}/files/{pid}/{stem}.WxH-…{ext}         (cropped / hidpi)
	 * Pagefile::rename() moves the file AND every variation on disk but
	 * leaves those URLs dangling. We mirror exactly what happened on disk:
	 * the page folder ({pid}) is unchanged, the extension is unchanged,
	 * every variation is carried along — only the stem changes.
	 *
	 * Candidate pages are found with the SAME FieldtypeTextarea sweep the
	 * where-used preflight uses (selector engine ⇒ multilang / repeater /
	 * access aware), so we rewrite precisely the embeds that preflight
	 * warned about. The per-URL swap is then anchored on /{pid}/{oldStem}.
	 * AND the trailing OLD extension, so a same-stem sibling of a different
	 * type (foo.jpg vs foo.png sharing one page folder) is left untouched.
	 *
	 * Referencing pages are saved here (single field only). When a page
	 * embeds its own image we write through the caller's own $ownerPage
	 * instance rather than a second copy, so the deferred image-field save
	 * in the caller doesn't clobber the rewrite. Returns one row per
	 * (page, field) rewritten — {pageId, pageTitle, fieldName, editUrl} —
	 * in the same shape the where-used list consumes, so the client can
	 * show the editor exactly which embeds were fixed.
	 *
	 * Note: in a batch rename this runs once per image, like the preflight;
	 * correctness over micro-optimisation — RTE-embedded library images in
	 * large batches are rare.
	 *
	 * @return array<int,array{pageId:int,pageTitle:string,fieldName:string,editUrl:string}>
	 */
	protected function rewriteEmbeddedReferences(Pageimage $img, Page $ownerPage, string $oldBasename, string $newBasename, array &$refPageIds = []): array {
		$oldDot  = strrpos($oldBasename, '.');
		$oldStem = $oldDot === false ? $oldBasename : substr($oldBasename, 0, $oldDot);
		$oldExt  = $oldDot === false ? '' : substr($oldBasename, $oldDot + 1);
		$newDot  = strrpos($newBasename, '.');
		$newStem = $newDot === false ? $newBasename : substr($newBasename, 0, $newDot);
		if ($oldStem === '' || $oldExt === '' || $oldStem === $newStem) return [];

		// {pid} in the asset URL is the page that physically HOLDS the file.
		// For a repeater-hosted image that's the repeater item page, not the
		// owner passed into the rename, so $img->page is the authority.
		$filePage = $img->page;
		$pid = ($filePage && $filePage->id) ? (int) $filePage->id : (int) $ownerPage->id;
		if (!$pid) return [];

		$pages     = $this->wire('pages');
		$sanitizer = $this->wire('sanitizer');

		$qOld = preg_quote($oldStem, '#');
		$qExt = preg_quote($oldExt, '#');
		// pwimage stores the inserted variation in the SOURCE image's own folder,
		// so its URL is /<sourcePid>/<stem>.<variation>[-is][-pid<editorPid>].<ext>
		// for same- and cross-page inserts (-pid<N> is the EDITING page that uses
		// it, NOT the source; see ImageLibraryUsage / PW core). Both regexes
		// rewrite ONLY the stem (via a lookahead) so any variation (crop,
		// multi-dot, hidpi) and the pinned extension stay intact. The direct
		// branch covers every standard embed; the cross branch is a defensive
		// fallback for the rarer copy-in-another-folder setup:
		//   direct: /<pid>/<stem>.<variation>.<ext>
		//   cross:  /<anyPid>/<stem>.<variation>-pid<pid>[-hidpi].<ext>
		$directRe = '#(/' . $pid . '/)' . $qOld . '(?=\.[^"\'\s/]*' . $qExt . '\b)#i';
		$crossRe  = '#(/\d+/)' . $qOld . '(?=\.[^"\'\s/]*-pid' . $pid . '\b[^"\'\s/]*' . $qExt . '\b)#i';

		// Loose stem needle finds candidates of EITHER form. A /<pid>/ needle
		// would miss the cross-page copy, which lives under the editing page's id.
		$escaped = $sanitizer->selectorValue('/' . $oldStem . '.');

		$refs = [];
		$seenRefs = [];
		foreach ($this->wire('fields') as $field) {
			if (!($field->type instanceof FieldtypeTextarea)) continue;
			$name = $field->name;
			try {
				$ids = $pages->findIDs($name . '%=' . $escaped . ', include=all');
			} catch (\Throwable $e) {
				continue;
			}
			foreach ($ids as $refId) {
				$refId = (int) $refId;
				// Self-embed: reuse the caller's instance so the deferred
				// image-field save can't overwrite this rewrite.
				$refPage = ($refId === (int) $ownerPage->id) ? $ownerPage : $pages->get($refId);
				if (!$refPage->id) continue;
				try {
					if ($this->rewriteTextareaField($refPage, $name, $directRe, $crossRe, $newStem)) {
						$refPage->save($name);
						// Field-only save doesn't fire Pages::saved, so the usage
						// reindex hook won't run for this page — collect its raw id
						// so the caller can reindex it after the row cache is fresh.
						$refPageIds[(int) $refPage->id] = true;
						// The cross-page copy is an independent file PW regenerates
						// from the source — once the source is renamed it can't, so
						// the embed breaks. Rename the copy on the editing page to
						// the new stem so the rewritten URL resolves (and PW can
						// regenerate from the now-renamed source too).
						$this->renameCrossPageCopies($refPage, $oldStem, $newStem, $pid);
						// Rewrite targets the page that holds the field ($refPage,
						// possibly a repeater item); the DISPLAYED ref resolves to
						// the owning content page so the link is usable. De-dupe so
						// several repeater items of one owner collapse.
						$ref = $this->usageRefForPage($refPage, $name);
						$dk  = $ref['pageId'] . ':' . $ref['fieldName'];
						if (!isset($seenRefs[$dk])) {
							$seenRefs[$dk] = true;
							$refs[] = $ref;
						}
					}
				} catch (\Throwable $e) {
					// One un-writable reference must not abort the rename or
					// the remaining rewrites.
				}
			}
		}
		return $refs;
	}

	/**
	 * Defensive cleanup for a NON-standard setup: an "Insert from library" copy
	 * that landed in the EDITING page's own files folder, named
	 * "<oldStem>.<variation>-pid<sourcePid>...<ext>" (tagged with the source id).
	 * In standard pwimage the inserted variation instead lives in the SOURCE
	 * image's folder and PW regenerates it from the source after a rename, so the
	 * URL rewrite alone suffices and this scan matches nothing. Where such an
	 * independent copy DOES exist it would dangle once the source is renamed, so
	 * re-stem it here. Only files carrying the exact -pid<sourcePid> marker are
	 * touched, so an unrelated same-stem image on that page is never affected.
	 * Filesystem-level, mirroring the dedup engine. Harmless no-op on standard
	 * installs; kept as a safety net.
	 */
	protected function renameCrossPageCopies(Page $page, string $oldStem, string $newStem, int $sourcePid): void {
		$dir = (string) $page->filesPath();
		if ($dir === '' || !is_dir($dir)) return;
		$re  = '#^' . preg_quote($oldStem, '#') . '\..*-pid' . $sourcePid . '\b#i';
		$pfx = $oldStem . '.';
		foreach (scandir($dir) ?: [] as $bn) {
			if (strncmp($bn, $pfx, strlen($pfx)) !== 0) continue;   // starts "<oldStem>."
			if (!preg_match($re, $bn)) continue;                    // …and carries -pid<sourcePid>
			$newBn = $newStem . substr($bn, strlen($oldStem));
			if ($newBn !== $bn && !file_exists($dir . $newBn)) {
				@rename($dir . $bn, $dir . $newBn);
			}
		}
	}

	/**
	 * Swap the stem in one textarea field of one page (direct + cross-page URL
	 * forms), across every language slot, returning true if anything changed.
	 * Both regexes match only up to the stem (lookahead), so the callback just
	 * replaces the stem — variation/extension are preserved untouched, and a
	 * stem containing $ or \ can't corrupt the replacement.
	 */
	protected function rewriteTextareaField(Page $page, string $fieldName, string $directRe, string $crossRe, string $newStem): bool {
		$page->of(false);
		$value = $page->getUnformatted($fieldName);

		$swap = function (string $html) use ($directRe, $crossRe, $newStem): string {
			$cb = function ($m) use ($newStem) { return $m[1] . $newStem; };   // $m[1] = "/<pid>/"
			$out = preg_replace_callback($directRe, $cb, $html);
			if ($out === null) $out = $html;
			$out2 = preg_replace_callback($crossRe, $cb, $out);
			return $out2 === null ? $out : $out2;
		};

		// Multilang textarea — a LanguagesPageFieldValue. Rewrite each
		// language slot in place so untouched translations are preserved.
		if (is_object($value)
			&& method_exists($value, 'getLanguageValue')
			&& method_exists($value, 'setLanguageValue')) {
			$languages = $this->wire('languages');
			if ($languages) {
				$changed = false;
				foreach ($languages as $lang) {
					$old = (string) $value->getLanguageValue($lang);
					if ($old === '') continue;
					$new = $swap($old);
					if ($new !== $old) {
						$value->setLanguageValue($lang, $new);
						$changed = true;
					}
				}
				if ($changed) $page->set($fieldName, $value);
				return $changed;
			}
		}

		// Single-language textarea — a plain string.
		$old = (string) $value;
		if ($old === '') return false;
		$new = $swap($old);
		if ($new === $old) return false;
		$page->set($fieldName, $new);
		return true;
	}

	/**
	 * Does one textarea field of $page actually contain a URL matching $regex?
	 * Checks every language slot for multilang fields. Used to confirm a loose
	 * "/<stem>." candidate really references the image we're looking for.
	 */
	protected function fieldValueMatches(Page $page, string $fieldName, string $regex): bool {
		$page->of(false);
		$value = $page->getUnformatted($fieldName);
		if (is_object($value) && method_exists($value, 'getLanguageValue')) {
			$languages = $this->wire('languages');
			if ($languages) {
				foreach ($languages as $lang) {
					if (preg_match($regex, (string) $value->getLanguageValue($lang))) return true;
				}
				return false;
			}
		}
		return (bool) preg_match($regex, (string) $value);
	}

	/**
	 * Standard context bag for resolveRenamePattern. Centralizes the
	 * date lookup (server timezone, Y-m-d) so every Save / Bulk /
	 * Rename path shares the same value within one request — even
	 * across multiple items in a batch, the (d) token expands to the
	 * same string for every row, which is what users expect.
	 */
	protected function buildPlaceholderCtx(Page $page, string $fieldName, int $n, int $total): array {
		static $date = null;
		if ($date === null) $date = date('Y-m-d');
		return [
			'n'     => $n,
			'total' => $total,
			'page'  => $page,
			'field' => $fieldName,
			'date'  => $date,
		];
	}

	/**
	 * Expand a placeholder pattern into a concrete string for the
	 * given image's context. Used by every Save / Bulk / Rename
	 * path — same token grammar applies whether the user is typing
	 * a filename stem, a description, a custom textarea, or a
	 * free-form tag string.
	 *
	 * Supported tokens:
	 *   (n)              counter — $ctx['n']
	 *   (n2) … (n5)      zero-padded counter, N digits
	 *   (N)              total — $ctx['total']
	 *   (t)              page title in the editor's admin language;
	 *                    follows repeater rows up to the owner page
	 *                    so the stored title is meaningful even for
	 *                    repeater-hosted images
	 *   (d)              current date YYYY-MM-DD (server timezone)
	 *   (p)              page name (PW URL slug); same repeater-
	 *                    owner resolution as (t)
	 *   (f)              image field name — $ctx['field']
	 *
	 * Unknown tokens are passed through verbatim; downstream
	 * sanitisers (filename path) strip the parens, prose paths
	 * leave them alone. The helper itself does NOT sanitise —
	 * that's the caller's job.
	 *
	 * @param array{n?:int,total?:int,page?:Page,field?:string,date?:string} $ctx
	 */
	protected function resolveRenamePattern(string $pattern, array $ctx): string {
		$n     = (int) ($ctx['n']     ?? 0);
		$total = (int) ($ctx['total'] ?? 0);
		$page  = $ctx['page']  ?? null;
		$field = (string) ($ctx['field'] ?? '');
		$date  = (string) ($ctx['date']  ?? '');

		// Owner-page resolution for (t) and (p): for repeater /
		// RepeaterMatrix images the bare $page is the hidden
		// repeater_<field> page whose title / name are admin-internal
		// noise. Walk up to the user-facing owner so placeholders
		// expand to something meaningful.
		$ownerPage = $page;
		if ($page instanceof Page && $page->id && method_exists($page, 'getForPageRoot')) {
			$owner = $page->getForPageRoot();
			if ($owner && $owner->id && $owner->id !== $page->id) {
				$ownerPage = $owner;
			}
		}

		return preg_replace_callback(
			'/\((n[2-5]?|N|t|d|p|f)\)/',
			function ($m) use ($n, $total, $ownerPage, $field, $date) {
				$key = $m[1];
				if ($key === 'n') return (string) $n;
				if ($key === 'N') return (string) $total;
				if ($key === 't') return ($ownerPage instanceof Page && $ownerPage->id) ? (string) $ownerPage->title : '';
				if ($key === 'd') return $date;
				if ($key === 'p') return ($ownerPage instanceof Page && $ownerPage->id) ? (string) $ownerPage->name : '';
				if ($key === 'f') return $field;
				// n2 … n5 — zero-padded counter
				$digits = (int) substr($key, 1);
				return str_pad((string) $n, $digits, '0', STR_PAD_LEFT);
			},
			$pattern
		) ?? $pattern;
	}

	/**
	 * Shared preamble for every JSON AJAX POST endpoint: flag the request as
	 * ajax, send the JSON content-type, start output buffering, and enforce
	 * POST + a valid CSRF token. On failure it emits the JSON error and exits
	 * (jsonError -> emitJson exits), so returning means the request may proceed.
	 * Call as the first statement of the endpoint: $this->beginJsonPost();
	 */
	protected function beginJsonPost(): void {
		$this->wire('config')->ajax = true;
		header('Content-Type: application/json');
		ob_start();
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			$this->jsonError('POST required', 405);
		}
		if (!$this->wire('session')->CSRF->hasValidToken()) {
			$this->jsonError('Invalid CSRF token', 403);
		}
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
		$this->beginJsonPost();

		$input     = $this->wire('input');
		$sanitizer = $this->wire('sanitizer');

		$action    = (string) $input->post('action');
		$value     = (string) $input->post('value');
		$subfield  = $sanitizer->fieldName((string) $input->post('subfield'));
		$mode      = (string) $input->post('mode'); // 'replace' (default) | 'add' | 'remove'

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
		if (!in_array($mode, ['add', 'remove'], true)) $mode = 'replace';
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
		// Each item carries a global counter that preserves the order
		// the client sent — batch rename uses this for the (n)
		// placeholder so numbering follows the user's selection order
		// regardless of how items get redistributed across pages.
		$byPage = [];
		$counter = 0;
		foreach ($items as $item) {
			$pid = (int) ($item['pageId'] ?? 0);
			$fn  = $sanitizer->fieldName((string) ($item['fieldName'] ?? ''));
			$bn  = basename((string) ($item['basename'] ?? ''));
			if (!$pid || !$fn || !$bn) continue;
			$byPage[$pid][] = ['fieldName' => $fn, 'basename' => $bn, 'n' => ++$counter];
		}
		if (!$byPage) return $this->jsonError('No valid items');

		$succeeded   = 0;
		// Track every successful row so the match-aware check at the
		// end can ask "which of these now fall out of the filter?".
		// Renamed rows record the NEW basename (the key the client
		// holds in its DOM after re-render).
		$succeededKeys = [];
		// {oldKey: newKey} map of basename renames so the client can
		// update its persistent selection Set across the rename
		// instead of clearing it. Only populated by the rename branch
		// (other subfields don't change basename).
		$renamed = [];
		// Raw referencing-page ids whose embeds were rewritten by a rename in
		// this batch — reindexed once after the cache clear (field-only saves
		// skip the Pages::saved usage hook).
		$renamedRefPageIds = [];
		$failed      = [];
		$tagsCfg     = $this->getTagsConfig();
		// New tags entered on mode-3 ("predefined + own") fields during this
		// batch, per field — promoted into each field's predefined list once at
		// the end so they're offered on every image.
		$mode3TagTokens = [];   // fieldName => [tag => true]
		$imageFields = $this->discoverImageFields();
		// Total used by the (N) placeholder. Matches $counter from
		// the byPage build above — i.e. the number of items the
		// client sent that resolved to a valid pageId/field/basename.
		$totalItems  = $counter;

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

				// Same context bag for every write path in this batch
				// — counter + total are per-item, page + field + date
				// shared. Cheap to build (date is memoized).
				$ctx = $this->buildPlaceholderCtx($page, $fn, (int) $it['n'], $totalItems);

				// basename is the identity, not an editable subfield —
				// editableSubfields() rightly excludes it. Branch the
				// rename path before that gate.
				if ($subfield === 'basename') {
					$img = $this->resolvePageimage($page, $fn, $bn);
					if (!$img) {
						$failed[] = sprintf('Image %s not found in %d.%s', $bn, $pid, $fn);
						continue;
					}
					$renameResult = $this->performRename($img, $page, $fn, $value, $ctx);
					if (!$renameResult['ok']) {
						$failed[] = sprintf('Rename %s in %s: %s', $bn, $fn, $renameResult['error']);
						continue;
					}
					if (empty($renameResult['unchanged'])) {
						$fieldsTouched[$fn] = true;
					}
					foreach ($renameResult['embedsRefPageIds'] ?? [] as $rid) {
						$renamedRefPageIds[(int) $rid] = true;
					}
					$succeeded++;
					$newBn = (string) $renameResult['basename'];
					$succeededKeys[] = $this->rowKey($pid, $fn, $newBn);
					if ($newBn !== $bn) {
						$renamed[$this->rowKey($pid, $fn, $bn)]
							= $this->rowKey($pid, $fn, $newBn);
					}
					continue;
				}

				if (!in_array($subfield, $this->editableSubfields($fn), true)) {
					// In a batch, the broadcast can naturally hit rows
					// whose image field doesn't carry that subfield —
					// "author" on a "lead_image" that wasn't configured
					// with the custom. Not a user error and not a save
					// failure; just nothing to do for that row.
					$succeeded++;
					$succeededKeys[] = $this->rowKey($pid, $fn, $bn);
					continue;
				}

				$img = $this->resolvePageimage($page, $fn, $bn);
				if (!$img) {
					$failed[] = sprintf('Image %s not found in %d.%s', $bn, $pid, $fn);
					continue;
				}

				// Typed custom subfields (checkbox / date / select):
				// coerce + set directly. Replace-only — the client sends a
				// single scalar, no Add / Remove for these.
				$customTypeBulk = $this->getCustomTypes()[$subfield] ?? null;
				if (in_array($customTypeBulk, ['checkbox', 'date', 'number', 'select', 'page'], true)) {
					$field = $this->wire('fields')->get($subfield);
					$img->set($subfield, $this->coerceCustomValue($page, $field, $customTypeBulk, (string) $value));
					$fieldsTouched[$fn] = true;
					$succeeded++;
					$succeededKeys[] = $this->rowKey($pid, $fn, $bn);
					continue;
				}

				// Placeholder resolution runs BEFORE any Add-merging —
				// (d) / (t) / (n) etc. become part of the literal value
				// before the pipeline treats it as a single string.
				// Skipped for tags (any mode): tags are token sets,
				// not prose; placeholder expansion would land as a
				// literal "2026-05-27"-style tag, not useful metadata.
				$itemValue = $subfield === 'tags'
					? (string) $value
					: $this->resolveRenamePattern((string) $value, $ctx);

				if ($subfield === 'tags') {
					// Whitelist gate per item: tag config can differ per field,
					// so resolve per row rather than rejecting the whole batch.
					$tagCfg   = $tagsCfg[$fn] ?? ['mode' => 0, 'allowed' => []];
					$resolved = $this->resolveBulkTagValue((string) $img->tags, $itemValue, $mode, $tagCfg);
					if ($resolved['rejected']) {
						$failed[] = sprintf('Tag(s) not in whitelist for %s: %s', $fn, implode(', ', $resolved['rejected']));
						continue;
					}
					// Promote newly-entered mode-3 tags into the field's
					// predefined list after the batch (offered on every image).
					foreach ($resolved['newTags'] as $t) $mode3TagTokens[$fn][$t] = true;
					$itemValue = $resolved['value'];
				} elseif ($mode === 'add') {
					// Add of an empty delta is a no-op — don't append a
					// trailing space to every selected row.
					if ($itemValue === '') {
						$succeeded++;
						$succeededKeys[] = $this->rowKey($pid, $fn, $bn);
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
					// Per-language placeholder resolution (skipped for
					// tags — same reasoning as the scalar branch).
					if ($subfield === 'tags') {
						$this->applyLangValues($img, $subfield, $langValues);
					} else {
						$resolvedLangValues = [];
						foreach ($langValues as $lk => $lv) {
							$resolvedLangValues[$lk] = $this->resolveRenamePattern((string) $lv, $ctx);
						}
						$this->applyLangValues($img, $subfield, $resolvedLangValues);
					}
				} else {
					$this->writeLangValue($img, $subfield, $itemValue);
				}
				$fieldsTouched[$fn] = true;
				$succeeded++;
				$succeededKeys[] = $this->rowKey($pid, $fn, $bn);
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

		// Refresh where-used rows for pages whose embeds a rename rewrote — after
		// the cache clear so the stem index reflects the new basenames.
		$this->reindexUsageForRefPages(array_keys($renamedRefPageIds));

		// Promote any new mode-3 tags into their fields' predefined lists, so
		// they're offered on every image. Hand the updated lists back keyed by
		// field for live client refresh.
		$tagsAllowed = [];
		foreach ($mode3TagTokens as $fn => $tokenSet) {
			$tagsAllowed[$fn] = array_values(
				$this->registerFieldTags($fn, implode(' ', array_keys($tokenSet)))
			);
		}

		// Match-aware UX: report which of the just-saved rows no
		// longer pass the active filter so the client can fade them.
		$match = $this->matchTouchedRows($succeededKeys);

		return $this->jsonResponse([
			'ok'        => true,
			'succeeded' => $succeeded,
			'failed'    => $failed,
			'vanished'  => $match['vanished'],
			'newTotal'  => $match['newTotal'],
			'renamed'   => (object) $renamed,
			'tagsAllowed' => (object) $tagsAllowed,
		]);
	}

	/**
	 * Pure resolver for a bulk tag write on one row: given the row's existing
	 * tag string, the user's input, the mode (replace / add / remove) and the
	 * field's tag config, return the final tag-string value, the new tokens to
	 * promote into a mode-3 predefined list, and any whitelist-rejected tokens
	 * (mode 2). The caller owns the side effects (fail on rejected, accumulate
	 * newTags); no DOM / save / page state here.
	 *
	 * @param array{mode:int,allowed:array<int,string>} $tagCfg
	 * @return array{value:string,newTags:array<int,string>,rejected:array<int,string>}
	 */
	protected function resolveBulkTagValue(string $existingTags, string $input, string $mode, array $tagCfg): array {
		$tokens   = $this->splitTags($input);
		$rejected = [];
		$newTags  = [];
		if ($tagCfg['mode'] === 2) {
			$rejected = array_values(array_diff($tokens, $tagCfg['allowed']));
		} elseif ($tagCfg['mode'] === 3 && $mode !== 'remove') {
			$newTags = $tokens;
		}
		if ($mode === 'add') {
			// Union with the row's existing tags, dedup.
			$existing = $this->splitImageTags($existingTags);
			$tokens   = array_values(array_unique(array_merge($existing, $tokens)));
		} elseif ($mode === 'remove') {
			// Set difference: drop every listed token from the row's set.
			$existing = $this->splitImageTags($existingTags);
			$drop     = array_flip($tokens);
			$tokens   = array_values(array_filter($existing, function ($t) use ($drop) {
				return !isset($drop[$t]);
			}));
		}
		return ['value' => implode(' ', $tokens), 'newTags' => $newTags, 'rejected' => $rejected];
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
	 * and chosen page size) to $user->meta('imageLibraryPrefs'). JS
	 * debounces calls here whenever the user toggles a checkbox,
	 * drag-reorders a column, or picks a different page size, and
	 * always sends the full state so the saved record stays
	 * consistent. Cross-device by design — the same user on a
	 * different browser sees the same layout + page size on next
	 * load.
	 */
	public function ___executeUserPrefs() {
		$this->beginJsonPost();

		$raw = (string) $this->wire('input')->post('prefs');
		$data = $raw !== '' ? json_decode($raw, true) : null;
		if (!is_array($data)) {
			return $this->jsonError('Invalid payload');
		}

		$sanitizer = $this->wire('sanitizer');
		$clean = [
			'columns'     => ['visible' => [], 'order' => []],
			'pageSize'    => null,
			'bookmarks'   => [],
			'collections' => [],
			'thumbScale'  => null,
			'viewMode'    => null,
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
		// Bookmarks + collections are TEAM-wide now (executeSharedPrefs); the
		// per-user prefs blob no longer stores them, so we deliberately ignore
		// any bookmarks/collections in this payload and keep them empty.
		if (isset($data['thumbScale'])) {
			$ts = (float) $data['thumbScale'];
			if ($ts >= self::THUMB_SCALE_MIN && $ts <= self::THUMB_SCALE_MAX) {
				$clean['thumbScale'] = round($ts, 2);
			}
		}
		if (isset($data['viewMode']) && in_array($data['viewMode'], $this->viewModes(), true)) {
			$clean['viewMode'] = (string) $data['viewMode'];
		}

		$this->wire('user')->meta('imageLibraryPrefs', $clean);
		return $this->jsonResponse(['ok' => true]);
	}

	/**
	 * Write the team-wide SHARED bookmarks + collections to module config.
	 * Same payload shape as executeUserPrefs (bookmarks[], collections[]),
	 * but gated by canManageShared() and persisted via saveConfig so every
	 * user with library access reads the result through getSharedPrefs().
	 * The full list is replaced on each call (the client sends the desired
	 * end-state), other config keys are preserved.
	 */
	public function ___executeSharedPrefs() {
		$this->beginJsonPost();
		if (!$this->canManageShared()) {
			return $this->jsonError('Not allowed', 403);
		}

		$raw = (string) $this->wire('input')->post('prefs');
		$data = $raw !== '' ? json_decode($raw, true) : null;
		if (!is_array($data)) {
			return $this->jsonError('Invalid payload');
		}

		$sanitizer = $this->wire('sanitizer');
		$bookmarks = [];
		$collections = [];
		if (isset($data['bookmarks']) && is_array($data['bookmarks'])) {
			foreach ($data['bookmarks'] as $b) {
				$clean = $this->sanitizeBookmark($b);
				if ($clean !== null) $bookmarks[] = $clean;
				if (count($bookmarks) >= self::COLLECTION_MAX) break;
			}
		}
		if (isset($data['collections']) && is_array($data['collections'])) {
			foreach ($data['collections'] as $c) {
				$col = $this->sanitizeCollection($c);
				if ($col !== null) $collections[] = $col;
				if (count($collections) >= self::COLLECTION_MAX) break;
			}
		}

		$cfg = $this->wire('modules')->getConfig($this);
		if (!is_array($cfg)) $cfg = [];
		$cfg['sharedBookmarks']   = $bookmarks;
		$cfg['sharedCollections'] = $collections;
		$this->wire('modules')->saveConfig($this, $cfg);

		return $this->jsonResponse(['ok' => true]);
	}

	/**
	 * Library-wide tag management PW itself has no tool for: rename a tag (fix a
	 * typo) or delete it, across the field's predefined list AND every image that
	 * carries it. POST + CSRF, manager-gated. Params:
	 *   op    = 'rename' | 'delete'
	 *   field = image field name
	 *   tag   = the existing tag
	 *   newTag= replacement (rename only)
	 *   apply = '0' → preview only (return affected image count)
	 *           '1' → apply (retag/untag images, update tagsList)
	 */
	public function ___executeTagBulk() {
		$this->beginJsonPost();
		if (!$this->canManageShared()) {
			return $this->jsonError('Not allowed', 403);
		}

		$input     = $this->wire('input');
		$sanitizer = $this->wire('sanitizer');
		$field  = $sanitizer->fieldName((string) $input->post('field'));
		$op     = (string) $input->post('op');
		$oldTag = trim((string) $input->post('tag'));
		$apply  = (int) $input->post('apply') === 1;

		if (!in_array($field, $this->discoverImageFields(), true)) {
			return $this->jsonError('Field is not a managed image field');
		}
		if (!in_array($op, ['rename', 'delete'], true) || $oldTag === '') {
			return $this->jsonError('Invalid request');
		}

		$newTag = '';
		if ($op === 'rename') {
			// Same charset PW enforces for tags: spaces → underscore, then keep
			// letters / digits / underscore / hyphen.
			$newTag = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '_', trim((string) $input->post('newTag'))));
			if ($newTag === '') return $this->jsonError('New tag is empty');
			// Exact compare so a case-only fix ("poppy" → "Poppy") is allowed —
			// PW's tag API is case-insensitive on the key but preserves the stored
			// case, so removeTag(old) + addTag(new) actually changes the casing.
			if ($newTag === $oldTag) {
				return $this->jsonError('New tag is unchanged');
			}
		}

		$affected = $this->findImagesWithTag($field, $oldTag);
		$total = 0;
		foreach ($affected as $bns) $total += count($bns);

		if (!$apply) {
			return $this->jsonResponse(['ok' => true, 'count' => $total]);
		}

		// Apply to every affected image via PW's own tag API (case-insensitive,
		// sanitising). Skip pages the user can't edit.
		@set_time_limit(180);
		$pages   = $this->wire('pages');
		$changed = 0;
		foreach (array_keys($affected) as $pid) {
			$page = $pages->get((int) $pid);
			if (!$page->id || !$page->editable()) continue;
			$page->of(false);
			$val = $page->getUnformatted($field);
			if (!$val) continue;
			$items = ($val instanceof Pageimage) ? [$val] : $val;   // Pageimages iterable
			$touched = false;
			foreach ($items as $img) {
				if (!$img instanceof Pageimage || !$img->hasTag($oldTag)) continue;
				$img->removeTag($oldTag);
				if ($op === 'rename') $img->addTag($newTag);
				$touched = true;
				$changed++;
			}
			if ($touched) {
				try { $page->save($field); } catch (\Throwable $e) {
					$this->wire('log')->error('ImageLibrary: tag bulk save failed for page ' . $pid . ': ' . $e->getMessage());
				}
			}
		}

		$allowed = $this->updateFieldTagList($field, $oldTag, $op === 'rename' ? $newTag : null);
		$this->wire('cache')->deleteFor($this);   // tags changed → invalidate row cache

		return $this->jsonResponse([
			'ok'          => true,
			'op'          => $op,
			'field'       => $field,
			'oldTag'      => $oldTag,
			'newTag'      => $newTag,
			'count'       => $changed,
			'tagsAllowed' => array_values($allowed),
		]);
	}

	/**
	 * Canonical bookmark query string: only filter-shaped params
	 * (q, template, field, tags, no_desc, no_tags, no_custom_*),
	 * empty values dropped, keys alphabetically sorted. Same shape
	 * the JS emits, so server-side bookmark matching ("which tab is
	 * active for the current URL?") is a straight string compare.
	 *
	 * @param string $qs Full query string (with or without leading "?").
	 */
	protected function canonicalizeBookmarkQs(string $qs): string {
		$qs = ltrim($qs, '?');
		if ($qs === '') return '';
		parse_str($qs, $params);
		$keep = [];
		foreach ($params as $k => $v) {
			$k = (string) $k;
			$ok = in_array($k, ['q', 'template', 'field', 'tags', 'no_desc', 'no_tags', 'dupes'], true)
				|| strncmp($k, 'no_custom_', 10) === 0;
			if (!$ok) continue;
			if (is_array($v)) {
				$v = implode(',', array_values($v));
			}
			$v = (string) $v;
			if ($v === '') continue;
			$keep[$k] = $v;
		}
		if (!$keep) return '';
		ksort($keep);
		return '?' . http_build_query($keep);
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
		// Display set: the full enumeration MINUS page-version repeater items
		// (those are kept for the dedup engine via loadImageRowsAll, but must
		// not clutter the library / inflate duplicate counts).
		$rows = array_values(array_filter(
			$this->loadImageRowsAll(),
			static fn($r) => empty($r['isVersionItem'])
		));

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
	 * The full flat image-row enumeration INCLUDING page-version repeater items
	 * (each marked with isVersionItem). Cached. Used by the dedup engine (scan
	 * + orphan-prune) so version-copy files also get hardlinked; the display
	 * loadRows() filters the version items out.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function loadImageRowsAll(): array {
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
			// Repeater / RepeaterMatrix integration: an image inside a
			// repeater field lives on a hidden repeater_<field> page,
			// not on the editor's mental "host" page. Resolve those
			// rows to their owner page (and mark version items) BEFORE we
			// cache, so sort by pageTitle, the template filter, and the
			// table's Page link all operate on the owner. The original
			// pageId stays on the row — that's the storage truth the
			// save / rename endpoints need to write to.
			$rows = $this->resolveRepeaterRows($rows);
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
		// Bump suffix when the cached row shape changes:
		//   v2: multilang values as raw {langId: value} arrays (was JSON)
		//   v3: multilang round-trip in export / import
		//   v4: repeater pages resolved to owner (pageTitle / templateId
		//       overridden; ownerPageId + repeaterField added)
		//   v5: cached pageTitle for repeater rows is default-language
		//       (was leaking the cache-populator's user language across
		//       editors); hydrateSlice sets the per-request user-
		//       language title on top.
		//   v6: created + modified Pagefile timestamps added to every
		//       row (sortable, filterable, displayable as date columns).
		//   v7: created + modified stored as DATETIME strings, not
		//       Unix-int casts (PW's DB schema is DATETIME; v6 cast
		//       lost everything past the year).
		//   v8: enumeration selector gained check_access=0 (buildSelector) so
		//       the row set is access-independent — a fresh key discards any
		//       cache poisoned by an earlier guest/cron-context rebuild.
		return 'rows-v8-' . substr(md5((string) json_encode($keyData)), 0, 16);
	}

	/**
	 * Resolve repeater / RepeaterMatrix rows to their owning user-
	 * facing page. Walked once over the freshly-flattened row list:
	 *
	 *   1. Gather pageIds of rows whose templateId is in the
	 *      repeater-template set.
	 *   2. Batch-load those repeater pages via getById().
	 *   3. For each, walk getForPageRoot() up through any nested
	 *      repeater containers until a non-repeater owner shows up.
	 *   4. Override pageTitle + templateId on the row with the
	 *      owner's, and record ownerPageId + repeaterField so the
	 *      hydrate step can build display URLs and the table can
	 *      annotate the Field column with the repeater context.
	 *
	 * pageId itself stays put — the image lives on the repeater
	 * page in PW's data model, so save / rename endpoints continue
	 * to target the right slot.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	protected function resolveRepeaterRows(array $rows): array {
		if (!$rows) return $rows;
		$repeaterIds = $this->repeaterTemplateIds();
		if (!$repeaterIds) return $rows;

		$repeaterPageIds = [];
		foreach ($rows as $r) {
			if (isset($repeaterIds[(int) $r['templateId']])) {
				$repeaterPageIds[(int) $r['pageId']] = true;
			}
		}
		if (!$repeaterPageIds) return $rows;

		$ownerByRepeaterId = [];
		$repeaterPages = $this->wire('pages')->getById(array_keys($repeaterPageIds));

		// Page Versions: a versioned page keeps a SEPARATE set of repeater-item
		// pages per version, under a version-specific container named
		// "for-page-<id>-v<n>" (live items live under "for-page-<id>"). findRaw
		// enumerates those version items too. We MARK them ($row['isVersionItem'])
		// rather than drop them: the display (loadRows) filters them out so the
		// library shows only live content, but the dedup engine still sees them
		// so their byte-identical files get hardlinked (real, invisible saving).
		$versionItemIds = [];
		foreach ($repeaterPages as $rp) {
			$parent = $rp->parent;
			if ($parent && $parent->id && preg_match('/-v\d+$/', (string) $parent->name)) {
				$versionItemIds[(int) $rp->id] = true;
			}
		}

		// Title cached in the default language so the row cache stays
		// language-neutral (one cache entry for all editors). The
		// editor's own-language title is set in hydrateSlice() per
		// request — that path is what the user actually sees.
		$defaultLang = $this->wire('languages') ? $this->wire('languages')->getDefault() : null;
		foreach ($repeaterPages as $rp) {
			$owner = method_exists($rp, 'getForPageRoot') ? $rp->getForPageRoot() : null;
			if (!$owner || !$owner->id || $owner->id === $rp->id) continue;
			$field = method_exists($rp, 'getForField') ? $rp->getForField() : null;
			$ownerByRepeaterId[(int) $rp->id] = [
				'ownerPageId'    => (int) $owner->id,
				'ownerTitle'     => $this->defaultLangTitle($owner, $defaultLang),
				'ownerTemplate'  => (int) $owner->template->id,
				'repeaterField'  => $field ? (string) $field->name : '',
			];
		}
		if (!$ownerByRepeaterId) return $rows;

		foreach ($rows as &$r) {
			$pid = (int) $r['pageId'];
			if (!isset($ownerByRepeaterId[$pid])) continue;
			$info = $ownerByRepeaterId[$pid];
			$r['ownerPageId']   = $info['ownerPageId'];
			$r['repeaterField'] = $info['repeaterField'];
			// Override pageTitle + templateId so sort / filter / search
			// operate on the owner. Original pageId stays.
			$r['pageTitle']     = $info['ownerTitle'];
			$r['templateId']    = $info['ownerTemplate'];
		}
		unset($r);

		// Mark page-version repeater items (kept for the dedup engine; the
		// display path filters them out).
		if ($versionItemIds) {
			foreach ($rows as &$r) {
				if (isset($versionItemIds[(int) $r['pageId']])) $r['isVersionItem'] = true;
			}
			unset($r);
		}

		return $rows;
	}

	/**
	 * Pull a page's title in the default language, regardless of the
	 * current user's admin language. Used when writing language-
	 * neutral values into the row cache; hydrateSlice() later
	 * overrides for display with the editor's own language.
	 */
	protected function defaultLangTitle(Page $page, ?Language $defaultLang): string {
		$title = $page->title;
		if ($defaultLang && is_object($title) && method_exists($title, 'getLanguageValue')) {
			$val = (string) $title->getLanguageValue($defaultLang);
			if ($val !== '') return $val;
		}
		return (string) $title;
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

	/**
	 * Promote freshly-entered tags into a field's predefined list (mode 3,
	 * "predefined + can input their own"). A tag a user invents while editing
	 * one image must become an offered tag for EVERY image of that field — so we
	 * append any token not already in the field's space-separated tagsList and
	 * save the field once. Only tokens valid as predefined tags (letters,
	 * digits, underscore, hyphen — per PW's own tagsList rule) are added.
	 * Returns the full, updated predefined list so the caller can hand it back
	 * to the client for live refresh. No-op (returns current list) when nothing
	 * is new.
	 *
	 * @return array<int,string>
	 */
	protected function registerFieldTags(string $fieldName, string $tagsValue): array {
		$field = $this->wire('fields')->get($fieldName);
		if (!$field || !($field->type instanceof FieldtypeImage)) return [];

		$list = $this->splitTags((string) $field->tagsList);
		$have = array_flip($list);
		$added = false;
		foreach ($this->splitTags($tagsValue) as $t) {
			if (isset($have[$t])) continue;
			if (!preg_match('/^[A-Za-z0-9_-]+$/', $t)) continue;   // PW tagsList charset
			$have[$t] = true;
			$list[] = $t;
			$added = true;
		}
		if ($added) {
			usort($list, 'strcasecmp');   // keep the stored list alphabetical
			try {
				$field->set('tagsList', implode(' ', $list));
				$this->wire('fields')->save($field);
			} catch (\Throwable $e) {
				// The tag is already stored on the image; failing to promote it
				// into the field list must not fail the whole save.
				$this->wire('log')->error('ImageLibrary: tagsList update failed for '
					. $fieldName . ': ' . $e->getMessage());
			}
		}
		usort($list, 'strcasecmp');
		return $list;
	}

	/**
	 * Rename or delete a tag in a field's predefined list (tagsList). Drops the
	 * old tag (case-insensitively, incl. any case variants), and for a rename
	 * appends the new tag if valid + not already present. Returns the updated
	 * list. The per-image tags are handled separately by the caller.
	 *
	 * @return array<int,string>
	 */
	protected function updateFieldTagList(string $fieldName, string $oldTag, ?string $newTag): array {
		$field = $this->wire('fields')->get($fieldName);
		if (!$field || !($field->type instanceof FieldtypeImage)) return [];

		$oldLc = mb_strtolower($oldTag);
		$newLc = $newTag !== null ? mb_strtolower($newTag) : '';
		$out = [];
		$seen = [];
		$hasNew = false;
		foreach ($this->splitTags((string) $field->tagsList) as $t) {
			if (mb_strtolower($t) === $oldLc) continue;          // drop the old tag
			$lc = mb_strtolower($t);
			if (isset($seen[$lc])) continue;
			$seen[$lc] = true;
			if ($newLc !== '' && $lc === $newLc) $hasNew = true;
			$out[] = $t;
		}
		if ($newTag !== null && $newTag !== '' && !$hasNew && preg_match('/^[A-Za-z0-9_-]+$/', $newTag)) {
			$out[] = $newTag;
		}
		usort($out, 'strcasecmp');   // keep the stored list alphabetical
		try {
			$field->set('tagsList', implode(' ', $out));
			$this->wire('fields')->save($field);
		} catch (\Throwable $e) {
			$this->wire('log')->error('ImageLibrary: tagsList update failed for ' . $fieldName . ': ' . $e->getMessage());
		}
		return $out;
	}

	/**
	 * Live images of $fieldName that carry $tag (case-insensitive), grouped
	 * pageId => [basename, …]. Read from the cached row enumeration, so it's the
	 * same set the library shows (no version copies). Drives both the affected-
	 * count preview and the apply pass of the tag rename/delete.
	 *
	 * @return array<int,array<int,string>>
	 */
	protected function findImagesWithTag(string $fieldName, string $tag): array {
		$tagLc = mb_strtolower($tag);
		$out = [];
		foreach ($this->loadRows() as $r) {
			if (($r['fieldName'] ?? '') !== $fieldName) continue;
			foreach ($this->splitImageTags((string) ($r['tags'] ?? '')) as $t) {
				if (mb_strtolower($t) === $tagLc) {
					$out[(int) $r['pageId']][] = (string) $r['basename'];
					break;
				}
			}
		}
		return $out;
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
		// include=unpublished returns published + hidden + UNPUBLISHED, still
		// excludes trash — editors need to see images on draft / unpublished
		// pages too (they manage them like any other page).
		//
		// check_access=0 is essential: findRaw() applies front-end view access by
		// default, so the SAME selector returns fewer pages when run as a guest
		// (e.g. an hourly LazyCron maintenance pass triggered by a front-end
		// visitor) than as the superuser viewing the admin. The flattened row
		// list is cached GLOBALLY (one key per discovery state, not per user), so
		// whichever request rebuilds the cache first decides what everyone sees —
		// a guest-context rebuild would poison it with a truncated set (and make
		// pruneOrphanedRows delete the "missing" pages' fingerprints, collapsing
		// the duplicate view). The library is an admin audit tool gated by its own
		// image-library-access permission; per-page edit rights are still enforced
		// separately. So enumerate EVERY managed image regardless of viewer access.
		return 'template=' . implode('|', $eligibleTemplates) . ', include=unpublished, check_access=0';
	}

	/**
	 * Flatten the findRaw result into one row per (pageId, fieldName, basename).
	 *
	 * @param array<int|string,mixed> $rawData
	 * @param array<int,string> $imageFields
	 * @return array<int,array<string,mixed>>
	 */
	protected function flattenRows(array $rawData, array $imageFields): array {
		$rows = [];
		// A physical image is uniquely (pageId, fieldName, basename). Guard
		// against the same file being emitted more than once — e.g. duplicate
		// rows in field_<name> (seen with RepeaterMatrix) would otherwise show
		// as phantom "extra copies" and inflate duplicate clusters.
		$seen = [];
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
					$seenKey  = $this->hashKey($pageId, $fieldName, $basename);
					if (isset($seen[$seenKey])) continue;   // same physical file already emitted
					$seen[$seenKey] = true;
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
						// Pagefile timestamps — created = upload,
						// modified = last metadata change. PW stores
						// both as MySQL DATETIME strings (e.g.
						// "2026-05-27 17:00:45"), NOT Unix seconds —
						// (int) casting would lose everything past
						// the year. Keep them as strings: lex sort
						// matches chronological sort for ISO-style
						// datetime, and the format helper parses on
						// render.
						'created'     => (string) ($img['created']  ?? ''),
						'modified'    => (string) ($img['modified'] ?? ''),
						'ext'         => pathinfo($basename, PATHINFO_EXTENSION),
						// Custom-fields-on-images are NOT findRaw columns — a bare
						// image-field request returns SELECT *, i.e. the native
						// Pagefile columns only (data, description, filesize, ratio,
						// sort, …). Custom subfield values are hydrated separately
						// via the Pageimage API in hydrateSlice, so leave this empty
						// here rather than misclassifying stray native columns.
						'custom'      => [],
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
	/**
	 * Parse a raw querystring (typically the client's location.search
	 * round-tripped via a hidden filterQs POST field) into the same
	 * filter array shape readFilterInput emits. Used by the save /
	 * bulk / rename AJAX endpoints to ask "does this row still match
	 * the user's currently-applied filters after the change?" so the
	 * client can fade rows out that fall out of scope.
	 *
	 * @param array<int,string> $imageFields
	 * @param array<int,string> $eligibleTemplates
	 * @param array<int,string> $customCols
	 * @return array<string,mixed>
	 */
	protected function parseFilterQs(string $qs, array $imageFields, array $eligibleTemplates, array $customCols = []): array {
		$qs = ltrim($qs, '?');
		$params = [];
		if ($qs !== '') parse_str($qs, $params);
		return $this->buildFilters($params, $imageFields, $eligibleTemplates, $customCols);
	}

	/**
	 * The single source of truth for turning a raw param map (from a parsed
	 * querystring OR from $input->get) into the canonical filter array
	 * applyRowFilters consumes. parseFilterQs and readFilterInput differ only in
	 * WHERE the params come from; this keeps their output byte-identical, which
	 * the match-aware "row vanished" check relies on.
	 *
	 * @param array<string,mixed> $params
	 * @param array<int,string> $imageFields
	 * @param array<int,string> $eligibleTemplates
	 * @param array<int,string> $customCols
	 * @return array<string,mixed>
	 */
	protected function buildFilters(array $params, array $imageFields, array $eligibleTemplates, array $customCols = []): array {
		$template = (string) ($params['template'] ?? '');
		$field    = (string) ($params['field'] ?? '');

		$noCustom = [];
		foreach ($customCols as $name) {
			if (!empty($params['no_custom_' . $name])) $noCustom[$name] = true;
		}

		// Tags: comma form (?tags=foo,bar) or bracket array form (?tags[]=foo&tags[]=bar).
		$tags = [];
		$rawTags = $params['tags'] ?? '';
		if (is_array($rawTags)) {
			foreach ($rawTags as $t) {
				$t = trim((string) $t);
				if ($t !== '') $tags[] = $t;
			}
		} elseif (is_string($rawTags) && $rawTags !== '') {
			foreach ($this->splitTags($rawTags) as $t) $tags[] = $t;
		}
		$tags = array_values(array_unique($tags));

		$coll = (string) ($params['coll'] ?? '');

		return [
			'q'         => trim((string) ($params['q'] ?? '')),
			'template'  => in_array($template, $eligibleTemplates, true) ? $template : '',
			'field'     => in_array($field, $imageFields, true) ? $field : '',
			'no_desc'   => !empty($params['no_desc']),
			'no_tags'   => !empty($params['no_tags']),
			'no_custom' => $noCustom,
			'dupes'     => !empty($params['dupes']),
			'tags'      => $tags,
			'coll'      => $this->sanitizeIdToken($coll),
			'sel'       => $coll !== '' ? $this->resolveCollectionKeys($coll) : [],
		];
	}

	/**
	 * Canonical row-identity key shared with the client JS:
	 * "pageId:fieldName:basename". Centralised so the format lives in
	 * one place — the table's data-key, the selection set, the
	 * match-aware vanish checks and the bulk / delete result lists all
	 * depend on it matching byte-for-byte.
	 */
	protected function rowKey(int $pageId, string $fieldName, string $basename): string {
		return sprintf('%d:%s:%s', $pageId, $fieldName, $basename);
	}

	/**
	 * The INTERNAL hash-map identity key for an image: "pageId\0fieldName\0
	 * basename". The NUL-joined twin of rowKey() (which uses ':' for the
	 * client-facing key) — used purely as a PHP array key for the dedup /
	 * usage maps, where a NUL separator can't collide with field names or
	 * basenames. Typed params keep the casting consistent across every call
	 * site (a row's pageId may arrive as int or numeric string).
	 */
	protected function hashKey(int $pageId, string $fieldName, string $basename): string {
		return $pageId . "\0" . $fieldName . "\0" . $basename;
	}

	/**
	 * Strip a collection / bookmark id (or parent id) down to its safe token
	 * charset ([A-Za-z0-9] only). These ids are client-supplied (POSTed prefs),
	 * so every read sanitises before use; centralised so the charset can't
	 * drift between call sites.
	 */
	protected function sanitizeIdToken($value): string {
		return preg_replace('/[^a-z0-9]/i', '', (string) $value) ?? '';
	}

	/**
	 * Split a Pageimage tags string into tokens. Image tags are WHITESPACE-
	 * separated (a single tag may itself contain a comma), so this is
	 * deliberately NOT splitTags() — that also splits on commas for the
	 * comma-separated filter-input form.
	 *
	 * @return array<int,string>
	 */
	protected function splitImageTags(string $tags): array {
		return preg_split('/\s+/', $tags, -1, PREG_SPLIT_NO_EMPTY) ?: [];
	}

	/** Hard caps so a malicious / runaway payload can't bloat $user->meta. */
	const COLLECTION_MAX        = 200;   // collections per user
	const COLLECTION_KEYS_MAX   = 5000;  // images per collection

	/**
	 * Validate + canonicalise one image-identity key ("pageId:fieldName:
	 * basename"). Returns the rebuilt key, or '' if malformed — so stored /
	 * resolved keys always match the exact shape rowKey() emits for the grid.
	 */
	protected function sanitizeRowKey(string $key): string {
		$parts = explode(':', $key, 3);
		if (count($parts) !== 3) return '';
		$pageId = (int) $parts[0];
		$field  = $this->wire('sanitizer')->fieldName($parts[1]);
		$base   = basename($parts[2]);
		if ($pageId <= 0 || $field === '' || $base === '') return '';
		return $this->rowKey($pageId, $field, $base);
	}

	/**
	 * Validate one raw collection ({id, name, keys[]}) from client/meta into a
	 * clean record, or null if unusable. id is alnum, name is capped text, keys
	 * are sanitised + de-duplicated + capped.
	 *
	 * @param mixed $c
	 * @return array{id:string,name:string,keys:array<int,string>}|null
	 */
	/** A fresh alphanumeric id for a bookmark / folder (stable handle for
	 *  nesting). Hex from uniqid(), dot stripped, capped — same charset the
	 *  client's id generator emits. */
	protected function newBookmarkId(): string {
		return substr(str_replace('.', '', uniqid('', true)), 0, 12);
	}

	/**
	 * Validate one raw bookmark into a clean record, or null if unusable.
	 * A bookmark is now {id, name, qs, parent}: a FILTER bookmark carries a
	 * canonical qs (a saved filter, always a leaf), while a FOLDER is an empty
	 * container (qs === '') that only groups children — and is the only thing
	 * allowed to be a parent. id is generated when missing so legacy records
	 * (which had none) get a stable handle. Single team store, like collections.
	 *
	 * @param mixed $b
	 * @return array{id:string,name:string,qs:string,parent:string}|null
	 */
	protected function sanitizeBookmark($b): ?array {
		if (!is_array($b)) return null;
		$name = $this->wire('sanitizer')->text((string) ($b['name'] ?? ''), ['maxLength' => 80]);
		if ($name === '') return null;
		$id = $this->sanitizeIdToken($b['id'] ?? '');
		if ($id === '') $id = $this->newBookmarkId();
		$qs = $this->canonicalizeBookmarkQs((string) ($b['qs'] ?? ''));
		// Parent folder id for nesting; a bookmark can never be its own parent.
		// Structural validity (parent is a folder, depth cap) is enforced by the
		// manager UI; array order is the display order.
		$parent = $this->sanitizeIdToken($b['parent'] ?? '');
		if ($parent === $id) $parent = '';
		return ['id' => $id, 'name' => $name, 'qs' => $qs, 'parent' => $parent];
	}

	protected function sanitizeCollection($c): ?array {
		if (!is_array($c)) return null;
		$id   = $this->sanitizeIdToken($c['id'] ?? '');
		$name = $this->wire('sanitizer')->text((string) ($c['name'] ?? ''), ['maxLength' => 80]);
		if ($id === '' || $name === '') return null;

		// Parent collection id for nesting. Same id charset; a collection can
		// never be its own parent. Structural validity (parent exists, depth cap,
		// same store) is enforced by the manager UI; the array order is preserved
		// as the display order (a child sits right after its parent).
		$parent = $this->sanitizeIdToken($c['parent'] ?? '');
		if ($parent === $id) $parent = '';

		$keys = [];
		if (isset($c['keys']) && is_array($c['keys'])) {
			foreach ($c['keys'] as $k) {
				$clean = $this->sanitizeRowKey((string) $k);
				if ($clean !== '') $keys[$clean] = true;          // de-dupe
				if (count($keys) >= self::COLLECTION_KEYS_MAX) break;
			}
		}
		// Empty keys are allowed: a collection can be an empty container created
		// up-front (you then nest subgroups under it, or add images later). A
		// valid id + name is enough.
		return ['id' => $id, 'name' => $name, 'keys' => array_keys($keys), 'parent' => $parent];
	}

	/**
	 * Row-keys of the saved collection with the given id (empty if none). The
	 * source for the ?coll= grid filter — keys live in $user->meta, never the URL.
	 *
	 * A collection resolves to the UNION of its OWN keys and the keys of ALL its
	 * descendant collections (recursive). So a parent like "Flowers" that only
	 * groups colour subgroups shows every image in red ∪ yellow ∪ pink, plus
	 * any it holds directly. Leaves (no children) just return their own keys.
	 *
	 * @return array<int,string>
	 */
	/**
	 * Map each image row-key to the comma-joined names of the collections it
	 * appears under — UNION membership (own keys + sub-collections), in display
	 * order, same as the Collections column. Drives the text sort for that column.
	 *
	 * @return array<string,string>
	 */
	protected function collectionNamesByKey(): array {
		$out = [];
		$list = $this->getSharedPrefs()['collections'];
		foreach ($list as $coll) {
			$name = (string) ($coll['name'] ?? '');
			$cid  = (string) ($coll['id'] ?? '');
			if ($name === '' || $cid === '') continue;
			foreach ($this->collectionUnionKeys($cid, $list) as $k) {
				$out[(string) $k][] = $name;
			}
		}
		foreach ($out as $k => $names) {
			$out[$k] = implode(', ', $names);
		}
		return $out;
	}

	protected function resolveCollectionKeys(string $id): array {
		$id = $this->sanitizeIdToken($id);
		if ($id === '') return [];
		// Collections live in ONE team-wide store now (no personal split).
		return $this->collectionUnionKeys($id, $this->getSharedPrefs()['collections']);
	}

	/**
	 * Union of a collection's own keys plus every descendant's keys, de-duped.
	 * Depth-first over the parent→children relationship; cycle-safe via $seen.
	 *
	 * @param array<int,array<string,mixed>> $list  one store's collections
	 * @return array<int,string>
	 */
	protected function collectionUnionKeys(string $id, array $list): array {
		$keysById = [];
		$children = [];
		foreach ($list as $c) {
			$cid = (string) ($c['id'] ?? '');
			if ($cid === '') continue;
			$keysById[$cid] = $c['keys'] ?? [];
			$p = (string) ($c['parent'] ?? '');
			if ($p !== '') $children[$p][] = $cid;
		}
		$out  = [];
		$seen = [];
		$stack = [$id];
		while ($stack) {
			$cur = array_pop($stack);
			if (isset($seen[$cur])) continue;
			$seen[$cur] = true;
			foreach ($keysById[$cur] ?? [] as $k) $out[$k] = true;
			foreach ($children[$cur] ?? [] as $childId) {
				if (!isset($seen[$childId])) $stack[] = $childId;
			}
		}
		return array_keys($out);
	}

	/**
	 * Shared match-aware post-write step for executeSave / executeRename
	 * / executeBulk: read the client's current filter state (the
	 * filterQs POST field) and report which of the just-touched row keys
	 * no longer pass it, plus the new total. Lets each endpoint hand the
	 * client a stillMatches / vanished / newTotal payload without
	 * re-deriving the filter set inline.
	 *
	 * @param array<int,string> $keys touched row keys (rowKey() shape)
	 * @return array{vanished:array<int,string>,newTotal:int}
	 */
	protected function matchTouchedRows(array $keys): array {
		$imageFields       = $this->discoverImageFields();
		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);
		$customCols        = $this->collectCustomNames();
		$filters           = $this->parseFilterQs(
			(string) $this->wire('input')->post('filterQs'),
			$imageFields, $eligibleTemplates, $customCols
		);
		return $this->evaluateFilterTouchedRows($keys, $filters);
	}

	/**
	 * Match-check after a write: for the just-touched rows, work out
	 * which of them no longer belong in the current filtered view, and
	 * return the new total visible count.
	 *
	 * Returns {vanished: ["pageId:fieldName:basename", …], newTotal: N}.
	 * An empty filter set short-circuits — nothing can vanish from an
	 * unfiltered view, so we skip the loadRows + apply pass.
	 *
	 * @param array<int,string> $touchedKeys
	 * @param array<string,mixed> $filters
	 * @return array{vanished:array<int,string>,newTotal:int}
	 */
	protected function evaluateFilterTouchedRows(array $touchedKeys, array $filters): array {
		// No filters → nothing can vanish; skip the full reload.
		// (newTotal stays unknown; client doesn't need it in this case.)
		$anyFilter = ($filters['q'] ?? '') !== ''
			|| ($filters['template'] ?? '') !== ''
			|| ($filters['field'] ?? '') !== ''
			|| !empty($filters['no_desc'])
			|| !empty($filters['no_tags'])
			|| !empty($filters['no_custom'])
			|| !empty($filters['dupes'])
			|| !empty($filters['tags']);
		if (!$anyFilter) {
			return ['vanished' => [], 'newTotal' => -1];
		}

		$rows = $this->loadRows($filters);
		$rows = $this->applyRowFilters($rows, $filters);
		$rows = $this->applyTagFilter($rows, $filters['tags'] ?? []);

		$present = [];
		foreach ($rows as $r) {
			$k = $this->rowKey(
				(int) $r['pageId'],
				(string) $r['fieldName'],
				(string) $r['basename']
			);
			$present[$k] = true;
		}

		$vanished = [];
		foreach ($touchedKeys as $k) {
			if (!isset($present[$k])) $vanished[] = $k;
		}

		return [
			'vanished' => $vanished,
			'newTotal' => count($rows),
		];
	}

	protected function readFilterInput(array $imageFields, array $eligibleTemplates, array $customCols = []): array {
		$input  = $this->wire('input');
		// Collect the same keys parseFilterQs reads from a querystring, then run
		// the shared builder so both paths emit a byte-identical filter array.
		// Tags accepts either the comma form (?tags=foo,bar) or the bracket-array
		// form (?tags[]=foo&tags[]=bar) — buildFilters normalises both.
		$params = [
			'q'        => $input->get('q'),
			'template' => $input->get('template'),
			'field'    => $input->get('field'),
			'no_desc'  => $input->get('no_desc'),
			'no_tags'  => $input->get('no_tags'),
			'dupes'    => $input->get('dupes'),
			'tags'     => $input->get('tags'),
			'coll'     => $input->get('coll'),
		];
		foreach ($customCols as $name) {
			$params['no_custom_' . $name] = $input->get('no_custom_' . $name);
		}
		return $this->buildFilters($params, $imageFields, $eligibleTemplates, $customCols);
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

		if ($sort === '') {
			// Empty sort = "use the configured default sort". Don't
			// touch $dir — buildUrl omits sort+dir individually when
			// each matches its own default, so a URL like ?dir=desc
			// (default sort, flipped direction) must survive. Old
			// code blanket-reset both whenever sort was empty, which
			// killed the toggle-direction round-trip on the default
			// sort column.
			$sort = $this->getDefaultSort();
		} elseif (!in_array($sort, $whitelist, true)) {
			// Unknown sort key — fall back wholesale so an injected
			// $_GET can't smuggle arbitrary keys into the row lookup.
			$sort = $this->getDefaultSort();
			$dir  = $this->getDefaultSortDir();
		}
		if ($dir === '') $dir = $this->getDefaultSortDir();
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
	 * Populate $row['variationsCount'] on every given row by scanning each
	 * image's variation files. Used to sort the table by the Variations
	 * column: the count isn't in the cached rows, and unlike hydrateSlice
	 * (which only does the visible slice) a sort needs it on the full set.
	 * Pages are batch-loaded once; the per-image getVariations() filesystem
	 * scan is the cost, so this only runs when that sort is actually chosen.
	 *
	 * @param array<int,array<string,mixed>> $rows by reference
	 */
	protected function hydrateVariationCounts(array &$rows): void {
		if (!$rows) return;
		$idSet = [];
		foreach ($rows as $r) {
			if (!empty($r['pageId'])) $idSet[(int) $r['pageId']] = true;
		}
		$pagesById = [];
		foreach ($this->wire('pages')->getById(array_keys($idSet)) as $p) {
			$pagesById[$p->id] = $p;
		}
		foreach ($rows as &$r) {
			$r['variationsCount'] = 0;
			$page = $pagesById[(int) $r['pageId']] ?? null;
			if (!$page || !$page->id) continue;
			$img = $this->resolvePageimage($page, (string) $r['fieldName'], (string) $r['basename']);
			if (!$img) continue;
			$vars = $img->getVariations();
			$r['variationsCount'] = $vars ? $vars->count() : 0;
		}
		unset($r);
	}

	/**
	 * Parse a free-text query into REQUIRED, EXCLUDED and OPTIONAL terms for a
	 * tokenised, case-insensitive substring search (classic search-engine rules):
	 *   - a plain word is OPTIONAL — if any optional terms exist, at least one
	 *     must match (so "red rose" = red OR rose)
	 *   - a +word is REQUIRED — every one must match ("red +rose" = rose must
	 *     match AND, from the optional group, red too, i.e. both)
	 *   - a -word is EXCLUDED — the row must NOT contain it
	 *   - a "quoted phrase" (optionally signed) matches its words contiguously
	 * Lone signs and empty quotes are ignored. Terms are lower-cased so the
	 * caller matches them against a lower-cased haystack.
	 *
	 * @return array{0:array<int,string>,1:array<int,string>,2:array<int,string>} [required, excluded, optional]
	 */
	protected function parseSearchTerms(string $q): array {
		$q = trim($q);
		if ($q === '') return [[], [], []];
		$required = [];
		$excluded = [];
		$optional = [];
		// Token = optional +/- sign, then a "quoted phrase" or a run of non-space.
		if (!preg_match_all('/([+-]?)(?:"([^"]*)"|(\S+))/u', $q, $matches, PREG_SET_ORDER)) {
			return [[], [], []];
		}
		foreach ($matches as $tok) {
			$sign = $tok[1];
			// $tok[2] = quoted body (may be ''), $tok[3] = bare word.
			$term = ($tok[2] !== '') ? $tok[2] : ($tok[3] ?? '');
			$term = mb_strtolower(trim($term));
			if ($term === '') continue;
			if ($sign === '-')      $excluded[] = $term;
			elseif ($sign === '+')  $required[] = $term;
			else                    $optional[] = $term;
		}
		return [
			array_values(array_unique($required)),
			array_values(array_unique($excluded)),
			array_values(array_unique($optional)),
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
		// Collection recall (?coll=): narrow to the saved set FIRST, then let the
		// normal filters below apply WITHIN it — a collection can be filtered, so
		// coll and the filter params coexist rather than replace each other. Rows
		// whose images were since deleted/renamed simply don't match here.
		// Gate on whether a collection is being recalled at all — NOT on whether
		// it resolved to any keys. An empty collection (a container with no
		// images yet) must show NOTHING, not fall through to the full grid.
		$collActive = ((string) ($filters['coll'] ?? '')) !== '';
		if ($collActive) {
			$sel    = $filters['sel'] ?? [];
			$selSet = array_fill_keys($sel, true);
			$rows = array_values(array_filter($rows, function ($r) use ($selSet) {
				$key = ((int) $r['pageId']) . ':' . $r['fieldName'] . ':' . $r['basename'];
				return isset($selSet[$key]);
			}));
		}

		[$reqTerms, $excTerms, $optTerms] = $this->parseSearchTerms((string) $filters['q']);
		$hasQ     = $reqTerms !== [] || $excTerms !== [] || $optTerms !== [];
		$tplName  = (string) ($filters['template'] ?? '');
		$field    = $filters['field'];
		$noDesc   = $filters['no_desc'];
		$noTags   = $filters['no_tags'];
		$noCustom = $filters['no_custom'] ?? [];
		$dupes    = !empty($filters['dupes']);

		if (!$hasQ && $tplName === '' && $field === '' && !$noDesc && !$noTags && !$noCustom && !$dupes) {
			return $rows;
		}

		// "Duplicates only" — keep rows whose image is a byte-identical copy,
		// then collapse each cluster to ONE representative row (below) so a
		// duplicate is listed once, not once per copy. Needs a scan; the map
		// is empty before one. Keyed identity => content_hash.
		$dupKeyHashes = $dupes ? $this->loadDuplicateKeyHashes() : [];

		// Template filter operates at PHP level now (was SQL before caching),
		// so we resolve the name → id once and compare to row['templateId'].
		$tplId = 0;
		if ($tplName !== '') {
			$tpl = $this->wire('templates')->get($tplName);
			if ($tpl && $tpl->id) $tplId = (int) $tpl->id;
		}

		$filtered = array_values(array_filter($rows, function ($r) use (
			$hasQ, $reqTerms, $excTerms, $optTerms, $tplId, $field, $noDesc, $noTags, $noCustom, $dupes, $dupKeyHashes
		) {
			if ($dupes && !isset($dupKeyHashes[$this->hashKey((int) $r['pageId'], (string) $r['fieldName'], (string) $r['basename'])])) return false;
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
				// + terms ALL required; - terms NONE allowed; plain terms are an
				// OR group — if any exist, at least one must match.
				foreach ($reqTerms as $t) {
					if (mb_strpos($hay, $t) === false) return false;
				}
				foreach ($excTerms as $t) {
					if (mb_strpos($hay, $t) !== false) return false;
				}
				if ($optTerms) {
					$anyOpt = false;
					foreach ($optTerms as $t) {
						if (mb_strpos($hay, $t) !== false) { $anyOpt = true; break; }
					}
					if (!$anyOpt) return false;
				}
			}
			return true;
		}));

		// "Duplicates" filter — CONTEXTUAL: keep only images that still have
		// ≥2 byte-identical copies AMONG the already-filtered rows. A copy
		// whose twins were filtered out (e.g. they live in a field this filter
		// excludes) is not a duplicate in this view, so it's dropped too.
		if ($dupes) {
			$cnt = [];
			foreach ($filtered as $r) {
				$h = $dupKeyHashes[$this->hashKey((int) $r['pageId'], (string) $r['fieldName'], (string) $r['basename'])] ?? null;
				if ($h !== null) $cnt[$h] = ($cnt[$h] ?? 0) + 1;
			}
			$kept = [];
			foreach ($filtered as $r) {
				$h = $dupKeyHashes[$this->hashKey((int) $r['pageId'], (string) $r['fieldName'], (string) $r['basename'])] ?? null;
				if ($h !== null && ($cnt[$h] ?? 0) >= 2) $kept[] = $r;
			}
			return $kept;
		}

		return $filtered;
	}

	/**
	 * Map a custom-fields-on-images subfield to the editor type the
	 * table uses: textarea / text get inline widgets; checkbox / date /
	 * select (single-value FieldtypeOptions) get type-specific inline
	 * widgets; page (and multi-value Options) route to the native
	 * per-image editor. Drives both the cell display and the inline
	 * editor dispatch.
	 */
	protected function customEditorType(Field $f): string {
		$t = $f->type;
		// Order matters: FieldtypeDatetime / Checkbox / Float extend
		// FieldtypeInteger, so the Integer check has to come last.
		if ($t instanceof FieldtypeTextarea) return 'textarea';
		if ($t instanceof FieldtypeCheckbox) return 'checkbox';
		if ($t instanceof FieldtypeDatetime) return 'date';
		if ($t instanceof FieldtypePage)     return 'page';
		if ($t instanceof FieldtypeOptions)  return 'select'; // single + multi
		if ($t instanceof FieldtypeInteger) return 'number';
		return 'text';
	}

	/**
	 * True when a FieldtypeOptions field renders a single-value input
	 * (plain select / radios) rather than a multi-select. Multi-value
	 * inputs don't fit the one-string inline editor, so they fall back
	 * to the native editor.
	 */
	protected function isSingleValueInput(Field $f): bool {
		$cls = (string) $f->get('inputfieldClass');
		return $cls === '' || in_array($cls, ['InputfieldSelect', 'InputfieldRadios'], true);
	}

	/**
	 * Custom subfield name => editor type, computed once per request
	 * across every image field's declared subfields. Shared by the
	 * table render (cell + editor dispatch) and the read pipeline
	 * (typed display in hydrateSlice / bulkHydrateCustomFields).
	 *
	 * @return array<string,string>
	 */
	protected function getCustomTypes(): array {
		if ($this->customTypesCache === null) {
			$map = [];
			foreach ($this->getCustomByField() as $names) {
				foreach ($names as $n) {
					if (isset($map[$n])) continue;
					$f = $this->wire('fields')->get($n);
					$map[$n] = $f instanceof Field ? $this->customEditorType($f) : 'text';
				}
			}
			$this->customTypesCache = $map;
		}
		return $this->customTypesCache;
	}

	/**
	 * Human-readable label for a typed custom value — page title(s) for
	 * Page references, option title(s) for Select Options — so the cell
	 * shows something meaningful instead of an id or an object dump.
	 *
	 * @param mixed $val
	 */
	protected function customLabel($val): string {
		if ($val instanceof Page) {
			return (string) (((string) $val->title !== '') ? $val->title : $val->name);
		}
		if ($val instanceof PageArray) {
			$t = [];
			foreach ($val as $p) {
				$t[] = (string) (((string) $p->title !== '') ? $p->title : $p->name);
			}
			return implode(', ', array_filter($t, fn($s) => $s !== ''));
		}
		// SelectableOptionArray (FieldtypeOptions) and any other WireArray
		// of title-bearing items.
		if (is_object($val) && $val instanceof \IteratorAggregate) {
			$t = [];
			foreach ($val as $opt) {
				$t[] = (is_object($opt) && (string) $opt->title !== '')
					? (string) $opt->title
					: (string) $opt;
			}
			$t = array_filter($t, fn($s) => $s !== '');
			if ($t) return implode(', ', $t);
		}
		if (is_object($val) && isset($val->title) && (string) $val->title !== '') {
			return (string) $val->title;
		}
		return $this->normalizeDescription($val);
	}

	/**
	 * Read a custom subfield off a Pageimage as a DISPLAY value for the
	 * table, typed by the subfield's editor type:
	 *   - text / textarea → langValueToStorable (keeps the {langId:value}
	 *     shape the multilang editor tabs read)
	 *   - checkbox → "✓" or "" (empty renders the cell's "—" placeholder)
	 *   - date → formatted via formatTimestamp
	 *   - select / page / other → human label(s)
	 * Returns null when the image has no value for the subfield.
	 *
	 * @return mixed
	 */
	protected function readCustomValue(Pageimage $img, string $name) {
		$val = $img->get($name);
		if ($val === null) return null;
		switch ($this->getCustomTypes()[$name] ?? 'text') {
			case 'text':
			case 'textarea':
				return $this->langValueToStorable($val);
			case 'checkbox':
				return ((int) (string) $val) ? '✓' : '';
			case 'number':
				return (string) $val;
			case 'date':
				$ts = is_numeric($val) ? (int) $val : @strtotime((string) $val);
				if (!$ts) return '';
				$f = $this->wire('fields')->get($name);
				// Use the field's OWN output format so a date-only field
				// doesn't show a spurious 00:00 time.
				return ($f instanceof Field) ? $this->formatCustomDate($f, $ts) : $this->formatTimestamp($ts);
			default:
				return $this->customLabel($val);
		}
	}

	/**
	 * Editor-RAW value for an inline-editable typed custom subfield —
	 * the value the popup widget round-trips, distinct from the display
	 * label: checkbox → "1"/"0", date → "Y-m-d", select → option id.
	 * Only meaningful for checkbox / date / select.
	 */
	protected function readCustomRaw(Pageimage $img, string $name): string {
		$val = $img->get($name);
		if ($val === null) return '';
		switch ($this->getCustomTypes()[$name] ?? 'text') {
			case 'checkbox':
				return ((int) (string) $val) ? '1' : '0';
			case 'number':
				return (string) $val;
			case 'date':
				$ts = is_numeric($val) ? (int) $val : @strtotime((string) $val);
				if (!$ts) return '';
				$f = $this->wire('fields')->get($name);
				// datetime-local needs the time component; a date-only
				// field uses the bare date.
				return ($f instanceof Field && $this->dateHasTime($f))
					? date('Y-m-d\TH:i', $ts)
					: date('Y-m-d', $ts);
			case 'select':
				// FieldtypeOptions stores a SelectableOptionArray; the
				// widget keys on the option id(s), comma-joined for multi.
				if (is_object($val) && $val instanceof \IteratorAggregate) {
					$ids = [];
					foreach ($val as $o) {
						if (is_object($o) && isset($o->id)) $ids[] = (int) $o->id;
					}
					return implode(',', $ids);
				}
				return (string) $val;
			case 'page':
				// Selected page id(s), comma-joined for the <select>.
				if ($val instanceof Page) return (string) $val->id;
				if ($val instanceof PageArray) {
					$ids = [];
					foreach ($val as $p) $ids[] = (int) $p->id;
					return implode(',', $ids);
				}
				return (string) $val;
			default:
				return '';
		}
	}

	/**
	 * Selectable options for a FieldtypeOptions custom subfield, as a
	 * [{value:id,label:title}] list for the inline <select> widget.
	 *
	 * @return array<int,array{value:int,label:string}>
	 */
	protected function getCustomOptions(string $name): array {
		$f = $this->wire('fields')->get($name);
		if (!($f instanceof Field) || !($f->type instanceof FieldtypeOptions)) return [];
		$out = [];
		$opts = $f->type->getOptions($f);
		if ($opts) {
			foreach ($opts as $o) {
				$out[] = ['value' => (int) $o->id, 'label' => (string) $o->title];
			}
		}
		return $out;
	}

	/**
	 * Coerce an inline-typed-custom string value from the editor into
	 * the shape the Fieldtype stores: checkbox → 0/1, date → timestamp,
	 * select → SelectableOptionArray (via the Fieldtype's own
	 * sanitizeValue, which rejects non-allowed ids). Empty string clears.
	 *
	 * @param mixed return type varies by Fieldtype
	 */
	protected function coerceCustomValue(Page $page, ?Field $field, string $type, string $value) {
		if ($type === 'checkbox') {
			return ($value === '1' || $value === 'on' || $value === 'true') ? 1 : 0;
		}
		if ($value === '') return '';
		if ($type === 'date') {
			$ts = strtotime($value);
			return $ts !== false ? $ts : '';
		}
		$ids = array_values(array_filter(
			array_map('intval', explode(',', $value)),
			fn($i) => $i > 0
		));
		if ($type === 'select') {
			// FieldtypeOptions accepts an array of option ids and routes
			// it through its own sanitizeValue on Pagefile::setFieldValue.
			return $ids;
		}
		if ($type === 'page') {
			// FieldtypePage is shape-sensitive:
			//
			//  - Single-value (derefAsPage > 0): sanitizeValuePage accepts
			//    Page / PageArray / string / int, but NOT a raw int array;
			//    the raw-array path falls through every branch and returns
			//    the blank value. That's the "save returns ok but the new
			//    value never lands" bug — we were silently clearing the
			//    field. Pass a single int id (or '' to clear).
			//
			//  - Multi-value (derefAsPage == 0): sanitizeValuePageArray
			//    treats an int array as ADDITIVE — it loads the existing
			//    PageArray and adds the new ids to it, so deselections
			//    silently survive. Build a fresh PageArray ourselves so
			//    line 778 of FieldtypePage takes the early-return branch
			//    and we get REPLACE semantics.
			$pages   = $this->wire('pages');
			$isMulti = $field instanceof Field && ((int) $field->get('derefAsPage') === 0);
			if (!$isMulti) {
				return $ids ? $ids[0] : '';
			}
			$pa = $pages->newPageArray();
			foreach ($ids as $id) {
				$p = $pages->get($id);
				if ($p && $p->id) $pa->add($p);
			}
			return $pa;
		}
		// number: let the Fieldtype validate + coerce.
		if ($field instanceof Field) {
			try { return $field->type->sanitizeValue($page, $field, $value); }
			catch (\Throwable $e) { /* fall through */ }
		}
		return $value;
	}

	/**
	 * True if a FieldtypeDatetime field's output format carries a time
	 * component (so the editor uses datetime-local instead of date, and
	 * the display keeps the time).
	 */
	protected function dateHasTime(Field $f): bool {
		$fmt = (string) $f->get('dateOutputFormat');
		// date() time tokens, plus the common strftime time tokens.
		return $fmt !== '' && (bool) preg_match('/[aAgGhHisuv]|%[HIklMpPrRSTX]/', $fmt);
	}

	/**
	 * Format a timestamp with a Datetime field's own output format
	 * (falling back to the module's generic timestamp format). wireDate()
	 * handles both date() and legacy strftime formats safely.
	 */
	protected function formatCustomDate(Field $f, int $ts): string {
		$fmt = (string) $f->get('dateOutputFormat');
		if ($fmt === '') return $this->formatTimestamp($ts);
		if (function_exists('ProcessWire\\wireDate')) {
			$out = \ProcessWire\wireDate($fmt, $ts);
			if (is_string($out) && $out !== '') return $out;
		}
		return date($fmt, $ts);
	}

	/**
	 * Resolve a Page-reference custom subfield to a bounded inline-select
	 * config: { multiple: bool, options: [{value:id,label:title}] }.
	 * Returns null when the selectable set can't be a sane inline select
	 * (autocomplete / huge / unresolvable) — those keep the native editor.
	 *
	 * @return array{multiple:bool,options:array<int,array{value:int,label:string}>}|null
	 */
	const PAGEREF_INLINE_CAP = 2000;

	protected function getPageRefConfig(string $name): ?array {
		if (array_key_exists($name, $this->pageRefConfigCache)) {
			return $this->pageRefConfigCache[$name];
		}
		$result = null;
		$f = $this->wire('fields')->get($name);
		if ($f instanceof Field && $f->type instanceof FieldtypePage) {
			$pa = $this->resolvePageRefPages($f);
			if ($pa instanceof PageArray && $pa->count() > 0 && $pa->count() <= self::PAGEREF_INLINE_CAP) {
				$opts = [];
				foreach ($pa as $p) {
					$opts[] = [
						'value' => (int) $p->id,
						'label' => (string) (((string) $p->title !== '') ? $p->title : $p->name),
					];
				}
				$result = [
					'multiple' => ((int) $f->get('derefAsPage') === 0),
					'options'  => $opts,
				];
			}
		}
		$this->pageRefConfigCache[$name] = $result;
		return $result;
	}

	/**
	 * Resolve a Page-reference field's selectable pages. Tries the
	 * InputfieldPage's own getSelectablePages() first (honours every
	 * config incl. custom find code); falls back to a selector built
	 * straight from the field config when that yields nothing — more
	 * robust across the various page inputs (Select, AsmSelect,
	 * PageListSelect, …). Returns null when nothing bounded resolves.
	 */
	protected function resolvePageRefPages(Field $f): ?PageArray {
		try {
			$inputfield = $f->getInputfield($this->wire('page'));
			if ($inputfield && method_exists($inputfield, 'getSelectablePages')) {
				$pa = $inputfield->getSelectablePages($this->wire('page'));
				if ($pa instanceof PageArray && $pa->count() > 0) return $pa;
			}
		} catch (\Throwable $e) { /* fall through to config selector */ }

		$selector = $this->pageRefSelector($f);
		if ($selector === '') return null;
		try {
			$pa = $this->wire('pages')->find($selector . ', limit=' . (self::PAGEREF_INLINE_CAP + 1));
			return $pa instanceof PageArray ? $pa : null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * Build a find selector from a Page-reference field's config
	 * (findPagesSelector / parent_id / template_id(s)). Returns '' when
	 * the field relies on custom find code (can't be resolved safely) or
	 * has no usable constraint.
	 */
	protected function pageRefSelector(Field $f): string {
		if ((string) $f->get('findPagesCode') !== '') return '';
		$sel = trim((string) $f->get('findPagesSelector'));
		if ($sel !== '') return $sel . ', include=hidden';
		$parts = [];
		$parent = (int) $f->get('parent_id');
		if ($parent) $parts[] = 'parent_id=' . $parent;
		$tplIds = $f->get('template_ids');
		$tplId  = (int) $f->get('template_id');
		if (is_array($tplIds) && $tplIds) {
			$parts[] = 'template=' . implode('|', array_map('intval', $tplIds));
		} elseif ($tplId) {
			$parts[] = 'template=' . $tplId;
		}
		if (!$parts) return '';
		$parts[] = 'include=hidden';
		return implode(', ', $parts);
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

		// Load the storage page (for image resolution) AND the owner
		// page (for display URLs). For non-repeater rows ownerPageId is
		// unset and we just reuse the storage page. Both lookups share
		// one batched getById call.
		$idSet = [];
		foreach ($slice as $r) {
			if (!empty($r['pageId']))      $idSet[(int) $r['pageId']]      = true;
			if (!empty($r['ownerPageId'])) $idSet[(int) $r['ownerPageId']] = true;
		}
		// Build an explicit id => Page map. PageArray inherits WireArray::get(),
		// which treats integer keys as array indexes (0, 1, 2…), not page IDs,
		// so $pages->get($pageId) would silently return the wrong page.
		$pagesById = [];
		foreach ($this->wire('pages')->getById(array_keys($idSet)) as $p) {
			$pagesById[$p->id] = $p;
		}
		$customByField = $this->getCustomByField();
		$thumb = $this->getThumbDims();

		foreach ($slice as &$row) {
			$row['thumbUrl']        = '';
			$row['downloadUrl']     = '';
			$row['pageUrl']         = '';
			$row['pageEditUrl']     = '';
			$row['variationsCount'] = 0;

			$page = $pagesById[$row['pageId']] ?? null;
			if (!$page || !$page->id) continue;

			// Display URLs point at the owner page when the image lives
			// in a repeater field; storage stays on $page.
			$displayPage = $page;
			if (!empty($row['ownerPageId']) && isset($pagesById[(int) $row['ownerPageId']])) {
				$displayPage = $pagesById[(int) $row['ownerPageId']];
			}
			$row['pageUrl']     = $displayPage->url;
			$row['pageEditUrl'] = $displayPage->editUrl;
			// Base URL for the per-image editor modal. It edits the STORAGE
			// page (where the field lives — the repeater page for a repeater
			// image), NOT the display/owner page, so use $page (not
			// $displayPage). Crucially this resolves a User page to the
			// access/users/edit editor rather than page/edit: page/edit
			// redirects User pages there and drops our fields=/ml_focus_hash
			// scope, which made the modal render the whole user form instead
			// of the single image field. editUrl() gives the right editor for
			// every template; for normal + repeater pages it's the same
			// page/edit URL the modal used before.
			$row['pageEditBase'] = (string) $page->editUrl;
			// Title rendered in the editor's own admin language —
			// the cached pageTitle is intentionally default-language
			// (so sort / filter / search stay consistent across
			// editors); this override flips display to user-language.
			$row['pageTitle']   = (string) $displayPage->title;
			// Page name (slug) for the (p) placeholder; owner-page-
			// resolved like pageTitle so repeater rows expand to
			// something meaningful.
			$row['pageName']    = (string) $displayPage->name;

			$img = $this->resolvePageimage($page, (string) $row['fieldName'], (string) $row['basename']);
			if (!$img) continue;

			$thumbInfo = $this->resolveThumbForImage($img, $thumb);
			$row['thumbUrl']    = $thumbInfo['url'];
			$row['thumbWidth']  = $thumbInfo['width'];
			$row['thumbHeight'] = $thumbInfo['height'];
			// Original (full-size) file URL for the per-thumbnail download button.
			$row['downloadUrl'] = (string) $img->url;
			// Variations count — Phase 2 column from the concept,
			// useful for pre-warm diagnosis and cleanup. getVariations()
			// does a filesystem scan per image, but only for the 50-ish
			// rows in the visible slice, so the cost is bounded.
			$variations = $img->getVariations();
			$row['variationsCount'] = $variations ? $variations->count() : 0;

			// Custom-field hydration: read each declared custom subfield off
			// the Pageimage as a typed DISPLAY value (checkbox glyph, date
			// formatted, page / option labels; text/textarea keep their
			// multilang shape). See readCustomValue().
			foreach ($customByField[$row['fieldName']] ?? [] as $customName) {
				// Editor-RAW value for inline-editable typed cells — set on
				// every visible-slice row (even when the display value was
				// already filled by the bulk-hydrate pass).
				if (in_array($this->getCustomTypes()[$customName] ?? 'text', ['checkbox', 'date', 'number', 'select', 'page'], true)) {
					$row['customRaw'][$customName] = $this->readCustomRaw($img, $customName);
				}
				if (isset($row['custom'][$customName])) continue; // already filled by bulk pass
				$v = $this->readCustomValue($img, $customName);
				if ($v === null || $v === '') continue;
				$row['custom'][$customName] = $v;
			}
		}
		unset($row);

		// Where-used counts for the visible slice — one in-memory pass over the
		// usage index + dedup cluster map (no per-row queries). Only rows whose
		// image is embedded somewhere get a count; the rest render the dash.
		// Skipped entirely when the (default-hidden) column is off, so a table
		// nobody toggled it on for pays nothing.
		$usageCounts = $this->usedInColumnVisible() ? $this->usagePageCountsForRows($slice) : [];
		if ($usageCounts) {
			foreach ($slice as &$row) {
				$key = $this->hashKey((int) $row['pageId'], (string) $row['fieldName'], (string) $row['basename']);
				if (isset($usageCounts[$key])) $row['usageCount'] = (int) $usageCounts[$key];
			}
			unset($row);
		}

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
				// Typed display value (same as hydrateSlice): text/textarea
				// keep their multilang {langId:value} shape for the editor
				// tabs; checkbox/date/select/page collapse to a label so
				// search + "missing X" operate on something meaningful.
				$v = $this->readCustomValue($img, $name);
				if ($v === null) continue;
				$row['custom'][$name] = $v;
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

	/**
	 * Format a stored Pagefile datetime for display. PW writes both
	 * created and modified as MySQL DATETIME strings (e.g.
	 * "2026-05-27 17:00:45"); accept either that or a Unix-timestamp
	 * fallback so the helper stays robust. Uses $config->dateFormat
	 * when set, otherwise a sensible "Y-m-d H:i" default. Empty /
	 * zero / unparseable values render as the empty string so the
	 * cell looks blank for files with no recorded date.
	 *
	 * @param int|string|null $val
	 */
	protected function formatTimestamp($val): string {
		if ($val === null || $val === '' || $val === '0000-00-00 00:00:00') return '';
		$ts = is_numeric($val) ? (int) $val : strtotime((string) $val);
		if (!$ts || $ts <= 0) return '';
		$fmt = (string) $this->wire('config')->dateFormat;
		if ($fmt === '') $fmt = 'Y-m-d H:i';
		return date($fmt, $ts);
	}

	protected function loadAssets(): void {
		$config = $this->wire('config');
		$session = $this->wire('session');
		$baseUrl = $config->urls($this);
		// Cache-bust on each asset's mtime, not the module version, so
		// edits to the CSS / JS take effect on the next load without a
		// version bump or a manual hard-refresh. Per-file so a CSS-only
		// change doesn't force the JS to re-download. Falls back to the
		// module version if filemtime is unavailable (e.g. stat disabled).
		$baseDir = $config->paths($this);
		$version = (string) $this->wire('modules')->getModuleInfoProperty($this, 'version');
		$cssVer  = @filemtime($baseDir . 'ProcessImageLibrary.css') ?: $version;
		$jsVer   = @filemtime($baseDir . 'ProcessImageLibrary.js')  ?: $version;
		$config->styles->add($baseUrl . 'ProcessImageLibrary.css?v=' . $cssVer);
		// The pure tree model must load BEFORE the main script, which aliases
		// window.MLCollectionsModel at IIFE-init time (see collections-model.js).
		$modelVer = @filemtime($baseDir . 'assets/collections-model.js') ?: $version;
		$config->scripts->add($baseUrl . 'assets/collections-model.js?v=' . $modelVer);
		$config->scripts->add($baseUrl . 'ProcessImageLibrary.js?v=' . $jsVer);

		$imageFields = $this->discoverImageFields();
		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);

		$config->js('ProcessImageLibrary', [
			'saveUrl'   => $this->wire('page')->url . 'save/',
			'renderUrl' => $this->wire('page')->url . 'data/',
			'bulkUrl'   => $this->wire('page')->url . 'bulk/',
			'renameUrl'  => $this->wire('page')->url . 'rename/',
			'replaceUrl' => $this->wire('page')->url . 'replace/',
			'deleteUrl'  => $this->wire('page')->url . 'delete/',
			'usageUrl'   => $this->wire('page')->url . 'usage/',
			'usageDetailUrl' => $this->wire('page')->url . 'usage-detail/',
			'widgetUrl'  => $this->wire('page')->url . 'widget/',
			// Used to build the page-edit URL for the thumbnail-click
			// modal — wraps PW's native image editor in an iframe.
			'adminUrl'  => $config->urls->admin,
			'tplFields' => $this->getTemplateFieldsMap($imageFields, $eligibleTemplates),
			// Per-image-field capabilities. JS uses this to hide / show
			// the Tags filter fieldset + the Missing-X checkboxes when
			// the field filter changes, mirroring the server-side gate
			// in renderFilterBar so AJAX result swaps stay consistent.
			'fieldCaps' => $this->buildFieldCapsPayload($imageFields),
			'defaultPageSize'      => $this->getDefaultPageSize(),
			'defaultHiddenColumns' => $this->getDefaultHiddenColumns(),
			// Bounds for the per-user thumbnail size slider (multiplier
			// on the configured thumb dims). Current value rides in
			// userPrefs.thumbScale.
			'thumbScaleMin'        => self::THUMB_SCALE_MIN,
			'thumbScaleMax'        => self::THUMB_SCALE_MAX,
			'thumbScaleDefault'    => self::THUMB_SCALE_DEFAULT,
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
			// Team-wide shared bookmarks + collections (read by everyone,
			// written only by managers via the shared-prefs endpoint).
			'shared'               => $this->getSharedPrefs(),
			'sharedPrefsUrl'       => $this->wire('page')->url . 'shared-prefs/',
			'canManageShared'      => $this->canManageShared(),
			// Library-wide tag rename/delete (predefined tags), manager-gated.
			'tagBulkUrl'           => $this->wire('page')->url . 'tag-bulk/',
			'csrf' => [
				'name'  => $session->CSRF->getTokenName(),
				'value' => $session->CSRF->getTokenValue(),
			],
			'labels' => [
				'saving'           => $this->_('Saving…'),
				'loading'          => $this->_('Loading…'),
				'saved'            => $this->_('Saved'),
				'error'            => $this->_('Save failed'),
				'done'             => $this->_('Done'),
				'add'              => $this->_('Add'),
				'replace'          => $this->_('Replace'),
				'remove'           => $this->_('Remove'),
				'save'             => $this->_('Save'),
				'cancel'           => $this->_('Cancel'),
				'close'            => $this->_('Close'),
				// Label next to the checkbox widget for a boolean custom subfield.
				'enabled'          => $this->_('Enabled'),
				// Placeholder for the "add a new tag" input on predefined+own fields.
				'tagAddPlaceholder' => $this->_('Add tag…'),
				// Library-wide tag management (manager-only, in the tag modal).
				'tagDeleteTitle'    => $this->_('Delete tag'),
				'tagConfirmDelete'  => $this->_('Confirm delete'),
				'tagRenameTitle'    => $this->_('Rename tag'),
				'tagManageDelete'   => $this->_('Delete the tag “%s” everywhere?'),
				'tagManageRename'   => $this->_('Rename the tag “%s” to:'),
				'tagManageAffected' => $this->_('Affects %d image(s).'),
				'tagManageCounting' => $this->_('Checking…'),
				'tagDeleted'        => $this->_('Tag deleted from %d image(s)'),
				'tagRenamed'        => $this->_('Tag renamed on %d image(s)'),
				'rename'           => $this->_('New filename'),
				'placeholderHint'  => $this->_('Placeholders: (n) counter, (n2)…(n5) padded, (N) total, (t) page title, (d) date, (p) page name, (f) field name.'),
				'imageEditorTitle' => $this->_('Edit image: %s'),
				'importing'        => $this->_('Importing…'),
				'importSaved'      => $this->_('Saved'),
				'importSkipped'    => $this->_('Unchanged'),
				'importFailed'    => $this->_('Failed'),
				'importError'      => $this->_('Import failed'),
				'batching'         => $this->_('Applying to %d selected…'),
				'bulkResult'       => $this->_('Succeeded: %1$d  ·  Failed: %2$d'),
				// Bookmark labels — the +tab dialog + delete/save toasts.
				// Shown by the client when a match-aware save removes
				// the last row from the visible filter view; same
				// string the server-rendered empty state uses.
				'emptyResult'      => $this->_('No images match the current filters.'),
				'bookmarkSave'     => $this->_('Save bookmark'),
				'bookmarkHint'     => $this->_('Saves the active filter combination under a name.'),
				'bookmarkSaved'    => $this->_('Bookmark saved'),
				'bookmarkDeleted'  => $this->_('Bookmark deleted'),
				'bookmarkDelete'   => $this->_('Delete bookmark'),
				'bookmarkEmpty'    => $this->_('Apply some filters first.'),
				// Bookmarks-in-manager: folder grouping.
				'bmNewFolderName'  => $this->_('New folder'),
				'bmManageEmpty'    => $this->_('No bookmarks yet.'),
				'bmConfirmDeleteFolder' => $this->_('Click again — this also deletes the bookmarks inside the folder.'),
				// Collection labels — saving a checkbox selection as a named set.
				'collectionSave'    => $this->_('Save collection'),
				'collectionHint'    => $this->_('Saves the %d selected image(s) as a named collection.'),
				'collectionSaved'   => $this->_('Collection saved'),
				'collectionDeleted' => $this->_('Collection deleted'),
				'collectionDelete'  => $this->_('Delete collection'),
				// Add-button label swaps to this while a selection exists, and the
				// per-collection "+" adds the selection to that existing set.
				'bookmarkAdd'       => $this->_('New'),
				'collectionAdd'     => $this->_('New'),
				'collectionUpdated' => $this->_('Added %d image(s) to the collection'),
				'collectionRemoved' => $this->_('Removed %d image(s) from the collection'),
				// Collections manager (drag-and-drop reorder + nesting) row controls.
				'collMoveUp'        => $this->_('Move up'),
				'collMoveDown'      => $this->_('Move down'),
				'collNest'          => $this->_('Make a subgroup of the item above'),
				'collUnnest'        => $this->_('Move out one level'),
				'collCollapse'      => $this->_('Collapse'),
				'collExpand'        => $this->_('Expand'),
				'collDelete'        => $this->_('Delete collection'),
				'collConfirmDelete' => $this->_('Click again to delete'),
				'collConfirmDeleteTree' => $this->_('Click again — this also deletes its subgroups. The images stay in the library.'),
				'collRename'        => $this->_('Rename collection'),
				'collNewName'       => $this->_('New collection'),
				'collManageEmpty'   => $this->_('No collections yet.'),
				'collManageTeam'    => $this->_('Team'),
				'collectionsManage'      => $this->_('Manage bookmarks & collections'),
				'collectionsAssign'      => $this->_('Assign to collections'),
				'barBookmarks'           => $this->_('Bookmarks'),
				'barCollections'         => $this->_('Collections'),
				'collectionsAssignN'     => $this->_('Assign %d images to collections'),
				'collectionsManageShort' => $this->_('Manage'),
				// Shared (team-wide) bookmarks + collections — the manager-only
				// "share with team" toggle in the save dialog + its toasts.
				'shareWithTeam'     => $this->_('Share with the team'),
				'shareWithTeamHint' => $this->_('Visible to everyone with library access. Only managers can change it.'),
				'sharedSaved'       => $this->_('Shared with the team'),
				'sharedDeleted'     => $this->_('Removed from the team'),
				// Delete confirm + result labels. The JS substitutes %d
				// for the count; the %d placeholder stays literal in the
				// translatable strings.
				'deleteOne'        => $this->_('Delete this image?'),
				'deleteMany'       => $this->_('Delete %d images?'),
				'deleteOneIntro'   => $this->_('The following file will be permanently removed:'),
				'deleteManyIntro'  => $this->_('The following files will be permanently removed:'),
				'deleteWarn'       => $this->_('This cannot be undone.'),
				'deleteOk'         => $this->_('Delete'),
				'deleted'          => $this->_('Deleted %d'),
				'deletePartial'    => $this->_('Deleted %d, %d failed'),
				// Where-used preflight on the delete confirm dialog: the
				// %d / %s placeholders stay literal in translations.
				'usageHeading'     => $this->_('Still referenced in rich-text fields:'),
				'usageScanning'    => $this->_('Checking references…'),
				'usageFieldFmt'    => $this->_('“%1$s” · %2$s'),
				'usageCountFmt'    => $this->_('used in %d page(s)'),
				// "Used in" column click-through dialog.
				'usedInTitle'      => $this->_('Embedded on these pages & fields'),
				'usedInEmpty'      => $this->_('Not embedded in any rich-text field.'),
				'usedInLoading'    => $this->_('Loading…'),
				// Post-rename summary dialog. A rename rewrites every
				// rich-text embed automatically, so instead of warning
				// beforehand we confirm afterwards which embeds were fixed.
				'renameDoneTitle'  => $this->_('Renamed'),
				'embedsUpdatedHeading' => $this->_('Updated embedded references:'),
				// Advisory pre-rename warning when the image is still embedded.
				'renameUsageTitle' => $this->_('Heads up — still embedded in other pages'),
				'renameAnyway'     => $this->_('Rename anyway'),
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
		// include=unpublished to match buildSelector: a user whose only
		// editable image pages are unpublished must not be locked out.
		$selector = 'template=' . implode('|', $eligibleTemplates) . ', include=unpublished';
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

	/**
	 * Bookmarks bar: tab strip above the filter bar, listing the
	 * user's saved filter combinations + a baseline "All" tab + an
	 * Add button at the leftmost position. WireTabs + uk-tab classes
	 * piggyback on the admin theme's native tab styling — no module
	 * CSS for the chrome. Click handling is taken over by the module
	 * JS so the tab navigates via the existing AJAX filter swap
	 * pipeline (replaceFromQs).
	 *
	 * @param array<int,array{name:string,qs:string}> $bookmarks  legacy personal (migrated/empty)
	 * @param array<int,array{id:string,name:string,keys:array<int,string>}> $collections  legacy personal (empty)
	 * @param array<int,array{id:string,name:string,qs:string,parent:string}> $sharedBookmarks  THE team bookmark store
	 * @param array<int,array{id:string,name:string,keys:array<int,string>,parent:string}> $sharedCollections  THE team collection store
	 */
	protected function renderBookmarksBar(array $filters, array $bookmarks, array $collections = [], array $sharedBookmarks = [], array $sharedCollections = []): string {
		$san  = $this->wire('sanitizer');
		$page = $this->wire('page');
		$canManageShared = $this->canManageShared();

		$currentCanon = $this->canonicalizeBookmarkQs(http_build_query($this->bookmarkFilterPayload($filters)));
		$currentColl  = (string) ($filters['coll'] ?? '');
		$addTitle = $san->entities($this->_('Save current filter as bookmark'));
		$addLabel = $san->entities($this->_('New'));
		$allLabel = $san->entities($this->_('Show all'));
		$delTitle = $san->entities($this->_('Delete bookmark'));

		$out  = '<ul class="WireTabs uk-tab ml-bookmarks-tabs">';

		// Baseline "Show all" tab first - empty querystring, active iff nothing
		// filter-shaped AND no collection is currently set.
		$allActive = ($currentCanon === '' && $currentColl === '') ? ' class="uk-active"' : '';
		$out .= '<li' . $allActive . '>'
			. '<a class="ml-bookmark" href="' . $san->entities($page->url) . '" data-qs="">'
			. $allLabel . '</a></li>';

		// Tab strip ordered by TYPE, not by owner: first ALL filter bookmarks
		// (personal then team), then ALL collections (personal then team). No
		// separator — shared entries are told apart purely typographically
		// (.ml-bookmark--shared: italic/lighter), and their × (edit/delete) only
		// renders for users who may manage the team store.
		$bookmarkMatched = false;

		// Bookmarks are team-wide now (no personal/shared split, no italic). A
		// FILTER bookmark carries a qs and applies it on click; a FOLDER (empty
		// qs) only groups children — its nested children live in a hover flyout
		// the JS builds (rerenderBookmarksList on init), so here we render
		// top-level entries only. The × (delete) shows for managers only.
		$renderBookmark = function (array $b) use ($san, $page, $currentCanon, $currentColl, $delTitle, $canManageShared, &$bookmarkMatched): string {
			$bid = (string) ($b['id'] ?? '');
			if ($bid === '') return '';
			if (((string) ($b['parent'] ?? '')) !== '') return '';   // top-level only
			$canon = $this->canonicalizeBookmarkQs((string) ($b['qs'] ?? ''));
			$isFolder = ($canon === '');
			$href  = $isFolder ? '#' : ($page->url . $canon);
			$isActive = (!$isFolder && $canon === $currentCanon && $currentColl === '');
			if ($isActive) $bookmarkMatched = true;
			$cls = 'ml-bookmark' . ($isFolder ? ' ml-bookmark--folder' : '');
			return '<li' . ($isActive ? ' class="uk-active"' : '') . ' data-bookmark-id="' . $san->entities($bid) . '">'
				. '<a class="' . $cls . '"'
				. ' href="' . $san->entities($href) . '"'
				. ' data-qs="' . $san->entities($canon) . '">'
				. $san->entities((string) $b['name'])
				. '</a>'
				. ($canManageShared
					? '<button type="button" class="ml-bookmark-del"'
						. ' aria-label="' . $delTitle . '" title="' . $delTitle . '">'
						. '<i class="fa fa-times" aria-hidden="true"></i></button>'
					: '')
				. '</li>';
		};

		$renderCollection = function (array $c, bool $shared) use ($san, $page, $currentColl, $canManageShared): string {
			$cid = (string) ($c['id'] ?? '');
			if ($cid === '') return '';
			// Only top-level collections get a tab here. Nested ones live in the
			// parent tab's hover flyout, which the JS builds (rerenderBookmarksList
			// runs on init); rendering them flat too would flash duplicate tabs.
			if (($c['parent'] ?? '') !== '') return '';
			$qs = '?coll=' . rawurlencode($cid);
			$cls = 'ml-bookmark ml-bookmark--collection' . ($shared ? ' ml-bookmark--shared' : '');
			// Curate actions are cursor-driven, not buttons: while a selection
			// exists, clicking a collection tab adds (non-active) or removes
			// (active) the selection — the cursor signals which. The × only
			// deletes the collection (and is hidden during selection).
			return '<li' . ($cid === $currentColl ? ' class="uk-active"' : '') . ($shared ? ' data-shared="1"' : '') . ' data-coll-id="' . $san->entities($cid) . '">'
				. '<a class="' . $cls . '"'
				. ' href="' . $san->entities($page->url . $qs) . '"'
				. ' data-qs="' . $san->entities($qs) . '">'
				. '<i class="fa fa-clone" aria-hidden="true"></i> '
				. $san->entities((string) ($c['name'] ?? ''))
				. '</a>'
				// No × on collection tabs — deletion lives in the manager dialog.
				. '</li>';
		};

		// Bookmarks first (team store), then collections (team store). The legacy
		// personal arrays ($bookmarks/$collections) are migrated/empty now.
		foreach ($sharedBookmarks as $b)   $out .= $renderBookmark($b);
		foreach ($sharedCollections as $c) $out .= $renderCollection($c, true);

		// "Manage" — icon-only, sitting directly after the bookmarks/collections
		// and BEFORE the "New" link (no far-right float). Opens the drag-and-drop
		// manager. Managers only; shown even with none so the first can be created.
		if ($canManageShared) {
			$manageTitle = $this->_('Manage bookmarks & collections');   // literal &, no entities (see renderCollectionsDialog)
			$out .= '<li class="ml-collections-manage"><a href="#" role="button"'
				. ' title="' . $manageTitle . '" aria-label="' . $manageTitle . '">'
				. '<i class="ml-vicon ml-vicon-sliders" aria-hidden="true"></i></a></li>';
		}

		// Add ("New") button — opens the name-dialog. Server-side it's hidden
		// unless a non-saved filter is active; the JS additionally reveals it
		// whenever a checkbox selection exists (→ "save as collection").
		$addHidden = (!$canManageShared || $currentCanon === '' || $bookmarkMatched) ? ' hidden' : '';
		$out .= '<li class="ml-bookmarks-add"' . $addHidden . '><a href="#" role="button"'
			. ' title="' . $addTitle . '">'
			. '<i class="fa fa-plus" aria-hidden="true"></i> ' . $addLabel
			. '</a></li>';

		$out .= '</ul>';
		return $out;
	}

	/**
	 * Reduce a filter array to the params that participate in a
	 * bookmark — same param shape canonicalizeBookmarkQs filters
	 * through, so the two stay in lockstep.
	 *
	 * @param array<string,mixed> $filters
	 * @return array<string,string>
	 */
	protected function bookmarkFilterPayload(array $filters): array {
		$out = [];
		foreach (['q', 'template', 'field'] as $k) {
			if (!empty($filters[$k])) $out[$k] = (string) $filters[$k];
		}
		if (!empty($filters['tags']) && is_array($filters['tags'])) {
			$out['tags'] = implode(',', $filters['tags']);
		}
		foreach (['no_desc', 'no_tags', 'dupes'] as $k) {
			if (!empty($filters[$k])) $out[$k] = '1';
		}
		if (!empty($filters['no_custom']) && is_array($filters['no_custom'])) {
			foreach ($filters['no_custom'] as $name => $v) {
				if ($v) $out['no_custom_' . $name] = '1';
			}
		}
		return $out;
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

		$this->addSearchRow($outer, $filters, $imageFields, $eligibleTemplates);

		$this->addTagsFieldset($outer, $filters, $tagFilterPool);

		$this->addMissingCheckboxes($outer, $filters, $customCols);

		// Apply + Reset as raw UIkit buttons inside an InputfieldMarkup.
		// PW's InputfieldSubmit / InputfieldButton render their <button>
		// with jQuery-UI heritage classes (ui-button …) that
		// AdminThemeUikit styles with enough weight to override any
		// uk-button class added alongside — so adding uk classes to the
		// core fields has no visual effect. Hand-rendering is the only
		// way to get true UIkit buttons that match the module's own
		// dialog buttons (uk-button-secondary = grey, like Cancel /
		// Close). Apply is type=submit so the form's submit handler
		// still fires; Reset stays a real <a href="./"> the JS
		// intercepts for the AJAX reset. Flex-left layout via
		// .ml-filter-actions.
		$san = $this->wire('sanitizer');
		$actions = $modules->get('InputfieldMarkup');
		$actions->name        = 'mlActions';
		$actions->skipLabel   = Inputfield::skipLabelHeader;
		$actions->columnWidth = 100;
		$actions->value =
			'<div class="ml-filter-actions">'
			. '<button type="submit" name="apply" class="uk-button uk-button-primary">'
			. $san->entities($this->_('Apply')) . '</button>'
			. '<a href="./" class="ml-reset uk-button uk-button-secondary">'
			. $san->entities($this->_('Reset')) . '</a>'
			. '</div>';
		$outer->add($actions);

		$form->add($outer);

		return $form->render();
	}

	protected function addSearchRow(InputfieldFieldset $outer, array $filters, array $imageFields, array $eligibleTemplates): void {
		$modules = $this->wire('modules');
		// Row 1: Search + Template + Image field, 33/33/34 — except in the picker,
		// where Template / Image field are developer concepts a normal author
		// can't use, so only Search remains (full width). Authors narrow via the
		// search box, Tags, and admin-curated Bookmarks instead.
		$q = $modules->get('InputfieldText');
		$q->name        = 'q';
		$q->label       = $this->_('Search');
		$q->placeholder = $this->_('Page title, description, tags, filename, customs');
		// Searches all of the above. Plain words are OR (any match); +word is
		// required, -word excludes, "quote a phrase" to match it whole.
		$q->notes       = $this->_('Multiple words match any (OR). Use +word to require, -word to exclude, "quotes" for an exact phrase.');
		$q->value       = $filters['q'];
		$q->columnWidth = $this->pickerMode ? 100 : 33;
		$outer->add($q);

		if (!$this->pickerMode) {
			// Template dropdown lists only user-facing templates — the
			// auto-generated repeater_<field> templates are excluded
			// because rows from repeater fields have their templateId
			// rewritten to the owner page's template at flatten time.
			// Picking the owner template here naturally captures those
			// rows too.
			$tpl = $modules->get('InputfieldSelect');
			$tpl->name        = 'template';
			$tpl->label       = $this->_('Template');
			$tpl->addOption('', $this->_('All templates'));
			foreach ($this->userFacingTemplates($eligibleTemplates) as $t) $tpl->addOption($t, $t);
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
		}
	}

	protected function addTagsFieldset(InputfieldFieldset $outer, array $filters, array $tagFilterPool): void {
		$modules = $this->wire('modules');
		// Tags fieldset (full width, always open when present). Rendered
		// unconditionally so the JS field-capability filter has DOM to
		// toggle — same pattern as the template→field narrowing: PHP
		// emits everything, JS hides what doesn't apply to the current
		// selection and resets invalidated values.
		$selectedTags = $filters['tags'] ?? [];
		if ($tagFilterPool || $selectedTags) {
			$displayPool = $tagFilterPool;
			foreach ($selectedTags as $t) {
				if (!in_array($t, $displayPool, true)) $displayPool[] = $t;
			}
			sort($displayPool, SORT_NATURAL | SORT_FLAG_CASE);

			/** @var \ProcessWire\InputfieldFieldset $tagsFs */
			$tagsFs = $modules->get('InputfieldFieldset');
			$tagsFs->name  = 'mlTagsFs';
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
			foreach ($displayPool as $t) $cbs->addOption($t, $t);
			$cbs->value = $selectedTags;
			$tagsFs->add($cbs);

			$outer->add($tagsFs);
		}
	}

	protected function addMissingCheckboxes(InputfieldFieldset $outer, array $filters, array $customCols): void {
		$modules = $this->wire('modules');
		// Missing-X + Duplicates are AUDIT filters (find images that need
		// metadata, or byte-identical copies). When you're picking an image to
		// insert into a field they're just noise — skip them in the picker.
		if (!$this->pickerMode) {
			// Missing-X checkboxes inline, each 25% wide — fixed
			// description / tags first, then one per custom field. All
			// rendered; JS hides / unchecks the ones that don't apply to
			// the selected field, same as template→field.
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

			// "Duplicates only" — narrow the current view (table / gallery) to
			// images that are byte-identical copies of another. Needs a scan
			// (run once from the Duplicates tab); empty before that.
			$dupCb = $modules->get('InputfieldCheckbox');
			$dupCb->name        = 'dupes';
			$dupCb->label       = $this->_('Duplicates');
			$dupCb->columnWidth = 25;
			if (!empty($filters['dupes'])) $dupCb->attr('checked', 'checked');
			$outer->add($dupCb);
		}
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
	 * Filter rows down to those whose tag set includes ANY of the requested
	 * tag tokens (OR semantics) — each extra tag WIDENS the result ("show me
	 * everything tagged with any of these"), which is the natural gesture when
	 * browsing for an image. Called separately from applyRowFilters so the
	 * filter UI can build its tag pool from "rows after non-tag filters" —
	 * selecting a tag then doesn't shrink the available options.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<int,string> $tags wanted tags (case-sensitive match)
	 */
	protected function applyTagFilter(array $rows, array $tags): array {
		if (!$tags) return $rows;
		$wanted = array_fill_keys($tags, true);
		return array_values(array_filter($rows, function ($row) use ($wanted) {
			$rowTags = $this->splitImageTags((string) ($row['tags'] ?? ''));
			foreach ($rowTags as $t) {
				if (isset($wanted[$t])) return true;   // any match → keep
			}
			return false;
		}));
	}

	/**
	 * Wrap a textarea-backed cell value (description + custom textareas)
	 * in the .ml-clamp box. CSS caps the box to a few lines with an
	 * ellipsis so long prose can't blow up the row height — but the
	 * value itself is never truncated: the full text stays in the DOM,
	 * so td.textContent (the source the inline editor AND bulk Add-mode
	 * read) always returns the complete string. Empty values render
	 * nothing so the cell's :empty "—" placeholder still shows.
	 */
	protected function clampCell(string $text): string {
		if ($text === '') return '';
		return '<div class="ml-clamp">' . $this->wire('sanitizer')->entities($text) . '</div>';
	}

	/**
	 * @param array<int,array<string,mixed>> $slice hydrated slice
	 * @param array<int,string> $customCols custom-field column names
	 * @param array<string,array{mode:int,allowed:array<int,string>}> $tagsConfig per-field tag mode + whitelist
	 */
	protected function renderTable(array $slice, array $customCols, array $filters = [], string $sort = '', string $dir = '', array $tagsConfig = []): string {
		$san = $this->wire('sanitizer');
		$thumb = $this->getThumbDims();

		// Per-subfield editor type (text / textarea / checkbox / date /
		// select / page), keyed by subfield name so the per-row loop is
		// just a lookup. text + textarea are inline-editable; the typed
		// ones open the native per-image editor for now (Phase 1).
		$customByField = $this->getCustomByField();
		$customInputTypes = $this->getCustomTypes();

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
			['created',     $this->_('Uploaded'),    'created'],
			['modified',    $this->_('Modified'),    'modified'],
			['variations',  $this->_('Variations'),  'variationsCount'],
			['usedIn',      $this->_('Used in'),     'usageCount'],
			['collections', $this->_('Collections'), 'collectionNames'],
		];
		if (!$showTagsCol) {
			$headers = array_values(array_filter($headers, fn($h) => $h[0] !== 'tags'));
		}

		$out = $this->renderTableHead($headers, $customCols, $sort, $dir, $filters);

		// Collections column: index every team collection's row-keys once, so each
		// row can list (and link) the collections it appears under in O(1). UNION
		// membership — a row in a sub-collection also appears under its parent(s)
		// (recall shows the union), so list the whole union, not just direct keys.
		// Stored in display (pre-order) order, so parents come before their
		// children. Cheap (no per-image queries), so it's not gated.
		$collByKey = [];
		$collList = $this->getSharedPrefs()['collections'];
		foreach ($collList as $coll) {
			$cid = (string) ($coll['id'] ?? '');
			if ($cid === '') continue;
			foreach ($this->collectionUnionKeys($cid, $collList) as $k) {
				$collByKey[(string) $k][] = ['id' => $cid, 'name' => (string) ($coll['name'] ?? '')];
			}
		}
		// Field-editor link base for the Field column (resolved field id per name,
		// cached across rows). $fields->get() is itself cached, but skip the repeat.
		$fieldEditBase = $this->wire('config')->urls->admin . 'setup/field/edit?id=';
		$fieldIdCache  = [];
		// Managers can assign an image to collections straight from the column.
		$canAssignColl = $this->canManageShared();

		// The slice arrives collapsed into per-image units (buildDisplayUnits):
		// a duplicated image's copies sit consecutively, head first. Only the
		// head is shown — it carries the indicator, which toggles its other
		// copies (rendered hidden right beneath it). The copies carry no
		// indicator; once revealed they bulk-edit like any other rows. Applies
		// in every table view, not just the Duplicates filter.
		$prevDupHash = null;

		foreach ($slice as $row) {
			$desc = $this->normalizeDescription($row['description']);
			$tags = (string) $row['tags'];
			$dims = ($row['width'] && $row['height'])
				? $row['width'] . '×' . $row['height']
				: '';
			$size = $this->formatFilesize((int) $row['filesize']);

			$editAttrs = sprintf(
				'data-page-id="%d" data-field="%s" data-basename="%s" data-file-hash="%s" data-edit-base="%s"',
				(int) $row['pageId'],
				$san->entities((string) $row['fieldName']),
				$san->entities((string) $row['basename']),
				md5((string) $row['basename']),
				$san->entities((string) ($row['pageEditBase'] ?? ''))
			);
			// A11y: editable cells expose themselves as buttons so
			// keyboard users can Tab to them and Enter / Space to
			// open the inline editor (handled in JS). Per-cell labels
			// are added at the call sites since each subfield needs
			// its own descriptive name.
			$editA11y = ' role="button" tabindex="0"';

			$selKey = $this->rowKey(
				(int) $row['pageId'],
				(string) $row['fieldName'],
				(string) $row['basename']
			);

			// Row identity attrs (only when editable) so the JS
			// drag-and-drop / click-replace handlers can resolve the
			// target without walking into individual cells. Page
			// title + name ride along so the batch-save optimistic
			// update can resolve (t) / (p) placeholders per row.
			$rowAttrs = '';
			if (!empty($row['pageEditUrl'])) {
				$rowAttrs = sprintf(
					' data-page-id="%d" data-field="%s" data-basename="%s"'
					. ' data-page-title="%s" data-page-name="%s"',
					(int) $row['pageId'],
					$san->entities((string) $row['fieldName']),
					$san->entities((string) $row['basename']),
					$san->entities((string) ($row['pageTitle'] ?? '')),
					$san->entities((string) ($row['pageName']  ?? ''))
				);
			}
			// Accordion: the first row of each cluster is the visible "head"
			// (carries the toggle indicator); the rest are hidden copy rows
			// revealed by clicking it.
			$isDupHead = false; $isDupMember = false; $rowDupHash = '';
			$rowDupHash = (string) ($row['dupHash'] ?? '');
			if ($rowDupHash !== '') {
				if ($rowDupHash !== $prevDupHash) { $isDupHead = true; }
				else { $isDupMember = true; }
				$prevDupHash = $rowDupHash;
			}
			$rowClass = 'ml-row';
			$rowExtra = '';
			if ($isDupHead)   { $rowClass .= ' ml-dup-head'; }
			if ($isDupMember) {
				$rowClass .= ' ml-dup-member';
				$rowExtra = ' data-dup-hash="' . $san->entities($rowDupHash) . '" hidden';
			}
			$out .= '<tr class="' . $rowClass . '"' . $rowAttrs . $rowExtra . '>';

			$out .= '<td class="ml-cell-select">'
				. '<input type="checkbox" class="uk-checkbox ml-select-row" data-key="'
				. $san->entities($selKey) . '"></td>';

			$out .= $this->renderThumbCell($row, $editAttrs, $editA11y, $isDupHead, $rowDupHash, $thumb);

			$pageTitle = $this->normalizeDescription($row['pageTitle']);
			$out .= '<td class="ml-cell-page" data-col="page">';
			if (!empty($row['pageEditUrl'])) {
				$out .= '<a href="' . $san->entities($row['pageEditUrl']) . '">'
					. $san->entities($pageTitle) . '</a>';
			} else {
				$out .= $san->entities($pageTitle);
			}
			$out .= '</td>';

			// Field cell — for repeater-hosted rows, prefix the
			// containing repeater field so editors can tell at a glance
			// that the image lives inside (e.g.) "gallery.images" rather
			// than a top-level field "images".
			$fieldLabel = !empty($row['repeaterField'])
				? $san->entities((string) $row['repeaterField']) . '.' . $san->entities((string) $row['fieldName'])
				: $san->entities((string) $row['fieldName']);
			// Link the field name to PW's field editor (resolve + cache the id).
			$fname = (string) $row['fieldName'];
			if (!array_key_exists($fname, $fieldIdCache)) {
				$f = $this->wire('fields')->get($fname);
				$fieldIdCache[$fname] = ($f && $f->id) ? (int) $f->id : 0;
			}
			$fid = $fieldIdCache[$fname];
			$out .= '<td data-col="field"><code>';
			if ($fid) {
				$out .= '<a href="' . $san->entities($fieldEditBase . $fid) . '" title="'
					. $san->entities(sprintf($this->_('Edit the “%s” field'), $fname)) . '">'
					. $fieldLabel . '</a>';
			} else {
				$out .= $fieldLabel;
			}
			$out .= '</code></td>';
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
				. $this->clampCell($desc) . '</td>';
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
					// Predefined set (checkbox group) for whitelist (2) AND
					// predefined-plus-own (3).
					if ($tagCfg['mode'] === 2 || $tagCfg['mode'] === 3) {
						$tagAttrs .= " data-tags-allowed='" . $san->entities(
							json_encode(array_values($tagCfg['allowed']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
						) . "'";
					}
					// Autocomplete datalist for free-form (1) AND the add-tag
					// input of predefined-plus-own (3).
					if ($tagCfg['mode'] === 1 || $tagCfg['mode'] === 3) {
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
			$out .= '<td class="ml-cell-nowrap" data-col="created">'
				. $san->entities($this->formatTimestamp($row['created']  ?? '')) . '</td>';
			$out .= '<td class="ml-cell-nowrap" data-col="modified">'
				. $san->entities($this->formatTimestamp($row['modified'] ?? '')) . '</td>';
			$out .= '<td class="ml-cell-nowrap ml-cell-variations" data-col="variations">'
				. (int) ($row['variationsCount'] ?? 0) . '</td>';

			// Where-used: how many pages embed this image in a rich-text field
			// (content-based, hydrated for the visible slice only). A count
			// badge opens the page list; a dash means "embedded nowhere".
			$usageCount = (int) ($row['usageCount'] ?? 0);
			$out .= '<td class="ml-cell-nowrap ml-cell-usedin" data-col="usedIn">';
			if ($usageCount > 0) {
				$out .= '<a href="#" class="ml-usage-link"'
					. ' data-page-id="' . (int) $row['pageId'] . '"'
					. ' data-field="' . $san->entities((string) $row['fieldName']) . '"'
					. ' data-basename="' . $san->entities((string) $row['basename']) . '"'
					. ' title="' . $san->entities(sprintf(
						$this->_('Embedded on %d page(s) — click to list'), $usageCount
					)) . '">' . $usageCount . '</a>';
			} else {
				$out .= '<span class="ml-usage-none" aria-hidden="true">–</span>';
			}
			$out .= '</td>';

			// Collections this image directly belongs to — each links to its
			// ?coll= recall view. A dash means it's in no collection. Managers get
			// a clickable cell (caret affordance) that opens an inline checkbox
			// tree to assign / unassign the image (JS reads the row identity attrs).
			$out .= '<td class="ml-cell-collections' . ($canAssignColl ? ' ml-cell-coll-edit' : '') . '" data-col="collections"';
			if ($canAssignColl) {
				$out .= ' ' . $editAttrs . ' role="button" tabindex="0" title="'
					. $san->entities($this->_('Assign to collections')) . '"';
			}
			$out .= '>';
			$out .= '<span class="ml-coll-cell-list">';
			$memberOf = $collByKey[$selKey] ?? [];
			if ($memberOf) {
				$links = [];
				foreach ($memberOf as $m) {
					$links[] = '<a href="?coll=' . rawurlencode($m['id']) . '">'
						. $san->entities($m['name']) . '</a>';
				}
				$out .= implode(', ', $links);
			} else {
				$out .= '<span class="ml-usage-none" aria-hidden="true">–</span>';
			}
			$out .= '</span>';
			if ($canAssignColl) {
				$out .= ' <i class="fa fa-caret-down ml-coll-cell-caret" aria-hidden="true"></i>';
			}
			$out .= '</td>';

			$rowCustoms = $customByField[$row['fieldName']] ?? [];
			foreach ($customCols as $name) {
				$out .= $this->renderCustomCell($name, $row, $rowCustoms, $customInputTypes, $editAttrs, $editA11y);
			}

			$out .= '</tr>';
		}

		$out .= '</tbody></table></div>';
		return $out;
	}

	/**
	 * Render one custom-subfield <td> for a row. Branches: text/textarea inline-
	 * editable, other typed inputs inline-editable (on editable pages), page-ref
	 * fallback to the native editor, else display-only. A subfield the row's field
	 * doesn't declare renders as a disabled N/A cell.
	 */
	protected function renderCustomCell(string $name, array $row, array $rowCustoms, array $customInputTypes, string $editAttrs, string $editA11y): string {
		$san = $this->wire('sanitizer');
		$colAttr = ' data-col="custom:' . $san->entities($name) . '"';
		// Type-class lives on every custom cell (NA + editable
		// alike) so column-level width rules apply uniformly
		// regardless of whether a given row has that subfield.
		// Keyed by Inputfield type, not by field name, so the
		// CSS scales to new custom subfields without per-name
		// rules.
		$inputType = $customInputTypes[$name] ?? 'text';
		$typeClass = 'ml-cell-' . $san->entities($inputType);
		// When the customCols list is the union across image
		// fields (no field filter), some rows won't host every
		// listed subfield — render those as visually disabled
		// instead of editable so a click can't trigger an editor
		// for a field the server would reject anyway.
		if (!in_array($name, $rowCustoms, true)) {
			return '<td class="ml-cell-na ' . $typeClass . '"' . $colAttr . ' title="'
				. $san->entities(sprintf(
					$this->_('%1$s is not configured on %2$s'),
					$name,
					(string) $row['fieldName']
				)) . '">—</td>';
		}
		$raw = $row['custom'][$name] ?? '';
		$val = $this->normalizeDescription($raw);
		// Page-reference fields render PW's configured inputfield
		// (PageAutocomplete / PageListSelect / ASMSelect / …) in
		// the popup — JS fetches the rendered HTML from
		// ___executeWidget, injects it, fires the 'reloaded' DOM
		// event so each inputfield's own JS initialises on the
		// new nodes. No more inline checkbox-list / multi-select.
		$inlineTyped = in_array($inputType, ['checkbox', 'date', 'number', 'select', 'page'], true);
		if (in_array($inputType, ['text', 'textarea'], true)) {
			// Inline-editable prose cell.
			$customAria = sprintf(' aria-label="%s"', $san->entities(sprintf(
				$this->_('Edit %1$s of %2$s'), $name, (string) $row['basename']
			)));
			// Only textarea-backed customs get the clamp box; single-
			// line text customs are short and stay a plain text node.
			$customInner = $inputType === 'textarea'
				? $this->clampCell((string) $val)
				: $san->entities((string) $val);
			return '<td class="ml-cell-editable ' . $typeClass . '"' . $colAttr . ' ' . $editAttrs . $editA11y . $customAria
				. ' data-subfield="' . $san->entities($name) . '"'
				. ' data-input="' . $san->entities($inputType) . '"'
				. $this->buildLangAttrs($raw) . '>'
				. $customInner . '</td>';
		} elseif ($inlineTyped && !empty($row['pageEditUrl'])) {
			// Inline-editable typed cell. Display shows the typed
			// value (glyph / date / label); data-value carries
			// the editor-RAW value. select + date carry their
			// type-specific config attrs. page-ref defers the
			// inputfield render to ___executeWidget; the cell
			// just needs the rawVal for change-detection.
			$rawVal = (string) ($row['customRaw'][$name] ?? '');
			$typedExtra = ' data-value="' . $san->entities($rawVal) . '"';
			if ($inputType === 'select') {
				$typedExtra .= " data-options='" . $san->entities(
					json_encode($this->getCustomOptions($name), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
				) . "'";
				$sf = $this->wire('fields')->get($name);
				if ($sf instanceof Field && !$this->isSingleValueInput($sf)) {
					$typedExtra .= ' data-multiple="1"';
				}
			} elseif ($inputType === 'date') {
				$df = $this->wire('fields')->get($name);
				if ($df instanceof Field && $this->dateHasTime($df)) $typedExtra .= ' data-datetime="1"';
			}
			$customAria = sprintf(' aria-label="%s"', $san->entities(sprintf(
				$this->_('Edit %1$s of %2$s'), $name, (string) $row['basename']
			)));
			return '<td class="ml-cell-editable ' . $typeClass . '"' . $colAttr . ' ' . $editAttrs . $editA11y . $customAria
				. ' data-subfield="' . $san->entities($name) . '"'
				. ' data-input="' . $san->entities($inputType) . '"' . $typedExtra . '>'
				. $san->entities((string) $val) . '</td>';
		} elseif (!empty($row['pageEditUrl'])) {
			// Only page-refs whose selectable set can't be a bounded
			// inline select (autocomplete / huge / custom find code)
			// fall back to the native per-image editor.
			$nativeAria = sprintf(' aria-label="%s"', $san->entities(sprintf(
				$this->_('Edit %1$s of %2$s in the image editor'), $name, (string) $row['basename']
			)));
			return '<td class="ml-cell-native ' . $typeClass . '"' . $colAttr . ' ' . $editAttrs . ' role="button" tabindex="0"' . $nativeAria
				. ' data-subfield="' . $san->entities($name) . '"'
				. ' data-input="' . $san->entities($inputType) . '">'
				. $san->entities((string) $val) . '</td>';
		} else {
			// Typed subfield on a non-editable page → display only.
			return '<td class="' . $typeClass . '"' . $colAttr . '>'
				. $san->entities((string) $val) . '</td>';
		}
	}

	/**
	 * Hover-revealed download button (bottom-right of the thumb) for one row —
	 * a native <a download> pointing at the ORIGINAL file. Read-only, so it's
	 * shown on every row regardless of edit rights; suppressed in the picker
	 * (you're choosing an image there, not managing files) and when the row
	 * carries no resolved file URL. Shared by the table + tile renderers.
	 */
	protected function renderDownloadButton(array $row): string {
		if ($this->pickerMode || empty($row['downloadUrl'])) return '';
		// Duplicates carry no per-file download: the tile / row represents N
		// byte-identical copies, so a single "download this one" is ambiguous
		// (and the masonry dup tile opens the cluster modal instead).
		if ((int) ($row['dupCount'] ?? 0) >= 2) return '';
		$san   = $this->wire('sanitizer');
		$label = $san->entities(sprintf($this->_('Download %s'), (string) $row['basename']));
		return '<a class="ml-download-btn" href="' . $san->entities((string) $row['downloadUrl']) . '"'
			. ' download="' . $san->entities((string) $row['basename']) . '"'
			. ' title="' . $label . '" aria-label="' . $label . '">'
			. '<i class="fa fa-download" aria-hidden="true"></i></a>';
	}

	/**
	 * Render the thumbnail <td> for one table row. Clickable (opens the
	 * per-image editor modal) with hover replace / delete actions when the host
	 * page is editable; a duplicate-cluster head also carries the expand toggle.
	 * <img> dimensions come from thumbDisplayDims() so the box is reserved
	 * before the bytes land.
	 */
	protected function renderThumbCell(array $row, string $editAttrs, string $editA11y, bool $isDupHead, string $rowDupHash, array $thumb): string {
		$san = $this->wire('sanitizer');
		$out = '';
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
			[$dispW, $dispH, $cls] = $this->thumbDisplayDims(
				$thumb,
				(int) ($row['thumbWidth']  ?? 0),
				(int) ($row['thumbHeight'] ?? 0)
			);
			$out .= '<img class="' . $cls . '"'
				. ' src="' . $san->entities($row['thumbUrl']) . '"'
				. ' alt="' . $san->entities($row['basename']) . '"'
				. ' loading="lazy"'
				. ' width="' . $dispW . '"'
				. ' height="' . $dispH . '">';
		}
		// Per-row actions — both icons hang in the top-right of the
		// thumb cell and are visible only on row hover. Replace
		// triggers the file picker / accepts a row DnD; Delete
		// opens a confirm dialog. Batch semantics for Delete
		// follow the existing paintbrush: when N rows are
		// selected, clicking Delete on any selected row's icon
		// deletes the whole selection.
		if (!empty($row['pageEditUrl'])) {
			$replaceLabel = $san->entities(sprintf(
				$this->_('Replace %s'), (string) $row['basename']
			));
			$deleteLabel = $san->entities(sprintf(
				$this->_('Delete %s'), (string) $row['basename']
			));
			$out .= '<button type="button" class="ml-replace-btn"'
				. ' title="' . $replaceLabel . '"'
				. ' aria-label="' . $replaceLabel . '">'
				. '<i class="fa fa-upload" aria-hidden="true"></i>'
				. '</button>';
			$out .= '<button type="button" class="ml-delete-btn"'
				. ' title="' . $deleteLabel . '"'
				. ' aria-label="' . $deleteLabel . '">'
				. '<i class="fa fa-trash-o" aria-hidden="true"></i>'
				. '</button>';
		}
		// Head of a duplicate cluster → expand/collapse toggle. Copy rows
		// and unique images carry no indicator.
		if ($isDupHead) {
			$out .= $this->renderDupToggle((int) ($row['dupCount'] ?? 0), $rowDupHash);
		}
		$out .= $this->renderDownloadButton($row);
		$out .= '</td>';
		return $out;
	}

	/**
	 * Render the table's opening chrome: the responsive scroller wrapper, the
	 * <table>, and the <thead> (select-all box + sortable column headers, base
	 * and custom), through the opening <tbody>. The caller appends the rows.
	 *
	 * @param array<int,array{0:string,1:string,2:?string}> $headers
	 * @param array<int,string> $customCols
	 */
	protected function renderTableHead(array $headers, array $customCols, string $sort, string $dir, array $filters): string {
		$san = $this->wire('sanitizer');
		// Outer scroller so the wide table can overflow horizontally
		// on narrow viewports without breaking the table layout.
		// pw-table-responsive + uk-overflow-auto handle horizontal
		// scroll on narrow viewports the same way every other PW
		// data table does. pw-table-sortable is included because
		// the inner table carries .AdminDataTableSortable; the
		// wrapper class is what some PW-side JS hooks check.
		$out  = '<div class="ml-table-scroll pw-table-responsive uk-overflow-auto pw-table-sortable">';
		// Class set is intentional, every entry carries weight:
		//   ml-table         — module-side hooks
		//   AdminDataTable   — non-Uikit themes (Reno, Default) pick
		//                      up their own admin-table chrome here
		//   AdminDataTableSortable — paired with tablesorter-* classes
		//                      below to inherit the theme's sort
		//                      styling (active-asc / desc colour,
		//                      FontAwesome arrow glyphs) without
		//                      re-implementing it module-side
		//   uk-table*        — active styling under AdminThemeUikit
		$out .= '<table class="ml-table AdminDataTable AdminDataTableSortable uk-table uk-table-divider uk-table-small">';
		// tablesorter-headerRow matches AdminThemeUikit's compound
		// selector for the sort-state visuals.
		$out .= '<thead><tr class="tablesorter-headerRow">';
		$out .= '<th class="ml-cell-select">'
			. '<input type="checkbox" class="uk-checkbox ml-select-all" title="'
			. $san->entities($this->_('Select all on page')) . '"></th>';
		foreach ($headers as [$colKey, $label, $sortKey]) {
			$out .= $this->renderSortableHeader($colKey, $label, $sortKey, $sort, $dir, $filters);
		}
		foreach ($customCols as $name) {
			$out .= $this->renderSortableHeader('custom:' . $name, $name, 'custom:' . $name, $sort, $dir, $filters);
		}
		$out .= '</tr></thead><tbody>';
		return $out;
	}

	/**
	 * Display width / height (px) + <img> class for one table thumbnail.
	 * Keep-ratio mode caps the longer axis to the configured longerSide and
	 * scales the other to the source aspect; crop mode is a fixed W×H box (CSS
	 * object-fit absorbs any overflow). Returns [width, height, cssClass].
	 *
	 * @param array<string,mixed> $thumb
	 * @return array{0:int,1:int,2:string}
	 */
	protected function thumbDisplayDims(array $thumb, int $srcW, int $srcH): array {
		if ($thumb['keepRatio']) {
			$longer = (int) $thumb['longerSide'];
			if ($srcW >= $srcH) {
				$dispW = $srcW > 0 ? min($longer, $srcW) : $longer;
				$dispH = $srcW > 0 ? (int) round($srcH * $dispW / $srcW) : $srcH;
			} else {
				$dispH = $srcH > 0 ? min($longer, $srcH) : $longer;
				$dispW = $srcH > 0 ? (int) round($srcW * $dispH / $srcH) : $srcW;
			}
			return [$dispW, $dispH, 'ml-thumb'];
		}
		return [(int) $thumb['width'], (int) $thumb['height'], 'ml-thumb ml-thumb-crop'];
	}

	/**
	 * Render one <th>. If $sortKey is null the header is plain text;
	 * otherwise it becomes a link that, when clicked, sets sort=$sortKey
	 * and toggles dir (asc → desc → asc) while preserving the current
	 * filters.
	 */
	protected function renderSortableHeader(string $colKey, string $label, ?string $sortKey, string $currentSort, string $currentDir, array $filters): string {
		$san = $this->wire('sanitizer');
		$colAttr = ' data-col="' . $san->entities($colKey) . '"';
		$labelHtml = $san->entities($label);

		if ($sortKey === null) {
			return '<th' . $colAttr . '>' . $labelHtml . '</th>';
		}

		$isActive = $currentSort === $sortKey;
		$nextDir  = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
		// Reset to page 1 — page numbers don't map across sort changes.
		$href     = $this->buildUrl($filters, 1, $sortKey, $nextDir);

		// Use the same class names AdminThemeUikit's sort styles
		// target, so its compound selector
		// `.uk-table.AdminDataTableSortable tr.tablesorter-headerRow
		// th.tablesorter-headerAsc` (etc.) matches and we inherit the
		// active-colour, hover, FontAwesome arrow rules for free. The
		// .tablesorter-header-inner wrapper inside the <th> is the
		// hook the theme's ::after glyph attaches to.
		if ($isActive) {
			$thCls = $currentDir === 'asc' ? 'tablesorter-headerAsc' : 'tablesorter-headerDesc';
		} else {
			$thCls = 'tablesorter-headerUnSorted';
		}
		$thCls .= ' ml-th-sortable';

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

		// Native PW tablesorter renders the inner as a <div>; the
		// theme's ::after arrow glyph + colour rules assume a
		// block-level element with default inline-flow children.
		// Keep our server-side <a> as a click wrapper *around* a
		// <div class="tablesorter-header-inner"> so the styled
		// element matches the original element type 1:1.
		return '<th class="' . $thCls . '"' . $colAttr . $ariaSort . '>'
			. '<a href="' . $san->entities($href) . '" aria-label="'
			. $san->entities($linkAria) . '">'
			. '<div class="tablesorter-header-inner">' . $labelHtml . '</div>'
			. '</a></th>';
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
		// Thumbnail size slider — per-user view zoom. JS updates the
		// --ml-thumb-scale CSS var live and persists the value to
		// $user->meta (debounced). Server renders the saved value so it's
		// in sync after every AJAX swap.
		// Empty <span> that the JS turns into a jQuery-UI slider (same
		// widget PW's InputfieldImage size slider uses). Bounds + the
		// saved value ride in data-* so the slider is configured before
		// init; the JS reads them.
		$sizeLabel = $san->entities($this->_('Thumbnail size'));
		$out .= '<span class="ml-thumb-size" title="' . $sizeLabel . '">'
			. '<span class="ml-thumb-size-slider"'
			. ' data-min="' . self::THUMB_SCALE_MIN . '" data-max="' . self::THUMB_SCALE_MAX . '" data-step="0.1"'
			. ' data-value="' . rtrim(rtrim(number_format($this->getThumbScale(), 2, '.', ''), '0'), '.') . '"'
			. ' aria-label="' . $sizeLabel . '"></span>'
			. '</span>';

		// View-mode toggle — table (data grid) vs masonry (thumbnail
		// gallery). Anchors carry a ?view= URL so the choice is bookmark-
		// /reload-safe and degrades without JS; the JS intercepts the
		// click and AJAX-swaps the results in place. The server both
		// honours and persists the chosen mode (see getViewMode()).
		$currentView = $this->getViewMode();
		// No table/masonry switch in the picker — it's masonry-only.
		if (!$this->pickerMode) {
			$out .= $this->renderViewToggle($filters, $page, $sort, $dir, $pageSize);
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

		// Icon-only opener for the column-visibility dialog (rendered
		// as a sibling of .ml-results). The <i> stays decorative;
		// the anchor itself carries the accessible name via
		// aria-label / title. Without JS the picker is unavailable —
		// no href, the JS click handler runs the open.
		// Masonry / duplicates are thumbnail views — no columns to toggle, so
		// the column-visibility opener is table-view only.
		if ($currentView === self::VIEW_TABLE) {
			$colsLabel = $san->entities($this->_('Columns'));
			$out .= '<a class="ml-columns-toggle"'
				. ' title="' . $colsLabel . '"'
				. ' aria-label="' . $colsLabel . '">'
				. '<i class="ml-vicon ml-vicon-columns" aria-hidden="true"></i>'
				. '</a>';
		}
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
			'dupes'          => !empty($filters['dupes']) ? '1' : '',
			// Collection recall id only — NOT the resolved key list ('sel'),
			// which stays server-side so pagination links stay short.
			'coll'           => (string) ($filters['coll'] ?? ''),
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
	 * Install: create admin page under Setup and the access permission, make
	 * the automatic dedup self-sustaining (LazyCron), and kick off a first
	 * budgeted fingerprint + reclaim pass so existing duplicates start
	 * collapsing immediately (LazyCron + saves finish any backlog).
	 */
	public function ___install() {
		parent::___install();

		$permissions = $this->wire('permissions');
		if (!$permissions->get(self::PERMISSION_NAME)->id) {
			$p = $permissions->add(self::PERMISSION_NAME);
			$p->title = $this->_('Access the Image Library admin page');
			$p->save();
			$this->message("Created permission: " . self::PERMISSION_NAME);
		}
		if (!$permissions->get(self::PERMISSION_MANAGE_SHARED)->id) {
			$p = $permissions->add(self::PERMISSION_MANAGE_SHARED);
			$p->title = $this->_('Manage shared Image Library bookmarks and collections');
			$p->save();
			$this->message("Created permission: " . self::PERMISSION_MANAGE_SHARED);
		}

		// LazyCron powers the hourly maintenance safety net — install it so
		// auto-reclaim keeps running without any manual trigger.
		try { $this->wire('modules')->getInstall('LazyCron'); } catch (\Throwable $e) {}

		// First pass now (bounded), so newly-installed sites with existing
		// duplicates reclaim space right away instead of waiting for cron.
		$this->runMaintenancePass(15);
	}

	/**
	 * Runs automatically when the installed version is older than the file
	 * version (PW calls it on Modules → Refresh). ___install() does NOT run on
	 * upgrade, so a site coming from a build that predated the shared-store
	 * feature never got its permission — without which the "Share with the team"
	 * checkbox can't appear for non-superusers. Create it here, idempotently.
	 *
	 * @param int|string $fromVersion
	 * @param int|string $toVersion
	 */
	public function ___upgrade($fromVersion, $toVersion) {
		$permissions = $this->wire('permissions');
		if (!$permissions->get(self::PERMISSION_MANAGE_SHARED)->id) {
			$p = $permissions->add(self::PERMISSION_MANAGE_SHARED);
			$p->title = $this->_('Manage shared Image Library bookmarks and collections');
			$p->save();
			$this->message("Created permission: " . self::PERMISSION_MANAGE_SHARED);
		}
	}

	/**
	 * Uninstall: remove admin page and clear module cache entries.
	 */
	public function ___uninstall() {
		$cache = $this->wire('cache');
		$cache->deleteFor($this, '*');
		$this->dropHashTable();
		$this->dropUsageTable();

		$permissions = $this->wire('permissions');
		$shared = $permissions->get(self::PERMISSION_MANAGE_SHARED);
		if ($shared->id) $permissions->delete($shared);

		parent::___uninstall();
	}
}
