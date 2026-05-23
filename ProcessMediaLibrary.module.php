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
 * See MediaLibrary-Konzept.md for the full plan.
 */
class ProcessMediaLibrary extends Process {

	const ADMIN_PAGE_NAME = 'media-library';
	const PERMISSION_NAME = 'media-library-access';
	const CACHE_PREFIX = 'media-library-';

	/**
	 * Image subfields requested from every FieldtypeImage field via findRaw.
	 *
	 * Order is irrelevant for findRaw but is preserved when flattening so the
	 * resulting row arrays have a predictable shape for downstream phases.
	 */
	const STANDARD_SUBFIELDS = [
		'basename',
		'description',
		'tags',
		'filesize',
		'width',
		'height',
		'ext',
	];

	/**
	 * Render the main media library admin page.
	 *
	 * Phase 2 only emits a pipeline smoke summary. Phase 3 will replace this
	 * with the server-rendered filter bar and table shell.
	 */
	public function ___execute() {
		if ($this->wire('input')->get('debug')) {
			return $this->renderDebug();
		}
		$sanitizer = $this->wire('sanitizer');
		$imageFields = $this->discoverImageFields();
		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);
		$rows = $this->loadRows();

		$out  = '<div class="ml-phase-status">';
		$out .= '<h2>' . $sanitizer->entities($this->_('Media Library — Phase 2 pipeline check')) . '</h2>';
		$out .= '<ul class="uk-list uk-list-bullet">';
		$out .= '<li>'
			. $sanitizer->entities($this->_('Image fields discovered:'))
			. ' <strong>' . count($imageFields) . '</strong>'
			. ($imageFields ? ' (' . $sanitizer->entities(implode(', ', $imageFields)) . ')' : '')
			. '</li>';
		$out .= '<li>'
			. $sanitizer->entities($this->_('Eligible templates:'))
			. ' <strong>' . count($eligibleTemplates) . '</strong>'
			. ($eligibleTemplates ? ' (' . $sanitizer->entities(implode(', ', $eligibleTemplates)) . ')' : '')
			. '</li>';
		$out .= '<li>'
			. $sanitizer->entities($this->_('Total image rows:'))
			. ' <strong>' . count($rows) . '</strong></li>';
		$out .= '</ul>';
		$out .= '<p class="uk-text-meta">'
			. $sanitizer->entities($this->_('Render UI follows in Phase 3. Append ?debug=1 for a pipeline dump.'))
			. '</p>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * Render a verbose dump of every pipeline intermediate for diagnostics.
	 *
	 * Hit /processwire/setup/media-library/?debug=1. Kept around through
	 * Phase 3 so we can verify findRaw shape and selector output against a
	 * real install without temporary log statements.
	 */
	protected function renderDebug(): string {
		$sanitizer = $this->wire('sanitizer');
		$pages = $this->wire('pages');

		$imageFields = $this->discoverImageFields();
		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);
		$customByField = [];
		foreach ($imageFields as $f) {
			$customByField[$f] = $this->discoverCustomFields($f);
		}
		$rawFields = $this->buildRawFields($imageFields, $customByField);
		$selector  = $this->buildSelector($eligibleTemplates, []);
		$pageCount = $eligibleTemplates ? $pages->count($selector) : 0;
		$rawData   = $eligibleTemplates ? $pages->findRaw($selector, $rawFields) : [];
		$rows      = $this->flattenRows($rawData, $imageFields);

		$out  = '<div class="ml-debug">';
		$out .= '<h2>' . $sanitizer->entities($this->_('Phase 2 pipeline debug')) . '</h2>';
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
		if ($rows) {
			$out .= '<dt>First flattened row</dt>';
			$out .= '<dd><pre>'
				. $sanitizer->entities(json_encode(
					$rows[0],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				))
				. '</pre></dd>';
		}
		$out .= '</dl>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * AJAX endpoint: returns paginated rows + total count + filter summaries as JSON.
	 *
	 * Phase 2 returns the full row list with a hard 50-row cap on the response
	 * body; pagination, sort, and filters land in Phase 4.
	 */
	public function ___executeData() {
		$this->wire('config')->ajax = true;
		header('Content-Type: application/json');
		$rows = $this->loadRows();
		return json_encode([
			'total' => count($rows),
			'rows'  => array_slice($rows, 0, 50),
		]);
	}

	/**
	 * AJAX endpoint: validates and persists a single cell change.
	 *
	 * Expects POST: { pageId, fieldName, basename, subfield, value }
	 * Returns JSON: { ok, value, error? }
	 */
	public function ___executeSave() {
		$this->wire('config')->ajax = true;
		header('Content-Type: application/json');
		return json_encode(['ok' => false, 'error' => 'not implemented']);
	}

