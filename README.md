# Image Library

A ProcessWire admin module that puts every image across every page and every image field into one filterable, inline-editable table. Built for editorial teams that need to audit and update image metadata in bulk — description, tags, custom subfields, multilang values, filenames — without navigating to each page individually.

![Overview screenshot of the Image Library admin page showing bookmark tabs and the collapsed filter bar at the top, a table of images with thumbnails / filename / page / tags / description / size / dimensions / uploaded columns, and a toolbar with the thumbnail-size slider plus the table / gallery view toggle](docs/screenshots/01-overview.png)

## Contents

- [Quick tour](#quick-tour)
- [Table and gallery views](#table-and-gallery-views)
- [Requirements](#requirements)
- [Install](#install)
- [Permissions](#permissions)
- [Module configuration](#module-configuration)
  - [Thumbnail](#thumbnail)
  - [Pagination](#pagination)
  - [Default sort](#default-sort)
  - [Columns](#columns)
  - [Scope](#scope)
- [Filtering](#filtering)
- [Bookmarks](#bookmarks)
- [Collections](#collections)
- [The table](#the-table)
  - [Columns dialog](#columns-dialog)
  - [Pagination row](#pagination-row)
- [Inline editing](#inline-editing)
  - [Editing as paintbrush (bulk)](#editing-as-paintbrush-bulk)
- [Renaming files](#renaming-files)
  - [Placeholders](#placeholders)
  - [Single rename](#single-rename)
  - [Batch rename](#batch-rename)
  - [Embeds follow the rename](#embeds-follow-the-rename)
- [Replacing files](#replacing-files)
- [Deleting images](#deleting-images)
- [Deduplication](#deduplication)
  - [Browsing duplicates](#browsing-duplicates)
  - [Reclaiming disk](#reclaiming-disk-config-page)
- [Export / Import](#export--import)
  - [Export](#export)
  - [Import](#import)
- [Picker add-ons](#picker-add-ons)
  - [Image-field picker](#image-field-picker)
  - [Rich-text insert](#rich-text-insert)
- [Performance](#performance)
- [Accessibility](#accessibility)
- [File layout](#file-layout)
- [License](#license)

## Quick tour

- **Single view of every image** on the site. Aggregates all `FieldtypeImage` fields across all templates — including images that live inside Repeater / RepeaterMatrix fields, resolved up to their owner page. Rows are `(page, field, basename)` tuples.
- **Table or masonry gallery** — toggle between the data table and a thumbnail gallery that packs tiles into **height-balanced columns** (shortest-column masonry) for fast visual scanning; click a tile to open the per-image editor, or use the hover-revealed checkbox to select it. A size slider scales thumbnails (table) / tiles (gallery) live. Both choices persist per user via `$user->meta`.
- **Inline editing** for description, tags and any custom subfields (PW 3.0.142+ field-on-image templates). Click a cell, type, hit save — that's it. Multilang installs get per-language tabs in the editor.
- **Bulk edits as paintbrush** — tick a few rows, then edit any cell on a selected row to broadcast the change to all selected rows. Works for description, tags, customs, and filenames (with placeholder syntax for numbering).
- **Replace image in place** — drag a file onto the row or click the upload icon. The basename + every URL stay intact, variations regenerate, metadata is preserved. Extension match enforced so format conversions can't sneak in.
- **Delete (single + batch)** — trash icon on the row hides behind a confirm dialog. Selection-as-paintbrush works here too: with N rows ticked, clicking the trash on any selected row deletes the whole selection.
- **Automatic de-duplication** — byte-identical images are fingerprinted and collapsed onto one hardlinked file (lossless, reversible), so duplicate copies cost disk space only once. Runs by itself (on save + hourly); a *Duplicates* filter, copy-count badges and an expandable cluster view surface them, and the config page shows the disk saved with manual Scan / Re-measure / Revert tools.
- **Bookmarks** — save the current filter combination as a named tab above the filter bar. Click a tab to jump back to that view; the filter form repopulates so what you see matches what's applied. Persisted per user via `$user->meta`, cross-device. The "+ Add bookmark" tab surfaces only when the active filter isn't already saved.
- **Collections** — curate an arbitrary set of images that no filter could reproduce: tick checkboxes, save them as a named collection tab in the same strip. Recall the exact set instantly via a short `?coll=<id>` URL (the image keys live in `$user->meta`, never the URL — a 100-image collection is still a ~12-character link). Grow / trim a collection by clicking its tab while a selection is active; the cursor shows whether the click **adds** (+) or **removes** (−). Collections can themselves be filtered.
- **Picker add-ons** (optional, off by default) — two opt-in integrations that let editors pull a library image in elsewhere: a *Choose from library* button on every image field, and an *Insert from library* button in TinyMCE / CKEditor (admin + front-end inline editor). No re-upload — the existing file is assigned / embedded; on a versioned page it lands in that version's folder.
- **Filter, sort, paginate** with URL-state persistence so the view is bookmarkable. Per-user column visibility and order, page size — all stored in `$user->meta` so they follow the user across devices.
- **Export / Import** the current filter set as JSON or CSV, edit externally, re-upload to apply. Multilang values round-trip in language-suffixed columns.
- **Server-side performance** with `findRaw` + `WireCache` so listings stay fast across thousands of images. Thumbnails reuse PW's lazily-generated 260 px admin variation whenever possible, falling back to a custom size only when the configured display exceeds it.

## Table and gallery views

The toolbar carries a **view toggle** (top-right, next to the per-page picker): the data **table** for editing metadata column by column, or a **masonry gallery** for browsing visually. The gallery packs thumbnails into **height-balanced columns**: each tile keeps its natural aspect ratio (no crop) and the next tile always drops into the currently shortest column, so the columns stay even instead of ragged. The predicted tile height comes from the server-rendered image dimensions, so the layout settles immediately without waiting for images to load. Click any tile to open the per-image editor (full crop / focus / metadata); the same **selection checkbox** the picker uses sits in the tile's bottom-left corner — hover-revealed here, like the replace / delete actions, and staying visible once ticked — so tiles can be selected for bulk edits or collections just as in the table (the selection is shared across both views). The **size slider** beside the toggle scales thumbnails (table) or tiles (gallery) live; the chosen view and zoom both persist per user across devices.

![Masonry gallery view of the Image Library: the same toolbar with the size slider and table / gallery toggle, below it a grid of flower thumbnails of varying heights packed into even, height-balanced columns](docs/screenshots/04-masonry.png)

## Requirements

- ProcessWire **3.0.172+** (uses `findRaw` with subfield syntax) and ideally **3.0.155+** for inline rename (`Pagefile::rename`)
- PHP **8.0+**
- Admin theme: tested against AdminThemeUikit; should work with Reno / Default

## Install

1. Drop the repository into `site/modules/ProcessImageLibrary/`.
2. In the ProcessWire admin: **Modules → Refresh → Install „Image Library"**.
3. Find the new page under **Setup → Image Library**.

The installer adds:

- An admin page `image-library` under `setup/`
- A `image-library-access` permission (assign to roles that should see the page)

Uninstall is symmetric — the admin page and cache entries go; user-meta preferences (`imageLibraryPrefs`) stay so they survive a reinstall.

## Permissions

Two-tier model:

- **`image-library-access`** — gates the admin page itself. Without it the page is invisible.
- **`page-edit` on the target page** — checked per cell, per AJAX endpoint. Editors only ever modify pages they could already edit through the standard Page-Edit UI. The library doesn't elevate access; it just gives editors a faster surface for the same operations.

## Module configuration

Under **Modules → Configure → ProcessImageLibrary** (or via the **Config** link in the page header).

![Module configuration screenshot showing the Picker add-ons, Thumbnail, Pagination, Default sort, Columns and Scope fieldsets](docs/screenshots/02-config.png)

A collapsed **Picker add-ons** fieldset sits at the top (both toggles **off** by default) — the library itself works either way. See [Picker add-ons](#picker-add-ons) for what they do. A **Deduplication** fieldset at the bottom shows the disk saved and offers manual Scan / Re-measure / Revert tools — see [Deduplication](#deduplication).

### Thumbnail

- **Width / Height (px)** — exact box for crop mode. Defaults 120 × 80.
- **Longer side (px)** — alternative for ratio mode: caps the longer axis, the other follows the source's aspect. Default 100.
- **Keep image ratio** — toggles between the two modes. When on, Width / Height hide and Longer side appears.
- **JPEG quality (1–100)** — default 90 (matches `$config->imageSizerOptions` so the admin variation file names hash identically and get reused).

The runtime tries to ride PW's admin image-field variation (260 px on the shorter axis) whenever the configured display target fits. Above that threshold the module produces a dedicated variation.

### Pagination

- **Page-size options** — comma-separated list shown in the per-page picker. Default `25, 50, 100, 200`.
- **Default page size** — initial slice for users with no saved preference. Drawn from the options above.

### Default sort

- **Column** — `pageTitle`, `fieldName`, `basename`, `description`, `tags`, `width`, `filesize`, `created` (Uploaded), `modified`.
- **Direction** — Ascending or Descending. Defaults `pageTitle asc`.

URL overrides (`?sort=…&dir=…`) and header clicks always win; the default only applies on a clean URL.

### Columns

- **Hidden by default** — admin-side preset for the column picker. Users can still toggle individual columns on for themselves; this just controls the initial state for new users.

### Scope

- **Blacklisted templates** — pages of these templates are excluded from discovery. Lists only templates that actually host an image field (others would be no-ops to blacklist).
- **Blacklisted image fields** — entire image fields excluded regardless of which template hosts them. Useful when one field (e.g. `signature_image`) lives on many templates but doesn't belong in the library.

## Filtering

Click the **Filters** fieldset header to expand. The label carries an `(N)` suffix with the count of active filters so state stays visible while collapsed.

![Filter fieldset expanded, showing search field, template + image-field selects, tag checkboxes grid, and missing-X checkboxes with Apply / Reset buttons](docs/screenshots/03-filters.png)

Available filters:

| Filter | What it does |
|---|---|
| Search | Word-match across page title, description, tags, filename, custom subfields |
| Template | Restrict to pages of this template; the Image-field dropdown narrows to fields the chosen template actually carries |
| Image field | Restrict to images coming from one specific field |
| Tags | Multi-select AND-match against pooled tags across all rows |
| Missing description | Rows whose description is empty |
| Missing tags | Rows whose tags are empty |
| Missing &lt;custom&gt; | One checkbox per custom subfield; rows whose value for that subfield is empty |
| Duplicates | Only images with ≥2 byte-identical copies present in the current view; each cluster collapses to one representative (see [Deduplication](#deduplication)) |

**Live capability narrowing.** As soon as you pick a Template or an Image field, the rest of the filter bar collapses to what's actually applicable: the Tags fieldset hides when the selection has no `useTags` field, and each `Missing <custom>` checkbox hides when the selection doesn't expose that subfield. Selecting just a Template uses the union of capabilities across its image fields, so a template whose only image field has no tags / no customs also drops those filters. Stale ticks get cleared automatically so what you submit matches what you see.

All filter state lives in the URL (`?q=…&template=…&tags=foo,bar&…`) — bookmarkable, shareable.

After **Apply** the fieldset auto-collapses so the table has full vertical room. **Reset** clears every filter at once and rebuilds the view.

## Bookmarks

A tab strip sits above the filter bar with the user's saved filter combinations. PW-native chrome — the same `WireTabs` + `uk-tab` markup the rest of the admin uses (Page Edit, Profile, etc.), so the look matches and no module-specific CSS is involved.

- **Show all** is always the leftmost tab — empty filter state.
- **Saved bookmarks** sit between, in the order they were created. Each tab carries an `×` button on hover (only inside its own tab area) to delete. [Collections](#collections) share the same strip, marked with an icon.
- **+ Add bookmark** is the rightmost tab and appears when the active filter is BOTH non-empty AND not already saved — so it surfaces exactly when there's a new combination worth keeping. When a checkbox **selection** is active instead, the same button relabels to **+ Add collection** and saves the selection (see [Collections](#collections)).

Clicking a bookmark navigates via the same AJAX swap the filter form uses, and **resets + repopulates the filter form** so the visible inputs match the bookmark's state — no stale checkboxes left from the previous filter. Active tab is computed by canonicalising the current URL against each bookmark's saved querystring (filter-shaped params only, sorted, empty values dropped).

What's stored: only **filter** params (`q`, `template`, `field`, `tags`, `no_desc`, `no_tags`, `no_custom_*`). Sort, direction, page size and page number stay orthogonal — switching bookmarks doesn't clobber your current sort.

Storage piggy-backs on `$user->meta('imageLibraryPrefs')` alongside the existing `columns`, `pageSize`, `viewMode`, `thumbScale` and `collections` keys — cross-device, no new endpoint.

## Collections

Where a [bookmark](#bookmarks) saves a *filter*, a **collection** saves a *specific, hand-picked set of images* — useful when the set can't be expressed as a filter (e.g. filter to `red +flowers`, then keep only the three you actually want). Collections live in the same tab strip as bookmarks, marked with an icon, and work in the admin **and** the picker — handy for pulling up a curated set while inserting images.

![The bookmark / collection tab strip ("Show all", a "Flowers" collection, a "Red" collection, "+ Add collection") above the masonry gallery, with several flower tiles ticked via their selection checkboxes](docs/screenshots/13-collections.png)

**Storage, not URL.** A collection stores its image identity keys (`pageId:fieldName:basename`) as data in `$user->meta('imageLibraryPrefs')` under `collections`, each as `{ id, name, keys[] }`. Recall is a short `?coll=<id>` URL — a 100-image collection is a ~12-character link, never a multi-kilobyte query string that would blow past URL limits. The server resolves the id back to the key set and filters the grid to it.

**Create.** Tick image checkboxes (table or masonry). The bookmark bar's add button relabels to **+ Add collection**; click it, name the set, save. The checkboxes clear as confirmation, and the new collection tab joins the strip.

**Recall, add, remove — driven by the cursor.** With a selection active, clicking a collection tab curates it instead of navigating, and the cursor signals which way:

- Hovering a collection you're **not** viewing shows a **`+`** cursor — the click **adds** the selection to that collection.
- Hovering the collection you **are** viewing (its tab is active) shows a **`−`** cursor — the click **removes** the selected images from it (the rows leave the grid in place and the count updates).

Either way the checkboxes clear as confirmation. With **no** selection, a collection tab behaves like any tab — it recalls the set — and its `×` (delete) appears on hover.

**Snapshot semantics.** A collection is a snapshot of identities: images deleted or renamed after the fact simply drop out of the recalled view, silently. Duplicate markers are *contextual* (an image is only flagged when ≥2 of its byte-identical copies are present in the current view), so they appear inside a collection only if you deliberately added two copies of the same image to it.

**Filterable.** A collection can be narrowed: applying a filter (or Reset) while viewing one keeps `?coll` in the URL, so the filters apply *within* the collection rather than replacing it. Deleting the collection you're viewing drops `?coll` and reloads (any other active filters stay).

## The table

- **Thumb** — clickable when the host page is editable; opens the native PW page-edit form for this image in a full-screen iframe (with PW's crop / focus / variations UI).
- **Page** — link to the page-edit screen. For images that live inside a Repeater / RepeaterMatrix field, this resolves to the visible owner page (not the internal `repeater_<field>` storage page).
- **Field** — image field name.
- **Filename** — inline-editable (see [Renaming](#renaming-files)). Extension stays locked.
- **Description, Tags** — inline-editable (see [Editing](#inline-editing)).
- **Uploaded, Modified** — created / last-modified timestamps from the underlying Pagefile, formatted in `$config->dateFormat`. Read-only, sortable.
- **Dimensions, Size, Variations** — read-only.
- **Custom subfields** — auto-discovered from each image field's `field-{name}` custom template (PW 3.0.142+). Editable.

**Long-value display.** Description and Textarea-backed custom cells cap their *visible* height to a few lines (≈150 characters) with a trailing ellipsis so a long value can't stretch the row and blow up the table layout. Only the display is clamped — the full text always stays in the cell, so clicking it opens the editor with the complete value (see [Inline editing](#inline-editing)). The line count is configurable via the `--ml-clamp-lines` CSS custom property (default 3).

Column-header click toggles sort direction. Active sort gets `aria-sort=ascending/descending` for screen readers.

### Columns dialog

The `fa-columns` icon in the pagination row opens a `<dialog>` listing every column. Toggle visibility via checkbox, reorder via drag or the ▲ / ▼ buttons (keyboard-accessible). Order and visibility persist to `$user->meta` and follow the user across devices.

![Columns dialog showing the toggleable list with up/down reorder buttons and the drag hint](docs/screenshots/05-columns-dialog.png)

### Pagination row

- Summary + prev/next on the left
- Per-page picker + columns icon on the right
- Rendered both above and below the table for long pages

## Inline editing

Click any cell with a hover highlight. A modal popup opens with the widget appropriate to the subfield. Even when the table view clamps a long value to a few lines, the editor always opens with the **complete** text — the clamp is purely visual.

- **Description** — textarea
- **Tags (free-form)** — text input with native `<datalist>` autocomplete pulled from tags actually in use on rows of that field
- **Tags (whitelist, `useTags=2`)** — checkbox grid limited to the configured `tagsList`
- **Custom text / textarea** — text input or textarea matching the subfield's PW Inputfield type
- **Custom checkbox** — single checkbox; cell shows `✓` / `—`
- **Custom datetime** — native `<input type="date">` or `datetime-local` depending on whether the field's `dateOutputFormat` carries a time component
- **Custom integer** — numeric input
- **Custom options (single / multi)** — native `<select>` (single) or a touch-friendly checkbox list (multi); cell shows the option label(s)
- **Custom page reference** — PW's actually-configured Inputfield for that field (PageAutocomplete / PageListSelect / ASMSelect / etc.), rendered through `___executeWidget` so the editor inherits the field's search, hierarchy and sort UX. Cell shows the referenced page title(s).
- **Multilang** — any of the text-shaped widgets above gets language tabs when the install has &gt;1 language and the value is multilang-shaped. Each tab edits one language; save commits all in one POST.

![Inline edit popup for a Description cell, showing the textarea, multilang tabs across the top, Save / Cancel buttons](docs/screenshots/07-inline-edit-popup.png)

Save commits via AJAX, the cell flashes green on success / red on failure. Screen readers pick the outcome up via a hidden live region.

**Match-aware fade-out.** If the saved value pushes the row out of the active filter set — say, you assign a tag while looking at a "missing tags" bookmark — the row fades out and drops from the table after the success flash. Timing is deliberate: 1200 ms green flash → 200 ms breath so the user sees the new value applied → 250 ms fade → row removed, pagination summary count decremented. If that was the last row in the slice, the table swaps to the same "No images match the current filters." paragraph the server emits on a zero-result render; the pager stays.

### Editing as paintbrush (bulk)

When one or more rows are ticked via the selection checkboxes, editing any cell on a selected row opens the same popup with an extra mode radio group — **Add / Replace** for description, customs and filenames, plus a third **Remove** option for tags. The chosen value broadcasts to every selected row.

- **Replace** — overwrites the existing value
- **Add** — appends (for text/textarea), unions tag tokens (for tags)
- **Remove** (tags only) — drops the listed tag tokens from each selected row's tag set; a no-op for rows that don't carry them

![Bulk-edit popup with Add / Replace radios visible because multiple rows are selected](docs/screenshots/08-bulk-edit.png)

After save you get a result modal listing per-row failures (e.g. tag-whitelist violations, missing edit-permission on individual pages). The successful rows are saved per-page in batches so each page sees at most one `$page->save($field)` call regardless of how many of its images were affected.

## Renaming files

The Filename cell uses the same inline-edit popup. The input holds the file's stem; the extension shows next to it as a locked chip and is never sent over the wire — the server reattaches it from the original basename.

![Filename rename popup showing the stem input, locked .jpeg chip, and the placeholder syntax hint below the title](docs/screenshots/09-rename.png)

### Placeholders

The same token grammar applies to **every** prose-shaped editor in the table: filename rename, description, custom text / textarea fields — single and batch alike. The popup shows a hint listing the tokens whenever they're applicable. Tags are skipped on purpose: they're token sets, and `(d)` → `2026-05-27` would land as a literal tag, which is editorial noise rather than useful metadata.

| Token | Expands to | Example (n=3, total=12, page „Summer festival", field=`images`) |
|---|---|---|
| `(n)` | counter | `(n)` → `3` |
| `(n2)` … `(n5)` | zero-padded counter, N digits | `(n3)` → `003` |
| `(N)` | total in batch | `(n) of (N)` → `3 of 12` |
| `(t)` | page title (user's admin language; follows repeater rows up to the owner) | `(t)` → `Summer festival` |
| `(d)` | current date, `YYYY-MM-DD` | `(d)` → `2026-05-27` |
| `(p)` | page name (PW URL slug; same repeater-owner resolution as `(t)`) | `(p)` → `summer-festival` |
| `(f)` | image field name | `(f)` → `images` |

Tokens expand server-side before sanitization. For single edits `(n)` is always `1` and `(N)` is `1`; for batch the counter follows the JS-sent selection order. Unknown tokens like `(foo)` pass through verbatim (the filename sanitizer usually strips the parens).

### Single rename

Click any filename cell with the host page editable. So `(p)-cover` becomes `summer-festival-cover` straight away.

The server calls `Pagefile::rename()` — which on PW **3.0.172+** moves the original and every variation file together — saves the page, rewrites any rich-text embeds of the file (see **[Embeds follow the rename](#embeds-follow-the-rename)** below), then drops the module's row cache. The table re-renders with the new basename in every reference (thumb URL, `data-basename`, selection key).

### Batch rename

Select multiple rows, then click any selected row's filename cell. The popup opens without the Add / Replace radio (filename has only one mode) but with the placeholder hint. Type a pattern like `event-(n2)` and Save — every selected file gets a counter from 1..N in the order they appear in the JS-sent selection.

Collision detection runs per-image inside the same Pageimages collection; a name clash with another (non-selected) file in that field fails that one row with a clear message, others continue.

### Embeds follow the rename

A rename changes the basename, so any rich-text field that embedded the old URL would otherwise break. Rather than warn you up front, the module fixes them. Right after the file moves, the same Textarea-field scan the delete dialog uses runs over the site, and every embed of the renamed file is rewritten to the new stem:

- **Original and every variation** — `…/{pageId}/{stem}.{ext}`, `…/{stem}.WxH.{ext}`, cropped / hidpi suffixes — all follow along; only the stem changes, the extension and variation suffix are preserved.
- **All languages** of a multilang Textarea are rewritten in place; untouched translations stay put.
- **Repeater-hosted images** use the file-owning page as the URL base, so embeds inside Repeater / RepeaterMatrix content are caught too.
- A **same-stem sibling of a different type** (e.g. `foo.jpg` vs `foo.png` sharing one page folder) is left untouched — the match is pinned to the old extension.
- A reference that can't be saved is logged and skipped; it never aborts the rename or the remaining rewrites.

When a single rename touched at least one embed, a summary dialog confirms the new filename and lists each reference that was updated, with a link to its page. A plain rename with no embeds applies silently — the cell just flashes green. Batch rename rewrites embeds the same way and reports through its usual result summary.

![Post-rename summary dialog titled "Renamed": a monospace chip reads "img_6426.jpeg → daisy-white.jpeg", and below an "Updated embedded references" list links "Flowers" · body, with a single Close button](docs/screenshots/12-rename-done.png)

## Replacing files

Each editable row carries an upload icon in the **top-right** corner of the thumb cell, visible on row hover, plus the row itself is a drop target for files dragged from the OS. Both paths swap the file bytes of an existing image while keeping the basename, every URL pointing at it, and the Pagefile metadata (description, tags, customs, multilang) intact.

- **Click-to-pick** — the upload icon opens a file picker pre-filtered to the row's existing extension.
- **Drag-and-drop** — drop a file onto the row. Every editable row tints in the inline-edit colour while the drop target is hovered. A non-editable row (no `page-edit` permission) gets a `not-allowed` cursor and rejects the drop.

The server enforces an extension match — a `.jpg` slot stays a `.jpg`. Format conversions (jpg ↔ png) would change the basename, which would break references in CKEditor content, sitemaps, OG tags etc.; for those, delete + re-upload.

Process: `move_uploaded_file()` → `$img->removeVariations()` → `$page->save($field)`. The thumbnail variation the table displays is then regenerated server-side and returned in the response so the JS can swap the `<img src>` without a 404 round trip. Dimensions, file size, modified date and the variations counter are re-formatted on the server and patched into the row.

## Deleting images

The trash icon hangs in the **top-left** corner of each thumb cell — opposite the upload icon, so finger-taps on mobile can't fire the wrong action. Also hover-visible. Same selection-as-paintbrush as the rest of the module: with N rows ticked, clicking the trash on any selected row deletes the whole selection; without a selection or when the click landed on an unselected row, it deletes just that one.

A confirm dialog always intervenes — count in the header, first eight filenames listed inline, `+N more` if the batch is larger, plus a hard warning that the operation can't be undone. Successful rows fade out then drop from the DOM; the persistent selection set follows. Per-row failures (page no longer editable, file already gone) surface through the same result modal the bulk edits use.

![Delete confirm dialog for a batch of 4 images: the header counts the selection, the files are listed inline, and a red "Still referenced in rich-text fields" block names img_6426.jpeg with a link to the "Flowers" page body field that still embeds it](docs/screenshots/11-delete.png)

**Where-used preflight.** Before you confirm, the dialog runs a server-side scan over every Textarea field and lists the pages that still embed each image in their rich text. CKEditor and TinyMCE both insert images through the same `pwimage` plugin with the deterministic URL shape `/site/assets/files/{pageId}/{basename}` (or a sized variation `…/{stem}.WxH.{ext}`), so a single PW selector — `field%='/pageId/stem.'` — catches the original AND every PW-derived variation. The selector route is multilang-, repeater- and access-aware out of the box. Each reference is rendered as a link straight to that page's edit screen (new tab) so you can fix the embed before — or instead of — deleting. The list is advisory; you can still confirm the delete.

## Deduplication

The library fingerprints every managed image by its **exact byte content** and collapses byte-identical copies onto a single file via **hardlinks** — so the same picture used on ten pages costs disk space once, not ten times. It's **lossless and reversible**: the bytes never change, and any copy can be given its own independent file again at any time. Both originals **and** ProcessWire's generated variations (the sized thumbnails that pile up identically in each copy's folder) are deduplicated, across all pages and fields — and page-version files (`…/<id>/v<n>/`) too. The filesystem's own link counts are the source of truth (no manifest table); byte-identity is re-verified immediately before every link, so a wrong link is impossible.

**It runs itself.** De-duplication happens automatically — on every page save (the saved page's images are fingerprinted and any existing byte-identical twin is linked right away), hourly via `LazyCron`, and once as a bounded pass at install to clear any existing backlog. In normal use you never have to think about it.

### Browsing duplicates

- **Duplicates filter** — a *Duplicates* checkbox in the [filter bar](#filtering). It's **contextual**: an image is treated as a duplicate only when ≥2 of its byte-identical copies are present in the *current* filtered view, and each such cluster collapses to a single representative.
- **Copy-count badge** — duplicated thumbnails (table **and** masonry) carry a small colored pill showing how many identical copies exist (tooltip *"N identical copies"*). It's a plain count, not a multiplier. (The pill takes the admin theme's accent colour, so its exact hue varies.)
- **Table: expand / collapse** — in the table a duplicate shows as one **head** row with the count pill and a ▸ / ▾ caret; click it (or Enter / Space) to reveal or hide the other copies grouped beneath. Pagination counts a whole cluster as one unit, so a cluster never straddles a page break.
- **Masonry: cluster modal** — in the gallery a duplicate is a single tile (with the count badge); clicking it opens a modal listing every copy as an editable mini-table, so you can edit — or delete — each copy individually. The modal is titled with the filename and closes with **Close**.

![A duplicate cluster in the table view: one head row carrying the colored copy-count pill and a ▸/▾ caret, expanded to reveal the other byte-identical copy rows grouped beneath it](docs/screenshots/16-duplicates.png)

![The masonry cluster modal: a duplicated tile opened to a mini-table of all its copies, each row editable individually, with a Close button](docs/screenshots/19-cluster-modal.png)

### Reclaiming disk (config page)

The **Deduplication** fieldset at the bottom of [Module configuration](#module-configuration) shows the current saving and offers manual tools — needed only to process a large existing backlog immediately or to undo, since the automatic passes keep things linked day to day.

- **Status** — a live read-out: *Disk space reclaimed*, *Copies sharing a file*, *Exact-duplicate clusters*. When nothing is collapsed it shows *"Nothing is collapsed right now — run 'Scan and reclaim' below to free space."*
- **Scan and reclaim (live)** — fingerprints the whole library and hardlinks every byte-identical copy, with a live progress panel: a phase line, a progress bar, a per-cluster log (`• <name> [N copies]: …`) and a running totals line. The Status block updates as it goes.
- **Re-measure** — a real disk-usage audit: logical size (what FTP / backup sees) vs actual `du`, and the **space saved by hardlinks**, plus a breakdown for page-version files (including why any standalone ones weren't linked).
- **Revert (un-share all)** — gives every collapsed copy its own independent file again, undoing the saving (the next pass re-collapses them). It confirms first.

![Module config "Deduplication" fieldset: the Status read-out (disk space reclaimed, copies sharing a file, exact-duplicate clusters) above the "Scan and reclaim (live)", "Re-measure" and "Revert (un-share all)" buttons](docs/screenshots/17-dedup-config.png)

![The live progress panel during "Scan and reclaim": a phase line "Reclaiming clusters… X / Y", a progress bar, a totals line, and a monospace log of per-cluster entries](docs/screenshots/18-dedup-progress.png)

> **Caveat.** Backup / deploy tooling that doesn't preserve hardlinks (`rsync` without `-H`, plain `tar` / `cp`, syncing to another mount) re-expands the links over time — it never corrupts anything, and the hourly background pass re-links them on its next run.

## Export / Import

Bottom of the page — a collapsible fieldset with Export buttons and an Import form.

![Export / Import section at the page bottom, showing two export buttons (JSON / CSV) and an upload form](docs/screenshots/10-export-import.png)

### Export

- **Export JSON** — full structured export of the currently filtered set
- **Export CSV** — flat tabular export; multilang subfields expand to language-suffixed columns (e.g. `description_english`, `description_german`)
- **Image URL variant picker** — choose what URL goes into the `url` field of the export: **Original** (the raw file), or a same-axis variation at **260 / 512 / 1024 px shorter side**. The variants follow the admin-variation rule (shorter axis capped, longer axis auto). Use case: handing the export to an AI vision pipeline / agent without making it download 5 MB originals — the 260 px variant is already on disk from the admin's lazy generation and is usually enough for description-generation work. SVG / GIF are emitted untouched.

The download URL carries the live filter state at click time, so you always get exactly the slice you're looking at.

JSON structure:

```json
{
  "meta": {
    "exportedAt": "2026-05-26T12:56:55+02:00",
    "siteUrl": "https://yoursite.com",
    "imageCount": 59,
    "appliedFilter": { "no_desc": true },
    "urlVariant": "260",
    "editableFields": ["description", "tags", "custom.*"],
    "readOnlyFields": ["id", "pageId", "fieldName", "basename", "url", …]
  },
  "images": [
    {
      "id": "1234:images:hero.jpg",
      "pageId": 1234,
      "fieldName": "images",
      "basename": "hero.jpg",
      "url": "https://yoursite.com/site/assets/files/1234/hero.jpg",
      "pageTitle": "About us",
      "pageUrl": "https://yoursite.com/about/",
      "dimensions": "1600x900",
      "filesize": 245678,
      "description": "Team photo at the office",
      "tags": "people office",
      "custom": { "summary": "Team gathering, summer 2025" }
    }
  ]
}
```

Multilang values land as `{langName: value}` maps inside `description`, `tags` and custom subfields.

### Import

Upload a previously exported (and externally edited) JSON or CSV. The import:

- Validates: pages exist, fields are managed, current user can edit the target pages, tags pass any whitelist
- Skips rows whose values match what's already stored (idempotent — re-running the same file is a safe no-op)
- Reports per-row failures in the same modal pattern as bulk edits

## Picker add-ons

Two **optional** integrations that surface the library *outside* its own admin page, so editors can drop an existing library image into a page without re-uploading it. Both are **off by default** — the library is fully usable without them — and toggle independently in the collapsed **Picker add-ons** fieldset under [Module configuration](#module-configuration). Each opens the library in a modal **picker**: the normal table / gallery with selection checkboxes and a *Use selected* bar.

![The library opened as a modal picker (titled "Insert from library"): the bookmark / collection tabs, a filter bar, a "Use selected" button, and the masonry gallery with images selected](docs/screenshots/20-picker-modal.png)

Enabling either toggle makes the module `autoload` so its hooks run on the relevant edit screens; after flipping a toggle, run **Modules → Refresh** once.

### Image-field picker

*Config: “Image-field picker” → adds a “Choose from library” button to every image field.*

A **Choose from library** button is appended to every `InputfieldImage` in the page editor, beside the native upload control. It opens the picker scoped to that field; selecting an image copies the chosen file into the target field (native image fields can only reference files in their own page folder, so the bytes are copied), carrying the source's description / tags / custom subfields over, language-aware. The fresh copy is then hard-linked to its byte-identical source, so it costs ~no extra disk.

**Version-aware.** When the page editor is working in a [PagesVersions](https://processwire.com/) version, the pick lands in that version's files folder (`…/<id>/v<n>/`), not the live page — and is de-duplicated on the spot.

![A page editor's image field with its thumbnails, showing the added "Choose from library" button right below the native "Choose File" control](docs/screenshots/14-image-field-picker.png)

### Rich-text insert

*Config: “Rich-text insert” → adds “Insert from library” to TinyMCE / CKEditor.*

An **Insert from library** button (gallery icon) joins the toolbar of every TinyMCE and CKEditor field, right next to the native image button — in the admin **and** the front-end inline editor (PageFrontEdit). It opens the picker; a single pick hands straight off to ProcessWire's own image dialog (crop / resize / caption / align) pointed at the library file, and the `<img>` is only inserted once you confirm there — nothing is dropped into the page beforehand. Multiple picks insert directly. The embedded `<img>` references the shared library file, so no copy is made.

![The TinyMCE and CKEditor toolbars side by side, each carrying the "Insert from library" gallery-icon button next to the native image button](docs/screenshots/15-richtext-insert.png)

The same button in the **front-end inline editor** (PageFrontEdit) — TinyMCE (left) and CKEditor (right), floating over a live page:

<table><tr>
<td><img alt="TinyMCE inline editor floating over a live front-end page, with the Insert from library button in the toolbar" src="docs/screenshots/22-richtext-frontend.png" width="430"></td>
<td><img alt="CKEditor inline editor floating over a live front-end page, with the Insert from library button in the toolbar" src="docs/screenshots/23-richtext-frontend-cke.png" width="430"></td>
</tr></table>

A single pick hands off to ProcessWire's own image dialog (crop / resize / caption / align) before the image is inserted:

![ProcessWire's native image dialog opened on the picked library file: a preview, width/height fields, caption / hidpi options, and Insert image / Select another / Cancel buttons](docs/screenshots/21-richtext-dialog.png)

## Performance

- **Read pipeline**: `findRaw` pulls every image's subfields in one query, flattens to a flat row list, sorts + slices in PHP, only the visible slice ever touches `Pageimage` objects. Cached via `WireCache::saveFor()`.
- **Cache invalidation**: three layers — explicit `deleteFor` after own writes, `Pages::saved` hook for edits made outside the module (e.g. native ProcessPageEdit), and a cache-key hash that includes the discovered fields + templates so schema changes invalidate automatically.
- **Thumbnails**: hand-shake with PW's lazy admin variation. The module's `size()` call picks the same dimensions PW would (260 px on the shorter axis), so the file is generated once and reused everywhere it's needed — admin grid, library table, anywhere else.
- **Scalability**: tested smoothly up to ~10 k images. Beyond that the in-memory row cache becomes the bottleneck — a future migration to `findMany` + per-image-index would be needed.

## Accessibility

- Editable cells expose `role="button"` `tabindex="0"`, so they're Tab-reachable; Enter / Space opens the editor.
- Sortable column headers carry `aria-sort` reflecting current state; the arrow glyphs are `aria-hidden` so screen readers don't double-read them.
- Status flashes (save success / failure) feed a hidden `role="status" aria-live="polite"` region.
- Column reorder in the picker has up/down buttons next to the drag handles, for keyboard users.

## File layout

```
ProcessImageLibrary/
├── ProcessImageLibrary.module.php       # main module + AJAX endpoints + renders + filter/sort/pagination
├── ProcessImageLibrary.info.json        # module metadata
├── ProcessImageLibraryConfig.php        # module-config UI
├── ProcessImageLibrary.js               # admin script: inline edit, bulk, columns dialog, collections, masonry, AJAX nav
├── ProcessImageLibrary.css              # admin styles
├── assets/                              # feature-specific front-end assets
│   ├── reclaim-live.js / .css           # de-dup config: live scan / reclaim / revert / audit UI
│   ├── library-pick.js                  # add-on: "Choose from library" button glue on image fields
│   ├── insert-mce.js                    # add-on: TinyMCE "Insert from library" adapter
│   ├── insert-cke.js                    # add-on: CKEditor 4 "Insert from library" adapter
│   ├── insert-common.js                 # add-on: shared picker / native-dialog logic for both editors
│   └── insert-icon.png / .svg           # add-on: CKEditor toolbar icon (PNG @2x shipped, SVG is the source)
├── src/
│   ├── ImageLibraryDiscovery.php        # trait: image-field / template / tags-config introspection
│   ├── ImageLibraryMultilang.php        # trait: per-language read/write, name⇄id mapping
│   ├── ImageLibraryHashing.php          # trait: content-hash de-duplication (hard-links byte-identical copies)
│   └── ImageLibraryExportImport.php     # trait: JSON + CSV emit, parse, idempotent re-apply
├── docs/
│   ├── ImageLibrary-Concept_EN.md      # architecture / design notes (English)
│   ├── ImageLibrary-Konzept_DE.md      # German translation of the same
│   └── screenshots/                    # README screenshots
├── README.md                            # this file
└── LICENSE
```

## License

MIT — see [LICENSE](LICENSE).
