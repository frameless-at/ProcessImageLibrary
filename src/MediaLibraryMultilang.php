<?php namespace ProcessWire;

/**
 * Multilang plumbing for ProcessMediaLibrary.
 *
 * Pulled out of the main module file because PW's multilang
 * subfield handling (Pagefile::description($lang) vs. generic
 * setLanguageValue() vs. raw {langId: value} arrays from findRaw)
 * has enough fan-out that mixing it inline with the render and
 * AJAX code obscured both. Composed into ProcessMediaLibrary via
 * `use`.
 *
 * Conventions used everywhere in here:
 *   - Default language ⇒ id key 0 (NOT the actual Language id).
 *     That's what Pagefile's per-language storage uses too.
 *   - Multilang shapes hand the rest of the module {langId: value}
 *     arrays; single-language installs short-circuit to plain
 *     strings at the readLangValues / langValueToStorable level.
 *   - Export round-trips through language NAMES (langIdsToNames /
 *     langNamesToIds) so CSV column suffixes stay readable.
 */
trait MediaLibraryMultilang {

	/**
	 * The "0 = default / else id" key for the current user's
	 * admin language. Returns null on single-language installs.
	 */
	protected function getCurrentLangKey(): ?int {
		$languages = $this->wire('languages');
		if (!$languages || $languages->count() < 2) return null;
		$user = $this->wire('user');
		$lang = ($user && $user->language && $user->language->id)
			? $user->language
			: $languages->getDefault();
		if (!$lang || !$lang->id) return null;
		return $lang->isDefault() ? 0 : (int) $lang->id;
	}

	/**
	 * Compact list of installed languages for the popup-tabs UI.
	 * Returns [] on single-language installs so JS can flip to its
	 * simpler render path without further checks.
	 *
	 * @return array<int,array{id:int,name:string,title:string}>
	 */
	protected function buildLanguagesPayload(): array {
		$languages = $this->wire('languages');
		if (!$languages || $languages->count() < 2) return [];
		$out = [];
		foreach ($languages as $lang) {
			$out[] = [
				'id'    => $lang->isDefault() ? 0 : (int) $lang->id,
				'name'  => (string) $lang->name,
				'title' => (string) ($lang->get('title') ?: $lang->name),
			];
		}
		return $out;
	}

	/**
	 * For multilang cells, emit one data-lang-<id> attribute per
	 * language so the popup-tabs UI can read each language's value
	 * straight off the cell. Returns '' (and emits nothing) for
	 * single-language values so non-multilang cells stay lean.
	 */
	protected function buildLangAttrs($val): string {
		$arr = $this->decodeLangArray($val);
		if (!$arr) return '';
		$san = $this->wire('sanitizer');
		$out = '';
		foreach ($arr as $langId => $langVal) {
			if (!is_int($langId) && !ctype_digit((string) $langId)) continue;
			$out .= ' data-lang-' . (int) $langId . '="'
				. $san->entities((string) $langVal) . '"';
		}
		return $out;
	}

	/**
	 * Is the given string a valid language identifier in this
	 * install — either a numeric id (any of our ids, including 0
	 * for default) or a known language name? Backstop for the CSV
	 * column-name parser so a field literally called "summary_extra"
	 * doesn't get mistaken for a language-suffixed column.
	 */
	protected function isLangKey(string $key): bool {
		$languages = $this->wire('languages');
		if (!$languages) return false;
		if (ctype_digit($key)) {
			$id = (int) $key;
			if ($id === 0) return true;
			$lang = $languages->get($id);
			return $lang && $lang->id ? true : false;
		}
		$lang = $languages->get($key);
		return $lang && $lang->id ? true : false;
	}

