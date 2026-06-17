# Image Library – forum post series

Archive of the ProcessWire forum announcements for the module, in order.
Posts 1–3 are reproduced as originally published (their em-dash style kept
verbatim). Post 4 is the first-stable announcement.

---

## 1. Public beta (v0.54.x)

Hi all — we're putting this one up as a public beta and looking for feedback before we tag a stable release.

How it started. Over the past months we've been moving an old blog into a fresh PW site using our own SiteSync module and a Claude Code agent doing most of the migration grunt work. At some point the blog owner mentioned, in that very offhand way clients do, "hey, an image search would be nice." It was Saturday afternoon, so we let the agent build a prototype, pushed it through SiteSync, tested it on the phone an hour later. Worked great. Search results were… not great. But the search wasn't the problem – the underlying data was. Thousands of imported images, almost no descriptions, no tags, no nothing.

So we needed a way to retroactively caption and tag a few thousand images without clicking through hundreds of page edits one by one. Since PW (rightly) attaches images to the pages they belong to, we needed a tool that reaches across the whole install at once and – crucially – can edit metadata in bulk.

Why not the existing modules? We looked at the two obvious candidates:

- Media Manager by @kongondo – great if you're starting fresh and want a central media hub. But it's its own storage layer: you upload INTO Media Manager, editors pick FROM Media Manager. Images already sitting on per-page image fields stay invisible to it. Also commercial.
- MediaLibrary by @BitPoet – adds a MediaLibrary template with its own MediaImages / MediaFiles fields plus a CKEditor picker. Same pattern: a separate page hierarchy you migrate media into.

Both are well-designed for "we want a central media model from day one." Neither helps you when the media is already scattered across lead_image, body_images, gallery, images_in_some_repeater etc. Migrating that into a different storage layer would have broken the original page model the blog depends on.

So we built Image Library: a Process module that does nothing to your data – it just surfaces a cross-site table view of everything that's already there, with serious bulk editing on top.

The bulk-edit part – the reason this module exists.

Selection as a paintbrush. You tick N rows across any pages, templates and image fields. Then you edit a cell on ANY of those rows — the popup gains an Add / Replace mode picker (tags additionally offer Remove) and the value gets broadcast to the entire selection in one server round.

- Same row applies to description, tags, every custom subfield, AND the filename (with placeholders: (n), (n2)..(n5) padded counters, (N) total, (t) page title, (d) date, (p) page name, (f) field name → e.g. rename 200 selected files to event-2025-(n3).jpg).
- Same row applies to delete (one trash click, whole selection gone behind one confirm dialog with a where-used preflight – see below).
- Edits that push a row OUT of the active filter ("missing tags" → tag assigned → row no longer matches) fade out and drop from the table; counters auto-decrement.

Other highlights:

- One sortable, paginated, bookmarkable table across every FieldtypeImage field on every page on every template (with config-side blacklists).
- Inline edit per cell – multilang-aware: language tabs in the popup, all languages committed in one save.
- Typed widgets per custom subfield: checkbox, date, integer, options (single + multi), and FieldtypePage rendered through PW's actually configured Inputfield (PageAutocomplete / PageListSelect / ASMSelect / whatever the field uses) — no re-implementation.
- Replace image in place (drag-drop or upload icon) – basename stays, variations regen.
- Renaming an image in the library instantly rewrites every CKEditor/TinyMCE embed of that file across the site — original and all variations, in every language — so links never break, and a summary dialog shows which pages were updated.
- Delete with where-used preflight: dialog scans every Textarea via $pages->findIDs("field%='/pid/stem.', include=all") and lists the pages where the image is still embedded in rich text – CKEditor + TinyMCE both, multilang-aware, with direct edit links so you can fix embeds before deleting.
- JSON / CSV export + import for offline metadata work – hand a CSV to a copywriter or feed it to your agent, get it back, import it.
- View prefs (columns, page size, bookmarks) live in $user->meta, cross-device.

Status. v0.54.x – public beta. Module + docs (EN + DE concept) at GitHub or the Modules Directory

Feedback welcome – especially edge cases we haven't seen yet (weird Fieldtype combos in custom-field templates, ProFields, Repeaters / RepeaterMatrix nesting). And if you've got a use case the current feature set doesn't cover, let us know.

Cheers, Mike

---

## 2. v0.55.0 – This changes everything

Barely a week on from the public beta release here's where Image Library went next. Short version: it grew a brain for duplicates, and that quietly changed what the module is for.

