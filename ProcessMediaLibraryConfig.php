<?php namespace ProcessWire;

/**
 * Admin config UI for ProcessMediaLibrary. Auto-loaded by PW when
 * the user opens the module's settings screen — `wire('modules')`
 * resolves "ProcessMediaLibrary" + "Config" by convention.
 */
class ProcessMediaLibraryConfig extends ModuleConfig {

	public function getDefaults() {
		return [
			'thumbWidth'           => ProcessMediaLibrary::THUMB_WIDTH_DEFAULT,
			'thumbHeight'          => ProcessMediaLibrary::THUMB_HEIGHT_DEFAULT,
			'thumbQuality'         => ProcessMediaLibrary::THUMB_QUALITY_DEFAULT,
			'thumbCrop'            => 1,
			'pageSizeOptions'      => '25, 50, 100, 200',
			'defaultPageSize'      => ProcessMediaLibrary::PAGE_SIZE_DEFAULT,
			'defaultHiddenColumns' => [],
			'blacklistedTemplates' => [],
		];
	}

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
		$f->columnWidth = 33;
		$fs->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'thumbHeight';
		$f->label = $this->_('Height (px)');
		$f->value = (int) ($this->get('thumbHeight') ?: ProcessMediaLibrary::THUMB_HEIGHT_DEFAULT);
		$f->min = 16;
		$f->columnWidth = 33;
		$fs->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'thumbQuality';
		$f->label = $this->_('JPEG quality (1–100)');
		$f->value = (int) ($this->get('thumbQuality') ?: ProcessMediaLibrary::THUMB_QUALITY_DEFAULT);
		$f->min = 1;
		$f->max = 100;
		$f->columnWidth = 34;
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'thumbCrop';
		$f->label = $this->_('Crop thumbnail to exact dimensions');
		$f->label2 = $this->_('Enabled');
		$f->description = $this->_('When enabled, the thumb fills the full width × height box (center-crop). When disabled, the thumb fits within the box keeping the original aspect ratio — heights may vary per row.');
		// PW InputfieldCheckbox reads "1"/"" — null on the very first
		// load (no module data yet) should still render as checked
		// to match the previous hardcoded crop behaviour.
		$saved = $this->get('thumbCrop');
		$f->attr('checked', ($saved === null ? true : (bool) $saved) ? 'checked' : '');
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
		$f->notes = $this->_('Default: 25, 50, 100, 200');
		$f->value = (string) ($this->get('pageSizeOptions') ?? '25, 50, 100, 200');
		$f->columnWidth = 60;
		$fs->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'defaultPageSize';
		$f->label = $this->_('Default page size');
		$f->description = $this->_('Initial slice for users with no preference yet. Must be one of the page-size options above.');
		$f->value = (int) ($this->get('defaultPageSize') ?: ProcessMediaLibrary::PAGE_SIZE_DEFAULT);
		$f->columnWidth = 40;
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
		$fs->add($f);

		$inputfields->add($fs);

		// --- Template scope (existing setting, kept here for one-stop config) ---
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Scope');

		$f = $modules->get('InputfieldAsmSelect');
		$f->name = 'blacklistedTemplates';
		$f->label = $this->_('Blacklisted templates');
		$f->description = $this->_('Templates whose pages are excluded from discovery — typical use is to hide admin/system templates that happen to carry an image field.');
		foreach ($this->wire('templates') as $tpl) {
			if ($tpl->flags & Template::flagSystem) continue;
			$f->addOption($tpl->name, $tpl->name);
		}
		$val = $this->get('blacklistedTemplates');
		$f->value = is_array($val) ? $val : [];
		$fs->add($f);

		$inputfields->add($fs);

		return $inputfields;
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