	/**
	 * Apply one incoming value (string, {langId: value} array, or
	 * stdClass equivalent) to a Pagefile subfield. Multilang shapes
	 * route through applyLangValues() so every language slot lands
	 * via the right setter; single values reuse writeLangValue().
	 *
	 * Returns true if anything was actually written so the import
	 * loop knows whether to mark the page dirty.
	 */
	protected function importSubfieldValue(Pagefile $img, string $subfield, $val): bool {
		// Multilang shape — JSON object or arbitrary PHP array
		// keyed by language id. stdClass arrives when JSON decoded
		// without assoc=true upstream; cast for uniformity.
		if (is_array($val) || is_object($val)) {
			// Incoming map may be keyed by language name (new exports)
			// or by int id (old exports / direct API callers).
			// Normalize to int ids so the change-detection +
			// applyLangValues paths only have to know one shape.
			$map = $this->langNamesToIds((array) $val);
			if (!$map) return false;
			$existing = $this->readLangValues($img, $subfield) ?? [];
			$changed = false;
			foreach ($map as $lid => $v) {
				$existingVal = isset($existing[$lid]) ? (string) $existing[$lid] : '';
				if ((string) $v !== $existingVal) { $changed = true; break; }
			}
			if (!$changed) return false;
			$this->applyLangValues($img, $subfield, $map);
			return true;
		}

		// Scalar — single-language write.
		$new = is_scalar($val) ? (string) $val : '';
		$current = $img->get($subfield);
		$currentStr = $this->normalizeDescription($current);
		if ($new === $currentStr) return false;
		$this->writeLangValue($img, $subfield, $new);
		return true;
	}

	/**
	 * Convert an {int langId: value} map to {string langName: value}
	 * for export — column names like description_english read better
	 * than description_1979. langName() handles unknown IDs by
	 * passing the id-string through unchanged.
	 *
	 * @param array<int|string,string> $idMap
	 * @return array<string,string>
	 */
	protected function langIdsToNames(array $idMap): array {
		$out = [];
		foreach ($idMap as $id => $val) {
			$out[$this->langName($id)] = (string) $val;
		}
		return $out;
	}

	/**
	 * Reverse of langIdsToNames — accepts a map keyed by language
	 * name OR int id (numeric strings count as ids, for backward-
	 * compat with old exports), returns one keyed by int ids using
	 * our 0=default convention. Unresolvable keys are dropped.
	 *
	 * @param array<int|string,mixed> $nameMap
	 * @return array<int,string>
	 */
	protected function langNamesToIds(array $nameMap): array {
		$languages = $this->wire('languages');
		$out = [];
		foreach ($nameMap as $key => $val) {
			$keyStr = (string) $key;
			if (ctype_digit($keyStr)) {
				$out[(int) $keyStr] = (string) $val;
				continue;
			}
			if (!$languages) continue;
			$lang = $languages->get($keyStr);
			if (!$lang || !$lang->id) continue;
			$id = $lang->isDefault() ? 0 : (int) $lang->id;
			$out[$id] = (string) $val;
		}
		return $out;
	}

	/**
	 * Look up a language's name by its internal id (0 = default).
	 * Falls back to the id as a string when nothing resolves.
	 */
	protected function langName($id): string {
		$languages = $this->wire('languages');
		if (!$languages) return (string) $id;
		$lang = (int) $id === 0
			? $languages->getDefault()
			: $languages->get((int) $id);
		return ($lang && $lang->id) ? (string) $lang->name : (string) $id;
	}

	/**
	 * Shape a subfield value for export: multilang fields yield an
	 * {langName: value} map so external tooling can round-trip every
	 * translation; single-language fields yield the plain string.
	 * Single-language installs always yield the plain string
	 * regardless of subfield.
	 *
	 * @return array<string,string>|string
	 */
	protected function exportSubfieldValue(Pagefile $img, string $subfield) {
		$langValues = $this->readLangValues($img, $subfield);
		if ($langValues !== null) return $this->langIdsToNames($langValues);
		$val = $img->get($subfield);
		if ($val === null) return '';
		if (is_object($val)) return (string) $val;
		if (is_array($val)) return $this->normalizeDescription($val);
		return (string) $val;
	}

