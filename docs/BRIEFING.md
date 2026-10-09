# WebP-Umwandler – Briefing für Claude Code

> Dieses Dokument ist der Startprompt für Claude Code **und** die gemeinsame Kontextquelle für Claude Chat (Projekt „Pagespeed WordPress“).
> Kopiere alles ab „## Prompt“ in Claude Code. Die Spielregeln für den gemeinsamen Kontext stehen am Ende.

---

## Prompt

Du baust mit mir ein WordPress-Plugin namens **„WebP-Umwandler“** (Slug: `akuma-webp-umwandler`, Text-Domain gleich, Prefix `akwu_`, PHP-Namespace `Akuma\WebpUmwandler`). Lies dieses Briefing komplett, lege dann zuerst die Kontextdateien an (Abschnitt „Gemeinsamer Kontext“) und arbeite die Meilensteine der Reihe nach ab. Frag nach, bevor du von einer Anforderung abweichst.

### 1. Hintergrund

- Ich (Felix, Akuma Digital) optimiere als Freelancer für die Agentur **Niovo Web Studios** die PageSpeed von WordPress-Seiten, fast alle mit **Elementor** gebaut (oft mit Addons: ElementsKit, Crocoblock/JetEngine, Marquee Addons). Hosting meist **Raidboxes** (nginx, SSH + WP-CLI verfügbar, Staging per Klick). Caching teils mit **FastPixel**.
- Problem: Bei älteren Seiten liegen viele große PNG/JPG in der Mediathek. Plugins wie WebP Express oder Converter for Media lassen die Originale liegen und liefern WebP nur zusätzlich über Rewrite-Regeln aus. Deinstalliert man sie, ist alles wieder beim Alten. Das wollen wir nicht.
- Ziel laut Projektleitung: Bilder **1:1 durch WebP ersetzen, mit derselben Attachment-ID**, damit in Elementor nichts kaputtgeht. Danach soll das Plugin **entfernt werden können, und der Zustand bleibt**.
- Wichtig: In FastPixel ist die Bildkomprimierung bewusst **aus**, weil Bilder sonst doppelt komprimiert wurden. Unser Plugin darf bereits optimierte Bilder nicht verschlechtern.

### 2. Was das Plugin tut (Kurzfassung)

1. **Scan** (nur lesen): Bestandsaufnahme der Mediathek, Größen, Hochrechnung der Ersparnis, Fundstellen und Warnungen.
2. **Umwandlung** (ein Knopf): in Batches PNG/JPG → WebP. Die Attachment-ID bleibt gleich, Metadaten und Thumbnails werden neu erzeugt, alle Verweise in der DB werden ersetzt, danach Elementor-CSS neu erzeugen und Caches leeren.
3. **Bericht**: vorher/nachher, pro Bild, Restfundstellen, CSV-Export, optional PageSpeed-Wert vorher/nachher.
4. **Rückgängig** (komplett oder pro Bild), solange die Originale noch existieren.
5. **Originale löschen** als separater, bewusst ausgelöster Schritt.

### 3. Technische Rahmenbedingungen

- WordPress ≥ 6.0, PHP ≥ 7.4 (muss auch auf 8.1–8.3 laufen), keine Composer-Abhängigkeiten zur Laufzeit (Dev-Tools wie PHPUnit/PHPCS sind ok).
- Nur Admins: Capability `manage_options`, Nonces auf allen AJAX- und REST-Aufrufen.
- Keine externen Requests aus dem Admin, außer der optionalen PageSpeed-API. **Fonts (Outfit, Inter) lokal mitliefern**, kein Google-Fonts-CDN (DSGVO).
- Multisite: vorerst nicht unterstützt. Bei Multisite sauber abbrechen und einen Hinweis zeigen.
- Zusätzlich zur Admin-UI ein **WP-CLI-Command** `wp akwu scan|convert|report|rollback|purge-originals` mit `--dry-run`, `--limit=`, `--ids=`. Gleiche Kernlogik, keine doppelte Implementierung.
- Saubere Struktur, zum Beispiel:
  ```
  akuma-webp-umwandler.php
  uninstall.php
  includes/ (Scanner, Converter, Replacer, Rollback, Report, CachePurger, Cli, RestController, Admin)
  assets/ (admin.css, admin.js, fonts/)
  design/ (Referenz-Mockups, siehe unten)
  tests/
  docs/
  ```

