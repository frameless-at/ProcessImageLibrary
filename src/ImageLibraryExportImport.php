<?php namespace ProcessWire;

/**
 * Export / import for ProcessImageLibrary.
 *
 * Pulled out of the main module file because the round-trip
 * (JSON + CSV emit, CSV parse, language-aware column expansion,
 * idempotent re-apply on import) is a sizeable self-contained
 * slice — it doesn't depend on render or filter state beyond what
 * the AJAX endpoints hand it. Composed into ProcessImageLibrary
 * via `use`.
 *
 * Methods rely on the host class providing:
 *   - $this->loadRows(), $this->applyRowFilters(), $this->applySort(),
 *     $this->applyTagFilter(), $this->bulkHydrateCustomFields(),
 *     $this->discoverImageFields(), $this->discoverEligibleTemplates(),
 *     $this->getCustomByField(), $this->getTagsConfig(),
 *     $this->getDefaultPageSize(), $this->buildUrl()
 *   - $this->resolvePageimage(), $this->splitTags(),
 *     $this->readFilterInput(), $this->readSortInput()
 *   - Multilang helpers via ImageLibraryMultilang
 *   - $this->jsonError(), $this->jsonResponse() AJAX response helpers
 */
trait ImageLibraryExportImport {

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

			$img = $this->resolvePageimage($page, (string) $row['fieldName'], (string) $row['basename']);
			if (!$img) continue;