	/**
	 * Read every language's stored value for a subfield as a flat
	 * {langId: value} map. Returns null on single-language installs
	 * and for fields whose stored value isn't multilang — callers
	 * can use that as the cue to skip emitting per-language data
	 * attrs in the save response.
	 *
	 * @return array<int,string>|null
	 */
	protected function readLangValues(Pagefile $img, string $subfield): ?array {
		$languages = $this->wire('languages');
		if (!$languages || $languages->count() < 2) return null;

		// Pagefile.description has the dedicated per-language getter.
		if ($subfield === 'description' && method_exists($img, 'description')) {
			$out = [];
			foreach ($languages as $lang) {
				$key = $lang->isDefault() ? 0 : (int) $lang->id;
				$out[$key] = (string) $img->description($lang);
			}
			return $out;
		}

		$val = $img->get($subfield);
		if (is_object($val) && method_exists($val, 'getLanguageValue')) {
			$out = [];
			foreach ($languages as $lang) {
				$key = $lang->isDefault() ? 0 : (int) $lang->id;
				$out[$key] = (string) $val->getLanguageValue($lang);
			}
			return $out;
		}
		if (is_array($val)) return $val;
		return null;
	}

	/**
	 * Coerce a Pagefile subfield value into a cacheable shape that
	 * still preserves every language slot. LanguagesPageFieldValue
	 * objects can't be serialized into WireCache as-is; this flips
	 * them to a {langId: value} array so the popup can read each
	 * language tab without re-loading the image. Plain strings pass
	 * through untouched.
	 *
	 * @param mixed $val
	 * @return mixed
	 */
	protected function langValueToStorable($val) {
		if (is_object($val) && method_exists($val, 'getLanguageValue')) {
			$languages = $this->wire('languages');
			if ($languages && $languages->count() > 1) {
				$arr = [];
				foreach ($languages as $lang) {
					$key = $lang->isDefault() ? 0 : (int) $lang->id;
					$arr[$key] = (string) $val->getLanguageValue($lang);
				}
				return $arr;
			}
			return (string) $val;
		}
		if (is_object($val)) return (string) $val;
		return $val;
	}

	/**
	 * If $val is (or decodes to) a per-language {langId: value}
	 * array, return that array. Otherwise return null. Used by the
	 * table render to emit per-language data attributes on multilang
	 * cells so the popup can populate its tabs without a follow-up
	 * round-trip.
	 *
	 * @return array<int|string,string>|null
	 */
	protected function decodeLangArray($val): ?array {
		if (is_array($val)) return $val;
		if (is_string($val) && $val !== '' && ($val[0] === '{' || $val[0] === '[')) {
			$decoded = json_decode($val, true);
			if (is_array($decoded)) return $decoded;
		}
		if (is_object($val) && method_exists($val, 'getLanguages')) {
			$arr = [];
			foreach ($this->wire('languages') as $lang) {
				$key = $lang->isDefault() ? 0 : $lang->id;
				$arr[$key] = method_exists($val, 'getLanguageValue')
					? (string) $val->getLanguageValue($lang)
					: '';
			}
			return $arr;
		}
		return null;
	}

	/**
	 * Reduce a possibly-multilingual subfield value to a display
	 * string. For multilingual installs we honour the editor's
	 * current language with a fallback chain (current → default →
	 * id 0 → first non-empty), so the table shows the user the
	 * value that matches their admin language instead of a
	 * JSON-encoded {langId: value} dump.
	 *
	 * @param mixed $val
	 */
	protected function normalizeDescription($val): string {
		if ($val === null) return '';
		if (is_string($val)) {
			// findRaw hands multilang subfield values back as JSON-
			// encoded strings ({"0":"…","1979":"…"} or [""] for the
			// empty-default-only case), not arrays. Decode + recurse
			// so the rest of this function still gets to pick the
			// editor's language out of the data.
			if ($val !== '' && ($val[0] === '{' || $val[0] === '[')) {
				$decoded = json_decode($val, true);
				if (is_array($decoded)) return $this->normalizeDescription($decoded);
			}
			return $val;
		}
		if (is_object($val)) {
			// LanguagesPageFieldValue::__toString returns the current
			// user-language value (with PW's own fallback rules).
			return (string) $val;
		}
		if (!is_array($val)) return (string) $val;

		// Raw {langId: string} shape (typical for findRaw after we
		// json_decode above, and for $img->get('description') on a
		// multilang Pagefile).
		$user = $this->wire('user');
		$userLangId = ($user && $user->language && $user->language->id) ? (int) $user->language->id : 0;
		if (isset($val[$userLangId]) && $val[$userLangId] !== '') {
			return (string) $val[$userLangId];
		}
		$languages = $this->wire('languages');
		if ($languages) {
			$def = $languages->getDefault();
			if ($def && isset($val[$def->id]) && $val[$def->id] !== '') {
				return (string) $val[$def->id];
			}
		}
		if (isset($val[0]) && $val[0] !== '') return (string) $val[0];
		foreach ($val as $v) {
			if (is_string($v) && $v !== '') return $v;
		}
		return '';
	}

