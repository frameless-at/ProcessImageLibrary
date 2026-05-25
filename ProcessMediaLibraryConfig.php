<?php namespace ProcessWire;

/**
 * Admin config UI for ProcessMediaLibrary. PW auto-loads this when
 * the user opens the module's settings screen — file name +
 * "Config" suffix is the convention.
 *
 * Static field definitions live in __construct() via $this->add(),
 * the documented primary pattern for ModuleConfig — it handles
 * default values, save / restore semantics for checkboxes and the
 * rest of the type matrix consistently. The getInputfields()
 * override only attaches options to the two fields whose option
 * list depends on the live install (custom subfields, templates).
 */
class ProcessMediaLibraryConfig extends ModuleConfig {

	public function __construct() {
		$this->add([
			[
				'name'        => 'thumbWidth',
				'type'        => 'integer',
				'label'       => $this->_('Thumbnail width (px)'),
				'value'       => ProcessMediaLibrary::THUMB_WIDTH_DEFAULT,
				'min'         => 16,
				'columnWidth' => 33,
			],
			[
				'name'        => 'thumbHeight',
				'type'        => 'integer',
				'label'       => $this->_('Thumbnail height (px)'),
				'value'       => ProcessMediaLibrary::THUMB_HEIGHT_DEFAULT,
				'min'         => 16,
				'columnWidth' => 33,
			],
			[
				'name'        => 'thumbQuality',
				'type'        => 'integer',
				'label'       => $this->_('Thumbnail JPEG quality (1–100)'),
				'value'       => ProcessMediaLibrary::THUMB_QUALITY_DEFAULT,
				'min'         => 1,
				'max'         => 100,
				'columnWidth' => 34,
			],
			[
				'name'        => 'thumbCrop',
				'type'        => 'checkbox',
				'label'       => $this->_('Crop thumbnail to exact dimensions'),
				'label2'      => $this->_('Enabled'),
				'description' => $this->_('When enabled, the thumb fills the full width × height box (center-crop). When disabled, it fits within the box keeping the original aspect ratio — heights may vary per row.'),
				'value'       => 1,
			],
			[
				'name'        => 'pageSizeOptions',
				'type'        => 'text',
				'label'       => $this->_('Page-size options'),
				'description' => $this->_('Comma- or space-separated list of integers shown in the per-page picker.'),
				'notes'       => $this->_('Default: 25, 50, 100, 200'),
				'value'       => '25, 50, 100, 200',
				'columnWidth' => 60,
			],
			[
				'name'        => 'defaultPageSize',
				'type'        => 'integer',
				'label'       => $this->_('Default page size'),
				'description' => $this->_('Initial slice for users with no preference yet. Must be one of the page-size options above.'),
				'value'       => ProcessMediaLibrary::PAGE_SIZE_DEFAULT,
				'columnWidth' => 40,
			],
			[
				'name'          => 'defaultHiddenColumns',
				'type'          => 'checkboxes',
				'label'         => $this->_('Columns hidden by default'),
				'description'   => $this->_('Users can still toggle these on per browser via the Columns picker — this just sets the default state.'),
				'value'         => [],
				'optionColumns' => 3,
				// Options attached in ___getInputfields() — depend on
				// runtime field discovery.
			],
			[
				'name'        => 'blacklistedTemplates',
				'type'        => 'AsmSelect',
				'label'       => $this->_('Blacklisted templates'),
				'description' => $this->_('Templates whose pages are excluded from discovery — typical use is to hide admin / system templates that happen to carry an image field.'),
				'value'       => [],
			],
		]);
	}

	public function getInputfields() {
		$inputfields = parent::getInputfields();

		// Fill option lists for the two fields whose options
		// depend on the current install (custom subfields,
		// template list). The fields themselves were declared
		// in __construct so save/restore goes through the
		// standard ModuleConfig path.
		$f = $inputfields->get('defaultHiddenColumns');
		if ($f) {
			foreach ($this->buildColumnOptions() as $key => $label) {
				$f->addOption($key, $label);
			}
		}

		$f = $inputfields->get('blacklistedTemplates');
		if ($f) {
			foreach ($this->wire('templates') as $tpl) {
				if ($tpl->flags & Template::flagSystem) continue;
				$f->addOption($tpl->name, $tpl->name);
			}
		}

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