How it continued. Barely a week after the first post, a different client site handed us the next problem – and this time it wasn't missing metadata, it was duplication, and a lot of it. ~10,000+ images on the site, of which only ~3,500 were actually unique – meaning roughly 6,500 exact-or-near-duplicate copies of the same pictures scattered across lead_image, body_images, galleries, repeaters, you name it. Years of "just re-upload it on this page too."

So we went looking for a way to make duplicates stop mattering – without migrating anything. The full rationale is in the repo (docs/deduplication-design.md); the gist:

- Give every image a content identity – a byte hash (xxh128, md5 fallback), computed lazily and cached by path+size+mtime, with a cheap size/dimension pre-filter so we only hash real candidates.
- Group identical images into clusters = "this picture, in these K places."
- Then duplicates become manageable, not just visible: a Duplicates filter, a copy-count badge per image, an expandable cluster in the table (a cluster modal in the gallery), where-used per cluster, and the big one – edit-once-propagate: caption/tag a cluster once, written to all copies. Pure DB writes, fully reversible, zero filesystem risk.
- Optional, opt-in, reversible disk reclaim: byte-identical copies get hardlinked onto one inode, so 6,500 copies stop costing 6,500× the bytes. Lossless, runs in the background (on save + hourly), with Scan / Re-measure / Revert tools and a live "disk reclaimed" readout. (We tried perceptual near-dup detection too – dHash/pHash — and pulled it: it grouped unrelated photos that merely share a tonal layout. Detection-only, never destructive, and still not trustworthy enough to ship.)

And then it clicked. What we'd built, almost by accident, was a DAM without the DAM. Strip a digital-asset manager down to what it actually gives you: one logical asset instead of scattered copies, metadata edited once as a single source of truth, a "where is this used," a central place to browse and pick – and storage that doesn't pay for the same file twice. Every one of those falls straight out of the content-identity + cluster technique. The difference: we get it over the images already sitting in native FieldtypeImage fields – the asset layer is synthesised from what's there, not a store you migrate into. No central library, no new page type, no migration, and crucially no template changes. That last part is the whole point: on some of these projects, re-wiring every template and every chunk of content onto a new media model would be a multi-week job and a regression-test nightmare. We just… didn't have to – and the editors get the DAM experience anyway.

A word on MediaHub. If you are starting a fresh project and want a proper central media model from day one, MediaHub is – in our opinion – the best option out there. Huge thanks to @Peter Knight for letting us test it; we're genuinely impressed, it's a lovely piece of work. Different tool, different job: new site → MediaHub. Existing site full of scattered images → this. Image Library isn't trying to change the way PW image handling works; it's a layer over the files, pages, templates, methods and structures you already have.

Which is exactly the rule we build by:

- must work with existing installations, as-is;
- plug & play – install, open, done;
- no rebuild, no migration, no new fields, no template surgery;
- no new workflow for editors;
- and – new – front-end-editor capable. BAM. 💥

That last one is the other big addition: two optional, off-by-default picker add-ons:

- Choose from library – a button on every image field to assign an existing library image without re-uploading (version-aware: inside a page version it lands in that version's folder, and it's deduped on the spot).
- Insert from library – a button in TinyMCE and CKEditor that drops a library image straight into rich text… and it works in the front-end inline editor (PageFrontEdit) too. Editor live on the page, click, pick, done.

Plus, while we were in there:

- Collections – a hand-picked set of images no filter could reproduce (tick the ones you want, save them as a named tab). Recalled by a tiny ?coll=<id> link – the image list lives in your user profile, not the URL, so a 100-image collection is still a ~12-character link. Add/remove just by clicking a collection's tab while images are selected (the cursor tells you which way it goes), and collections are themselves filterable.
- Masonry gallery view – height-balanced (shortest-column) packing, natural aspect ratios, hover-revealed selection checkboxes – the same selection that drives bulk edits and collections.

Status. v0.55.0 – public beta on top of the original. Module + docs (EN + DE concept, plus the dedup design doc) on GitHub / the Modules Directory.
As before: feedback very welcome – especially duplication in the wild, odd Fieldtype combos in custom-field templates, ProFields, deep Repeater/RepeaterMatrix nesting, and anything the front-end picker trips over.

Cheers,
Mike

---

## 3. Update (v0.58.0) – tag-vocabulary management & a "Used in" column

Hi, everyone! We´ve been enjoying developing the module a bit further this week. Since the last update the module has grown from 0.55 to 0.58. Two bigger additions:

