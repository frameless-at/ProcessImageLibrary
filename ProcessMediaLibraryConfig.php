<?php namespace ProcessWire;

/**
 * Admin config UI for ProcessMediaLibrary. PW picks this up via
 * info.json's "configurable": "ProcessMediaLibraryConfig.php".
 *
 * We override getInputfields() programmatically so the dynamic
 * option lists (custom subfields, eligible templates, image fields)
 * can be filled live from the install at render time.
 */
class ProcessMediaLibraryConfig extends ModuleConfig {

	public function getInputfields() {
		$inputfields = parent::getInputfields();
		$modules = $this->wire('modules');

		// --- Thumbnail rendering ---
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Thumbnail');
		$fs->description = $this->_('Per-row preview image rendered into the table. Larger = better preview but more bytes per page; quality lower = faster generation + smaller cache.');

		$f = $modules->get('InputfieldInteger');
		$f->name = 'thumbWidth';
		$f->label = $this->_('Width (px)');
		$f->value = (int) ($this->get('thumbWidth') ?: ProcessMediaLibrary::THUMB_WIDTH_DEFAULT);
		$f->min = 16;
		$f->notes = sprintf($this->_('Default: %d'), ProcessMediaLibrary::THUMB_WIDTH_DEFAULT);
		$f->columnWidth = 33;
		$fs->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'thumbHeight';
		$f->label = $this->_('Height (px)');
		$f->value = (int) ($this->get('thumbHeight') ?: ProcessMediaLibrary::THUMB_HEIGHT_DEFAULT);
		$f->min = 16;
		$f->notes = sprintf($this->_('Default: %d'), ProcessMediaLibrary::THUMB_HEIGHT_DEFAULT);
		$f->columnWidth = 33;
		$fs->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'thumbQuality';
		$f->label = $this->_('JPEG quality (1–100)');
		$f->value = (int) ($this->get('thumbQuality') ?: ProcessMediaLibrary::THUMB_QUALITY_DEFAULT);
		$f->min = 1;
		$f->max = 100;
		$f->notes = sprintf($this->_('Default: %d'), ProcessMediaLibrary::THUMB_QUALITY_DEFAULT);
		$f->columnWidth = 34;
		$fs->add($f);

		// Crop toggle. InputfieldCheckbox comes with checkedValue='1'
		// and uncheckedValue='' out of the box, so submission of an
		// unchecked box persists as '' (false-y) and a checked one as
		// '1' (truthy) — exactly what the runtime helper expects.
		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'thumbCrop';
		$f->label = $this->_('Crop thumbnail to exact dimensions');
		$f->label2 = $this->_('Enabled');
		$f->description = $this->_('When enabled, the thumb fills the full width × height box (center-crop). When disabled, the thumb fits within the box keeping the original aspect ratio — heights may vary per row.');
		$saved = $this->get('thumbCrop');
		// null = never saved → default ON to match PW's $img->size()
		// default + the pre-config behaviour.
		$f->checked(($saved === null) ? true : (bool) $saved);
		$f->notes = $this->_('Default: enabled');
		$f->columnWidth = 100;
		$fs->add($f);

		$inputfields->add($fs);

		// --- Pagination ---
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Pagination');

		$f = $modules->get('InputfieldText');
		$f->name = 'pageSizeOptions';
		$f->label = $this->_('Page-size options');
		$f->description = $this->_('Comma- or space-separated list of integers shown in the per-page picker.');
		$f->value = (string) ($this->get('pageSizeOptions') ?? '25, 50, 100, 200');
		$f->notes = $this->_('Default: ') . implode(', ', ProcessMediaLibrary::PAGE_SIZE_OPTIONS);
		$f->columnWidth = 60;
		$fs->add($f);

		// Select instead of free-text integer: forces a choice from the
		// currently-saved page-size options. If the admin edits the
		// options list, the select repopulates on next config render;
		// a stale saved value snaps to PAGE_SIZE_DEFAULT (or the first
		// option) so the dropdown always shows a valid selection.
		$psOpts = $this->parsePageSizeOptions((string) ($this->get('pageSizeOptions') ?? ''));
		$f = $modules->get('InputfieldSelect');
		$f->name = 'defaultPageSize';
		$f->label = $this->_('Default page size');
		$f->description = $this->_('Initial slice for users with no preference yet. Drawn from the options above.');
		foreach ($psOpts as $n) $f->addOption((string) $n, (string) $n);
		$savedPs = (int) $this->get('defaultPageSize');
		$f->value = in_array($savedPs, $psOpts, true)
			? (string) $savedPs
			: (in_array(ProcessMediaLibrary::PAGE_SIZE_DEFAULT, $psOpts, true)
				? (string) ProcessMediaLibrary::PAGE_SIZE_DEFAULT
				: (string) ($psOpts[0] ?? ProcessMediaLibrary::PAGE_SIZE_DEFAULT));
		$f->notes = sprintf($this->_('Default: %d'), ProcessMediaLibrary::PAGE_SIZE_DEFAULT);
		$f->required = true;
		$f->columnWidth = 40;
		$fs->add($f);

		$inputfields->add($fs);

		// --- Default sort ---
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Default sort');
		$fs->description = $this->_('Sort applied on a fresh visit. The URL\'s ?sort= and clicking a column header still win.');

		$f = $modules->get('InputfieldSelect');
		$f->name = 'defaultSort';
		$f->label = $this->_('Column');
		foreach (array_keys(ProcessMediaLibrary::SORTABLE_COLUMNS) as $key) {
			$f->addOption($key, $key);
		}
		$savedSort = (string) $this->get('defaultSort');
		$f->value = array_key_exists($savedSort, ProcessMediaLibrary::SORTABLE_COLUMNS)
			? $savedSort
			: ProcessMediaLibrary::DEFAULT_SORT;
		$f->notes = sprintf($this->_('Default: %s'), ProcessMediaLibrary::DEFAULT_SORT);
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldRadios');
		$f->name = 'defaultSortDir';
		$f->label = $this->_('Direction');
		$f->addOption('asc',  $this->_('Ascending'));
		$f->addOption('desc', $this->_('Descending'));
		$f->value = ((string) $this->get('defaultSortDir') === 'desc') ? 'desc' : 'asc';
		$f->notes = sprintf($this->_('Default: %s'), ProcessMediaLibrary::DEFAULT_DIR);
		$f->columnWidth = 50;
		$fs->add($f);

		$inputfields->add($fs);

		// --- Columns ---
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Columns');
		$fs->description = $this->_('Pick which columns render hidden on a fresh visit. Users can still toggle them on per browser via the Columns picker — this just sets the default.');

		$f = $modules->get('InputfieldCheckboxes');
		$f->name = 'defaultHiddenColumns';
		$f->label = $this->_('Hidden by default');
		$f->optionColumns = 3;
		foreach ($this->buildColumnOptions() as $key => $label) {
			$f->addOption($key, $label);
		}
		$val = $this->get('defaultHiddenColumns');
		$f->value = is_array($val) ? $val : [];
		$f->notes = $this->_('Default: all columns visible');
		$fs->add($f);

		$inputfields->add($fs);

		// --- Scope ---
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Scope');
		$fs->description = $this->_('Narrow down what the library considers part of its content set. Use either toggle when the discovery default sweeps in pages or fields you don\'t want to manage from here.');

		// Template list = every template that hosts at least one
		// image field. System templates (admin, user, role, …) are
		// included if they happen to carry one, which matches the
		// field description and the runtime behaviour of
		// discoverEligibleTemplates(). Templates without any image
		// field would be no-ops to blacklist, so they're omitted to
		// keep the picker focused.
		$f = $modules->get('InputfieldAsmSelect');
		$f->name = 'blacklistedTemplates';
		$f->label = $this->_('Blacklisted templates');
		$f->description = $this->_('Templates whose pages are excluded from discovery — typical use is to hide admin / system templates that happen to carry an image field.');
		foreach ($this->wire('templates') as $tpl) {
			$hasImage = false;
			foreach ($tpl->fieldgroup as $field) {
				if ($field->type instanceof FieldtypeImage) {
					$hasImage = true;
					break;
				}
			}
			if (!$hasImage) continue;
			$f->addOption($tpl->name, $tpl->name);
		}
		$val = $this->get('blacklistedTemplates');
		$f->value = is_array($val) ? $val : [];
		$f->notes = $this->_('Default: none');
		$f->columnWidth = 50;
		$fs->add($f);

		// Field list = every FieldtypeImage in the system. More
		// efficient than blacklisting every template that uses the
		// field, when the field is the wrong scope (e.g. a
		// signature_image or internal_scan field used across many
		// templates).
		$f = $modules->get('InputfieldAsmSelect');
		$f->name = 'blacklistedFields';
		$f->label = $this->_('Blacklisted image fields');
		$f->description = $this->_('Image fields whose entries are excluded from discovery — useful when a field carries content you don\'t want to surface here regardless of which template it lives on.');
		foreach ($this->wire('fields') as $field) {
			if ($field->type instanceof FieldtypeImage) {
				$f->addOption($field->name, $field->name);
			}
		}
		$val = $this->get('blacklistedFields');
		$f->value = is_array($val) ? $val : [];
		$f->notes = $this->_('Default: none');
		$f->columnWidth = 50;
		$fs->add($f);

		$inputfields->add($fs);

		return $inputfields;
	}

