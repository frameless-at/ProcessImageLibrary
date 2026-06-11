# Where-Used Index — design & rationale

> Status: **proposed, not implemented.** This documents a design for surfacing
> "where is this image used?" as a fast, at-a-glance column, and why the naive
> approach can't work. The reference-finding logic it builds on
> (`findImageReferences`, the rich-text rewrite on rename/delete) already exists
> and is hardened for direct and cross-page embeds.

## Goal

Let an editor see, **per image and at a glance**, where that image is embedded
in rich-text (CKEditor / TinyMCE) content across the whole site — as a sortable
"Used in" column with a count, click-through to the detail list.

This is the one piece of usage the library can't otherwise show: the table
already tells you which page an image's **field** lives on, but the same image
can be inserted into the **body text of other pages** via "Insert from library".
Those embeds are invisible from the library, and they're exactly what breaks
when an image is deleted or renamed (the reason the rename/delete preflight
exists).

## The problem: per-row computation doesn't scale

`findImageReferences()` answers "where is *this* image used?" by running, for the
image, one `findIDs` query **per rich-text field**, then verifying each candidate
page's content with a regex. That's fine for a single image (the delete/rename
preflight).

Doing it **per table row at render time** is not:

```
cost ≈  rows_on_page  ×  textarea_fields  ×  (1 query + content read)
```

For 50 rows and a handful of textarea fields that's hundreds-to-thousands of
queries and content scans **on every page build** — unacceptable.

## The approach: invert the question, then index

Instead of "for each image, find where it's used", do it once in the other
direction: **scan all rich-text content once, build an index** keyed by image,
then have each row do an O(1) lookup.

```
imageKey (pageId : stem)  →  { count, [ referencing (page, field) … ] }
```

Per-row render cost becomes a single map lookup — **no queries, no slowdown**.
The work moves into a cached, incrementally-maintained background index, exactly
like the deduplication engine.

### Reference forms recognised

The index reuses the same matcher the rename/delete path uses, so it covers both
ways pwimage embeds a library image:

- **Direct, same page:** `/<pid>/<stem>.<variation>.<ext>`
- **Cross-page insert:** `/<srcPid>/<stem>.<variation>-is-pid<targetPid>[-hidpi].<ext>`
  — pwimage stores the inserted sized/cropped variation in the **source image's
  own** files folder, so the directory id `<srcPid>` is the source page; the
  `-pid<targetPid>` suffix records the page the variation was made *for* (the one
  whose rich-text holds the embed), **not** the source. The source page is
  therefore the URL directory, not the marker. (Multi-dot crop variations and
  `-hidpi` included. Verified against real data: `/files/1164/img.x-is-pid1171.jpeg`
  is the page-1164 image used on page 1171.)

### Building the index

- **One query per textarea field**, not per image:
  `field_<name> WHERE data LIKE '%/site/assets/files/%'` (or the selector-engine
  equivalent), `include=all`, `check_access=0` so it's complete and
  access-independent like the row enumeration.
- Parse each value once in PHP, extract every `/files/<pid>/<stem>…` token, and
  record the resolved source image key. The source page is the **URL directory
  id** (where the file physically lives); the `-pid<id>` marker is the *target*
  page and is only tried as a fallback for the rarer setup where the copy lands
  in the editing page's folder instead. The matched stem is the longest known
  managed-image stem on that page that prefixes the filename.
- **Budgeted** like the dedup scan so a large site converges over a few passes
  rather than blocking one request.

### Maintaining it incrementally

Rich-text content can change on *any* page, so the index can't be a one-shot
build. It's kept current the same way the dedup hash table is:

- Hook `Pages::saved` / `Pages::savedField`: re-scan **only the saved page's**
  textarea fields, **drop that page's old contributions** and **add its new
  ones** (prune-then-add, mirroring `hashPageImages` / `pruneStaleRowsForPage`).
- An hourly LazyCron safety pass reconciles anything missed (e.g. out-of-band
  DB edits), matching the dedup maintenance pattern.

This keeps the steady-state cost to "re-scan one page on save", not a full
rebuild.

## Rendering

- A **"Used in" column**, **hidden by default** (toggled via the existing
  column picker), so users who don't need it pay nothing.
- The cell shows a **count badge** (e.g. `3`, or `–` when unused).
- **Clicking the badge** opens the existing where-used list dialog (pages +
  fields, resolved to the owning content page for repeater embeds) — the detail
  logic already exists; the column just makes the *summary* visible.
- The count is hydrated for the **visible slice only** (one batched lookup for
  the page's rows), not the whole library.

## Trade-offs (honest)

- **It's a real feature, not a quick column** — same class of work as the dedup
  engine: an index store, a budgeted first scan, and broad invalidation.
- **Invalidation is broad:** any page with a textarea can affect the index, so
  the on-save hook fires widely. The incremental prune-then-add keeps each such
  save cheap, but the bookkeeping is non-trivial.
- **Eventual consistency:** with on-save maintenance the count is exact; if a
  build only relies on the budgeted/cron pass, a freshly-edited page's count can
  lag by one pass (acceptable, as with dedup).
- **Storage:** small — either one aggregated count per image, or one row per
  `(image, referencing page, field)` if the detail list is materialised too.

## Edge cases (already covered by the shared matcher)

- **Repeater / Matrix embeds** resolve to the owning content page for display
  (`usageRefForPage`).
- **Cross-page `-pid` copies** and **multi-dot crop variations** are matched.
- **Multilang textareas** are scanned per language slot.
- **Deleted / renamed images:** the owning page's save prunes stale index rows;
  the cron pass is the backstop.
- **Access control:** the index is a global admin audit, built with
  `check_access=0` so it's complete regardless of who triggers the scan.

## Implementation plan (phased)

1. **Index store + builder** — schema (or WireCache), budgeted full scan, the
   shared reference regex.
2. **On-save maintenance** — per-page re-scan + prune-then-add; LazyCron
   reconcile pass.
3. **Lookup API** — `usageCountFor(imageKey)` and a batch variant for a row
   slice; hydrate the visible slice only.
4. **UI** — hidden "Used in" column with a count badge; click → the existing
   where-used dialog. Make it sortable.
5. *(Optional)* feed the same index into the delete/rename preflight so that path
   also becomes O(1) instead of querying live.

## Relation to the deduplication engine

This is deliberately the **same shape** as dedup: a lazily-built, cached index,
maintained on save with a budgeted first pass and an hourly reconcile. The
scaffolding (on-save hooks, budgeted scanning, cache invalidation, the
direct/cross-page reference regex) is already proven there, so the new work is
mostly the index store, its maintenance, and the column UI — not new
infrastructure.