### 4. Kernlogik im Detail

**4.1 Systemprüfung**
- `wp_image_editor_supports(['mime_type' => 'image/webp'])`. Imagick bevorzugen, GD als Fallback. Ohne WebP-Support: kein Start, klare Meldung.
- Prüfen und anzeigen: freier Speicher im Upload-Ordner (mindestens Originalgröße × 1,5), Schreibrechte, `max_execution_time`, Memory-Limit.
- Bekannte Konflikte erkennen und warnen: aktive Bildoptimierer mit eigener Komprimierung oder Rewrite (FastPixel-Bildkomprimierung, WebP Express, Converter for Media, Imagify, ShortPixel, EWWW, Smush, Optimole). Nur warnen, nichts automatisch umstellen.
- Checkbox „Backup von Datenbank und Uploads ist erstellt“ ist Pflicht vor dem Start.

**4.2 Scan**
- Alle Attachments mit `image/jpeg` und `image/png`. GIF und SVG ignorieren, bestehende WebP/AVIF als „schon modern“ zählen.
- Größe pro Attachment = Original (inkl. `original_image` bei `-scaled`) + alle registrierten Größen aus `_wp_attachment_metadata` + Elementor-Custom-Thumbs (`uploads/elementor/thumbs/`), sofern zuordenbar.
- Hochrechnung: Stichprobe von 20 Bildern (gemischt nach Format und Größe) in ein Temp-Verzeichnis umwandeln, Ersparnis messen, Temp-Dateien löschen.
- **Verwendung ermitteln**: Wo kommt die ID oder URL vor? `post_content`, `_elementor_data` (als JSON parsen, nicht per String raten), `_elementor_page_settings`, Elementor-Kit (`elementor_active_kit`), Theme-Mods, Options, sonstige Postmeta (z. B. JetEngine/ACF).
- Warnungen für Fundstellen, die **nicht automatisch** ersetzt werden: Customizer-CSS (`wp_get_custom_css`), Code-Snippets-Tabelle (`{prefix}snippets`, falls vorhanden), Dateien im Theme, hartcodierte URLs in Elementor-Custom-CSS. Die werden nur gelistet, nie verändert.
- Ergebnis in einer Option bzw. eigenen Tabelle cachen, mit Zeitstempel.

**4.3 Umwandlung pro Attachment (Kern)**
1. Quelle bestimmen: bei `-scaled` das `original_image` verwenden, sonst die angehängte Datei.
2. WebP erzeugen über `wp_get_image_editor()` → `save($ziel, 'image/webp')`.
   - JPG: Qualität aus den Einstellungen (Standard 82).
   - PNG: mit Alpha verlustfrei, wenn Imagick verfügbar (`webp:lossless`), sonst hohe Qualität (90). Ohne Alpha wie JPG.
   - EXIF-Orientierung berücksichtigen.
3. **Schutz vor doppelter Komprimierung**: Nur übernehmen, wenn die WebP-Datei mindestens X % kleiner ist (Einstellung, Standard 10 %). Sonst überspringen und den Grund protokollieren.
4. Dateinamenskollision verhindern: Gibt es `bild.png` und `bild.jpg` im selben Ordner, darf nicht beides `bild.webp` werden → `wp_unique_filename()`.
5. **Vor der Änderung den Zustand sichern** (für Rollback): `_wp_attached_file`, `_wp_attachment_metadata`, `post_mime_type`, Liste aller alten Dateipfade.
6. Attachment umstellen, **ID bleibt gleich**:
   - `update_attached_file($id, $neuerPfad)`
   - `wp_update_post(['ID' => $id, 'post_mime_type' => 'image/webp'])`
   - `require_once ABSPATH.'wp-admin/includes/image.php'` → `wp_generate_attachment_metadata()` → `wp_update_attachment_metadata()` (erzeugt alle Größen neu als WebP)
   - `guid` **nicht** anfassen.
7. URL-Mapping bauen: alte URL → neue URL für das Original, `-scaled` und **jede** Größe (über Größenname matchen, nicht über Pixelmaße raten). Elementor-Custom-Thumbs: alte Dateien listen und den Elementor-Cache neu erzeugen lassen.
8. Alte Dateien **nicht löschen** (erst in Schritt 4.7).

