# ImageLibrary Module — Konzept

**Ziel-Repo:** https://github.com/frameless-at/image-library

## Ziel

Ein ProcessWire-Modul, das **alle Bilder einer PW-Installation** in einer einzigen Tabelle zeigt — eine zentrale Medien-Bibliothek-Sicht. Editoren bearbeiten Bild-Metadaten quer durch alle Pages und Image-Felder inline (Description, Tags, Custom-Subfields), ohne pro Page navigieren zu müssen.

**Primärer Use-Case:** Content-lastige Site mit tausenden Bildern über dutzende oder hunderte Pages verteilt, viele mit fehlender Description oder unvollständigen Tags. Editor will filtern (z.B. „nur Bilder ohne Description"), abarbeiten, das nächste Filter wechseln — ohne ständig zwischen Page-Edit-Screens zu springen.

## Scope

**In-Scope (umgesetzt):**

- Aggregiert Bilder aus **allen** `FieldtypeImage`-Feldern auf **allen** Templates der Installation (mit konfigurierbaren Template- und Field-Blacklists)
- Jede Tabellen-Zeile = Tupel `(page, fieldName, basename)`
- Inline-Edit der editable Subfields (Description, Tags, Custom-Felder); Textarea-Customs öffnen einen Popup
- **Mehrsprachige Subfields**: per-Sprache-Tabs im Popup, Roundtrip in JSON/CSV-Export-Import
- **Bulk-Edit** via „Selection als Pinsel": markierte Rows werden gemeinsam mit der nächsten Cell-Save in Add- oder Replace-Mode aktualisiert
- **Variations-Spalte**: pro Bild Zähler aus `$img->getVariations()`
- **Export / Import**: JSON und CSV (mit multilang-aware Spalten-Suffixen `<subfield>_<langName>`)
- Server-side Filter / Sortierung / Pagination
- Spalten-Konfiguration per User in `$user->meta('imageLibraryPrefs')` — **cross-device**, inklusive Page-Size
- Auto-Erkennung von **Custom Fields on Images** (`field-{fieldname}`-Template, PW 3.0.142+)

**Out-of-Scope (auch in der aktuellen Version):**

- Bilder hochladen / löschen / verschieben zwischen Pages
- Variations-Management (Crop / Focus / Re-Generate) — Modul zeigt den Variations-Zähler, regeneriert aber nicht
- Re-Sort innerhalb eines Image-Felds (`$img->sort`)
- Bulk-Delete / File-Rename / Replace-Image (mögliche Phase-2-Themen)

## Architektur

**Modul-Typ:** `Process`-Modul, eigene Admin-URL `/processwire/setup/image-library/`.

**Klassen:** `ProcessImageLibrary`

**Dateistruktur:**

```
ProcessImageLibrary/
├── ProcessImageLibrary.module.php
├── ProcessImageLibrary.info.json
├── ProcessImageLibrary.css
├── ProcessImageLibrary.js
├── README.md
└── LICENSE
```

**Methods:**

- `___execute()` — Tabelle + Filter-UI rendern (Server-rendered HTML; JS hydratisiert für Interaktion). Spalten-Picker liegt als sibling-`<dialog>` neben `.ml-results` damit AJAX-Swaps Drag/Toggle-Handler intakt lassen.
- `___executeData()` — AJAX-GET, returnt nur den `.ml-results`-Block (Tabelle + Pagination) für Filter/Sort/Page-Swaps.
- `___executeSave()` — AJAX-POST, validiert + speichert eine Cell-Änderung, gibt JSON zurück. Multilang-aware: payload kann `langId` tragen, dann wird nur dieser Sprach-Slot geschrieben.
- `___executeBulk()` — AJAX-POST: identische Cell-Save auf eine Selektion anwenden (Add- oder Replace-Mode).
- `___executeExport()` — Direct-Download von JSON oder CSV unter Berücksichtigung der aktiven Filter.
- `___executeImport()` — AJAX-POST, akzeptiert eine vorher exportierte (und extern bearbeitete) JSON/CSV-Datei und schreibt zurück; idempotent (unverändert gebliebene Items werden geskippt).
- `___executeUserPrefs()` — AJAX-POST persistiert Spalten + Page-Size in `$user->meta('imageLibraryPrefs')` (debounced).
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

- **Thumb** (`$img->size(120, 80)->url`), `loading="lazy"`
- **Page** (Title + Link zur PW-Edit-Seite)
- **Field** (Field-Name, z.B. `images`, `lead_image`)
- **Filename** (`$img->basename`)

Default-sichtbar / editable:

- **Description** — Textarea
- **Tags** — Input je nach `useTags`-Konfig des jeweiligen Felds:
  - `useTags=0`: Spalte verbergen
  - `useTags=1`: Text-Input + Autocomplete aus historisch verwendeten Tags
  - `useTags=2`: Multi-Select aus `tagsList`
  - `useTags=8|9`: Multi-Select + freier Text-Input
- **Dimensions** (`{w}×{h}`) — read-only
- **Filesize** — read-only

Auto-entdeckt (alle Custom-Field-Subfields des `field-{fieldname}`-Templates):

- Input-Typ-Mapping basierend auf der Inputfield-Klasse:
  - Text/Textarea → editable text/textarea
  - Checkbox → Checkbox
  - Page-Reference → Select / Multi-Select
  - Datetime → Date-Picker

**Konfig:** Per User in `$user->meta('imageLibraryPrefs')` — Cross-device-persistiert via `___executeUserPrefs`. Struktur: `{columns: {visible: {col: bool}, order: [col]}, pageSize: int|null}`. Default-Set: Pflicht + Description + Tags + Custom-Fields (Admin kann eine Default-Hidden-Liste in der Module-Config setzen).

## Edit-Semantik

**Inline-Edit pro Zelle:**

1. Klick (oder Tastatur-Enter/Space — die Zellen sind `role="button" tabindex="0"`) auf Zelle → Input/Textarea ersetzt Display-Wert. Textarea-Customs öffnen einen modalen Popup mit Multilang-Tabs wenn die Installation Languages aktiviert hat.
2. Blur ODER Enter → AJAX-POST mit `{ pageId, fieldName, basename, subfield, value, langId? }`
3. Server: validiert (Tag-Whitelist wenn `useTags=2`, etc.), führt `$page->save()` aus, returnt `{ ok, value }` oder `{ ok: false, error }`.
4. UI: optimistic update, grüner Check / rotes X. Beide Status-Wechsel werden zusätzlich in eine visually-hidden `aria-live`-Region geschrieben, damit Screen Reader sie picken.
5. Cache wird sowohl explicit als auch durch den `Pages::saved`-Hook invalidiert.

**Bulk-Edit (Selection als Pinsel):** Wenn die editierte Zelle zu einer aktiven Selektion gehört, blendet sich beim Save ein „Add / Replace"-Picker ein. Auswahl + Commit verteilt die neue Value auf alle selektierten Rows mit derselben Subfield-Adressierung.

**Save-Queue:** Mehrere Edits werden pro PageId seriell geschickt — keine parallelen `$page->save()`-Aufrufe auf derselben Page (vermeidet ChangeTracker-Races).

## Filter

Klappbares „Filters"-Fieldset (Icon `fa-filter`) oberhalb der Tabelle. Das Label trägt einen `(N)`-Suffix mit der Zahl der aktiven Filter, damit der Zustand auch bei zugeklapptem Fieldset sichtbar bleibt.

| Filter | Wo gefiltert | Notiz |
|---|---|---|
| Volltextsuche (Page-Title, description, tags, filename, customs) | PHP | Word-Match, multilang-aware |
| Template-Filter | PHP | aus `eligibleTemplates` |
| Image-Feld-Filter | PHP | aus `imageFields`, narrowt das Custom-Field-Dropdown |
| „Missing description" / „Missing tags" | PHP | pro Bild verifiziert |
| „Missing &lt;custom&gt;" | PHP | je Custom-Subfield ein eigener Checkbox-Filter, dynamisch |
| Tags | PHP | Multi-Select aus tatsächlich vergebenen Tags, AND-Semantik |

Filter werden URL-state-persisted und sind bookmarkbar. Tags werden als komma-separierter Wert (`?tags=foo,bar`) emittiert; die alte Bracket-Form (`?tags[]=…`) bleibt akzeptiert. Nach „Apply" klappt das Fieldset automatisch ein damit die Resultate-Tabelle freie Sicht hat.

## Sortierung

Spalten-Klick toggelt ascending/descending. Sortierbare Felder: Page-Title, Field, Filename, Description, Tags, Width, Filesize sowie alle Custom-Subfields via `custom:<name>`.

**Default**: in der Module-Config (Fieldset „Default sort") wählbar — Column + Direction. Built-in-Default ist `pageTitle asc`. URL-Override (`?sort=basename&dir=desc`) gewinnt; URLs lassen sort/dir weg wenn sie dem konfigurierten Default entsprechen, damit geteilte Links übersichtlich bleiben.

## Pagination

50 Rows/Seite als built-in-Default (Module-Config überschreibbar). URL-State `?p=3` + `?ps=100`. Total-Count + „Page 3 of 25" in der Pagination-Zeile. Picker im Pagination-Block (Optionen ebenfalls Module-Config) — Auswahl persistiert in `$user->meta('imageLibraryPrefs').pageSize`, gilt also cross-device. Die Pagination-Zeile wird oben und unten gerendert; rechts daneben ein `fa-columns`-Icon, das den Spalten-Picker-Dialog öffnet.

## Permissions

- **Anzeige der Admin-Page:** User braucht `page-edit` auf irgendeiner Page mit Image-Feld (Modul-Check beim Boot)
- **Edit pro Cell:** `$page->editable()` auf der konkreten Ziel-Page (im Save-Endpoint pro Request geprüft)
- Optional separate Permission `image-library-access` für engere Steuerung — wenn vorhanden, scopt das Admin-Page-Sichtbarkeit zusätzlich

## Technische Constraints

- **PHP:** Ausschließlich PW-API (`$pages->findRaw()`, `$pages->getMany()`, `$page->save()`, `$img->size()`, `$cache->save()`, etc.). **Kein direktes SQL.**
- **JS:** Vanilla, Fetch-API, keine Framework-Dependency.
- **CSS:** Verträglich mit AdminThemeUikit (Uikit-Klassen wo möglich).
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
- **Bulk-Operations** → Selection-als-Pinsel umgesetzt (Add/Replace-Modes). Bulk-Delete und File-Rename bleiben offen.
- **Page-Size** → 50 Default, Picker mit konfigurierbarer Options-Liste, Auswahl in `$user->meta`.
- **Permission-Granularität** → beides: `image-library-access` als Hard-Gate für die Admin-Page, `$page->editable()` pro Cell-Save.
- **Variations-Spalte** → umgesetzt (read-only Zähler).
- **Modul-Info / Versioning** → SemVer, GitHub-Tags. Composer-Support nicht aktiv geplant.

## Verbleibende Open Questions

1. **Mobile**: aktuell flex-wrap + horizontal-scrollende Tabelle. Reicht das oder lohnt ein dedizierter Card-View < 640 px?

2. **Skalierung jenseits ~10k Bilder**: Aktueller Pfad `findRaw + WireCache::saveFor` ist linear in der Anzahl Image-Rows. Ab 30k+ wird der Cache-Re-build spürbar. Pfad zu `findMany` + per-Image-Index ist im Konzept dokumentiert, aber noch nicht beziffert.

3. **Bulk-Delete / File-Rename / Replace-Image** als Phase-2-Feature-Set: Bedarfsabhängig vom Editor-Workflow.

4. **WebP / AVIF / SVG / animated GIF** als Original-Format: aktuell wird `$img->size()` blind aufgerufen. Funktioniert, aber SVG → PNG (Rasterisierung), animierte GIFs werden statisch. Hinweis im UI sinnvoll?

5. **Alt-Text als separates Subfield**: PW behandelt `description` als impliziten Alt-Text. Für strenge a11y/SEO-Workflows ggf. ein separates `alt`-Custom-Field empfohlen — aber das wäre eine Editorial-Konvention, kein Modul-Feature.