🏷️ Tag-vocabulary management

You can now curate a field's predefined tag vocabulary right inside the tag picker, with no trip to the field settings:

- Rename or delete a predefined tag library-wide, inline (no modal-on-modal).
- Table rows live-refresh immediately after a library-wide rename/delete.
- Newly entered tags can be promoted into the field's predefined list. This is only available when the image fields file tags setting is set to "User selects from list of predefined tags + can input their own".
- Alphabetical ordering, and a mobile-friendly single-column tag chooser with touch controls.

🔎 "Used in" – a content-based where-used column

A new column answers the question you actually have at a glance: which pages embed this image? It scans rich-text content (not just the field relations), so it also catches images placed via "Insert from library":

- Cross-page "Insert from library" embeds are resolved to their true source image, and embeds inside (Matrix)Repeaters are attributed to the owning page.
- The count is a plain link that opens a dialog listing every page and the fields the image is embedded in.
- The "Used in" and "Variations" columns are now sortable, and integer columns are centre-aligned.

Next up (in development): nested collections – group collections into subgroups with a drag-and-drop manager, cascading fly-out menus in the bar, and touch-friendly curation.

Have a nice weekend!
Cheers, Mike

---

## 4. v1.0 – out of beta (and nested collections landed)

Hi all,

Last time I signed off with "next up: nested collections – a drag-and-drop manager, cascading fly-out menus, touch-friendly curation." Well, here they are. And after a good run in production across a few sites, we're finally tagging a **stable release**: v1.0.1.

Quick recap for anyone just finding this: Image Library is a Process module that surfaces every image already sitting in your native FieldtypeImage fields, across every page, template and (Matrix)Repeater, in one cross-site, filterable, inline-editable table, with serious bulk editing, content-based de-duplication and where-used on top. It does nothing to your data and needs no migration. The full story is in the three posts above.

**Collections, all grown up.** What was a flat "tick some images, save them as a tab" feature is now a proper little hierarchy:

- **Folders / nesting.** A collection can hold its own images *and* nest sub-collections, up to 3 levels, like folders that also hold files. There's no separate "container" type: an empty collection is just one with no images yet. Open a parent and you see the union of its own images plus every descendant's; remove an image from a parent and it cascades down.
- **A manager dialog.** One tabbed dialog (Collections | Bookmarks) to rename, reorder, nest / un-nest and delete, by drag or buttons. Deleting a collection cascades to its sub-collections; the images themselves stay put in the library.
- **A Collections column.** A new table column shows the collections each image belongs to (linked), with inline re-assignment via a checkbox tree, for one row or a whole batch selection. Membership shows as a union, so a parent lights up whenever the image is in one of its sub-collections.
- **Team-wide now.** One thing changed under the hood: bookmarks and collections moved from per-user storage to a single **team store**, so the whole editorial team works from the same set. Creating and editing them is gated by a manage-shared permission; bookmarks got folders too.
- **And on the phone:** the whole strip collapses to three tabs (Show all / Bookmarks / Collections) with cascading tap-to-open fly-outs, so curation works on touch, not just desktop hover.

**Search that actually narrows.** The search box learned operators: multiple words match *any* (OR), `+word` requires, `-word` excludes, and `"a phrase"` matches exactly, e.g. `red +rose -draft`. Across title, description, tags, filename and custom subfields.

**Picker polish.** Both add-ons (*Choose from library* on image fields, *Insert from library* in TinyMCE / CKEditor and the front-end inline editor) now open through ProcessWire's own modal window, so they look like the rest of the admin, with a primary **Use selected** and a secondary **Cancel**.

**Why "stable" now.** Beyond the features, this release got the boring-but-important pass: uploads on Replace are validated by content and not just by extension, a rename now keeps an image's "Used in" entry, output is escaped, lookups are hardened, and the codebase went through a sizable DRY refactor (one shared AJAX guard, shared key / fetch helpers, CSS design tokens) with no behaviour change. It has been running real editorial work for weeks, so 1.0 felt earned.

**Status.** v1.0.1, first stable. Module + docs (EN, plus the DE concept and the dedup / where-used design notes) on GitHub and the Modules Directory.

As always, feedback and edge cases very welcome: deep Repeater / RepeaterMatrix nesting, odd Fieldtype combos in custom-field templates, ProFields, and anything the collections manager or front-end picker trips over.

Cheers,
Mike
