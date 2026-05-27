<?php namespace ProcessWire;

/**
 * Read-only schema introspection for ProcessImageLibrary.
 *
 * Pulled out of the main module file because the discovery layer is
 * a self-contained read-only slice — it asks "which image fields,
 * templates, custom subfields and tag whitelists exist on this site"
 * without writing anything and without depending on render-, AJAX-
 * or filter-side state. Composed into ProcessImageLibrary via a `use`
 * statement.
 *
 * Methods rely on the host class providing:
 *   - $this->splitTags()              (main class helper)
 *   - $this->getBlacklistedFields()   (main class helper)
 *   - $this->getBlacklistedTemplates() (main class helper)
 *   - $this->customByFieldCache       (instance property)
 *   - self::STANDARD_SUBFIELDS        (class constant)
 */
trait ImageLibraryDiscovery {

	/**
	 * @return array<int,string> names of every FieldtypeImage field in the system,
	 *                           minus those listed in the module's field blacklist
	 */
	protected function discoverImageFields(): array {
		$blacklist = array_flip($this->getBlacklistedFields());
		$names = [];
		foreach ($this->wire('fields') as $field) {
			if (!($field->type instanceof FieldtypeImage)) continue;
			if (isset($blacklist[$field->name])) continue;
			$names[] = $field->name;
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
			$allowed    = $this->splitTags($rawList);

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
	 * Subset of eligibleTemplates that excludes internal repeater /
	 * RepeaterMatrix templates. Used to populate the template-filter
	 * dropdown so editors see their content templates (blog_post,
	 * project, …) and not the auto-generated repeater_<field>
	 * templates that store repeater items. The full eligibleTemplates
	 * list still drives the findRaw selector + cache key — we just
	 * narrow what we OFFER as a filter.
	 *
	 * @param array<int,string> $eligibleTemplates
	 * @return array<int,string>
	 */
	protected function userFacingTemplates(array $eligibleTemplates): array {
		$out = [];
		foreach ($eligibleTemplates as $name) {
			if (strncmp($name, 'repeater_', 9) === 0) continue;
			$out[] = $name;
		}
		return $out;
	}

	/**
	 * Template IDs whose pages store repeater / RepeaterMatrix items.
	 * Used at flatten time to detect rows that need owner-page
	 * resolution. Built once per request — repeater template counts
	 * are tiny so the iteration cost is negligible.
	 *
	 * @return array<int,int> templateId => templateId (set-shape)
	 */
	protected function repeaterTemplateIds(): array {
		$ids = [];
		foreach ($this->wire('templates') as $tpl) {
			if (strncmp((string) $tpl->name, 'repeater_', 9) === 0) {
				$ids[(int) $tpl->id] = (int) $tpl->id;
			}
		}
		return $ids;
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
			// Skip the auto-generated repeater templates — their image
			// fields surface under the OWNER template's entry below
			// thanks to collectImageFieldsRecursive, which recurses
			// through any FieldtypeRepeater / RepeaterMatrix fields it
			// finds in a template's fieldgroup.
			if (strncmp((string) $tname, 'repeater_', 9) === 0) continue;
			$tpl = $this->wire('templates')->get($tname);
			if (!$tpl) continue;
			$fields = [];
			$this->collectImageFieldsRecursive($tpl, $fieldSet, $fields, []);
			$map[$tname] = array_values(array_unique($fields));
		}
		return $map;
	}

	/**
	 * Walk a template's fieldgroup collecting image-field names that
	 * the editor can reach from that template — directly OR through
	 * repeater / RepeaterMatrix fields nested inside it. The $seen
	 * array breaks circular reference loops in case of perverse
	 * repeater-inside-its-own-repeater schema.
	 *
	 * @param array<string,int> $fieldSet      image-field names as a set
	 * @param array<int,string> $out           collected names, by reference
	 * @param array<string,bool> $seen         template names already walked
	 */
	protected function collectImageFieldsRecursive(\ProcessWire\Template $tpl, array $fieldSet, array &$out, array $seen): void {
		if (isset($seen[$tpl->name])) return;
		$seen[$tpl->name] = true;
		foreach ($tpl->fieldgroup as $f) {
			if (isset($fieldSet[$f->name])) {
				$out[] = $f->name;
			}
			if ($f->type instanceof \ProcessWire\FieldtypeRepeater) {
				$innerTpl = $this->wire('templates')->get('repeater_' . $f->name);
				if ($innerTpl) {
					$this->collectImageFieldsRecursive($innerTpl, $fieldSet, $out, $seen);
				}
			}
		}
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
	 * @return bool true if any image field has at least one custom subfield declared
	 */
	protected function hasAnyCustomFields(): bool {
		foreach ($this->getCustomByField() as $list) {
			if (!empty($list)) return true;
		}
		return false;
	}

	/**
	 * Per-image-field capability payload for the JS filter-bar narrowing:
	 * `{ field: { useTags: bool, customs: [name, …] } }`. Consumed by
	 * applyFieldCapabilityFilter() in the JS to hide / show the Tags
	 * fieldset + Missing-X checkboxes as the user changes the field
	 * filter, mirroring the server-side gate in renderFilterBar.
	 *
	 * @param array<int,string> $imageFields
	 * @return array<string,array{useTags:bool,customs:array<int,string>}>
	 */
	protected function buildFieldCapsPayload(array $imageFields): array {
		$tagsCfg = $this->getTagsConfig();
		$cByF    = $this->getCustomByField();
		$out = [];
		foreach ($imageFields as $f) {
			$out[$f] = [
				'useTags' => ($tagsCfg[$f]['mode'] ?? 0) > 0,
				'customs' => array_values($cByF[$f] ?? []),
			];
		}
		return $out;
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
}
