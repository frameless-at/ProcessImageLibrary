# Deduplication — design & rationale

> Status: implemented for **exact** duplicates — detection (content hash), a
> duplicates view, edit-once-propagate (with a per-copy "keep own text" lock),
> and reversible hardlink space reclaim.
> **Near-duplicate detection was explored and removed**: perceptual hashing
> (both a 9×8 dHash and a DCT pHash) grouped unrelated photos that merely share
> a tonal layout, so it wasn't reliable enough to ship. The sections below that
> describe near-dup detection are kept as a design record but are NOT in the code.
>
> Scenario driving this: an existing site with **10000+ images of which ~3500 are
> unique** (≈6500 exact or near-duplicate copies scattered across pages/fields).

## Goal

Make duplicates **stop mattering** on an existing site **without forcing a
migration** to a central-library / DAM model. Editors should be able to treat
the N physical copies of one picture as **one logical asset** — find them,
manage their metadata once, see everywhere each is used, and (optionally)
reclaim the disk — while every existing page reference keeps working untouched.

## Why an audit layer over native fields (not a central store)

Two broad architectures can address duplication:

- **Central-library / reference model** — each file is uploaded once into a
  dedicated store, and every consumer holds a *reference* (a page-ID / asset
  reference), not a copy. This prevents *future* duplicates for content routed
  through the store, but it (a) requires **migrating** existing content into the
  store and re-wiring templates / fields to the references, and (b) does nothing
  for the copies **already** sprawled across an existing site. A reference model
  also can't recognise *near*-duplicates (the same photo uploaded twice at
  different sizes / formats becomes two unrelated assets).
- **Identity layer over native `FieldtypeImage` fields** (this module) — the
  unit is `(pageId, fieldName, basename)`, with **no central store**. We add a
  *content identity* to the images the module already enumerates and let
  duplicates be **managed as one logical asset**. Nothing moves; nothing is
  migrated.

Because the goal here is cleaning up an **existing** site, the second shape is
the right place to attack duplication: it operates on what's already there,
leaves every page reference intact, and needs no new field types or page
structures.

## The idea: a logical content-identity layer (no migration)

Add a **content identity** to every image the module already enumerates, then
let duplicates be managed as one asset. Nothing moves; nothing is migrated.

1. **Exact identity** — a byte hash (`xxh128` where available, else `md5`) per
   `(pageId, basename)`, computed lazily and **cached incrementally** keyed by
   `path + filesize + mtime`. The first full pass over ~1000 files is I/O-bound
   (seconds to low tens of seconds); re-scans only hash changed / new files, so
   it's effectively free thereafter. `filesize` / `width` / `height` are already
   in each row (no file open) → a **cheap pre-filter**: only files sharing
   (size, w, h) are candidates worth hashing.

2. **Near-duplicate identity** *(explored, not shipped)* — a 64-bit **dHash**
   (pure PHP + GD: downscale to 9×8 grayscale, compare adjacent luminance,
   Hamming distance for similarity). Aimed at catching the same photo at
   different resolutions / formats. Cached the same (path, size, mtime) way and
   **review-gated** (candidates, never auto-trusted). Removed because it grouped
   visually unrelated photos too often to be trustworthy; documented here for
   the record only.

3. **Cluster = one logical asset.** Group rows by exact hash. A cluster is
   "this picture, in these K places."

### What clusters unlock (the "duplicates become unnecessary" payoff)

- **Duplicates view / filter** — a duplicates-only mode that shows just the
  images with ≥1 twin, grouped, with a per-cluster count and thumbnail.
  Instantly answers "how much duplication do I have, and where."
- **Where-used per cluster** — reuse the where-used scan to show every page
  (structured field + RTE embed) touching *any* copy in the cluster.
- **Edit-once-propagate** — edit alt / description / tags **once** for a cluster
  and write it to all K copies' `field_{name}` rows. Pure DB writes, **zero
  filesystem risk, fully reversible** — the single-source-of-truth-for-metadata
  benefit without a separate asset store.
- **Optional physical collapse** — see storage strategies below. Off by default.

This makes the 6500 stray copies behave like the 3500 assets they really are — on
the existing site, today, with no migration and no new field types.

## Storage strategies — feasibility (what actually touches disk)

Context: the site runs on a web server; `/site/assets/files/` is a normal
server-side tree (typically one ext4 / xfs filesystem). Deduplication operates
on that asset directory.

| Strategy | Disk saved | reads / variations | rename | delete one copy | replace bytes | Backup / sync tooling | Reversible | Risk |
|---|---|---|---|---|---|---|---|---|
| **Hash-track only** (logical) | no | safe | safe | safe | safe | none | trivially | **Low ✅ (default)** |
| **Normalize-to-canonical** (overwrite a dup's bytes with the canonical's, same basename / URL) | no (sets up hardlinks) | safe | safe | safe | n/a | none | yes | **Low** |
| **Hardlink** identical files to one inode | **yes** | safe | safe (rename keeps inode) | safe (inode survives last link) | diverges (no corruption) | may expand to copies (see below) | yes (re-copy) | **Low–Med** |
| Symlink | yes | safe | fragile / dangling | safe | replaces link | — | yes | **High ✗** |
| Repoint references + delete | yes | — | — | — | — | none | hard | **High ✗** (forces a central-library migration) |

Key facts behind the matrix:

- Native PW identity is `(pageId, basename)`; the same bytes under two pages are
  two independent files in two `/{id}/` folders. There is no native shared file.