**4.4 Verweise ersetzen (Replacer)**
- Für jede Mapping-Zeile ersetzen in: `posts.post_content`, `posts.post_excerpt`, `postmeta.meta_value`, `options.option_value`, `termmeta`. Nie `guid`.
- **Serialisierungssicher**: Werte mit `maybe_unserialize` lesen, rekursiv ersetzen, wieder serialisieren. Niemals rohes `REPLACE()` auf serialisierte Daten.
- **JSON-sicher** für `_elementor_data`: decodieren, rekursiv ersetzen, mit `wp_slash(wp_json_encode(...))` zurückschreiben (so wie Elementor selbst speichert). Zusätzlich die maskierte Schreibweise `https:\/\/...` abdecken, falls Daten nicht decodierbar sind.
- URL-Varianten: absolut mit http und https, protokollrelativ `//`, relativ `/wp-content/uploads/...`.
- Vorher per SQL `LIKE` vorfiltern, damit nicht die ganze DB in PHP geladen wird.
- Zählen und protokollieren, wie viele Ersetzungen wo passiert sind (Tabelle, Post-ID, Meta-Key).
- Danach Gegenprobe: Gibt es noch Vorkommen der alten URLs? → in „Bitte prüfen“ aufnehmen.

**4.5 Abschluss eines Laufs**
- Elementor-CSS neu erzeugen: `\Elementor\Plugin::$instance->files_manager->clear_cache()` (mit `class_exists` absichern).
- Caches leeren, jeweils nur wenn vorhanden (mit `function_exists` / `has_action` prüfen): WordPress-Objekt-Cache, FastPixel, Raidboxes, WP Rocket, LiteSpeed, W3TC, WP Super Cache, Autoptimize. **Keine APIs erfinden.** Was du nicht verifizieren kannst, kommt als TODO in `docs/HANDOFF.md`.

**4.6 Rollback**
- Pro Attachment aus dem gesicherten Zustand wiederherstellen (Meta, Mime, Datei-Pointer), URL-Mapping rückwärts ersetzen, neue WebP-Dateien löschen.
- Funktioniert nur, solange die Originale existieren. Danach den Rollback-Knopf deaktivieren und den Grund anzeigen.

**4.7 Originale löschen**
- Eigener Schritt mit Bestätigungsdialog (Zahl eintippen o. ä.), nur nach abgeschlossenem Lauf, listet die freizugebende Größe.
- Löscht alte Originale, alte Größen und die zugehörigen Elementor-Thumbs. Protokolliert alles.

**4.8 Batches und Robustheit**
- Verarbeitung in Paketen (Standard 10 Attachments) per REST/AJAX aus der UI, damit kein Timeout auftritt. Ein Lock verhindert parallele Läufe.
- Fortsetzbar: Status pro Attachment in eigener Tabelle `{prefix}akwu_log` (attachment_id, status [pending|done|skipped|error|rolled_back], alte/neue Pfade, alte Meta als JSON, Bytes vorher/nachher, Anzahl Ersetzungen, Fehlermeldung, Zeitstempel).
- Abbruch oder Fenster zu → beim nächsten Start dort weitermachen.
- „Erst 10 testen“-Modus: verarbeitet nur 10 Bilder, danach Bericht.

**4.9 Bericht**
- Gesamtgröße vorher/nachher, Anzahl umgewandelt/übersprungen/Fehler, Anzahl ersetzter Verweise und betroffener Seiten, Tabelle pro Bild, Restfundstellen.
- CSV-Export (Semikolon-getrennt, UTF-8 mit BOM, damit Excel in DE sauber öffnet).
- Optional: PageSpeed-Insights-API (API-Key in den Einstellungen) misst die Startseite mobil vorher und nachher.

**4.10 Deinstallation**
- Deaktivieren und Löschen ändert **nichts** an den umgewandelten Bildern.
- `uninstall.php` löscht nur die Plugin-Optionen und die Log-Tabelle. Hinweis in der UI: Danach ist kein Rollback mehr möglich.

### 5. Oberfläche