			$customs = [];
			foreach ($customByField[$row['fieldName']] ?? [] as $name) {
				$customs[$name] = $this->exportSubfieldValue($img, $name);
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
				'pageTitle'   => $this->normalizeDescription($row['pageTitle']),
				'pageUrl'     => $page->httpUrl,
				'dimensions'  => ($row['width'] && $row['height'])
					? ((int) $row['width']) . 'x' . ((int) $row['height'])
					: '',
				'filesize'    => (int) $row['filesize'],
				// ISO 8601 keeps the round-trip readable and parsable
				// by every spreadsheet / JSON consumer. Empty string
				// when no timestamp is recorded yet.
				'created'     => (int) ($row['created']  ?? 0) > 0 ? date('c', (int) $row['created'])  : '',
				'modified'    => (int) ($row['modified'] ?? 0) > 0 ? date('c', (int) $row['modified']) : '',
				'description' => $this->exportSubfieldValue($img, 'description'),
				'tags'        => $this->exportSubfieldValue($img, 'tags'),
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
				'readOnlyFields' => ['id', 'pageId', 'fieldName', 'basename', 'url', 'dimensions', 'filesize', 'created', 'modified', 'pageTitle', 'pageUrl'],
			],
			'images' => $images,
		];

		$format = (string) $this->wire('input')->get('format');
		if ($format === 'csv') {
			$this->streamExportCsv($images);
			exit;
		}

		$filename = sprintf('image-library-export-%s.json', date('Ymd-His'));
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
				$this->assignCsvCell($entry, $h, $val);
			}
			// After all columns are read, collapse multilang accumulators
			// into the same shape the JSON path uses ({langId: value}).
			foreach (['description', 'tags'] as $f) {
				if (isset($entry[$f . '__langs'])) {
					$entry[$f] = $entry[$f . '__langs'];
					unset($entry[$f . '__langs']);
				}
			}
			if (isset($entry['custom__langs']) && is_array($entry['custom__langs'])) {
				foreach ($entry['custom__langs'] as $name => $map) {
					$entry['custom'][$name] = $map;
				}
				unset($entry['custom__langs']);
			}
			$out[] = $entry;
		}
		fclose($stream);
		return $out;
	}

	/**
	 * Drop one CSV cell into the import entry array, handling the
	 * four column-name shapes the export emits:
	 *   id / pageId / pageTitle / …       → plain key
	 *   description | tags                 → plain key
	 *   description_<lid> | tags_<lid>     → accumulate into …__langs map
	 *   custom_<name>                      → entry.custom[name]
	 *   custom_<name>_<lid>                → entry.custom__langs[name][lid]
	 *
	 * @param array<string,mixed> $entry passed by reference
	 */
	protected function assignCsvCell(array &$entry, string $header, string $val): void {
		// custom_<name>_<langName> | custom_<name>_<langId> | custom_<name>
		if (strncmp($header, 'custom_', 7) === 0) {
			$rest = substr($header, 7);
			if (preg_match('/^(.+)_([^_]+)$/', $rest, $m)
				&& $this->isLangKey($m[2])
			) {
				$entry['custom__langs'][$m[1]][$m[2]] = $val;
			} else {
				$entry['custom'][$rest] = $val;
			}
			return;
		}
		// description_<lang> | tags_<lang>
		if (preg_match('/^(description|tags)_(.+)$/', $header, $m)
			&& $this->isLangKey($m[2])
		) {
			$entry[$m[1] . '__langs'][$m[2]] = $val;
			return;
		}
		$entry[$header] = $val;
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
		// Walk all image entries first to compute the column set.
		// Multilang subfields expand into one column per language
		// (suffix _<langName>, e.g. description_english); single-
		// language stay as one column. Stable shape per export
		// ensures every row has every cell.
		$langCols = [];   // [field => [langName => true]]
		$plainCols = [];  // [field => true]
		$customCols = []; // [customName => [langName => true] | true]

		$mark = function (string $key, $val) use (&$langCols, &$plainCols) {
			if (is_array($val)) {
				foreach ($val as $langName => $_) {
					$langCols[$key][(string) $langName] = true;
				}
			} else {
				$plainCols[$key] = true;
			}
		};

		foreach ($images as $img) {
			$mark('description', $img['description'] ?? '');
			$mark('tags', $img['tags'] ?? '');
			foreach ((array) $img['custom'] as $c => $cval) {
				if (is_array($cval)) {
					foreach ($cval as $langName => $_) {
						$customCols[$c][(string) $langName] = true;
					}
				} else {
					if (!isset($customCols[$c])) $customCols[$c] = true;
				}
			}
		}

		$headers = ['id', 'pageId', 'fieldName', 'basename', 'url',
			'pageTitle', 'pageUrl', 'dimensions', 'filesize', 'created', 'modified'];

		// Build headers for description / tags — language-suffixed if
		// any image carried multilang values for that subfield.
		foreach (['description', 'tags'] as $f) {
			if (isset($langCols[$f])) {
				$names = array_keys($langCols[$f]);
				sort($names);
				foreach ($names as $n) $headers[] = $f . '_' . $n;
			} elseif (isset($plainCols[$f])) {
				$headers[] = $f;
			}
		}

		ksort($customCols);
		foreach ($customCols as $c => $langs) {
			if (is_array($langs)) {
				$names = array_keys($langs);
				sort($names);
				foreach ($names as $n) $headers[] = 'custom_' . $c . '_' . $n;
			} else {
				$headers[] = 'custom_' . $c;
			}
		}

		$filename = sprintf('image-library-export-%s.csv', date('Ymd-His'));
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		echo "\xEF\xBB\xBF";
		$out = fopen('php://output', 'w');
		fputcsv($out, $headers);
		foreach ($images as $img) {
			$row = [];
			foreach ($headers as $h) {
				$row[] = (string) $this->csvCellValue($h, $img);
			}
			fputcsv($out, $row);
		}
		fclose($out);
	}

	/**
	 * Resolve a CSV cell value by header name. Handles the four
	 * shapes:
	 *   - identity / metadata column ("id", "pageId" …)
	 *   - language-suffixed multilang column ("description_1979")
	 *   - language-suffixed multilang custom ("custom_summary_0")
	 *   - plain single-language column ("description", "custom_code")
	 *
	 * @param array<string,mixed> $img
	 */
	protected function csvCellValue(string $header, array $img): string {
		// custom_<name>_<langName> | custom_<name>
		if (strncmp($header, 'custom_', 7) === 0) {
			$rest = substr($header, 7);
			$customs = (array) ($img['custom'] ?? []);
			// Multilang custom: try the longest "<name>_<lang>" split
			// that resolves to an actual custom + lang slot.
			if (preg_match('/^(.+)_([^_]+)$/', $rest, $m)) {
				$name = $m[1];
				$langName = $m[2];
				if (isset($customs[$name]) && is_array($customs[$name])) {
					return (string) ($customs[$name][$langName] ?? '');
				}
			}
			$val = $customs[$rest] ?? '';
			return is_array($val) ? $this->normalizeDescription($val) : (string) $val;
		}
		// description_<langName> / tags_<langName>
		if (preg_match('/^(description|tags)_(.+)$/', $header, $m)) {
			$val = $img[$m[1]] ?? '';
			$langName = $m[2];
			return is_array($val) ? (string) ($val[$langName] ?? '') : (string) $val;
		}
		// Plain description / tags or any identity column
		$val = $img[$header] ?? '';
		if (is_array($val)) return $this->normalizeDescription($val);
		return (string) $val;
	}

	public function ___executeImport() {
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

				$img = $this->resolvePageimage($page, $fn, $bn);
				if (!$img) {
					$failed[] = "Image $bn not in $pid.$fn";
					continue;
				}

				$dirty = false;

				if (array_key_exists('description', $item)) {
					if ($this->importSubfieldValue($img, 'description', $item['description'])) {
						$dirty = true;
					}
				}

				if (array_key_exists('tags', $item)) {
					$raw = $item['tags'];
					// Tag whitelist still needs validating on a per-string
					// basis. For multilang tags (array shape), validate
					// each language slot individually.
					$cfg = $tagsConfig[$fn] ?? ['mode' => 0, 'allowed' => []];
					$candidates = is_array($raw) ? $raw : ['_' => (string) $raw];
					$invalid = false;
					if ($cfg['mode'] === 2) {
						foreach ($candidates as $cand) {
							$tokens = $this->splitTags((string) $cand);
							$bad = array_diff($tokens, $cfg['allowed']);
							if ($bad) {
								$failed[] = "Tag(s) not in whitelist for $fn: " . implode(', ', $bad);
								$invalid = true;
								break;
							}
						}
					}
					if (!$invalid && $this->importSubfieldValue($img, 'tags', $raw)) {
						$dirty = true;
					}
				}

				if (!empty($item['custom']) && (is_array($item['custom']) || is_object($item['custom']))) {
					$allowedCustoms = $customByField[$fn] ?? [];
					foreach ((array) $item['custom'] as $name => $val) {
						$name = $sanitizer->fieldName((string) $name);
						if (!in_array($name, $allowedCustoms, true)) continue;
						if ($this->importSubfieldValue($img, $name, $val)) {
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

		return $this->jsonResponse([
			'ok'        => true,
			'succeeded' => $succeeded,
			'skipped'   => $skipped,
			'failed'    => $failed,
		]);
	}
}