- Hardlinks survive read, variation-generation, `removeVariations()`,
  `Pagefile::rename()` (rename keeps the inode) and **deleting one page** (the
  inode persists while any link remains). They **diverge** only on a byte
  *replace* — gracefully (no corruption), the link just breaks. They require the
  copies to be on the **same filesystem** (true for one `/site/assets/files/`
  tree).
- **Operational caveat:** some backup / deploy / sync pipelines don't preserve
  hardlinks — `rsync` without `-H`, plain `tar` / `cp`, or syncing assets to a
  *different* mount / second server / object storage will **expand the links
  back into separate full files**. That silently *loses* the space saving over
  time (it does not corrupt or break images). So physical collapse is an
  **opt-in, reversible** extra — never the default — and only makes sense when
  the assets dir stays on one persistent server filesystem and the backup tooling
  is hardlink-aware (or the re-bloat is acceptable, since the background pass
  re-links on its next run).
- **Avoid** symlinks (dangling / `open_basedir`) and reference-repointing (a
  native image field can only hold files in its own page's folder, so repointing
  *is* the central-library migration we're avoiding).

**Recommendation:** the product is **hash-track + edit-once-propagate (with
per-placement overrides)**. The hardlink space-reclaim pass is **in scope**
(per decision 1) but ships gated and reversible, and **only for exact
duplicates**.

## How it fits ProcessImageLibrary (extension points)

- **Hashing**: `computeContentHash(Pageimage)` called lazily for visible rows;
  cached via `cache->saveFor()` keyed by `pageId:basename:mtime`, invalidated by
  the existing `cache->deleteFor($this)` on save / replace. (A `dhash` column
  exists in the store but is unused — see the near-dup note above.)
- **AJAX actions** (same CSRF / POST / `jsonResponse` pattern as the rest):
  duplicate-cluster rendering, chunked background hashing and reclaim
  (time-budgeted, so big sites don't time out), un-share (revert) and a
  real-disk audit.
- **Reuse existing primitives**:
  - the replace path (swap bytes, keep basename + URLs, regen variations) is the
    shape of the normalize-to-canonical op;
  - the where-used scan + embed rewriter give per-cluster where-used.
- **UI**: a content-hash-backed *Duplicates* filter, a copy-count badge, a table
  cluster expand / masonry cluster modal, and the config-page reclaim tools.
- **Config**: hash algorithm, size bounds for hashing, field / template
  exclusions, and a separate explicit opt-in for the hardlink space-reclaim pass.

## Design principles

- **No migration.** Works on the existing site immediately; images stay in their
  native fields; no new page type, no re-wiring of templates / content.
- **Cleans up existing sprawl** — the copies already on the site, not just future
  uploads.
- **Single source of truth for metadata** delivered as safe, reversible DB
  writes, with **zero** filesystem risk by default.
- **Optional, honest space reclaim** instead of a forced architecture change —
  off by default, gated, and reversible.

## Phased roadmap

*(Phases 1–3 and 5 are shipped for exact duplicates; phase 4 was explored and
dropped — see the status note.)*

1. **Identity + cache** — content hash (+ size / dim pre-filter), incremental
   cache, content-hash column. (No UI behaviour change yet.)
2. **Detect + present** — duplicate clusters, a duplicates-only filter, cluster
   grouping, per-cluster where-used.
3. **Manage** — edit-once-propagate across a cluster (DB-only), with a
   per-placement "keep custom" flag so individual placements can opt out.
4. **Near-dup** *(explored, removed)* — dHash candidates, review-gated grouping
   (manage only; files left untouched).
5. **Reclaim (exact only)** — guarded, reversible hardlink pass over
   byte-identical groups, with a backup / deploy hardlink-awareness warning.

## Decisions (resolved 2026-06-05)

1. **Space reclaim IS in scope.** Ship the opt-in, guarded, reversible hardlink
   pass — but only for **byte-identical (exact) duplicates**. See the important
   consequence below.
2. **Near-duplicate detection** was attempted from the start (dHash,
   review-gated) and later **removed** as unreliable.
3. **Per-placement overrides allowed.** Propagate sets a cluster-wide value, but a
   given placement may keep its own alt / description / tags. Default = propagate
   to all; an explicit per-placement "keep custom" wins over propagation.

### Important consequence: exact vs. near for space saving

Hardlinks need files to be **byte-identical**, so **only exact duplicates can be
physically collapsed** to save disk. **Near-duplicates** (same photo, different
resolution / format) are *not* identical bytes — had they shipped, they'd be
detected and managed (grouped, where-used, metadata propagation) but their files
**left alone**. "Healing" a near-dup into an exact one would mean overwriting,
say, an 800px copy with the 1600px bytes — that changes its dimensions and could
break layouts, so it is **not** done automatically; at most it would be a manual,
per-item action.

So the two tracks were:

- **Exact group** → manage + optional one-click hardlink reclaim (auto-trustable).
- **Near group** → manage only (detect, propagate metadata); file bytes untouched.

### Metadata vs. files are independent

Per-placement overrides (decision 3) concern **DB metadata**
(`field_{name}.description / tags / …`). Hardlinking (decision 1) concerns the
**file bytes**. They never collide: two pages can share one file inode while each
keeps its own description row. So "save space" and "allow custom captions" are
fully compatible.

### Settled implementation choices

- Hash algorithm: `xxh128` (fast, PHP 8.1+) with `md5` fallback via
  `in_array('xxh128', hash_algos())`.
- Propagation honours a per-placement "keep custom" flag (stored in the module's
  own cache / meta, keyed `pageId:fieldName:basename`) — no new PW field needed.
