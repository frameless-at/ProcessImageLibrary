# ImageLibrary Module — Concept

**Target repo:** https://github.com/frameless-at/image-library

## Goal

A ProcessWire module that shows **every image in a PW installation** in a single table — a central image-library view. Editors edit image metadata across all pages and image fields inline (description, tags, custom subfields) without having to navigate per page.

**Primary use case:** Content-heavy site with thousands of images spread across dozens or hundreds of pages, many with missing descriptions or incomplete tags. The editor wants to filter (e.g. "images without description"), work through them, switch to the next filter — without jumping between Page-Edit screens.

## Scope

**In scope (implemented):**

- Aggregates images from **all** `FieldtypeImage` fields on **all** templates in the installation (with configurable template and field blacklists)
- Each table row = tuple `(page, fieldName, basename)`
- **Repeater / RepeaterMatrix support**: images stored inside a repeater field are resolved up to their owner page so the Page column, sort and template filter operate on the visible owner — not on the internal `repeater_<field>` storage page
- Inline edit of editable subfields (description, tags, custom fields); Textarea customs open a popup
- **Multilingual subfields**: per-language tabs in the popup (pre-activated on the editor's current admin language), round-trip in JSON / CSV export-import
- **Bulk edit** via "selection as paintbrush": ticked rows are updated alongside the next cell save in Add or Replace mode
- **Filename rename**: inline (single image) or batch (across the active selection) via the same popup; placeholder grammar `(n)`, `(n2)..(n5)`, `(N)`, `(t)`, `(d)`, `(p)`, `(f)` works in every prose-shaped editor (filename, description, custom text/textarea) — tags excluded
- **Date columns**: Uploaded (Pagefile `created`) and Modified, sortable, formatted in `$config->dateFormat`
- **Variations column**: per-image counter from `$img->getVariations()`
- **Export / Import**: JSON and CSV (with multilang-aware column suffixes `<subfield>_<langName>`)
- Server-side filtering / sorting / pagination with **capability-based filter narrowing**: the Tags fieldset + each `Missing <custom>` checkbox hide live as soon as the chosen Template / Image-field has no fitting capability
- Per-user column configuration in `$user->meta('imageLibraryPrefs')` — **cross-device**, including page size
- Auto-detection of **Custom Fields on Images** (`field-{fieldname}` template, PW 3.0.142+)
- AdminThemeUikit light / dark theme integration via `--pw-*` CSS custom properties

**Out of scope (also in the current version):**

- Uploading / deleting images
- Moving images between pages
- Variations management (crop / focus / regenerate) — module shows the variations counter but doesn't regenerate
- Re-sorting within an image field (`$img->sort`)
- Bulk delete / replace image (possible Phase-2 topics)

## Architecture

**Module type:** `Process` module, dedicated admin URL `/processwire/setup/image-library/`.

**Class:** `ProcessImageLibrary`

**File structure:**

```
ProcessImageLibrary/
├── ProcessImageLibrary.module.php
├── ProcessImageLibrary.info.json
├── ProcessImageLibraryConfig.php
├── ProcessImageLibrary.css
├── ProcessImageLibrary.js
├── src/
│   ├── ImageLibraryDiscovery.php     # trait: image-field / template / tags-config introspection
│   ├── ImageLibraryMultilang.php     # trait: per-language read/write, name⇄id mapping
│   └── ImageLibraryExportImport.php  # trait: JSON + CSV emit, parse, idempotent re-apply
├── docs/
│   ├── ImageLibrary-Concept_EN.md
│   ├── ImageLibrary-Konzept_DE.md
│   └── screenshots/
├── README.md
└── LICENSE
```

The `src/` traits keep the main module file focused on AJAX endpoints + rendering; discovery, multilang and export/import each own a cohesive slice.

**Methods:**

- `___execute()` — renders the table and filter UI (server-rendered HTML; JS hydrates for interaction). Columns picker lives as a sibling `<dialog>` next to `.ml-results` so AJAX swaps leave drag/toggle handlers intact.
- `___executeData()` — AJAX GET, returns only the `.ml-results` block (table + pagination) for filter/sort/page swaps.
- `___executeSave()` — AJAX POST, validates + saves a cell change, returns JSON. Multilang-aware: the payload can carry `langId`, in which case only that language slot is written.
- `___executeBulk()` — AJAX POST: apply an identical cell save to a selection (Add or Replace mode).
- `___executeRename()` — AJAX POST, renames a single image's file (or every selected image in batch mode) via `Pagefile::rename()` after expanding placeholders and clearing old variation files.
- `___executeExport()` — direct download of JSON or CSV honoring the active filters.
- `___executeImport()` — AJAX POST, accepts a previously-exported (and externally edited) JSON / CSV file and writes it back; idempotent (unchanged items are skipped).
- `___executeUserPrefs()` — AJAX POST, persists columns + page size into `$user->meta('imageLibraryPrefs')` (debounced).
- `___install()` / `___uninstall()` — admin-page lifecycle + `image-library-access` permission.

## Data model (PW-native)

Each table row is identified by the tuple `(pageId, fieldName, basename)`.

**Listing pipeline (read path):**

1. **Field discovery at boot:**

   ```php
   $imageFields = [];
   foreach (wire('fields') as $f) {
       if ($f->type instanceof FieldtypeImage) $imageFields[] = $f->name;
   }
   ```

2. **Build the selector** at page level with every filter that PW's selector engine can express natively:

   ```php
   $selector = "template=" . implode('|', $eligibleTemplates) . ", status<=hidden";
   if ($missingDescription) $selector .= ", images.description=";
   if ($templateFilter)     $selector .= ", template=$templateFilter";
   ```

3. **`$pages->findRaw()`** pulls the complete image data in one go without Page-object hydration:

   ```php
   $rawData = $pages->findRaw($selector, array_merge(
       ["id", "title", "url", "templates_id"],
       array_map(fn($f) => "$f.basename",    $imageFields),
       array_map(fn($f) => "$f.description", $imageFields),
       array_map(fn($f) => "$f.tags",        $imageFields),
       array_map(fn($f) => "$f.filesize",    $imageFields),
       // plus auto-discovered custom subfields per field
   ));
   ```

4. **Flatten in PHP** to an image-row list:

   ```php
   $rows = [];
   foreach ($rawData as $pageId => $pageData) {
       foreach ($imageFields as $fieldName) {
           foreach ($pageData[$fieldName] ?? [] as $img) {
               $rows[] = [
                   'pageId'    => $pageId,
                   'fieldName' => $fieldName,
                   'basename'  => $img['basename'],
                   // … remaining subfields …
               ];
           }
       }
   }
   ```

5. **Image-level filters in PHP** (for filters that PW's selector can't express exactly at the subfield level):

   ```php
   $rows = array_values(array_filter($rows, $userFilterFn));
   ```

6. **Sort + slice** for pagination:

   ```php
   usort($rows, $sortFn);
   $total = count($rows);
   $slice = array_slice($rows, $offset, $limit);
   ```

7. **Load thumbnail URLs for the 50-row slice** (only now do we touch real `Pageimage` objects):

   ```php
   $uniquePageIds = array_unique(array_column($slice, 'pageId'));
   $pagesById     = $pages->getMany($uniquePageIds);
   foreach ($slice as &$r) {
       $page = $pagesById->get($r['pageId']);
       $img  = $page->{$r['fieldName']}->getFile($r['basename']);
       $r['thumbUrl']  = $img->size(120, 80)->url;
       $r['pageUrl']   = $page->url;
       $r['pageTitle'] = $page->title;
   }
   ```

→ Only the **50 displayed images** trigger real `Pageimage` loads for thumb URLs. Everything else stays a `findRaw` data array.

**Caching:**

The `findRaw` result is cached via `WireCache::saveFor($this, ...)`. Invalidation is **triple-belt**:

1. **Explicit** after every own Save / Bulk / Import (`$cache->deleteFor($this)` straight after `$page->save()`).
2. **`Pages::saved` hook** in `init()` — when a page hosting a managed image field is saved outside the module (e.g. in the native ProcessPageEdit), the row cache is dropped so the next listing shows fresh values.
3. **Cache-key hash** over `imageFields + eligibleTemplates`, so schema changes (new image fields, modified template selection) automatically produce new keys.

**Save path (pure PW API):**

```php
$page = $pages->get($pageId);
$img  = $page->{$fieldName}->getFile($basename);
$img->{$subfield} = $value;
$page->save($fieldName);  // triggers cache invalidation
```

**Custom-field discovery per image field:**

```php
$customTpl = $templates->get("field-{$fieldName}");
if ($customTpl) {
    foreach ($customTpl->fields as $cf) {
        // auto-create column for $cf->name, include in findRaw fields
    }
}
```

## Performance expectations

All Phase-1 paths use the PW API exclusively. Expected latencies for a dataset of ~3500 images across ~40 pages:

| Operation | Cold (cache miss) | Warm (cache hit) |
|---|---|---|
| `findRaw` multi-field-subfield query | ~80–150 ms | cached: ~10 ms |
| PHP filter over ~3500 rows | ~10–20 ms | same |
| Sort + slice (50 rows) | <5 ms | same |
| `getMany` for ~30 unique pages | ~50 ms | same (Pageimage loads) |
| Thumb URLs (with variations already cached) | <30 ms for 50 | same |
| **Total listing request** | **~200 ms** | **~100 ms** |
| Save request (single cell) | ~150–200 ms | same |

**Scalability outlook** (Phase 2 if needed): for datasets beyond ~10 k images, switch to `$pages->findMany()` — lazy-loading iterator that streams pages in chunks and keeps memory in check. The cache format would then likely move to a per-image key instead of serializing the whole array.

## Default columns

Mandatory / read-only:

- **Thumb** — rendered via a hybrid pipeline that prefers PW's lazily-generated 260 px admin variation and only falls back to a dedicated `$img->size()` when the configured display target exceeds the admin variation's longer side. `loading="lazy"` on the `<img>`.
- **Page** (title + link to the PW edit page; resolves to the owner page when the image lives inside a Repeater / RepeaterMatrix item)
- **Field** (field name, e.g. `images`, `lead_image`)
- **Filename** (`$img->basename`, inline-editable in single or batch mode)

Visible by default / editable:

- **Description** — textarea
- **Tags** — input depends on the field's `useTags` config:
  - `useTags=0`: hide the column
  - `useTags=1`: text input + autocomplete from historically used tags
  - `useTags=2`: multi-select from `tagsList`
  - `useTags=8|9`: multi-select + free text input
- **Uploaded** (Pagefile `created`) and **Modified** — formatted via `$config->dateFormat`, sortable, read-only
- **Dimensions** (`{w}×{h}`) — read-only
- **Filesize** — read-only

Auto-discovered (every custom-field subfield of the `field-{fieldname}` template):

- Input-type mapping based on the Inputfield class:
  - Text / Textarea → editable text / textarea
  - Checkbox → checkbox
  - Page reference → select / multi-select
  - Datetime → date picker

**Config:** Per user in `$user->meta('imageLibraryPrefs')` — cross-device persisted via `___executeUserPrefs`. Shape: `{columns: {visible: {col: bool}, order: [col]}, pageSize: int|null}`. Default set: mandatory + description + tags + custom fields (admin can preset a default-hidden list in the module config).

## Edit semantics

**Per-cell inline edit:**

1. Click (or keyboard Enter/Space — cells are `role="button" tabindex="0"`) on a cell → input/textarea replaces the display value. Textarea customs open a modal popup with multilang tabs when the installation has languages enabled.
2. Blur OR Enter → AJAX POST with `{ pageId, fieldName, basename, subfield, value, langId? }`
3. Server: validates (tag whitelist when `useTags=2`, etc.), runs `$page->save()`, returns `{ ok, value }` or `{ ok: false, error }`.
4. UI: optimistic update, green check / red X. Both state changes are additionally written into a visually-hidden `aria-live` region so screen readers pick them up.
5. Cache is invalidated both explicitly and via the `Pages::saved` hook.

**Bulk edit (selection as paintbrush):** When the edited cell belongs to an active selection, an "Add / Replace" picker appears on save. Choice + commit distributes the new value to all selected rows with the same subfield addressing.

**Save queue:** Multiple edits get serialized per pageId — no parallel `$page->save()` calls against the same page (avoids ChangeTracker races).

## Filter

Collapsible "Filters" fieldset (icon `fa-filter`) above the table. The label carries an `(N)` suffix with the count of active filters so the state stays visible while the fieldset is collapsed.

| Filter | Filtered where | Note |
|---|---|---|
| Full-text search (page title, description, tags, filename, customs) | PHP | Word match, multilang-aware |
| Template filter | PHP | from `eligibleTemplates`; live-narrows the Image-field dropdown to fields that actually live on the chosen template |
| Image-field filter | PHP | from `imageFields` |
| "Missing description" | PHP | always visible — every image has a description slot |
| "Missing tags" | PHP | visible only when the active Template / Image-field selection has at least one field with `useTags` |
| "Missing &lt;custom&gt;" | PHP | one checkbox filter per custom subfield; visible only when the active selection exposes that subfield |
| Tags | PHP | multi-select of actually-applied tags, AND semantics; the whole fieldset hides when the selection has no tag-capable field |

**Capability-based narrowing.** A second JS function mirrors the template→field pattern: as soon as a Template or Image-field is picked, the Tags fieldset and the `Missing tags` / `Missing <custom>` checkboxes hide and uncheck themselves when they don't apply. With only a Template selected, the effective capability set is the union across that template's image fields — so a template whose only image field has no tags / no customs also collapses those filters. Map shipped to JS as `config.fieldCaps`; PHP keeps emitting the full DOM so JS has something to toggle, same shape as the existing template→field narrowing.

Filters are URL-state persisted and bookmarkable. Tags are emitted as a comma-separated value (`?tags=foo,bar`); the legacy bracket form (`?tags[]=…`) remains accepted. After "Apply" the fieldset auto-collapses so the results table has full vertical room.

## Sorting

Column-header click toggles ascending/descending. Sortable fields: page title, field, filename, description, tags, width, filesize, `created` (Uploaded), `modified`, and every custom subfield via `custom:<name>`.

**Default:** selectable in the module config (fieldset "Default sort") — column + direction. Built-in default is `pageTitle asc`. URL overrides (`?sort=basename&dir=desc`) win; URLs omit sort/dir when they match the configured default so shared links stay clean.

## Pagination

50 rows/page as the built-in default (module-config overridable). URL state `?p=3` + `?ps=100`. Total count + "Page 3 of 25" in the pagination row. Picker in the pagination block (options also module-config) — the selection persists in `$user->meta('imageLibraryPrefs').pageSize`, so it's cross-device. The pagination row is rendered both above and below the table; next to it on the right sits a `fa-columns` icon that opens the columns picker dialog.

## Permissions

- **Admin-page visibility:** user needs `page-edit` on any page hosting an image field (module check at boot)
- **Per-cell edit:** `$page->editable()` against the concrete target page (verified per request in the save endpoint)
- Optional separate permission `image-library-access` for tighter control — when present, additionally scopes admin-page visibility

## Technical constraints

- **PHP:** Exclusively the PW API (`$pages->findRaw()`, `$pages->getMany()`, `$page->save()`, `$img->size()`, `$cache->save()`, etc.). **No direct SQL.**
- **JS:** Vanilla, Fetch API, no framework dependency.
- **CSS:** Compatible with AdminThemeUikit. All colour values route through PW's `--pw-*` CSS custom properties so the table follows the active light / dark theme without manual overrides. Sortable headers adopt PW's native `.tablesorter-headerAsc` / `.tablesorter-headerDesc` / `.tablesorter-header-inner` markup so the sort visuals match what other Process modules render.
- **PW version:** 3.0.172+ (for `findRaw` with subfield wildcards) and 3.0.142+ (for custom-fields-on-images)
- **PHP version:** 8.0+

## License

MIT (or GPL depending on repo convention). The module should be submittable as a public module on modules.processwire.com.

## Install / Uninstall

`___install()`:

- Creates the admin page "Image Library" under Setup, linked to the `ProcessImageLibrary` Process class
- Optionally creates the `image-library-access` permission
- No field or template changes — the module reads existing structures

`___uninstall()`:

- Removes the admin page
- Deletes `image-library-*` cache entries
- Leaves user-meta (`imageLibraryPrefs`) in place (user setting, not module state; the legacy `mediaLibraryPrefs` and the even earlier `mediaLibraryColumns` are still honored as read-side fallbacks)
- Optionally leaves the permission (the user decides manually)

## Answered Open Questions

- **Template whitelist** → auto-discovery + optional blacklist in module config (`blacklistedTemplates`, `blacklistedFields`).
- **Single-image fields** → included, no separate toggle.
- **Custom-field columns default visibility** → auto-visible; admin can hide per column via `defaultHiddenColumns`, the user can override via the columns picker.
- **Column-config scope** → `$user->meta('imageLibraryPrefs')`, cross-device.
- **Edit mode** → inline auto-save on blur/Enter.
- **Filter URL state** → URL params, bookmarkable (tags as a comma-separated `?tags=…` value).
- **Bulk operations** → selection-as-paintbrush implemented (Add/Replace modes); single + batch filename rename implemented with placeholder grammar (`(n)`, `(N)`, `(t)`, `(d)`, `(p)`, `(f)`). Bulk delete remains open.
- **Page size** → 50 default, picker with a configurable options list, selection in `$user->meta`.
- **Permission granularity** → both: `image-library-access` as a hard gate for the admin page, `$page->editable()` per cell save.
- **Variations column** → implemented (read-only counter).
- **Module info / versioning** → SemVer, GitHub tags. Composer support not actively planned.

## Remaining Open Questions

1. **Mobile**: currently flex-wrap + horizontally-scrolling table. Sufficient, or worth a dedicated card view < 640 px?

2. **Scaling beyond ~10 k images**: the current `findRaw + WireCache::saveFor` path is linear in the number of image rows. From 30 k+ the cache rebuild becomes noticeable. The path to `findMany` + per-image index is documented in this concept but not yet quantified.

3. **Bulk delete / replace image** as a Phase-2 feature set: demand-driven by editor workflow (file rename has shipped).

4. **WebP / AVIF / SVG / animated GIF** as the source format: currently `$img->size()` is called blindly. Works, but SVG → PNG (rasterization), animated GIFs become static. UI hint worthwhile?

5. **Alt text as a separate subfield**: PW treats `description` as the implicit alt text. For strict a11y / SEO workflows a dedicated `alt` custom field may be advisable — but that's an editorial convention, not a module feature.