	/**
	 * Apply a {langId: value} map to a multilang subfield. The
	 * presence of this map already implies the field is multilang
	 * (we only emit langValues when the cell carries data-lang
	 * attrs), so for description we go straight to Pagefile's
	 * dedicated $img->description($lang, $value) signature without
	 * letting writeLangValue() second-guess via shape detection.
	 * Other subfields stay on the generic writer.
	 *
	 * @param array<int|string,string> $langValues
	 */
	protected function applyLangValues(Pagefile $img, string $subfield, array $langValues): void {
		$languages = $this->wire('languages');
		if (!$languages) return;
		$isDescription = $subfield === 'description' && method_exists($img, 'description');

		foreach ($langValues as $langKey => $langValue) {
			$langKeyInt = (int) $langKey;
			$lang = $langKeyInt === 0
				? $languages->getDefault()
				: $languages->get($langKeyInt);
			if (!$lang || !$lang->id) continue;

			if ($isDescription) {
				$img->description($lang, (string) $langValue);
			} else {
				$this->writeLangValue($img, $subfield, (string) $langValue, $lang);
			}
		}
	}

	/**
	 * Write a single subfield value while preserving translations
	 * the editor isn't touching. Without this, $img->set('description',
	 * $value) on a multilang field blanks every other language —
	 * including ones the editor doesn't even have access to.
	 *
	 * Uses Pagefile's dedicated description($lang, $value) signature
	 * when available; falls back to the LanguagesPageFieldValue's
	 * setLanguageValue() for custom subfields, and finally to a
	 * plain set() for installs without multilang.
	 */
	protected function writeLangValue(Pagefile $img, string $subfield, string $value, ?Language $lang = null): void {
		$languages = $this->wire('languages');
		if (!$languages || $languages->count() < 2) {
			$img->set($subfield, $value);
			return;
		}

		if (!$lang) {
			$user = $this->wire('user');
			$lang = ($user && $user->language && $user->language->id)
				? $user->language
				: $languages->getDefault();
		}
		if (!$lang) {
			$img->set($subfield, $value);
			return;
		}

		// Branch by the SHAPE of the actual stored value, so a
		// single-language description on a multilang install (where
		// the FieldtypeImage's useLanguages is off) doesn't get
		// pushed through the 2-arg Pagefile::description() signature.
		$current = $img->get($subfield);

		// (1) Multilang LanguagesPageFieldValue — typical for custom
		// subfields backed by a multilang Fieldtype.
		if (is_object($current) && method_exists($current, 'setLanguageValue')) {
			$current->setLanguageValue($lang, $value);
			$img->set($subfield, $current);
			return;
		}

		// (2) Raw {langId: value} array — typical for Pagefile's
		// built-in description on a multilang-enabled image field.
		// For description specifically PW's own signature is the
		// safest writer; fall back to set() with the merged array
		// for other subfields that arrive in this shape.
		if (is_array($current)) {
			if ($subfield === 'description' && method_exists($img, 'description')) {
				$img->description($lang, $value);
			} else {
				$current[$lang->id] = $value;
				$img->set($subfield, $current);
			}
			return;
		}

		// (3) Plain string — single-language storage on a multilang
		// install. Do NOT route through description($lang, $value)
		// here; that signature is multilang-only.
		$img->set($subfield, $value);
	}
}
