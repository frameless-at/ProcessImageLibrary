# ImageLibrary Module — Konzept

**Ziel-Repo:** https://github.com/frameless-at/image-library

## Ziel

Ein ProcessWire-Modul, das **alle Bilder einer PW-Installation** in einer einzigen Tabelle zeigt — eine zentrale Medien-Bibliothek-Sicht. Editoren bearbeiten Bild-Metadaten quer durch alle Pages und Image-Felder inline (Description, Tags, Custom-Subfields), ohne pro Page navigieren zu müssen.

**Primärer Use-Case:** Content-lastige Site mit tausenden Bildern über dutzende oder hunderte Pages verteilt, viele mit fehlender Description oder unvollständigen Tags. Editor will filtern (z.B. „nur Bilder ohne Description"), abarbeiten, das nächste Filter wechseln — ohne ständig zwischen Page-Edit-Screens zu springen.

## Scope

**In-Scope (umgesetzt):**

- Aggregiert Bilder aus **allen** `FieldtypeImage`-Feldern auf **allen** Templates der Installation (mit konfigurierbaren Template- und Field-Blacklists)
- Jede Tabellen-Zeile = Tupel `(page, fieldName, basename)`
- **Repeater- / RepeaterMatrix-Support**: Bilder, die in einem Repeater-Feld liegen, werden bis zur Owner-Page aufgelöst, damit Page-Spalte, Sort und Template-Filter auf der sichtbaren Owner-Page operieren — nicht auf der internen `repeater_<field>`-Storage-Page
- Inline-Edit der editable Subfields (Description, Tags, Custom-Felder); Textarea-Customs öffnen einen Popup
- **Mehrsprachige Subfields**: per-Sprache-Tabs im Popup (pre-aktiviert auf der aktuellen Admin-Sprache des Editors), Roundtrip in JSON/CSV-Export-Import
- **Bulk-Edit** via „Selection als Pinsel": markierte Rows werden gemeinsam mit der nächsten Cell-Save in Add- oder Replace-Mode aktualisiert (Tags bieten zusätzlich einen Remove-Mode, der die genannten Tag-Tokens aus jeder selektierten Row entfernt)
- **Filename-Rename**: inline (Einzelbild) oder Batch (über die aktive Selektion) via desselben Popups; Platzhalter-Grammatik `(n)`, `(n2)..(n5)`, `(N)`, `(t)`, `(d)`, `(p)`, `(f)` greift in jedem prosenförmigen Editor (Filename, Description, Custom-Text/Textarea) — Tags ausgenommen
- **Replace Image in place**: Click auf das Upload-Icon der Row ODER Drag-and-Drop einer Datei auf die Row. Bytes werden getauscht, Basename + URLs + Pagefile-Metadata bleiben. Extension-Match wird erzwungen (keine jpg → png-Überraschungen). Variations werden serverseitig neu generiert, die Zellen der Row (Thumb / Dimensions / Size / Modified / Variations) werden in-place gepatcht
- **Delete Image (Einzel + Batch)**: Trash-Icon auf der Row, hinter einem Confirm-Dialog mit Count + Filename-Preview + „kein Undo"-Warnung. Der Dialog macht zusätzlich einen **Where-used-Preflight** (`___executeUsage`) und listet alle Pages, deren `contentType=html`-Textarea-Felder ein Bild noch einbetten, mit direkten Edit-Links — als Hinweis, nicht als Block. Selection-als-Pinsel gilt auch hier: bei N selektierten Rows löscht ein Klick auf das Trash-Icon einer selektierten Row die gesamte Selektion. Per-Row-Fehler landen im bestehenden Bulk-Result-Modal
- **Bookmarks**: gespeicherte Filter-Kombinationen als Tab-Strip oberhalb der Filter-Bar (`WireTabs uk-tab`-Markup — dasselbe Chrome, das der Rest des Admins nutzt, kein modul-eigenes Tab-CSS). Click auf einen Bookmark → AJAX-Filter-Swap + Filter-Form reset + repopulate. „+ Add bookmark" erscheint nur dann, wenn der aktive Filter noch nicht gespeichert ist. Persistenz piggybackt auf `$user->meta('imageLibraryPrefs').bookmarks`, cross-device; nur filter-shaped Params werden gespeichert (Sort / Page-Size bleiben orthogonal)
- **Match-aware Inline-Save**: wenn ein Edit die Row aus dem aktiven Filter-Set kickt (z. B. Tag-Zuweisung unter einem „missing tags"-Bookmark), fadet die Row nach dem Success-Flash raus und verschwindet. Sequenz: 1200 ms grüner Flash → 200 ms Atempause → 250 ms Fade → DOM-Removal + Pagination-Counter −1. Server-Seite: `___executeSave` / `___executeRename` / `___executeBulk` akzeptieren ein `filterQs`-POST-Feld, laufen `parseFilterQs()` + `evaluateFilterTouchedRows()` und liefern `stillMatches` / `vanished` / `newTotal`. Letzte Row entfernt → Tabellen-Wrapper wird durch das Empty-State-`<p>` ersetzt; Pager bleibt stehen
- **Datums-Spalten**: Uploaded (Pagefile `created`) und Modified, sortierbar, formatiert über `$config->dateFormat`
- **Variations-Spalte**: pro Bild Zähler aus `$img->getVariations()`
- **Export / Import**: JSON und CSV (mit multilang-aware Spalten-Suffixen `<subfield>_<langName>`). Image-URL-Varianten-Picker im Export — Original / 260 / 512 / 1024 px kürzere Seite — damit externe Pipelines (z. B. AI-Vision-Agents) günstige Admin-Variations statt der Original-Files fetchen können
- Server-side Filter / Sortierung / Pagination mit **capability-basierter Filter-Verengung**: das Tags-Fieldset und jede `Missing <custom>`-Checkbox blenden sich live aus, sobald die aktive Template- / Image-Feld-Auswahl die jeweilige Capability nicht trägt
- Spalten-Konfiguration per User in `$user->meta('imageLibraryPrefs')` — **cross-device**, inklusive Page-Size
- Auto-Erkennung von **Custom Fields on Images** (`field-{fieldname}`-Template, PW 3.0.142+)
- AdminThemeUikit Light- / Dark-Theme-Integration via `--pw-*`-CSS-Custom-Properties
- **Tabellen- oder Masonry-Gallery-Ansicht** — Toolbar-Toggle zwischen Daten-Tabelle und Thumbnail-Gallery; die Gallery behält das natürliche Seitenverhältnis jedes Bilds und packt die Kacheln per Shortest-Column-Platzierung in höhenbalancierte Spalten. Ein per-User-Thumbnail-Größen-Slider skaliert Thumbs / Kacheln live; Ansicht + Zoom persistieren in `$user->meta`, cross-device (siehe [Ansichten](#ansichten-tabelle--masonry-gallery))
- **Automatische De-Duplizierung** — byte-identische Bilder werden gefingerprintet (`content_hash`) und auf einen Hardlink-Inode kollabiert (verlustfrei, reversibel; Originale + Variations + Page-Version-Files). Läuft bei jedem Save + stündlich (`LazyCron`) + Install-Pass; ein *Duplicates*-Filter, Kopienzähler-Badges und ein Cluster-Aufklappen / -Modal machen sie sichtbar, die Config-Seite bietet Scan / Re-measure / Revert (siehe [De-Duplizierung](#de-duplizierung))
- **Collections** — eine handverlesene Bildmenge, per User gespeichert (`collections: {id, name, keys[]}`), abrufbar über eine kurze `?coll=<id>`-URL; kuratiert per Klick auf einen Collection-Tab bei aktiver Auswahl (Cursor signalisiert Hinzufügen vs. Entfernen), und selbst filterbar (siehe [Collections](#collections))
- **Picker-Add-ons** (optional, default aus) — bringen die Library außerhalb ihrer Admin-Page: ein *Choose from library*-Button an jedem Image-Feld (versionsbewusste Zuweisung) und ein *Insert from library*-Button in TinyMCE / CKEditor (Admin + Frontend-Inline-Editor) (siehe [Picker-Add-ons](#picker-add-ons))
- **Rename schreibt Rich-Text-Embeds um** — nach einer Basename-Änderung schreibt derselbe `contentType=html`-Scan jeden Embed des alten Files auf den neuen Stem um (Original + Variations, alle Sprachen, Repeater-aware), damit Embeds nicht still brechen

**Out-of-Scope (auch in der aktuellen Version):**

- Standalone-Upload komplett neuer Bilder (Replace tauscht einen bestehenden Slot, der Picker weist ein bestehendes Library-File zu; ein eigenständiger Upload wird nicht angeboten)
- Bilder zwischen Pages verschieben
- Eigenes Variations-Management (Crop / Focus / Re-Generate) — das Modul zeigt den Variations-Zähler und öffnet bei Klick auf den Thumb PWs **nativen** Per-Bild-Editor (Crop / Focus / Variations-UI), regeneriert / verwaltet Variations aber nicht selbst
- Re-Sort innerhalb eines Image-Felds (`$img->sort`)

## Architektur

**Modul-Typ:** `Process`-Modul, eigene Admin-URL `/processwire/setup/image-library/`.

**Klassen:** `ProcessImageLibrary`

**Dateistruktur:**

```
ProcessImageLibrary/
├── ProcessImageLibrary.module.php
├── ProcessImageLibrary.info.json
├── ProcessImageLibraryConfig.php
├── ProcessImageLibrary.css
├── ProcessImageLibrary.js
├── assets/                           # feature-spezifische Frontend-Assets
│   ├── reclaim-live.js / .css        # De-Dup-Config: Live-Scan / Reclaim / Revert / Audit-UI
│   ├── library-pick.js               # Add-on: "Choose from library" an Image-Feldern
│   ├── insert-mce.js                 # Add-on: TinyMCE-"Insert from library"-Adapter
│   ├── insert-cke.js                 # Add-on: CKEditor-4-"Insert from library"-Adapter
│   ├── insert-common.js              # Add-on: geteilte Picker- / Native-Dialog-Logik
│   └── insert-icon.svg               # Add-on: CKEditor-Toolbar-Icon
├── src/
│   ├── ImageLibraryDiscovery.php     # Trait: Image-Field / Template / Tags-Config-Introspection
│   ├── ImageLibraryMultilang.php     # Trait: per-Sprache Read/Write, name⇄id-Mapping
│   ├── ImageLibraryHashing.php       # Trait: content-hash De-Dup (Hardlink, Reclaim, Audit, Revert)
│   └── ImageLibraryExportImport.php  # Trait: JSON + CSV emit, parse, idempotent Re-Apply
├── docs/
│   ├── ImageLibrary-Concept_EN.md
│   ├── ImageLibrary-Konzept_DE.md
│   └── screenshots/
├── README.md
└── LICENSE
```

Die `src/`-Traits halten das Main-Modul-File auf AJAX-Endpoints + Rendering fokussiert; Discovery, Multilang, Hashing/De-Dup und Export/Import besitzen jeweils einen kohärenten Slice.

**Methods:**

- `___execute()` — Tabelle + Filter-UI rendern (Server-rendered HTML; JS hydratisiert für Interaktion). Spalten-Picker liegt als sibling-`<dialog>` neben `.ml-results` damit AJAX-Swaps Drag/Toggle-Handler intakt lassen.
- `___executeData()` — AJAX-GET, returnt nur den `.ml-results`-Block (Tabelle + Pagination) für Filter/Sort/Page-Swaps.
- `___executeSave()` — AJAX-POST, validiert + speichert eine Cell-Änderung, gibt JSON zurück. Multilang-aware: payload kann eine `langValues`-JSON-Map (`{langId: value}`) tragen, dann wird jeder Sprach-Slot in einem POST via `applyLangValues()` geschrieben (Single-Language-Installs schicken nur `value`). Liest `filterQs` aus dem POST und gibt `stillMatches` + `newTotal` zurück, damit der Client Rows ausfaden kann, die aus dem Scope gefallen sind.
- `___executeBulk()` — AJAX-POST: identische Cell-Save auf eine Selektion anwenden (Add-/Replace-Mode, plus ein Tags-only Remove-Mode). Liefert `vanished` (Liste der Selection-Keys, die aus dem Filter gefallen sind) + `newTotal` zusätzlich zu den Succeed/Fail-Counts. Rows deren Image-Field das Broadcast-Subfield nicht trägt zählen als stiller Success (No-op) statt als Failure — eine Paintbrush-Aktion über heterogene Selektionen (z. B. „author" über Rows aus `images` + `lead_image`, wo nur eines davon das Subfield hat) ist redaktioneller Alltag, kein User-Fehler.
- `___executeRename()` — AJAX-POST, benennt das File eines einzelnen Bildes um (oder im Batch-Modus jedes selektierte Bild) via `Pagefile::rename()` nach Platzhalter-Expansion und Bereinigung alter Variations-Files.
- `___executeReplace()` — AJAX-POST, ersetzt die File-Bytes eines Bildes via `move_uploaded_file()` auf den existierenden Pfad, droppt alte Variations, regeneriert das Thumb-Variation und gibt den aktualisierten Cell-Payload zurück (Thumb-URL, Dimensions, Filesize, Modified, Variations-Zähler). Extension-Match wird erzwungen, damit der Basename gültig bleibt.
- `___executeDelete()` — AJAX-POST mit einem `items`-Array; Einzel + Batch teilen denselben Pfad. Pro Page `$page->editable()`, dann `$pageimages->delete($img)` + `$page->save($field)`. Returns succeeded / failed-Listen, damit JS die Rows ausfaden lassen und Partial-Failures via Bulk-Result-Dialog reporten kann.
- `___executeExport()` — Direct-Download von JSON oder CSV unter Berücksichtigung der aktiven Filter. Liest `urlVariant` (`original` Default; `260` / `512` / `1024` für same-axis Variations) und emittiert die passende URL in der `url`-Spalte; die gewählte Variante wird in `meta.urlVariant` festgehalten.
- `___executeImport()` — AJAX-POST, akzeptiert eine vorher exportierte (und extern bearbeitete) JSON/CSV-Datei und schreibt zurück; idempotent (unverändert gebliebene Items werden geskippt).
- `___executeUserPrefs()` — AJAX-POST persistiert Spalten + Page-Size + View-Mode + Thumbnail-Scale + Bookmarks + **Collections** in `$user->meta('imageLibraryPrefs')` (debounced). Bookmarks werden via `$sanitizer->text(maxLength: 80)` für den Namen und `canonicalizeBookmarkQs()` für den Querystring validiert; Collections via `sanitizeCollection()` (alnum-id, gekappter Name, sanitisierte + deduplizierte + gekappte Row-Keys) — damit Save- und Load-Shape konsistent bleiben.
- `___executeAssign()` — AJAX-POST (Image-Feld-Picker-Add-on): kopiert ein bestehendes Library-Bild in das Image-Feld einer Ziel-Page (native Image-Felder referenzieren nur ihren eigenen Page-Ordner, daher werden die Bytes kopiert), überträgt Description / Tags / Customs sprach-bewusst. Versionsbewusst — arbeitet der Editor in einer `PagesVersions`-Version, landet die Kopie in `…/<id>/v<n>/` und wird sofort gegen ihre byte-identische Quelle gehardlinkt.
- `___executeClusterTable()` — AJAX-GET, rendert die editierbare Mini-Tabelle der Kopien eines Duplikat-Clusters für das Masonry-Cluster-Modal.
- `___executeScanStep()` / `___executeReclaimStep()` / `___executeRevertStep()` / `___executeDiskAudit()` — gechunkte, zeit-budgetierte De-Dup-Endpoints, die die Live-Tools „Scan and reclaim" / „Revert" / „Re-measure" der Config-Seite treiben (Fingerprint-Scan, Hardlink-Reclaim, Un-Share, Real-Disk-Audit).
- `___executeUsage()` — AJAX-POST, Where-used-Preflight für den Delete-Confirm-Dialog. Akzeptiert `items=[{pageId, basename}, …]`, liefert `usage: { "pid:basename": [ {pageId, pageTitle, editUrl, fieldName}, … ] }`. Reverse-Scan über jedes `FieldtypeTextarea` per `$pages->findIDs("{field}%='/{pid}/{stem}.', include=all")` — der `%=`-Substring-Selector ist multilang-, repeater- und access-aware, kein raw SQL. Das Stem-Prefix-Needle fängt das Original UND jede PW-derivative Variation (`foo.500x300.jpg`, `foo.500x300-cropped.jpg`, `…hidpi.jpg`) mit einem Needle, weil `pwimage` üblicherweise eine resized Variation einfügt, nicht das Original. Editor-agnostisch — beide `pwimage`-Plugins (CKEditor + TinyMCE) schreiben dasselbe URL-Schema. Gate ist Existenz + Editability, NICHT `viewable()`: ein Admin mit `image-library-access` plus Per-Page-Edit-Rechten muss über Embeds Bescheid wissen, auch wenn die Page im Frontend nicht für ihn sichtbar ist.
- `___executeWidget()` — AJAX-GET, rendert das von PW konfigurierte Inputfield für ein Page-Reference-Custom-Subfield (PageAutocomplete / PageListSelect / ASMSelect / etc.). Snapshotted `$config->scripts` / `$config->styles` vor + nach dem Render, damit nur die NEUEN Asset-URLs zurück zum Client gehen. Das Popup injiziert das HTML, lazy-loaded neue Scripts / Styles und feuert das `'reloaded'`-DOM-Event auf jedes `.Inputfield`, damit PWs delegierte Init-Handler die UI hochziehen. Save läuft über `___executeSave`; `coerceCustomValue()` formt `[id]` zu einem int (Single-Page-Feld) oder zu einem frischen `PageArray` (Multi-Page) — letzteres ist nötig, damit `FieldtypePage::sanitizeValuePageArray()` REPLACE statt MERGE macht.
- `___install()` / `___uninstall()` — Admin-Page-Lifecycle + Permission `image-library-access`.

## Datenmodell (PW-nativ)

Jede Tabellen-Zeile ist identifiziert durch das Tupel `(pageId, fieldName, basename)`.

**Listing-Pipeline (Read-Pfad):**

1. **Field-Discovery beim Boot:**

   ```php
   $imageFields = [];
   foreach (wire('fields') as $f) {
       if ($f->type instanceof FieldtypeImage) $imageFields[] = $f->name;
   }
   ```

2. **Selector aufbauen** auf Page-Ebene mit allen Filtern, die PW-Selector kennen:

   ```php
   $selector = "template=" . implode('|', $eligibleTemplates) . ", status<=hidden";
   if ($missingDescription) $selector .= ", images.description=";
   if ($templateFilter)     $selector .= ", template=$templateFilter";
   ```

3. **`$pages->findRaw()`** holt die kompletten Image-Daten in einem Schwung ohne Page-Object-Hydratation:

   ```php
   $rawData = $pages->findRaw($selector, array_merge(
       ["id", "title", "url", "templates_id"],
       array_map(fn($f) => "$f.basename",    $imageFields),
       array_map(fn($f) => "$f.description", $imageFields),
       array_map(fn($f) => "$f.tags",        $imageFields),
       array_map(fn($f) => "$f.filesize",    $imageFields),
       // plus auto-discovered custom subfields pro Feld
   ));
   ```

4. **Flatten in PHP** zu Image-Row-Liste:

   ```php
   $rows = [];
   foreach ($rawData as $pageId => $pageData) {
       foreach ($imageFields as $fieldName) {
           foreach ($pageData[$fieldName] ?? [] as $img) {
               $rows[] = [
                   'pageId'    => $pageId,
                   'fieldName' => $fieldName,
                   'basename'  => $img['basename'],
                   // … restliche Subfields …
               ];
           }
       }
   }
   ```

5. **Image-Level-Filter in PHP** (für Filter, die PW-Selector nicht auf der Subfield-Ebene exakt machen kann):

   ```php
   $rows = array_values(array_filter($rows, $userFilterFn));
   ```

6. **Sort + Slice** für die Pagination:

   ```php
   usort($rows, $sortFn);
   $total = count($rows);
   $slice = array_slice($rows, $offset, $limit);
   ```

7. **Thumbnail-URLs für die 50-Row-Slice laden** (nur jetzt echte `Pageimage`-Objects):

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

→ Nur die **angezeigten 50 Bilder** triggern echte `Pageimage`-Loads für Thumb-URLs. Der Rest ist `findRaw`-Datenarray.

**Caching:**

`findRaw`-Resultat wird via `WireCache::saveFor($this, ...)` gecacht. Invalidiert wird **dreifach abgesichert**:

1. **Explicit** nach jedem eigenen Save/Bulk/Import (`$cache->deleteFor($this)` direkt nach `$page->save()`).
2. **Hook auf `Pages::saved`** in `init()` — wenn eine Page mit einem managed Image-Field außerhalb des Moduls gespeichert wird (z. B. im nativen ProcessPageEdit), wird der Row-Cache gedroppt damit das nächste Listing die frischen Werte zeigt.
3. **Cache-Key-Hash** über `imageFields + eligibleTemplates`-Liste, damit Schema-Änderungen (neue Image-Felder, geänderte Template-Auswahl) automatisch zu neuen Keys führen.

**Save-Pfad (pure PW-API):**

```php
$page = $pages->get($pageId);
$img  = $page->{$fieldName}->getFile($basename);
$img->{$subfield} = $value;
$page->save($fieldName);  // löst die Cache-Invalidation aus
```

**Custom-Field-Discovery pro Image-Feld:**

```php
$customTpl = $templates->get("field-{$fieldName}");
if ($customTpl) {
    foreach ($customTpl->fields as $cf) {
        // Spalte für $cf->name auto-anlegen, in findRaw-Fields mit aufnehmen
    }
}
```

## Performance-Erwartungen

Alle Phase-1-Pfade verwenden ausschließlich PW-API. Erwartete Latenzen bei einem Dataset von ~3500 Bildern über ~40 Pages:

| Operation | Kalt (Cache miss) | Warm (Cache hit) |
|---|---|---|
| `findRaw` Multi-Field-Subfield-Query | ~80–150 ms | gecacht: ~10 ms |
| PHP-Filter über ~3500 Rows | ~10–20 ms | gleich |
| Sort + Slice (50 Rows) | <5 ms | gleich |
| `getMany` für ~30 unique Pages | ~50 ms | gleich (Pageimage-Loads) |
| Thumb-URLs (bei bereits gecachten Varianten) | <30 ms für 50 | gleich |
| **Gesamt-Listing-Request** | **~200 ms** | **~100 ms** |
| Save-Request (single cell) | ~150–200 ms | gleich |

**Skalierungs-Ausblick** (Phase 2 wenn nötig): bei Datasets jenseits ~10k Bildern auf `$pages->findMany()` umstellen — lazy-loading-Iterator, der Pages chunkweise streamt und Memory unter Kontrolle hält. Cache-Format dann ggf. auf einen per-Image-Key wechseln statt das volle Array zu serialisieren.

## Standard-Spalten

Pflicht / read-only:

- **Thumb** — gerendert über eine Hybrid-Pipeline, die bevorzugt PW's lazily-generierte 260-px-Admin-Variation verwendet und nur dann auf ein dediziertes `$img->size()` fällt, wenn die konfigurierte Display-Größe die längere Achse der Admin-Variation übersteigt. `loading="lazy"` am `<img>`.
- **Page** (Title + Link zur PW-Edit-Seite; bei Bildern in Repeater- / RepeaterMatrix-Items zur Owner-Page aufgelöst)
- **Field** (Field-Name, z.B. `images`, `lead_image`)
- **Filename** (`$img->basename`, inline editierbar im Einzel- oder Batch-Modus)

Default-sichtbar / editable:

- **Description** — Textarea. Lange Werte werden in der Tabelle per CSS auf wenige Zeilen (≈150 Zeichen) mit Ellipsis gekürzt (`.ml-clamp`, `--ml-clamp-lines`, Default 3); die Kürzung ist reine Anzeige — der volle Text bleibt in der Zelle, der Editor öffnet also immer mit dem kompletten Wert.
- **Tags** — Input je nach `useTags`-Konfig des jeweiligen Felds:
  - `useTags=0`: Spalte verbergen
  - `useTags=1`: Text-Input + Autocomplete aus historisch verwendeten Tags
  - `useTags=2`: Multi-Select aus `tagsList`
  - `useTags=8|9`: Multi-Select + freier Text-Input
- **Uploaded** (Pagefile `created`) und **Modified** — formatiert über `$config->dateFormat`, sortierbar, read-only
- **Dimensions** (`{w}×{h}`) — read-only
- **Filesize** — read-only

Auto-entdeckt (jedes Custom-Field-Subfield des `field-{fieldname}`-Templates). Cell-Display + Inline-Editor werden vom Fieldtype des Subfields typisiert:

- **Text / Textarea** → Text-Input / Textarea (Textarea-Cells teilen den Description-Clamp)
- **Checkbox** (`FieldtypeCheckbox`) → Display `✓` / `—` (leer); Inline-Editor ist eine einzelne Checkbox
- **Datetime** (`FieldtypeDatetime`) → Display über das `dateOutputFormat` des Feldes; Inline-Editor ist ein natives `<input type="date">` oder `datetime-local`, je nach Format-String
- **Integer** (`FieldtypeInteger`) → numerisches Input
- **FieldtypeOptions** (single + multi) → Display der Option-Label(s); Inline-Editor ist ein natives `<select>` (single) bzw. eine touch-freundliche Checkbox-Liste (multi)
- **FieldtypePage** (single + multi) → Display der Page-Title(s); Inline-Editor rendert **das von PW tatsächlich konfigurierte Inputfield** für dieses Feld — PageAutocomplete / PageListSelect / PageListSelectMultiple / ASMSelect / was auch immer die Field-Config wählt — via `___executeWidget`, damit 1000e wählbare Pages die korrekte Search / Hierarchy / Sort-UX bekommen, ohne dass das Modul irgendwas davon nachbaut

**Konfig:** Per User in `$user->meta('imageLibraryPrefs')` — Cross-device-persistiert via `___executeUserPrefs`. Struktur: `{columns: {visible: {col: bool}, order: [col]}, pageSize: int|null, viewMode: 'table'|'masonry', thumbScale: float, bookmarks: [{name, qs}], collections: [{id, name, keys[]}]}`. Default-Set: Pflicht + Description + Tags + Custom-Fields (Admin kann eine Default-Hidden-Liste in der Module-Config setzen).

## Edit-Semantik

**Inline-Edit pro Zelle:**

1. Klick (oder Tastatur-Enter/Space — die Zellen sind `role="button" tabindex="0"`) auf Zelle → ein modaler Popup öffnet mit dem Wert. Textarea-Zellen (Description + Textarea-Customs) sind in der Tabelle auf wenige Zeilen gekürzt, der Popup zeigt aber immer den vollständigen Text. Multilang-Felder bekommen per-Sprache-Tabs, wenn die Installation Languages aktiviert hat.
2. Blur ODER Enter → AJAX-POST mit `{ pageId, fieldName, basename, subfield, value, langValues? }` — `langValues` ist eine `{langId: value}`-Map, die für Multilang-Felder geschickt wird, damit alle Sprachen in einem Save committen.
3. Server: validiert (Tag-Whitelist wenn `useTags=2`, etc.), führt `$page->save()` aus, returnt `{ ok, value }` oder `{ ok: false, error }`.
4. UI: optimistic update, grüner Check / rotes X. Beide Status-Wechsel werden zusätzlich in eine visually-hidden `aria-live`-Region geschrieben, damit Screen Reader sie picken.
5. Cache wird sowohl explicit als auch durch den `Pages::saved`-Hook invalidiert.

**Bulk-Edit (Selection als Pinsel):** Wenn die editierte Zelle zu einer aktiven Selektion gehört, blendet sich beim Save ein „Add / Replace"-Picker ein (Tags bieten zusätzlich „Remove", das die genannten Tag-Tokens aus jeder selektierten Row entfernt). Auswahl + Commit verteilt die neue Value auf alle selektierten Rows mit derselben Subfield-Adressierung.

**Selection-Key + Survival über View-Wechsel.** Jede getickte Row liegt Client-seitig unter dem Tupel-Key `pageId:fieldName:basename`, nicht über DOM-Position. Filter-Wechsel, Sort-Flips und Pagination feuern den AJAX-Data-Fetch und bauen das `<tbody>` neu auf; die JS hakt anschließend jede Row wieder an, deren Key noch im Set steht. Rename liefert eine `renamed{oldKey: newKey}`-Map mit zurück, damit die Selection dem File folgt. Delete dropt die gelöschten Keys. Das Set überlebt jede View-Operation, die nicht selbst die Row entfernt.

**Save-Queue:** Mehrere Edits werden pro PageId seriell geschickt — keine parallelen `$page->save()`-Aufrufe auf derselben Page (vermeidet ChangeTracker-Races).

## Filter

Klappbares „Filters"-Fieldset (Icon `fa-filter`) oberhalb der Tabelle. Das Label trägt einen `(N)`-Suffix mit der Zahl der aktiven Filter, damit der Zustand auch bei zugeklapptem Fieldset sichtbar bleibt.

| Filter | Wo gefiltert | Notiz |
|---|---|---|
| Volltextsuche (Page-Title, description, tags, filename, customs) | PHP | Word-Match, multilang-aware |
| Template-Filter | PHP | aus `eligibleTemplates`; verengt live das Image-Field-Dropdown auf Felder, die das gewählte Template tatsächlich trägt |
| Image-Feld-Filter | PHP | aus `imageFields` |
| „Missing description" | PHP | immer sichtbar — jedes Bild hat einen Description-Slot |
| „Missing tags" | PHP | nur sichtbar, wenn die aktive Template- / Image-Field-Auswahl mindestens ein Feld mit `useTags` enthält |
| „Missing &lt;custom&gt;" | PHP | ein Checkbox-Filter pro Custom-Subfield; nur sichtbar wenn die aktive Auswahl dieses Subfield ausweist |
| Tags | PHP | Multi-Select aus tatsächlich vergebenen Tags, AND-Semantik; gesamtes Fieldset blendet sich aus, wenn die Auswahl kein tag-fähiges Feld trägt |

**Capability-basierte Verengung.** Eine zweite JS-Funktion spiegelt das Template→Field-Pattern: sobald ein Template oder Image-Feld gewählt ist, blenden sich Tags-Fieldset und `Missing tags` / `Missing <custom>`-Checkboxen aus (und entfernen ihre Häkchen), die nicht greifen können. Mit nur einem Template gewählt, ist das effektive Capability-Set die Union über die Image-Felder dieses Templates — ein Template, dessen einziges Image-Feld keine Tags / keine Customs hat, kollabiert die zugehörigen Filter ebenfalls. Die Map kommt als `config.fieldCaps` über `$config->js()`; PHP rendert immer das volle DOM, JS togglet — gleiche Form wie die bestehende Template→Field-Verengung.

Filter werden URL-state-persisted und sind bookmarkbar. Tags werden als komma-separierter Wert (`?tags=foo,bar`) emittiert; die alte Bracket-Form (`?tags[]=…`) bleibt akzeptiert. Nach „Apply" klappt das Fieldset automatisch ein damit die Resultate-Tabelle freie Sicht hat.

## Sortierung

Spalten-Klick toggelt ascending/descending. Sortierbare Felder: Page-Title, Field, Filename, Description, Tags, Width, Filesize, `created` (Uploaded), `modified` sowie alle Custom-Subfields via `custom:<name>`. Sort nach `custom:<name>` triggert vor `applySort` einen Bulk-Hydration-Pass, damit die Spalte überhaupt Werte zum Vergleichen hat — ohne den Hydrate würden alle Rows tied sein und in `pageId:basename`-Tiebreaker-Order bleiben.

**Default**: in der Module-Config (Fieldset „Default sort") wählbar — Column + Direction. Built-in-Default ist `pageTitle asc`. URL-Override (`?sort=basename&dir=desc`) gewinnt; URLs lassen sort/dir weg wenn sie dem konfigurierten Default entsprechen, damit geteilte Links übersichtlich bleiben.

## Pagination

50 Rows/Seite als built-in-Default (Module-Config überschreibbar). URL-State `?p=3` + `?ps=100`. Total-Count + „Page 3 of 25" in der Pagination-Zeile. Picker im Pagination-Block (Optionen ebenfalls Module-Config) — Auswahl persistiert in `$user->meta('imageLibraryPrefs').pageSize`, gilt also cross-device. Die Pagination-Zeile wird oben und unten gerendert; rechts daneben ein `fa-columns`-Icon, das den Spalten-Picker-Dialog öffnet.

## Ansichten: Tabelle & Masonry-Gallery

Ein Toolbar-**View-Toggle** (rechts in der Pagination-Zeile) schaltet zwischen der Daten-**Tabelle** und einer **Masonry-Gallery** um. Die Gallery behält das natürliche Seitenverhältnis jedes Thumbnails (kein Crop) und packt die Kacheln per **Shortest-Column**-Platzierung in **höhenbalancierte Spalten**: die nächste Kachel kommt in die aktuell kürzeste Spalte, anhand der server-gerenderten Bildmaße (`<img width/height>`), sodass das Layout sofort sitzt, ohne aufs Bild-Laden zu warten. Gallery-Kacheln tragen dieselbe Auswahl-Checkbox (hover-eingeblendet, unten links), Replace- / Delete-Aktionen und das Duplikat-Badge wie Tabellen-Zeilen, und das Selection-Set ist zwischen beiden Ansichten geteilt; Klick auf eine Kachel öffnet PWs nativen Per-Bild-Editor. Ein per-User-**Thumbnail-Größen-Slider** skaliert Tabellen-Thumbs / Gallery-Kacheln live; Ansichtswahl und Zoom persistieren in `$user->meta`, cross-device.

## De-Duplizierung

Jedes verwaltete Bild wird über seinen **exakten Byte-Inhalt** gefingerprintet (`content_hash`, xxh128 wo verfügbar, sonst md5), und byte-identische Kopien werden per **Hardlinks** auf einen Inode kollabiert — **verlustfrei und reversibel**: die Bytes ändern sich nie, jede Kopie kann jederzeit wieder ihre eigene Datei bekommen. Sowohl Originale **als auch** PWs generierte Variations sowie Page-Version-Files (`…/<id>/v<n>/`) werden dedupliziert, über alle Pages und Felder. Die Link-Counts des Dateisystems sind die Source of Truth (keine Manifest-Tabelle); Byte-Identität wird unmittelbar vor jedem Link erneut verifiziert.

Es läuft **automatisch** — bei jedem `Pages::saved` (die Bilder der gespeicherten Page werden gefingerprintet und ein bereits existierender Zwilling sofort verlinkt), stündlich via `LazyCron` und einmal als begrenzter Pass beim Install. Das **Deduplication**-Fieldset der Config-Seite zeigt den gesparten Platz (*Disk space reclaimed* / *Copies sharing a file* / *Exact-duplicate clusters*) und bietet manuelle Tools — **Scan and reclaim (live)**, **Re-measure**, **Revert (un-share all)** — über gechunkte, zeit-budgetierte Endpoints (`scan-step`, `reclaim-step`, `revert-step`, `disk-audit`) mit Live-Progress-Panel. Im Listing machen ein *Duplicates*-Filter (kontextuell, kollabiert jeden Cluster auf einen Repräsentanten), ein Kopienzähler-Badge auf Tabellen- + Masonry-Kacheln, das Tabellen-Cluster-Aufklappen und das Masonry-Cluster-Modal die Duplikate prüfbar. Hash-Store: `process_imagelibrary_hashes` (lazy angelegt, beim Uninstall gedroppt).

## Collections

Eine **Collection** speichert eine *handverlesene Menge konkreter Bilder* (wo ein Bookmark einen *Filter* speichert) — für Mengen, die kein Filter herstellen kann. Sie liegt per User in `$user->meta('imageLibraryPrefs').collections` als `{id, name, keys[]}` (die Row-Identity-Keys `pageId:fieldName:basename`), abrufbar über eine kurze `?coll=<id>`-URL — die Keys bleiben serverseitig, sodass eine 100-Bild-Collection ein ~12-Zeichen-Link ist, kein mehrere Kilobyte langer Query-String. Der Server löst die id zurück auf das Key-Set und verengt das Grid darauf.

Collections teilen sich den Bookmark-Tab-Strip (icon-markiert) und funktionieren im Admin **und** im Picker. Anlegen: Checkboxen anhaken → der Add-Button der Leiste wird zu *Add collection* → Name + Save (die Checkboxen werden geleert). Kuratieren: bei aktiver Auswahl fügt ein Klick auf einen Collection-Tab die Auswahl hinzu (nicht-aktiver Tab → `+`-Cursor) oder entfernt sie aus der gerade betrachteten (aktiver Tab → `−`-Cursor). Filterbar: `?coll` koexistiert mit den Filter-Params, Filtern verengt also *innerhalb* der Collection (`applyRowFilters` schneidet zuerst auf das sel-Set, dann die normalen Filter); Löschen der betrachteten Collection entfernt `?coll` und lädt neu.

## Picker-Add-ons

Zwei **optionale, standardmäßig deaktivierte** Integrationen (Config-Fieldset *Picker add-ons*), die die Library *außerhalb* ihrer Admin-Page sichtbar machen, jeweils als Modal-Picker. Aktivieren macht das Modul `autoload` (nach dem Umschalten **Modules → Refresh**).

- **Image-Feld-Picker** (`addonPicker`) — ein *Choose from library*-Button an jedem `InputfieldImage`. Die Zuweisung kopiert das gewählte File ins Ziel-Feld (native Image-Felder referenzieren nur ihren eigenen Page-Ordner, daher werden die Bytes via `___executeAssign` kopiert), überträgt Description / Tags / Customs sprach-bewusst und hardlinkt die Kopie gegen ihre byte-identische Quelle. **Versionsbewusst:** beim Bearbeiten einer `PagesVersions`-Version landet die Kopie im `v<n>/`-Ordner dieser Version.
- **Rich-Text-Insert** (`addonRichtext`) — ein *Insert from library*-Button (Gallery-Icon) in jedem TinyMCE- und CKEditor-Feld, im Admin **und** im Frontend-Inline-Editor. Eine Einzelauswahl übergibt direkt an PWs eigenen Bild-Dialog (Crop / Resize / Caption / Align), bevor das `<img>` eingefügt wird; das eingebettete Bild referenziert das geteilte Library-File (keine Kopie). Verdrahtet durch Inline-Glue, das dünne Per-Editor-Adapter (`assets/insert-mce.js` / `insert-cke.js`) über einem geteilten Kern (`assets/insert-common.js`) lädt; der TinyMCE- / CKEditor-Plugin-Name bleibt `mllibrary`.

## Permissions

- **Anzeige der Admin-Page:** User braucht `page-edit` auf irgendeiner Page mit Image-Feld (Modul-Check beim Boot)
- **Edit pro Cell:** `$page->editable()` auf der konkreten Ziel-Page (im Save-Endpoint pro Request geprüft)
- Optional separate Permission `image-library-access` für engere Steuerung — wenn vorhanden, scopt das Admin-Page-Sichtbarkeit zusätzlich

## Technische Constraints

- **PHP:** Ausschließlich PW-API (`$pages->findRaw()`, `$pages->getMany()`, `$page->save()`, `$img->size()`, `$cache->save()`, etc.). **Kein direktes SQL.**
- **JS:** Vanilla, Fetch-API, keine Framework-Dependency.
- **CSS:** Verträglich mit AdminThemeUikit. Alle Farbwerte gehen über PW's `--pw-*`-CSS-Custom-Properties, damit die Tabelle dem aktiven Light- / Dark-Theme folgt, ohne manuelle Overrides. Sortable-Headers verwenden PW's natives `.tablesorter-headerAsc` / `.tablesorter-headerDesc` / `.tablesorter-header-inner`-Markup, damit die Sort-Visuals zu dem passen, was andere Process-Module rendern.
- **PW-Version:** 3.0.172+ (für `findRaw` mit Subfield-Wildcards) und 3.0.142+ (für Custom-Fields-on-Images)
- **PHP-Version:** 8.0+

## Lizenz

MIT (oder GPL, je nach Repo-Konvention). Modul sollte als Public-Module auf modules.processwire.com einreichbar sein.

## Install / Uninstall

`___install()`:

- Legt Admin-Page „Image Library" unter Setup an, verknüpft mit der `ProcessImageLibrary`-Process-Klasse
- Optional Permission `image-library-access` anlegen
- Keine Field- oder Template-Änderungen — Modul liest existierende Strukturen

`___uninstall()`:

- Entfernt Admin-Page
- Löscht `image-library-*` Cache-Einträge
- Lässt User-Meta (`imageLibraryPrefs`) stehen (User-Setting, nicht Modul-State; legacy `mediaLibraryPrefs` und das noch frühere `mediaLibraryColumns` werden beim Lesen weiterhin als Fallback honoriert)
- Optional Permission stehen lassen (User entscheidet manuell)

## Beantwortete Open Questions

- **Template-Whitelist** → Auto-discovery + optionale Blacklist in Module-Config (`blacklistedTemplates`, `blacklistedFields`).
- **1-Image-Felder** → mit aufgenommen, kein gesonderter Schalter.
- **Custom-Field-Spalten Default-Sichtbarkeit** → auto-sichtbar; Admin kann pro Spalte über `defaultHiddenColumns` ausblenden, User per Spalten-Picker.
- **Spaltenkonfig-Scope** → `$user->meta('imageLibraryPrefs')`, cross-device.
- **Edit-Modus** → Inline-Auto-Save bei Blur/Enter.
- **Filter-URL-State** → URL-Params, bookmarkbar (Tags als komma-separierter `?tags=…`-Wert).
- **Bulk-Operations** → Selection-als-Pinsel umgesetzt für Edit, Filename-Rename, Replace-in-place und Delete; Platzhalter-Grammatik (`(n)`, `(N)`, `(t)`, `(d)`, `(p)`, `(f)`) wird in allen prosenförmigen Editoren geteilt.
- **Page-Size** → 50 Default, Picker mit konfigurierbarer Options-Liste, Auswahl in `$user->meta`.
- **Permission-Granularität** → beides: `image-library-access` als Hard-Gate für die Admin-Page, `$page->editable()` pro Cell-Save.
- **Variations-Spalte** → umgesetzt (read-only Zähler).
- **Modul-Info / Versioning** → SemVer, GitHub-Tags. Composer-Support nicht aktiv geplant.

## Roadmap (geplant)

- **Überarbeiten und Verbessern der Tag-Verwaltung** — Tag-Handling durchgängig prüfen und verbessern: den Inline-Editor über die `useTags`-Modi hinweg (frei `1` vs. Whitelist `2` vs. gemischt `8|9`), den Multi-Select-Tags-Filter, das Autocomplete aus verwendeten Tags und die Add- / Replace- / Remove-Pinsel-Modi. Wahrscheinliche Richtungen: library-weites Tag-**Rename / Merge** (einen Tag überall umbenennen, wo er verwendet wird), eine klarere Whitelist-Edit-UX und ein vereinheitlichter Tag-Pool über heterogene Felder hinweg. Scope noch offen — vor der Umsetzung zu spezifizieren.

*Kürzlich umgesetzt (vorher auf dieser Roadmap): der per-User-Thumbnail-Größen-Slider und Where-used beim Rename — jetzt als aktives Embed-Umschreiben implementiert (der Rename schreibt die Rich-Text-Embeds um, statt nur zu warnen).*

## Verbleibende Open Questions

1. **Mobile**: aktuell flex-wrap + horizontal-scrollende Tabelle. Reicht das oder lohnt ein dedizierter Card-View < 640 px?

2. **Skalierung jenseits ~10k Bilder**: Aktueller Pfad `findRaw + WireCache::saveFor` ist linear in der Anzahl Image-Rows. Ab 30k+ wird der Cache-Re-build spürbar. Pfad zu `findMany` + per-Image-Index ist im Konzept dokumentiert, aber noch nicht beziffert.

3. **Standalone-Upload** (ein komplett neuer Bild-Slot aus der Library heraus, kein Replace eines bestehenden) — würde das Row-als-`(page,field,basename)`-Modell aufbrechen, weil zuerst Ziel-Page + Feld gewählt werden müssten. Pages-Edit deckt das ohnehin gut ab; nur bei klarem Editor-Bedarf neu aufgreifen.

4. **WebP / AVIF / SVG / animated GIF** als Original-Format: aktuell wird `$img->size()` blind aufgerufen. Funktioniert, aber SVG → PNG (Rasterisierung), animierte GIFs werden statisch. Hinweis im UI sinnvoll?

5. **Alt-Text als separates Subfield**: PW behandelt `description` als impliziten Alt-Text. Für strenge a11y/SEO-Workflows ggf. ein separates `alt`-Custom-Field empfohlen — aber das wäre eine Editorial-Konvention, kein Modul-Feature.
