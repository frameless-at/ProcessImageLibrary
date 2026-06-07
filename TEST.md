# Testplan — Auto‑Deduplication & Library‑Picker

Branch: `feature/dedup-research`.

Deckt zwei Feature‑Bereiche ab:

1. **Automatische Deduplizierung** — byte‑identische Bilder werden beim
   Speichern (und stündlich per LazyCron) per **Hardlink** auf eine gemeinsame
   Datei zusammengelegt. Verlustfrei und reversibel.
2. **„Choose from library"** — ein vorhandenes Bild aus der Modulansicht einem
   Bildfeld zuweisen, ohne erneut hochzuladen (Kopie wird automatisch
   hardgelinkt → kein Extra‑Speicher).

> Legende: 🔴 kritisch (Datenintegrität) · 🟠 Kernfunktion · 🟡 Duplikate‑UI ·
> 🟢 Auto‑Dedup · ⚪ Sonstiges

## Voraussetzungen

- Mindestens ein Bildfeld in ≥2 Templates; idealerweise auch ein Bildfeld in
  einem **Repeater** und einem **RepeaterMatrix**.
- Ein paar byte‑identische Bilder auf verschiedenen Seiten (für Duplikate).
- SSH/Shell‑Zugriff auf den Server für die `ls -li`‑Inode‑Checks.
- Modul installiert (Initial‑Scan ist beim Install gelaufen).

### Datei‑Pfade & Inode‑Check

ProcessWire legt Bilder unter `/site/assets/files/<seitenID>/<dateiname>` ab.
Hardgelinkte Dateien teilen sich **dieselbe Inode‑Nummer** (1. Spalte von
`ls -li`) und haben einen Link‑Count ≥ 2 (3. Spalte):

```bash
ls -li /site/assets/files/123/foo.jpg /site/assets/files/456/foo.jpg
# gleiche erste Spalte (Inode) + Link-Count >= 2  => hardgelinkt
md5 /site/assets/files/123/foo.jpg /site/assets/files/456/foo.jpg
# gleiche Prüfsumme => byte-identisch
```

---

## 🔴 Hardlink‑Sicherheit (zuerst!)

Diese Fälle beweisen, dass das Zusammenlegen **verlustfrei** ist. Vorbereitung:
zwei Seiten mit demselben Bild, einmal scannen/reclaimen lassen, Inode‑Gleichheit
mit `ls -li` bestätigen.

- [ ] **1. Bytes ersetzen (copy‑on‑write).** Bei einer der beiden geteilten
  Dateien die Bytes ersetzen (Modul‑„Replace" **oder** im PW‑Editor neues Bild
  hochladen).
  - Erwartung: Der Link **bricht sauber auf** — die ersetzte Datei hat eine
    **neue** Inode, die **andere** Kopie ist unverändert (`md5` der anderen Datei
    identisch wie vorher). Keine Korruption, beide Bilder weiter anzeigbar.
- [ ] **2. Eine Kopie löschen.** Eine der beiden Kopien löschen (Bild im Feld
  entfernen **oder** die ganze Seite löschen).
  - Erwartung: Die verbleibende Kopie ist **intakt** und wird normal angezeigt
    (`md5` unverändert). Platz wird erst frei, wenn die letzte Kopie weg ist.
- [ ] **3. Umbenennen.** Ein geteiltes Bild umbenennen (Modul‑Rename).
  - Erwartung: Inode bleibt erhalten, der Hardlink überlebt.
- [ ] **4. Varianten erzeugen.** Auf einem geteilten Bild Crop/Resize/Focus im
  PW‑Editor ausführen.
  - Erwartung: Varianten sind **eigene** Dateien daneben; die Original‑Inode
    bleibt geteilt.
- [ ] **5. Revert → Reclaim (Round‑Trip).** Config → **Revert (un‑share all)**.
  - Erwartung: Alle Kopien wieder **eigenständige** Dateien (Inodes verschieden),
    Manifest leer, „Disk space reclaimed" = 0, Plattenplatz wächst zurück.
  - Danach **Scan & reclaim now** → wieder verlinkt, Zahlen wie zuvor. Bilder zu
    keinem Zeitpunkt kaputt.
- [ ] **6. Hardlink‑unfreundliches Deploy/Backup** *(falls testbar)*: z. B.
  `rsync` **ohne** `-H` auf ein anderes Ziel.
  - Erwartung: Links expandieren zu Vollkopien (Ersparnis geht verloren), aber
    **nichts kaputt**; der nächste Hintergrundlauf verlinkt neu.

---

## 🟠 Picker / „Choose from library"

- [ ] **7. Einzelbild‑Feld (maxFiles=1):** Assign **ersetzt** das vorhandene Bild.
- [ ] **8. Mehrbild‑Feld voll:** Assign wird mit Meldung abgewiesen.
- [ ] **9. Mehrfachauswahl:** mehrere Bilder ankreuzen → alle landen im Feld
  (sequentiell, keine Race‑Condition).