	/**
	 * Parse the raw pageSizeOptions string the same way the runtime
	 * does, so the defaultPageSize select stays in sync without
	 * re-implementing the validation.
	 *
	 * @return array<int,int>
	 */
	protected function parsePageSizeOptions(string $raw): array {
		$raw = trim($raw);
		if ($raw === '') return ProcessMediaLibrary::PAGE_SIZE_OPTIONS;
		$opts = array_values(array_unique(array_filter(
			array_map('intval', preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []),
			fn($n) => $n > 0
		)));
		sort($opts);
		return $opts ?: ProcessMediaLibrary::PAGE_SIZE_OPTIONS;
	}

	/**
	 * Built-in column keys + their labels, plus an auto-discovered
	 * entry per custom subfield across all image fields. Mirrors
	 * renderColumnsListMarkup() so the config UI lists exactly the
	 * same togglable columns the frontend exposes.
	 *
	 * @return array<string,string>
	 */
	protected function buildColumnOptions(): array {
		$opts = [
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
		$instance = $this->wire('modules')->get('ProcessMediaLibrary');
		if ($instance instanceof ProcessMediaLibrary) {
			foreach ($instance->collectCustomNames() as $name) {
				$opts['custom:' . $name] = $name . ' ' . $this->_('(custom)');
			}
		}
		return $opts;
	}
}