- Eigener Admin-Menüpunkt „WebP-Umwandler“ mit Unterseiten **Übersicht**, **Umwandlung**, **Bericht**, **Alle Bilder**, **Einstellungen**, **Systemprüfung**, **Rückgängig**.
- Layout wie die Referenz-Mockups in `design/` (Main, Umwandlung, Bericht): Plugin-Kopfzeile (Logo, Name, Version, „von Akuma Digital“), WordPress-Admin-Notices, darunter ein Panel mit eigener linker Navigation und Inhalt.
- Look: Akuma Digital, aber **nur im Plugin-Bereich**, der WP-Rahmen bleibt Standard.
  - Farben: Grün `#1c805d`, dunkler `#176247`, Headline-Akzent `#408062`, Überschriften `#263730`, Fließtext `#53615a`, Linien `#dde0de` / `#e1e4e2`, Flächen `#f8f9f9`. Neutraltöne grünstichig, kein reines Grau.
  - Schrift: Outfit 500 für Überschriften (nicht fett, letter-spacing −0.03em), Inter für Text. Lokal eingebunden.
  - Radien 16–18 px für Karten, Pill-Buttons. Primärbutton = helle Pille mit grünem Text und rundem grünem Pfeil-Medaillon. Weiche, grüngetönte, mehrlagige Schatten statt grauer.
  - Ruhige, kurze Texte, keine Ausrufezeichen, keine Emojis. Sprache Deutsch, alle Strings übersetzbar (`__()`).
- Kein Frontend-Framework nötig. Vanilla JS oder `@wordpress/element`, kein Build-Zwang. Wenn Build, dann mit einfachem npm-Script und eingecheckten Builds.
- Barrierefrei: echte Buttons und Labels, Fortschritt mit `role="progressbar"`, Fokus sichtbar.

### 6. Testen

- Lokale Umgebung mit `@wordpress/env` (wp-env) oder Docker, inklusive Elementor (free). Ein Seed-Script legt Testdaten an:
  - PNG mit/ohne Transparenz, große JPG, `-scaled`-Bild, Namenskollision `bild.png` + `bild.jpg`
  - Elementor-Seite mit Image-Widget, Section/Container-**Hintergrundbild**, Galerie, Bild in Text-Editor-HTML
  - Bild-URL in Customizer-CSS (muss als Restfundstelle auftauchen)
- PHPUnit-Tests mindestens für: URL-Mapping-Erzeugung, serialisierungssicheres Ersetzen, JSON-Ersetzen in `_elementor_data`, Rollback-Wiederherstellung.
- Nach jedem Meilenstein: kurzer Testbericht in `docs/HANDOFF.md`.
- PHPCS mit WordPress-Coding-Standards, keine Fehler.

### 7. Meilensteine

1. **M1 – Grundgerüst**: Plugin-Bootstrap, Menü, leere Seiten im Design, Systemprüfung, lokale Testumgebung + Seed-Daten, Kontextdateien.
2. **M2 – Scan**: komplette Bestandsaufnahme inkl. Hochrechnung, Verwendung und Warnungen, Übersichtsseite mit echten Daten. Ändert noch nichts.
3. **M3 – Umwandlung + Replacer**: Kernlogik, Batches, Log-Tabelle, „Erst 10 testen“, WP-CLI. Tests grün.
4. **M4 – Bericht + Rollback + Originale löschen + CSV**.
5. **M5 – Feinschliff**: PageSpeed-API optional, Cache-Purger, Uninstall, Übersetzbarkeit, README mit Anleitung für Niovo (Staging zuerst!).

Nach jedem Meilenstein: committen, `docs/HANDOFF.md` aktualisieren, kurz zusammenfassen und auf mein Okay warten.

### 8. Was du nicht tun sollst

- Keine Bilder überschreiben oder löschen, ohne vorher den Rollback-Zustand gesichert zu haben.
- Kein Rewrite-/.htaccess-Ansatz, keine dauerhafte Auslieferungslogik. Das Plugin muss entfernbar sein.
- Keine Fundstellen außerhalb der Datenbank automatisch ändern (Theme-Dateien, Code Snippets, Custom CSS), nur listen.
- Keine erfundenen Plugin-APIs. Lieber TODO und Rückfrage.

### 9. Gemeinsamer Kontext (zuerst anlegen)

Wir arbeiten parallel in Claude Code (Umsetzung) und Claude Chat (Planung, Design, Abstimmung mit Niovo). Damit beide denselben Stand haben, ist **das Repository die einzige Wahrheit**:

- **`CLAUDE.md`** (Repo-Wurzel): Projektzweck, Architektur in Stichpunkten, Konventionen, wie man testet, Verweis auf dieses Briefing. Kurz halten, aktuell halten.
- **`docs/BRIEFING.md`**: dieses Briefing, unverändert. Änderungen an Anforderungen nur mit Datum unten anhängen (Abschnitt „Änderungen“).
- **`docs/HANDOFF.md`**: das Übergabe-Logbuch. **Nach jeder Arbeitssitzung** oben einen neuen Eintrag anlegen, neueste zuerst, in diesem Format:
  ```
  ## YYYY-MM-DD HH:MM – <Kurztitel> (Claude Code | Claude Chat)
  **Stand:** M<x> – <offen/fertig>
  **Erledigt:** …
  **Entscheidungen:** … (mit kurzer Begründung)
  **Offen / Nächster Schritt:** …
  **Fragen an Felix:** …
  **Getestet:** … (was, wo, Ergebnis)
  ```
- **`docs/DECISIONS.md`**: dauerhafte Architektur-Entscheidungen, nummeriert (ADR-light, je 3–5 Zeilen).
- Einträge von „Claude Chat“ im Handoff sind Vorgaben aus der Planung. Lies sie zu Beginn jeder Sitzung und berücksichtige sie.
- Beginne **jede** Sitzung damit, `CLAUDE.md` und die obersten 3 Einträge in `docs/HANDOFF.md` zu lesen.

Leg jetzt das Repo-Grundgerüst und die vier Kontextdateien an, schreib den ersten Handoff-Eintrag mit deinem Plan für M1 und warte dann auf mein Okay.

---

## Spielregeln für den gemeinsamen Kontext (für Felix)

- **Repo = Wahrheit.** Claude Code schreibt nach jeder Sitzung in `docs/HANDOFF.md`.
- **Claude Chat (dieses Projekt)** liest den Stand direkt aus dem GitHub-Repo, sobald du mir den Repo-Namen gibst. Ich kann es hier in der Cloud-Sitzung anhängen und lesen. Vorgaben aus dem Chat schreibe ich als Handoff-Eintrag „(Claude Chat)“ ins Repo oder gebe sie dir zum Einfügen.
- Dieses Briefing liegt zusätzlich als Projekt-Dokument hier, damit jeder neue Chat im Projekt „Pagespeed WordPress“ es automatisch kennt.
- Design-Referenz: Die drei Mockups (`Main.dc.html`, `Umwandlung.dc.html`, `Bericht.dc.html`) gehören nach `design/` ins Repo.

---

## Änderungen

- **2026-10-09 – Testumgebung (§6):** Statt `@wordpress/env` oder Docker läuft die Testumgebung direkt in der Claude-Code-Cloud-Sitzung, eingerichtet per `bin/setup-env.sh` (MariaDB, WP-CLI, WordPress de_DE, Elementor, Hello Elementor, Seed-Daten). Grund: Felix arbeitet ausschließlich in Cloud-Sitzungen, ein lokales Docker gibt es nicht. Siehe ADR-009.
- **2026-10-09 – Ablauf (§7):** Felix verzichtet auf das Okay nach jedem Meilenstein. Claude Code arbeitet M2 bis M5 nacheinander ab, merged selbst nach `main`, sobald CI grün ist, und liefert am Ende eine installierbare ZIP-Datei. Siehe ADR-014.
- **2026-10-09 – FastPixel (§1, §4.1):** Es gilt immer die neueste FastPixel-Version. Nicht jede Seite hat FastPixel oder Bildkomprimierung aktiv. Siehe ADR-017.
- **2026-10-09 – MIME-Typ (§4.3.6):** `post_mime_type` wird direkt per `$wpdb` gesetzt statt über `wp_update_post()`, damit keine Hooks anderer Plugins und kein kses-Filter mitlaufen. Siehe ADR-018.
- **2026-10-09 – Serialisierte Daten (§4.4):** Ersetzt wird mit einem Tokenizer auf dem serialisierten Text statt mit `maybe_unserialize()`, ohne Objekte zu erzeugen. Siehe ADR-019.
- **2026-10-09 – Status (§4.8):** Zusätzliche Status `working`, `converted` und `cancelled` für die Fortsetzbarkeit. Siehe ADR-020.
- **2026-10-09 – Bild löschen (Ergänzung zu §4.7):** Wird ein umgewandeltes Bild aus der Mediathek gelöscht, löscht das Plugin die alten Originale mit, damit keine verwaisten JPG/PNG bleiben. Siehe ADR-024.