- [ ] **10. Custom‑Felder + mehrsprachig:** Beschreibung / Tags / Custom‑Subfelder
  werden in **allen Sprachen** mitkopiert (nur namensgleiche Subfelder des
  Zielfelds).
- [ ] **11. Extension‑Mismatch:** Zielfeld erlaubt nur z. B. `jpg`, Quelle ist
  `png` → erwartetes Verhalten/Meldung prüfen.
- [ ] **12. Repeater ↔ normales Feld:** Quelle im Repeater, Ziel normal — und
  umgekehrt. In **beiden** Varianten erscheint das Thumbnail **sofort** nach dem
  Schließen des Modals (ohne Seiten‑Reload), inkl. RepeaterMatrix.
- [ ] **13. Basename‑Kollision:** Quelle hat denselben Dateinamen wie ein
  vorhandenes Bild im Ziel → PW benennt um; der **Hardlink** muss trotzdem
  greifen (Match über Content‑Hash, nicht Dateiname).
- [ ] **14. Speicher‑Nachweis:** Nach dem Assign steigt in der Config
  „Copies sharing a file"; Quelle + neue Kopie teilen sich die **Inode**
  (`ls -li`).
- [ ] **15. Abbrechen:** Modal über „✕" schließen → keine Zuweisung, keine
  Nebenwirkungen.
- [ ] **16. Auswahl über Seiten/Views:** in Tabelle **und** Masonry auswählen;
  zwischen den Views hin‑/herschalten → Checkboxen bleiben erhalten.
- [ ] **17. Nicht‑editierbare Zielseite:** Button erscheint nicht bzw. Assign
  liefert 403.
- [ ] **18. Andere ungespeicherte Änderungen:** im Editor ein anderes Feld
  ändern (nicht speichern), dann Bild zuweisen → das zugewiesene Bild erscheint,
  die ungespeicherte Änderung **bleibt** erhalten.

---

## 🟡 Duplikate‑Ansicht / Filter (kontextbezogen)

- [ ] **19. Akkordeon:** In der Tabelle klappt ein Duplikat zu **einer** Zeile
  zusammen; Klick auf den Indikator klappt die Kopien auf/zu.
- [ ] **20. Kontext‑Test (wichtig):** Feld‑Filter aktiv, der Zwilling liegt in
  einem **anderen** (weggefilterten) Feld → **kein** Indikator/Toggle, normale
  Zeile.
- [ ] **21. Duplicates‑Filter:** zeigt nur kontextuelle Duplikate; Pagination
  **pro Cluster**; Spalten‑Sortierung ordnet innerhalb des Clusters.
- [ ] **22. Bulk‑Edit:** aufgeklappte Kopie‑Zeilen aus‑/anwählen und per
  Stapel‑Edit gemeinsam ändern.
- [ ] **23. Masonry:** Badge statt Akkordeon; Zähler = kontextuelle Anzahl.
- [ ] **24. Bookmark:** „Duplicates"‑Filter (auch mit weiteren Filtern) als
  Bookmark speichern und wieder laden; aktiver Tab wird erkannt.

---

## 🟢 Auto‑Dedup ohne Picker

- [ ] **25. Identischer Upload:** ein Bild hochladen, das byte‑identisch zu einem
  vorhandenen ist → beim Speichern automatisch hardgelinkt (`ls -li`).
- [ ] **26. Zwilling kommt später:** erst A, später B (identisch) hochladen →
  spätestens nächster Save / LazyCron verlinkt die beiden.
- [ ] **27. Frische Installation:** auf einer Site mit Bestands‑Duplikaten →
  Initial‑Lauf spart sofort Platz; LazyCron/Saves arbeiten den Rest ab.

---

## ⚪ Config & Sonstiges

- [ ] **28. Status:** „Disk space reclaimed", „Copies sharing a file",
  „Exact‑duplicate clusters" zeigen plausible Werte.
- [ ] **29. Scan & reclaim now:** läuft, Status aktualisiert sich nach dem
  Redirect.
- [ ] **30. Mehrsprachige Seite:** Picker‑Übernahme + Inline‑Edit in mehreren
  Sprachen.
- [ ] **31. Verschachtelte Repeater / RepeaterMatrix‑Typen.**
- [ ] **32. Rechte:** User mit `image-library-access`, aber eingeschränkten
  Edit‑Rechten → sieht nur, was er bearbeiten darf.
- [ ] **33. Leere Bibliothek / kein Scan gelaufen:** Picker funktioniert, keine
  Indikatoren, keine Fehler.

---

## Bekannte Grenzen (kein Bug)

- Cross‑Feld‑Custom‑Felder werden beim Assign nur **namensgleich** übernommen
  (technisch nicht anders möglich).
- Picker‑Auswahl ist pro sichtbarer Seite (kein seitenübergreifendes
  Multi‑Select).
- Hardlink‑Ersparnis kann durch hardlink‑unfreundliche Backup/Deploy‑Tools
  (`rsync` ohne `-H`, plain `tar`/`cp`, Sync auf anderes Mount) mit der Zeit
  zurückwachsen — der Hintergrundlauf verlinkt dann erneut. Nie Datenverlust.