	/**
	 * Load the full flat image-row list across all pages.
	 *
	 * Orchestrates field-discovery → eligible-templates → custom-field-discovery
	 * → findRaw → flatten. No caching yet (Phase 5), no row-level filtering
	 * yet (Phase 4); the $filters argument currently only narrows the
	 * page-level template selector.
	 *
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function loadRows(array $filters = []): array {
		$imageFields = $this->discoverImageFields();
		if (!$imageFields) return [];

		$eligibleTemplates = $this->discoverEligibleTemplates($imageFields);
		if (!$eligibleTemplates) return [];

		$customByField = [];
		foreach ($imageFields as $f) {
			$customByField[$f] = $this->discoverCustomFields($f);
		}

		$rawFields = $this->buildRawFields($imageFields, $customByField);
		$selector  = $this->buildSelector($eligibleTemplates, $filters);
		$rawData   = $this->wire('pages')->findRaw($selector, $rawFields);

		return $this->flattenRows($rawData, $imageFields);
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
	 * Build the field list passed to $pages->findRaw().
	 *
	 * @param array<int,string> $imageFields
	 * @param array<string,array<int,string>> $customByField
	 * @return array<int,string>
	 */
	protected function buildRawFields(array $imageFields, array $customByField): array {
		$fields = ['id', 'title', 'templates_id'];
		foreach ($imageFields as $f) {
			foreach (self::STANDARD_SUBFIELDS as $sub) {
				$fields[] = "$f.$sub";
			}
			foreach ($customByField[$f] ?? [] as $sub) {
				$fields[] = "$f.$sub";
			}
		}
		return $fields;
	}

	/**
	 * Build the page-level selector for findRaw.
	 *
	 * Currently honors $filters['template'] (string or array): intersected with
	 * the eligible set, returns 'id=0' if the intersection is empty so callers
	 * get an empty result instead of an unbounded query.
	 *
	 * @param array<int,string> $eligibleTemplates
	 * @param array<string,mixed> $filters
	 */
	protected function buildSelector(array $eligibleTemplates, array $filters = []): string {
		if (!$eligibleTemplates) return 'id=0';
		$templates = $eligibleTemplates;
		if (!empty($filters['template'])) {
			$requested = is_array($filters['template']) ? $filters['template'] : [$filters['template']];
			$templates = array_values(array_intersect($requested, $eligibleTemplates));
			if (!$templates) return 'id=0';
		}
		// include=hidden returns published + hidden, excludes unpublished and trash.
		// status<=hidden does NOT work for this — hidden pages have status 1025
		// (1 | Page::statusHidden), so a numeric <= 1024 filter excludes them.
		return 'template=' . implode('|', $templates) . ', include=hidden';
	}

	/**
	 * Flatten the findRaw result into one row per (pageId, fieldName, basename).
	 *
	 * Handles both list-shape (multi-image) and assoc-shape (maxFiles=1) field
	 * payloads. Skips fields that are null, empty, or shaped unexpectedly.
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
				$items = isset($payload['basename']) ? [$payload] : $payload;
				foreach ($items as $img) {
					if (!is_array($img) || empty($img['basename'])) continue;
					$rows[] = [
						'pageId'      => (int) $pageId,
						'pageTitle'   => $pageTitle,
						'templateId'  => $templateId,
						'fieldName'   => $fieldName,
						'basename'    => $img['basename'],
						'description' => $img['description'] ?? '',
						'tags'        => $img['tags'] ?? '',
						'filesize'    => (int) ($img['filesize'] ?? 0),
						'width'       => (int) ($img['width'] ?? 0),
						'height'      => (int) ($img['height'] ?? 0),
						'ext'         => $img['ext'] ?? '',
						'custom'      => array_diff_key($img, $standardKeys),
					];
				}
			}
		}
		return $rows;
	}

	/**
	 * Returns the template-name blacklist from module settings, if any.
	 *
	 * Accepts both an array (modern module-config form field) and a
	 * comma/whitespace-separated string (legacy text input). The actual
	 * settings UI lands in a later phase; this getter is forward-compatible.
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
	 *
	 * Leaves user-meta (mediaLibraryColumns) and the permission in place —
	 * those are user-owned state, not module state.
	 */
	public function ___uninstall() {
		$cache = $this->wire('cache');
		$cache->deleteFor($this, '*');

		parent::___uninstall();
	}
}
