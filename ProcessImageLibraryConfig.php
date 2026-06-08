<?php namespace ProcessWire;

/**
 * Admin config UI for ProcessImageLibrary. PW picks this up via
 * info.json's "configurable": "ProcessImageLibraryConfig.php".
 *
 * We override getInputfields() programmatically so the dynamic
 * option lists (custom subfields, eligible templates, image fields)
 * can be filled live from the install at render time.
 */
class ProcessImageLibraryConfig extends ModuleConfig {

	public function getInputfields() {
		$inputfields = parent::getInputfields();
		$modules = $this->wire('modules');
		$instance = $modules->get('ProcessImageLibrary');

		// Manual dedup triggers (scan / revert) arrive as a GET action on this
		// config page, guarded by a CSRF token in the link. Run the action,
		// flash a result, then redirect to the clean config URL so a reload
		// doesn't re-fire it.
		$session    = $this->wire('session');
		$tokenName  = $session->CSRF->getTokenName();
		$tokenValue = $session->CSRF->getTokenValue();
		$action     = (string) $this->wire('input')->get('ml_dedup');
		if ($action === 'revert' && $instance instanceof ProcessImageLibrary
			&& (string) $this->wire('input')->get($tokenName) === $tokenValue) {
			$n = $instance->revertAllNow(20);
			$this->message(sprintf(
				$this->_('Revert complete — %d file(s) un-shared.'), (int) $n
			));
			$session->redirect($this->wire('config')->urls->admin . 'module/edit?name=ProcessImageLibrary');
		}

		// --- Picker add-ons (collapsed, OFF by default) ---
		// Optional integrations that surface the library elsewhere in the admin.
		// The core library (browse / edit / dedup / export-import) works
		// regardless; these just add entry points and the only per-front-end-
		// request hook, so they stay off until explicitly enabled.
		$addons = $modules->get('InputfieldFieldset');
		$addons->label = $this->_('Picker add-ons');
		$addons->description = $this->_('Let editors pull images from the library elsewhere in the admin. Off by default; the library itself works either way.');
		$addons->collapsed = Inputfield::collapsedYes;

		$cbPicker = $modules->get('InputfieldCheckbox');
		$cbPicker->name   = 'addonPicker';
		$cbPicker->label  = $this->_('Image-field picker');
		$cbPicker->label2 = $this->_('Add a “Choose from library” button to every image field');
		$cbPicker->description = $this->_('Assign an existing library image to a page’s image field without re-uploading.');
		$cbPicker->columnWidth = 50;
		if ($this->get('addonPicker')) $cbPicker->attr('checked', 'checked');
		$addons->add($cbPicker);

		$cbRichtext = $modules->get('InputfieldCheckbox');
		$cbRichtext->name   = 'addonRichtext';
		$cbRichtext->label  = $this->_('Rich-text insert');
		$cbRichtext->label2 = $this->_('Add “Insert from library” to TinyMCE / CKEditor');
		$cbRichtext->description = $this->_('Insert a library image into rich-text fields, in the admin and the front-end inline editor.');
		$cbRichtext->columnWidth = 50;
		if ($this->get('addonRichtext')) $cbRichtext->attr('checked', 'checked');
		$addons->add($cbRichtext);

		$inputfields->add($addons);

		// --- Thumbnail rendering ---
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Thumbnail');
		$fs->description = $this->_('Per-row preview image rendered into the table. Up to 260 px on the longer side the runtime reuses PW\'s lazily-generated admin image-field variation — no second resize pass per row. Beyond that, a dedicated variation is produced for the table.');

		// All four input fields share columnWidth=25. That keeps the
		// running sum (PW's InputfieldWrapper::render tracks it
		// across showIf-hidden children too) at exactly 100 by the
		// fourth field — KeepRatio falls onto row 2 by design, and
		// no single mode-toggle ever pushes the layout into a
		// pre-Quality reset. Visible result:
		//   ratio off → Quality + Width + Height  (3 × 25 %, slot 4 empty)
		//   ratio on  → Quality + Longer side     (2 × 25 %, slots 3+4 empty)
		// Quality leads in both modes; the rest follows in DOM
		// order.

		$f = $modules->get('InputfieldInteger');
		$f->name = 'thumbQuality';
		$f->label = $this->_('JPEG quality (1–100)');
		$f->value = (int) ($this->get('thumbQuality') ?: ProcessImageLibrary::THUMB_QUALITY_DEFAULT);
		$f->min = 1;
		$f->max = 100;
		$f->notes = sprintf($this->_('Default: %d'), ProcessImageLibrary::THUMB_QUALITY_DEFAULT);
		$f->columnWidth = 25;
		$fs->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'thumbWidth';
		$f->label = $this->_('Width (px)');
		$f->value = (int) ($this->get('thumbWidth') ?: ProcessImageLibrary::THUMB_WIDTH_DEFAULT);
		$f->min = 16;
		$f->notes = sprintf($this->_('Default: %d'), ProcessImageLibrary::THUMB_WIDTH_DEFAULT);
		$f->showIf = 'thumbKeepRatio!=1';
		$f->columnWidth = 25;
		$fs->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->name = 'thumbHeight';
		$f->label = $this->_('Height (px)');
		$f->value = (int) ($this->get('thumbHeight') ?: ProcessImageLibrary::THUMB_HEIGHT_DEFAULT);
		$f->min = 16;
		$f->notes = sprintf($this->_('Default: %d'), ProcessImageLibrary::THUMB_HEIGHT_DEFAULT);
		$f->showIf = 'thumbKeepRatio!=1';
		$f->columnWidth = 25;
		$fs->add($f);

		// Longer-side cap for the keep-ratio path. ≤ 260 ⇒ reuse PW's
		// admin variation as source; > 260 ⇒ runtime produces a
		// dedicated size($longer, 0) / size(0, $longer) variation.
		$f = $modules->get('InputfieldInteger');
		$f->name = 'thumbLongerSide';
		$f->label = $this->_('Longer side (px)');
		$f->value = (int) ($this->get('thumbLongerSide') ?: ProcessImageLibrary::THUMB_LONGER_SIDE_DEFAULT);
		$f->min = 16;
		$f->notes = sprintf($this->_('Default: %d'), ProcessImageLibrary::THUMB_LONGER_SIDE_DEFAULT);
		$f->showIf = 'thumbKeepRatio=1';
		$f->columnWidth = 25;
		$fs->add($f);

		// Keep-aspect toggle on its own full-width row.
		$f = $modules->get('InputfieldCheckbox');
		$f->name = 'thumbKeepRatio';
		$f->skipLabel = Inputfield::skipLabelHeader;
		$f->label2 = $this->_('Keep image ratio');
		$savedKR = $this->get('thumbKeepRatio');
		if ($savedKR === null) {
			$oldCrop = $this->get('thumbCrop');
			$savedKR = $oldCrop === null ? true : !$oldCrop;
		}
		$f->checked((bool) $savedKR);
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
		$f->notes = $this->_('Default: ') . implode(', ', ProcessImageLibrary::PAGE_SIZE_OPTIONS);
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
			: (in_array(ProcessImageLibrary::PAGE_SIZE_DEFAULT, $psOpts, true)
				? (string) ProcessImageLibrary::PAGE_SIZE_DEFAULT
				: (string) ($psOpts[0] ?? ProcessImageLibrary::PAGE_SIZE_DEFAULT));
		$f->notes = sprintf($this->_('Default: %d'), ProcessImageLibrary::PAGE_SIZE_DEFAULT);
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
		foreach (array_keys(ProcessImageLibrary::SORTABLE_COLUMNS) as $key) {
			$f->addOption($key, $key);
		}
		$savedSort = (string) $this->get('defaultSort');
		$f->value = array_key_exists($savedSort, ProcessImageLibrary::SORTABLE_COLUMNS)
			? $savedSort
			: ProcessImageLibrary::DEFAULT_SORT;
		$f->notes = sprintf($this->_('Default: %s'), ProcessImageLibrary::DEFAULT_SORT);
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldRadios');
		$f->name = 'defaultSortDir';
		$f->label = $this->_('Direction');
		$f->addOption('asc',  $this->_('Ascending'));
		$f->addOption('desc', $this->_('Descending'));
		$f->value = ((string) $this->get('defaultSortDir') === 'desc') ? 'desc' : 'asc';
		$f->notes = sprintf($this->_('Default: %s'), ProcessImageLibrary::DEFAULT_DIR);
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

		// --- Deduplication ---
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Deduplication');
		$fs->description = $this->_('Byte-identical images are fingerprinted and collapsed to one shared file (hardlink) automatically — on upload and via an hourly background pass — so duplicate copies cost disk space only once. It is lossless and reversible. The controls below show the current saving and let you run the pass now or undo it.');

		$san   = $this->wire('sanitizer');
		$stats = ($instance instanceof ProcessImageLibrary)
			? $instance->dedupStats()
			: ['reclaimedHuman' => '0', 'linkedCount' => 0, 'clusterCount' => 0];

		// Pass raw URLs (literal &) — InputfieldButton entity-encodes the href
		// attribute itself; pre-encoding would double it.
		$base      = $this->wire('config')->urls->admin . 'module/edit?name=ProcessImageLibrary';
		$revertUrl = $base . '&ml_dedup=revert&' . $tokenName . '=' . urlencode($tokenValue);
		$revertConfirm = $this->_('Give every collapsed copy its own independent file again? This undoes the space saving (the next pass will re-collapse them).');

		// Live scan + reclaim runs against the module's own AJAX endpoints
		// (chunked, with per-cluster progress). Enqueue the small driver JS.
		$libPage = $this->wire('pages')->get('template=admin, name=image-library, include=all');
		$libUrl  = ($libPage && $libPage->id) ? $libPage->url : '';
		if ($instance instanceof ProcessImageLibrary && $libUrl !== '') {
			$cfg     = $this->wire('config');
			$modPath = $cfg->paths($instance);
			$modUrl  = $cfg->urls($instance);
			$jsVer   = @filemtime($modPath . 'ml-reclaim-live.js') ?: '1';
			$cssVer  = @filemtime($modPath . 'ml-reclaim-live.css') ?: '1';
			$cfg->scripts->add($modUrl . 'ml-reclaim-live.js?v=' . $jsVer);
			$cfg->styles->add($modUrl . 'ml-reclaim-live.css?v=' . $cssVer);
		}

		// Status read-out. The <strong> values carry classes so the live JS
		// updates them in place after a run (otherwise the figures go stale).
		// The reclaimed / sharing lines only appear when something is actually
		// collapsed: after a Revert (or before the first scan) there is no
		// saving, so showing "Disk space reclaimed: 0" would be misleading — the
		// rows are hidden and a neutral note is shown instead. The JS toggles the
		// same rows live so the block stays honest after a run without a reload.
		$linked     = (int) $stats['linkedCount'];
		$hasReclaim = $linked > 0;
		$clusters   = (int) $stats['clusterCount'];
		$hide       = $hasReclaim ? '' : ' hidden';
		$status = $modules->get('InputfieldMarkup');
		$status->label = $this->_('Status');
		$status->value =
			'<div class="ml-dedup-status">'
			. '<ul class="uk-list uk-list-divider ml-stat-list" style="margin:0' . ($hasReclaim || $clusters ? '' : ';display:none') . '">'
			. '<li class="ml-stat-row-reclaimed"' . $hide . '>' . $san->entities($this->_('Disk space reclaimed')) . ': <strong class="ml-stat-reclaimed">' . $san->entities((string) $stats['reclaimedHuman']) . '</strong></li>'
			. '<li class="ml-stat-row-shared"' . $hide . '>' . $san->entities($this->_('Copies sharing a file')) . ': <strong class="ml-stat-shared">' . $linked . '</strong></li>'
			. '<li class="ml-stat-row-clusters"' . ($clusters ? '' : ' hidden') . '>' . $san->entities($this->_('Exact-duplicate clusters')) . ': <strong class="ml-stat-clusters">' . $clusters . '</strong></li>'
			. '</ul>'
			. '<p class="ml-stat-empty uk-text-muted uk-margin-remove"' . ($hasReclaim ? ' hidden' : '') . '>'
			. $san->entities($this->_('Nothing is collapsed right now — run “Scan and reclaim” below to free space.'))
			. '</p>'
			. '</div>';
		$status->notes = $this->_('A scan also runs automatically on every save and hourly in the background — running it here is only needed to reclaim a large existing backlog immediately. Caveat: backup/deploy tooling that does not preserve hardlinks (rsync without -H, plain tar/cp, syncing to another mount) re-expands them over time; the background pass re-links them on its next run.');
		$fs->add($status);

		// Tools at the BOTTOM: one plain-markup block (no InputfieldButton, so
		// the config form can't duplicate it on save). The JS driver runs the
		// chunked endpoints and shows live progress; Revert is a guarded link.
		$tools = $modules->get('InputfieldMarkup');
		$tools->value =
			'<div class="ml-reclaim-live"'
			. ' data-scan-url="'    . $san->entities($libUrl . 'scan-step/') . '"'
			. ' data-reclaim-url="' . $san->entities($libUrl . 'reclaim-step/') . '"'
			. ' data-revert-url="'  . $san->entities($libUrl . 'revert-step/') . '"'
			. ' data-audit-url="'   . $san->entities($libUrl . 'disk-audit/') . '"'
			. ' data-csrf-name="'   . $san->entities($tokenName) . '"'
			. ' data-csrf-value="'  . $san->entities($tokenValue) . '">'
			. '<p style="margin:0">'
			. '<button type="button" class="ml-reclaim-start ui-button uk-button uk-button-primary">'
			. '<i class="fa fa-refresh" aria-hidden="true"></i> ' . $san->entities($this->_('Scan and reclaim (live)')) . '</button> '
			. '<button type="button" class="ml-audit-start ui-button uk-button uk-button-default">'
			. '<i class="fa fa-refresh" aria-hidden="true"></i> ' . $san->entities($this->_('Re-measure')) . '</button> '
			// JS intercepts this and runs the chunked revert-step endpoint (so it
			// always finishes); the href + inline confirm remain a no-JS fallback.
			. '<a class="ml-reclaim-revert ui-button uk-button uk-button-default" href="' . $san->entities($revertUrl) . '"'
			. ' data-confirm="' . $san->entities($revertConfirm) . '"'
			. ' onclick="return confirm(' . $san->entities(json_encode($revertConfirm, JSON_HEX_APOS | JSON_HEX_QUOT)) . ')">'
			. '<i class="fa fa-undo" aria-hidden="true"></i> ' . $san->entities($this->_('Revert (un-share all)')) . '</a>'
			. '</p>'
			. '<div class="ml-audit-result uk-background-muted uk-border-rounded uk-padding-small uk-margin-small-top uk-text-small" hidden></div>'
			. '<div class="ml-reclaim-panel uk-margin-small-top" hidden>'
			. '<div class="ml-reclaim-phase uk-text-bold"></div>'
			. '<progress class="ml-reclaim-bar uk-progress uk-margin-small" max="100" value="0"></progress>'
			. '<div class="ml-reclaim-totals uk-text-small uk-margin-small-bottom"></div>'
			. '<ul class="ml-reclaim-log uk-list ml-mono uk-background-muted uk-border-rounded uk-padding-small uk-overflow-auto uk-height-max-medium"></ul>'
			. '</div></div>';
		$fs->add($tools);

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
		if ($raw === '') return ProcessImageLibrary::PAGE_SIZE_OPTIONS;
		$opts = array_values(array_unique(array_filter(
			array_map('intval', preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []),
			fn($n) => $n > 0
		)));
		sort($opts);
		return $opts ?: ProcessImageLibrary::PAGE_SIZE_OPTIONS;
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
			'created'     => $this->_('Uploaded'),
			'modified'    => $this->_('Modified'),
			'variations'  => $this->_('Variations'),
		];
		$instance = $this->wire('modules')->get('ProcessImageLibrary');
		if ($instance instanceof ProcessImageLibrary) {
			foreach ($instance->collectCustomNames() as $name) {
				$opts['custom:' . $name] = $name . ' ' . $this->_('(custom)');
			}
		}
		return $opts;
	}
}
