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
- **Bulk edit** via "selection as paintbrush": ticked rows are updated alongside the next cell save in Add or Replace mode (tags additionally offer a Remove mode that drops the listed tag tokens from each selected row)
- **Filename rename**: inline (single image) or batch (across the active selection) via the same popup; placeholder grammar `(n)`, `(n2)..(n5)`, `(N)`, `(t)`, `(d)`, `(p)`, `(f)` works in every prose-shaped editor (filename, description, custom text/textarea) — tags excluded
- **Replace image in place**: click the upload icon on a row OR drag a file onto the row. Bytes swap, basename + URLs + Pagefile metadata stay. Extension match enforced (no jpg → png surprises). Variations regenerate server-side, the table row's thumb / dimensions / size / modified / variations cells patch in place
- **Delete image (single + batch)**: trash icon on the row, behind a confirm dialog with count + filename preview + "no undo" warning. The dialog also runs a **where-used preflight** (`___executeUsage`) and lists any pages whose `contentType=html` Textarea fields still embed each image, with direct edit links — advisory, not a gate. Selection-as-paintbrush applies: with N rows ticked, deleting on any selected row deletes the whole selection. Per-row failures land in the existing bulk-result modal
- **Bookmarks**: saved filter combinations as a tab strip above the filter bar (`WireTabs uk-tab` markup — same chrome the rest of the admin uses, no module-specific tab CSS). Click a bookmark → AJAX filter swap + filter form reset + repopulate. "+ Add bookmark" only appears when the active filter isn't already saved. Storage piggy-backs on `$user->meta('imageLibraryPrefs').bookmarks`, cross-device; only filter-shaped params are kept (sort / page-size stay orthogonal)
- **Match-aware inline save**: when an edit pushes a row out of the active filter set (e.g. assigning a tag under a "missing tags" bookmark), the row fades and drops after the success flash. Sequence: 1200 ms green flash → 200 ms breath → 250 ms fade → DOM removal + pagination count decrement. Server side, `___executeSave` / `___executeRename` / `___executeBulk` accept a `filterQs` POST field, run `parseFilterQs()` + `evaluateFilterTouchedRows()` against the just-saved row(s), and return `stillMatches` / `vanished` / `newTotal`. Last row removed → table wrapper swaps to the empty-state paragraph; pager stays
- **Date columns**: Uploaded (Pagefile `created`) and Modified, sortable, formatted in `$config->dateFormat`
- **Variations column**: per-image counter from `$img->getVariations()`
- **Export / Import**: JSON and CSV (with multilang-aware column suffixes `<subfield>_<langName>`). Image-URL variant picker on export — Original / 260 / 512 / 1024 px shorter side — so external pipelines (e.g. AI vision agents) can fetch cheap admin variations instead of the raw originals
- Server-side filtering / sorting / pagination with **capability-based filter narrowing**: the Tags fieldset + each `Missing <custom>` checkbox hide live as soon as the chosen Template / Image-field has no fitting capability
- Per-user column configuration in `$user->meta('imageLibraryPrefs')` — **cross-device**, including page size
- Auto-detection of **Custom Fields on Images** (`field-{fieldname}` template, PW 3.0.142+)
- AdminThemeUikit light / dark theme integration via `--pw-*` CSS custom properties
- **Table or masonry gallery view** — a toolbar toggle between the data table and a thumbnail gallery; the gallery keeps each image's natural ratio and packs tiles into height-balanced columns via shortest-column placement. A per-user thumbnail-size slider scales thumbs / tiles live; view + zoom persist in `$user->meta`, cross-device (see [Views](#views-table--masonry-gallery))
- **Automatic de-duplication** — byte-identical images are fingerprinted (`content_hash`) and collapsed onto one hardlinked inode (lossless, reversible; originals + variations + page-version files). Runs on save + hourly (`LazyCron`) + an install pass; a *Duplicates* filter, copy-count badges and a cluster expand / modal surface them, and the config page offers Scan / Re-measure / Revert tools. Design detail in [`dedup-design.md`](dedup-design.md) (see [De-duplication](#de-duplication))
- **Collections** — a hand-picked set of images saved per user (`collections: {id, name, keys[]}`), recalled by a short `?coll=<id>` URL; curated by clicking a collection tab while a selection is active (the cursor signals add vs remove), and itself filterable (see [Collections](#collections))
- **Picker add-ons** (optional, off by default) — surface the library outside its admin page: a *Choose from library* button on every image field (version-aware assign), and an *Insert from library* button in TinyMCE / CKEditor (admin + front-end inline editor) (see [Picker add-ons](#picker-add-ons))
- **Rename rewrites rich-text embeds** — after a basename change the same `contentType=html` scan rewrites every embed of the old file to the new stem (original + variations, all languages, repeater-aware), so embeds don't silently break

**Out of scope (also in the current version):**

- Uploading brand-new images from scratch (replace swaps an existing slot, the picker assigns an existing library file; a standalone upload is not offered)
- Moving images between pages
- Variations management of its own (crop / focus / regenerate) — the module shows the variations counter and opens PW's **native** per-image editor (crop / focus / variations UI) on a thumb click, but doesn't itself regenerate or manage variation files
- Re-sorting within an image field (`$img->sort`)

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
├── assets/                           # feature-specific front-end assets
│   ├── reclaim-live.js / .css        # de-dup config: live scan / reclaim / revert / audit UI
│   ├── library-pick.js               # add-on: "Choose from library" on image fields
│   ├── insert-mce.js                 # add-on: TinyMCE "Insert from library" adapter
│   ├── insert-cke.js                 # add-on: CKEditor 4 "Insert from library" adapter
│   ├── insert-common.js              # add-on: shared picker / native-dialog logic
│   └── insert-icon.svg               # add-on: CKEditor toolbar icon
├── src/
│   ├── ImageLibraryDiscovery.php     # trait: image-field / template / tags-config introspection
│   ├── ImageLibraryMultilang.php     # trait: per-language read/write, name⇄id mapping
│   ├── ImageLibraryHashing.php       # trait: content-hash de-dup (hardlink, reclaim, audit, revert)
│   └── ImageLibraryExportImport.php  # trait: JSON + CSV emit, parse, idempotent re-apply
├── docs/
│   ├── ImageLibrary-Concept_EN.md
│   ├── ImageLibrary-Konzept_DE.md
│   ├── dedup-design.md               # de-duplication design rationale + phased plan
│   └── screenshots/
├── README.md
└── LICENSE
```

The `src/` traits keep the main module file focused on AJAX endpoints + rendering; discovery, multilang, hashing/de-dup and export/import each own a cohesive slice.

**Methods:**

- `___execute()` — renders the table and filter UI (server-rendered HTML; JS hydrates for interaction). Columns picker lives as a sibling `<dialog>` next to `.ml-results` so AJAX swaps leave drag/toggle handlers intact.
- `___executeData()` — AJAX GET, returns only the `.ml-results` block (table + pagination) for filter/sort/page swaps.
- `___executeSave()` — AJAX POST, validates + saves a cell change, returns JSON. Multilang-aware: the payload can carry a `langValues` JSON map (`{langId: value}`), in which case every language slot is written in one POST via `applyLangValues()` (single-language installs just send `value`). Reads `filterQs` from POST and returns `stillMatches` + `newTotal` so the client can fade rows that fell out of scope.
- `___executeBulk()` — AJAX POST: apply an identical cell save to a selection (Add / Replace mode, plus a tags-only Remove mode). Returns `vanished` (list of selection keys that dropped out of the filter) + `newTotal` alongside the success / failure counts. Rows whose image field doesn't carry the broadcast subfield silently succeed as no-ops instead of being counted as failures — a paintbrush hitting heterogeneous selections (e.g. "author" across rows from `images` + `lead_image` where only one carries it) is an editorial reality, not a user error.
- `___executeRename()` — AJAX POST, renames a single image's file (or every selected image in batch mode) via `Pagefile::rename()` after expanding placeholders and clearing old variation files.
- `___executeReplace()` — AJAX POST, replaces an image's file bytes via `move_uploaded_file()` onto the existing path, drops old variations, re-generates the thumb variation, returns the refreshed cell payload (thumb URL, dimensions, filesize, modified, variations count). Extension match enforced so the basename stays valid.
- `___executeDelete()` — AJAX POST with an `items` array; single + batch share the path. Per page `$page->editable()`, then `$pageimages->delete($img)` + `$page->save($field)`. Returns succeeded / failed lists so the JS can fade rows out and surface partial failures through the bulk-result dialog.
- `___executeExport()` — direct download of JSON or CSV honoring the active filters. Reads `urlVariant` (`original` default; `260` / `512` / `1024` for same-axis variations) and emits the matching URL in the `url` column; the chosen variant is recorded in `meta.urlVariant`.
- `___executeImport()` — AJAX POST, accepts a previously-exported (and externally edited) JSON / CSV file and writes it back; idempotent (unchanged items are skipped).
- `___executeUserPrefs()` — AJAX POST, persists columns + page size + view mode + thumbnail scale + bookmarks + **collections** into `$user->meta('imageLibraryPrefs')` (debounced). Bookmarks are validated via `$sanitizer->text(maxLength: 80)` for the name and `canonicalizeBookmarkQs()` for the querystring; collections via `sanitizeCollection()` (alnum id, capped name, sanitised + de-duped + capped row-keys) — so saved + loaded shapes stay in lockstep.
- `___executeAssign()` — AJAX POST (image-field picker add-on): copy an existing library image into a target page's image field (native fields reference only their own page folder, so the bytes are copied), carrying description / tags / customs over language-aware. Version-aware — when the editor works in a `PagesVersions` version the copy lands in `…/<id>/v<n>/` and is hardlinked to its byte-identical source on the spot.
- `___executeClusterTable()` — AJAX GET, renders the editable mini-table of one duplicate cluster's copies for the masonry cluster modal.
- `___executeScanStep()` / `___executeReclaimStep()` / `___executeRevertStep()` / `___executeDiskAudit()` — chunked, time-budgeted de-dup endpoints driving the config page's live "Scan and reclaim" / "Revert" / "Re-measure" tools (fingerprint scan, hardlink reclaim, un-share, real-disk audit). See [`dedup-design.md`](dedup-design.md).
- `___executeUsage()` — AJAX POST, where-used preflight for the delete confirm dialog. Accepts `items=[{pageId, basename}, …]`, returns `usage: { "pid:basename": [ {pageId, pageTitle, editUrl, fieldName}, … ] }`. Reverse-scans every `FieldtypeTextarea` via `$pages->findIDs("{field}%='/{pid}/{stem}.', include=all")` — the `%=` substring-LIKE selector is multilang-, repeater- and access-aware, no raw SQL needed. The stem-prefix needle catches the original AND every PW-derived variation (`foo.500x300.jpg`, `foo.500x300-cropped.jpg`, `…hidpi.jpg`) in one needle, since `pwimage` typically inserts a sized variation rather than the original. Editor-agnostic — both `pwimage` plugins (CKEditor + TinyMCE) insert the same URL shape. Gating is existence + editability, NOT `viewable()`: an admin with image-library-access plus per-page edit rights needs to know about embeds even in pages they can't see on the front-end.
- `___executeWidget()` — AJAX GET, renders PW's configured Inputfield for a page-reference custom subfield (PageAutocomplete / PageListSelect / ASMSelect / etc.). Captures `$config->scripts` / `$config->styles` snapshots before + after the render so only the NEW asset URLs go back to the client. The popup injects the HTML, lazy-loads any new scripts / styles, fires the `'reloaded'` DOM event on each `.Inputfield` so PW's delegated init handlers wire up. Save flows back through `___executeSave`; `coerceCustomValue()` shapes `[id]` into a single int (single-page fields) or a fresh `PageArray` (multi-page) — the second form is what gives `FieldtypePage::sanitizeValuePageArray()` its REPLACE-instead-of-merge semantics.
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

- **Description** — textarea. Long values are clamped in the table to a few lines (≈150 chars) with an ellipsis via CSS (`.ml-clamp`, `--ml-clamp-lines`, default 3); the clamp is display-only — the full text stays in the cell so the editor always opens with the complete value.
- **Tags** — input depends on the field's `useTags` config:
  - `useTags=0`: hide the column
  - `useTags=1`: text input + autocomplete from historically used tags
  - `useTags=2`: multi-select from `tagsList`
  - `useTags=8|9`: multi-select + free text input
- **Uploaded** (Pagefile `created`) and **Modified** — formatted via `$config->dateFormat`, sortable, read-only
- **Dimensions** (`{w}×{h}`) — read-only
- **Filesize** — read-only

Auto-discovered (every custom-field subfield of the `field-{fieldname}` template). The cell display and the inline editor are typed by the subfield's Fieldtype:

- **Text / Textarea** → text input / textarea (Textarea cells share the description clamp)
- **Checkbox** (`FieldtypeCheckbox`) → display `✓` / `—` (empty); inline editor is a single checkbox
- **Datetime** (`FieldtypeDatetime`) → display via the field's own `dateOutputFormat`; inline editor is a native `<input type="date">` or `datetime-local` depending on whether the format carries a time component
- **Integer** (`FieldtypeInteger`) → numeric input
- **FieldtypeOptions** (single + multi) → display the option label(s); inline editor is a native `<select>` (single) or a touch-friendly checkbox list (multi)
- **FieldtypePage** (single + multi) → display the page title(s); inline editor renders **PW's actually-configured Inputfield** for that field — PageAutocomplete / PageListSelect / PageListSelectMultiple / ASMSelect / whatever the field's own config picks — via `___executeWidget`, so 1000s of selectable pages get the proper search / hierarchy / sort UX with zero re-implementation

**Config:** Per user in `$user->meta('imageLibraryPrefs')` — cross-device persisted via `___executeUserPrefs`. Shape: `{columns: {visible: {col: bool}, order: [col]}, pageSize: int|null, viewMode: 'table'|'masonry', thumbScale: float, bookmarks: [{name, qs}], collections: [{id, name, keys[]}]}`. Default set: mandatory + description + tags + custom fields (admin can preset a default-hidden list in the module config).

## Edit semantics

**Per-cell inline edit:**

1. Click (or keyboard Enter/Space — cells are `role="button" tabindex="0"`) on a cell → a modal popup opens with the value. Textarea cells (description + Textarea customs) are clamped to a few lines in the table, but the popup always shows the full text. Multilang fields get per-language tabs when the installation has languages enabled.
2. Blur OR Enter → AJAX POST with `{ pageId, fieldName, basename, subfield, value, langValues? }` — `langValues` is a `{langId: value}` map sent for multilang fields so every language commits in one save.
3. Server: validates (tag whitelist when `useTags=2`, etc.), runs `$page->save()`, returns `{ ok, value }` or `{ ok: false, error }`.
4. UI: optimistic update, green check / red X. Both state changes are additionally written into a visually-hidden `aria-live` region so screen readers pick them up.
5. Cache is invalidated both explicitly and via the `Pages::saved` hook.

**Bulk edit (selection as paintbrush):** When the edited cell belongs to an active selection, an "Add / Replace" picker appears on save (tags additionally offer "Remove", which drops the listed tag tokens from each selected row). Choice + commit distributes the new value to all selected rows with the same subfield addressing.

**Selection key + survival across view changes.** Each ticked row is stored client-side under the tuple key `pageId:fieldName:basename`, not by DOM position. Filter swaps, sort flips and pagination re-issue the AJAX data fetch and rebuild the `<tbody>`; the JS then re-checks any tick whose key is still in the set. Rename returns a `renamed{oldKey: newKey}` map so the selection follows the file. Delete drops the deleted keys. The set survives every view operation that doesn't itself drop the row.

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

Column-header click toggles ascending/descending. Sortable fields: page title, field, filename, description, tags, width, filesize, `created` (Uploaded), `modified`, and every custom subfield via `custom:<name>`. Sorting by `custom:<name>` triggers a bulk hydration pass before `applySort` so the column has values to compare — without it, every row would tie and the list would stay in `pageId:basename` tiebreaker order.

**Default:** selectable in the module config (fieldset "Default sort") — column + direction. Built-in default is `pageTitle asc`. URL overrides (`?sort=basename&dir=desc`) win; URLs omit sort/dir when they match the configured default so shared links stay clean.

## Pagination

50 rows/page as the built-in default (module-config overridable). URL state `?p=3` + `?ps=100`. Total count + "Page 3 of 25" in the pagination row. Picker in the pagination block (options also module-config) — the selection persists in `$user->meta('imageLibraryPrefs').pageSize`, so it's cross-device. The pagination row is rendered both above and below the table; next to it on the right sits a `fa-columns` icon that opens the columns picker dialog.

## Views: table & masonry gallery

A toolbar **view toggle** (right of the pagination row) switches between the data **table** and a **masonry gallery**. The gallery keeps each thumbnail's natural aspect ratio (no crop) and packs tiles into **height-balanced columns** via shortest-column placement: the next tile drops into the currently shortest column, using the server-rendered image dimensions (`<img width/height>`) so the layout settles immediately without waiting for image loads. Gallery tiles carry the same selection checkbox (hover-revealed, bottom-left), replace / delete actions and duplicate badge as table rows, and the selection set is shared across both views; clicking a tile opens PW's native per-image editor. A per-user **thumbnail-size slider** scales table thumbs / gallery tiles live; the view choice and zoom both persist in `$user->meta`, cross-device.

## De-duplication

Every managed image is fingerprinted by its **exact byte content** (`content_hash`, xxh128 where available else md5) and byte-identical copies are collapsed onto a single inode via **hardlinks** — **lossless and reversible**: bytes never change, any copy can be given its own file again. Both originals **and** PW's generated variations, plus page-version files (`…/<id>/v<n>/`), are deduplicated, across all pages and fields. The filesystem's link counts are the source of truth (no manifest table); byte-identity is re-verified immediately before every link.

It runs **automatically** — on every `Pages::saved` (the saved page's images are fingerprinted and any existing twin linked at once), hourly via `LazyCron`, and once as a bounded pass at install. The config page's **Deduplication** fieldset shows the disk saved (*Disk space reclaimed* / *Copies sharing a file* / *Exact-duplicate clusters*) and offers manual tools — **Scan and reclaim (live)**, **Re-measure**, **Revert (un-share all)** — backed by chunked, time-budgeted endpoints (`scan-step`, `reclaim-step`, `revert-step`, `disk-audit`) with a live progress panel. In the listing, a *Duplicates* filter (contextual, collapsing each cluster to one representative), a copy-count badge on table + masonry tiles, the table cluster expand/collapse, and the masonry cluster modal surface duplicates for review. Hash store: `process_imagelibrary_hashes` (created lazily, dropped on uninstall). **Full design rationale + phased plan: [`dedup-design.md`](dedup-design.md).**

## Collections

A **collection** saves a *hand-picked set of specific images* (where a bookmark saves a *filter*) — for sets that no filter can reproduce. It's stored per user in `$user->meta('imageLibraryPrefs').collections` as `{id, name, keys[]}` (the row-identity keys `pageId:fieldName:basename`), recalled by a short `?coll=<id>` URL — the keys stay server-side, so a 100-image collection is a ~12-character link, not a multi-kilobyte query string. The server resolves the id back to the key set and narrows the grid to it.

Collections share the bookmark tab strip (icon-marked) and work in the admin **and** the picker. Create: tick checkboxes → the bar's add button relabels to *Add collection* → name + save (the checkboxes clear). Curate: with a selection active, clicking a collection tab adds the selection to it (non-active tab → `+` cursor) or removes it from the one you're viewing (active tab → `−` cursor). Filterable: `?coll` coexists with the filter params, so filtering narrows *within* the collection (`applyRowFilters` intersects the sel set first, then the normal filters); deleting the collection you're viewing drops `?coll` and reloads.

## Picker add-ons

Two **optional, off-by-default** integrations (config fieldset *Picker add-ons*) that surface the library *outside* its admin page, each opening it as a modal picker. Enabling either makes the module `autoload` (run **Modules → Refresh** after toggling).

- **Image-field picker** (`addonPicker`) — a *Choose from library* button on every `InputfieldImage`. Assigning copies the chosen file into the target field (native image fields reference only their own page folder, so the bytes are copied via `___executeAssign`), carries description / tags / customs over language-aware, and hardlinks the copy to its byte-identical source. **Version-aware:** when editing a `PagesVersions` version the copy lands in that version's `v<n>/` folder.
- **Rich-text insert** (`addonRichtext`) — an *Insert from library* button (gallery icon) in every TinyMCE and CKEditor field, in the admin **and** the front-end inline editor. A single pick hands straight to PW's own image dialog (crop / resize / caption / align) before the `<img>` is inserted; the embedded image references the shared library file (no copy). Wired by inline glue that loads thin per-editor adapters (`assets/insert-mce.js` / `insert-cke.js`) over a shared core (`assets/insert-common.js`); the TinyMCE / CKEditor plugin name stays `mllibrary`.

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
- **Bulk operations** → selection-as-paintbrush implemented for edit, filename rename, replace-in-place and delete; placeholder grammar (`(n)`, `(N)`, `(t)`, `(d)`, `(p)`, `(f)`) shared across every prose-shaped editor.
- **Page size** → 50 default, picker with a configurable options list, selection in `$user->meta`.
- **Permission granularity** → both: `image-library-access` as a hard gate for the admin page, `$page->editable()` per cell save.
- **Variations column** → implemented (read-only counter).
- **Module info / versioning** → SemVer, GitHub tags. Composer support not actively planned.

## Roadmap (planned)

- **Tag management overhaul** — review and improve tag handling end-to-end: the inline editor across the `useTags` modes (free-form `1` vs whitelist `2` vs mixed `8|9`), the multi-select tags filter, autocomplete from used tags, and the Add / Replace / Remove paintbrush modes. Likely directions: cross-library tag **rename / merge** (rename a tag everywhere it's used), a clearer whitelist-editing UX, and a unified tag pool across heterogeneous fields. Scope still open — to be specced before implementation.

*Recently shipped (previously on this roadmap): the per-user thumbnail-size slider, and where-used on rename — now implemented as active embed-rewriting (rename rewrites the rich-text embeds rather than only warning).*

## Remaining Open Questions

1. **Mobile**: currently flex-wrap + horizontally-scrolling table. Sufficient, or worth a dedicated card view < 640 px?

2. **Scaling beyond ~10 k images**: the current `findRaw + WireCache::saveFor` path is linear in the number of image rows. From 30 k+ the cache rebuild becomes noticeable. The path to `findMany` + per-image index is documented in this concept but not yet quantified.

3. **Standalone upload** (creating a brand-new image slot from the library, not replacing one) — would break the row-as-`(page,field,basename)` model since the target page + field would have to be picked first. Pages-Edit already covers this well; revisit only if editor demand is high.

4. **WebP / AVIF / SVG / animated GIF** as the source format: currently `$img->size()` is called blindly. Works, but SVG → PNG (rasterization), animated GIFs become static. UI hint worthwhile?

5. **Alt text as a separate subfield**: PW treats `description` as the implicit alt text. For strict a11y / SEO workflows a dedicated `alt` custom field may be advisable — but that's an editorial convention, not a module feature.
