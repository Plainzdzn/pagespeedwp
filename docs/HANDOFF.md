# Handoff-Logbuch

Nach jeder Arbeitssitzung oben einen neuen Eintrag anlegen, **neueste zuerst**. Uhrzeit in Europe/Berlin. Einträge „(Claude Chat)“ sind Vorgaben aus der Planung und gelten für Claude Code.

```
## YYYY-MM-DD HH:MM – <Kurztitel> (Claude Code | Claude Chat)
**Stand:** M<x> – <offen/fertig>
**Erledigt:** …
**Entscheidungen:** … (mit kurzer Begründung)
**Offen / Nächster Schritt:** …
**Fragen an Felix:** …
**Getestet:** … (was, wo, Ergebnis)
```

---

## 2026-10-09 20:10 – M3 Umwandlung (Claude Code)

**Stand:** M3 – fertig (Version 0.3.0). M4 folgt direkt.

**Erledigt:**
- M2 gemergt ([Plainzdzn/pagespeedwp#2](https://github.com/Plainzdzn/pagespeedwp/pull/2)).
- **Umwandlung** (`Conversion`, in Paketen per REST oder `wp akwu convert`):
  - Je Bild (`Converter`): Zustand in `akwu_log` sichern, WebP erzeugen, bei zu wenig Ersparnis überspringen, Anhang mit derselben ID umstellen, alle Größen neu erzeugen, `Url_Map` alt → neu je Größenname. Namenskollision `bild.png` + `bild.jpg` ergibt `bild.webp` und `bild-1.webp`.
  - Verweise je Paket (`Replacer`, `Value_Replacer`): Beiträge (Inhalt, Auszug), Postmeta, Termmeta, Optionen. Serialisiert ohne unserialize, `_elementor_data` als JSON. Nie `guid`, nie `custom_css`, nie Customizer-CSS, Snippets oder Theme-Dateien.
  - Abschluss: Elementor-CSS und Caches (`Cache_Purger`), dann Gegenprobe nach alten Adressen.
  - Pausieren, Fortsetzen, „Abbrechen und zurücksetzen“ (nimmt alle Bilder des Laufs zurück). Abgebrochene Requests werden beim nächsten Paket aufgeräumt, ein Bild, das zweimal abbricht, wird übersprungen.
- **Oberfläche:** Umwandlungsseite wie im Mockup (Fortschritt, Kacheln mit „Bild-IDs verändert“, Ablauf, Live-Protokoll), nach dem Lauf „Erneuert“ und „Gegenprobe“. Knöpfe in der Kopfzeile, Fortschritt in der Admin-Leiste, Hinweis auf allen Plugin-Seiten, solange ein Lauf offen ist. Auf der Übersicht „Erst 10 testen“ (die 10 meistgenutzten Bilder) und „Umwandlung starten“, beides nur mit Backup-Häkchen und bestandener Systemprüfung.
- **WP-CLI:** `wp akwu convert [--dry-run] [--limit=<n>] [--ids=<ids>] [--test] [--resume] [--yes]`.

**Entscheidungen:** ADR-018 bis ADR-021, im Briefing unter „Änderungen“ vermerkt:
- MIME-Typ direkt per `$wpdb` statt `wp_update_post()` (keine fremden Hooks, kein kses).
- Serialisierte Werte per Tokenizer statt `maybe_unserialize()`.
- Zusätzliche Status `working`, `converted`, `cancelled` für die Fortsetzbarkeit.
- Verweise je Paket statt am Ende, damit die Seite auch bei Abbruch nie auf fehlende Dateien zeigt.

**TODO (nicht verifiziert):** Raidboxes-Server-Cache ohne FastPixel. Dafür gibt es keine dokumentierte Schnittstelle. Das Plugin zeigt auf Raidboxes ohne FastPixel einen Hinweis, den Cache im Dashboard zu leeren (Erkennung: Hostname `box-…` und Ordner `rb-plugins`). FastPixel leert den Raidboxes-Cache laut eigener Doku mit.

**Offen / Nächster Schritt:** M4 – Bericht mit CSV, Rückgängig (alles oder je Bild), Originale löschen, `wp akwu report|rollback|purge-originals`.

**Fragen an Felix:** keine.

**Getestet** (Cloud-Sitzung, WordPress 7.1.3, Elementor 4.3.4, GD):
- PHPCS sauber, PHPUnit 59 Tests grün (neu: `Url_Map`, `Value_Replacer` mit serialisierten, verschachtelten und JSON-Werten).
- `tests/integration/roundtrip.php`: Seed, Scan, alle 6 Bilder umwandeln, prüfen (IDs gleich, WebP-Dateien da, `_elementor_data` gültig und ersetzt, Custom CSS und Customizer-CSS unverändert, Theme-Mod ersetzt, Gegenprobe ohne unerwartete Reste, alle Bilder der Testseite abrufbar), dann alles zurück: Beiträge, Postmeta, Theme-Mods, Customizer-CSS und Upload-Ordner Byte für Byte wie vorher. Bestanden.
- Im Browser (Playwright, Paketgröße 1): Start ohne Backup-Häkchen wird abgelehnt, Start, Pausieren nach dem ersten Paket, Hinweis auf anderen Seiten, Fortsetzen, Abbrechen (3 Bilder zurückgesetzt, 3 nie begonnen), neuer Lauf bis „Fertig“, Admin-Leiste zeigt den Fortschritt. Keine Konsolenfehler.
- `wp akwu convert --dry-run`, `--test --yes`, `--ids` mit ungültigen IDs, `--resume` ohne offenen Lauf.
- Screenshots: `docs/screenshots/m3/`.

---

## 2026-10-09 19:36 – M2 Scan (Claude Code)

**Stand:** M2 – fertig (Version 0.2.0). M3 folgt direkt.

**Vorgaben von Felix (nach M1):** M1 mergen, dann ohne Zwischenstopp weiterarbeiten und selbst nach `main` mergen. Ziel ist eine ZIP, die er nur noch installiert (ADR-014). Den Standard-Branch muss niemand umstellen. FastPixel: Es gilt immer die neueste Version, nicht jede Seite hat FastPixel oder Bildkomprimierung aktiv (ADR-017).

**Erledigt:**
- M1 gemergt ([Plainzdzn/pagespeedwp#1](https://github.com/Plainzdzn/pagespeedwp/pull/1)).
- **Scan** (`Scanner`, nur lesend, in Schritten per REST oder `wp akwu scan`):
  - Bestandsaufnahme mit Original, `-scaled`, allen Größen und eindeutig zuordenbaren Elementor-Thumbs. PNG-Transparenz liest `Png_Info` aus dem Dateikopf.
  - Verwendung: Beiträge (Inhalt, Auszug), Postmeta (`_elementor_data` als JSON, `_elementor_page_settings` strukturiert, sonst roh), Optionen, Termmeta, Beitragsbilder und Logo über die ID, Elementor-4-Atomic-Bilder über die ID.
  - Warnungen: Customizer-CSS, Custom CSS in Elementor, Code-Snippets-Tabelle, Theme-Dateien.
  - Hochrechnung aus bis zu 20 Stichproben, gemischt nach JPG, PNG, PNG mit Transparenz und vom kleinsten bis zum größten Bild. Die Umwandlung läuft dabei in ein temporäres Verzeichnis.
- **Encoder** nach Briefing §4.3: JPG und PNG ohne Transparenz mit Qualität 82, PNG mit Transparenz mit Imagick verlustfrei, sonst 90. EXIF-Drehung wird angewendet, Metadaten entfernt (Imagick).
- **Oberfläche:**
  - Übersicht mit echten Zahlen: Kennzahlen, Verteilung nach Format, größte Dateien, „Vor dem Start“ mit Warnungen und Speicher, Scan-Start mit Fortschritt.
  - „Alle Bilder“ mit Filtern, Fundstellen zum Aufklappen und Links zum Bearbeiten.
  - Einstellungen als Formular. Die Systemprüfung vergleicht den freien Speicher mit dem 1,5-Fachen.
- **Sperre** `Lock` gegen parallele Läufe (Admin, zweites Fenster, WP-CLI).
- **Release:** `bin/build-zip.sh` und Workflow `release.yml` (ZIP als GitHub-Release je Version).

**Entscheidungen:** ADR-014 bis ADR-017. Dazu:
- Fremde Hosts werden nie ersetzt (ADR-015). Staging mit Live-URLs im Inhalt bleibt unverändert.
- Gezählt werden Verweise auf Größen, die nicht in den Metadaten stehen (z. B. `bild-640x480.png` nach einem Theme-Wechsel). Sie landen unter „Bitte prüfen“.
- Elementor-Caches (`_elementor_css`, `_elementor_element_cache`, `_elementor_page_assets`) zählen nicht als Verwendung, weil `clear_cache()` sie nach der Umwandlung löscht (am Elementor-Quellcode 4.3.4 geprüft).
- Code Snippets: Tabelle `{prefix}snippets` mit `id`, `name`, `code`. Der Bearbeiten-Link kommt über `code_snippets()->get_menu_url( 'edit' )`, beides am Plugin-Quellcode geprüft.

**Offen / Nächster Schritt:** M3 – Umwandlung, Replacer, Log-Tabelle, Pakete, „Erst 10 testen“, `wp akwu convert`.

**Fragen an Felix:** keine.

**Getestet** (Cloud-Sitzung, WordPress 7.1.3, Elementor 4.3.4, GD):
- PHPCS sauber, PHPUnit 44 Tests grün (neu: `Url_Matcher`, `Png_Info`, `Settings`, `Estimator`).
- Scan gegen die Seed-Daten: Alle Fundstellen des Seeds werden erkannt (Section-, Container- und Kit-Hintergrund, Galerie, Text-Editor, Bildblock samt Link, Auszug, serialisiertes Postmeta, Theme-Mod). Customizer-CSS und Elementor-Custom-CSS erscheinen als Warnung. Die bereits komprimierte JPG wird als „Überspringen“ erkannt (WebP wäre 2 % größer).
- Im Browser: Scan per Knopf (2 s), Übersicht, „Alle Bilder“ mit Filter, Einstellungen speichern. Keine Konsolenfehler.
- `wp akwu scan` mit Zusammenfassung und Warnungsliste.

---

## 2026-10-09 19:05 – M1 Grundgerüst (Claude Code)

**Stand:** M1 – fertig, wartet auf Okay (Pull Request nach `main`)

**Antworten von Felix auf die Fragen vom 17:30-Eintrag:**
- Alle sieben Unterseiten umsetzen, WordPress zeigt sie selbst im Menü an (ADR-011).
- Alles auf Deutsch (ADR-010).
- Kein lokales Docker, Arbeit nur in Claude-Code-Cloud-Sitzungen. Die Testumgebung läuft deshalb in der Sitzung (ADR-009, Änderung am Briefing §6).
- CI: keine Vorgabe. Eingerichtet mit GitHub Actions, weil es ohne Aufwand jeden Pull Request prüft (ADR-012).
- `main` anlegen: erledigt, Arbeit ab jetzt per Pull Request.

**Erledigt:**
- Bootstrap mit eigenem Autoloader, Version 0.1.0. Multisite: Die Aktivierung bricht ab (pro Seite und netzwerkweit). Ist das Plugin trotzdem aktiv, erscheint nur ein Hinweis.
- Menü „WebP-Umwandler“ mit sieben Unterseiten im Layout der Mockups: Kopfzeile, WordPress-Hinweise, Panel mit eigener Navigation.
  - Übersicht: Gerüst der Kennzahlen, „Vor dem Start“ mit echten Prüfwerten und Backup-Checkbox, Start-Bereich (Button gesperrt bis M2).
  - Umwandlung: Fortschrittsbalken mit `role="progressbar"`, Ablauf in vier Schritten.
  - Einstellungen: die geplanten Standardwerte, noch nicht bearbeitbar.
  - Systemprüfung: vollständig.
  - Bericht, Alle Bilder, Rückgängig: Platzhalter „Folgt mit Mx“. Rückgängig mit Hinweis, dass nach dem Löschen des Plugins kein Rückgängig mehr geht.
- Systemprüfung (`System_Check`): WebP-Support und Editor (Imagick oder GD, mit Version), Upload-Ordner, freier Speicher, Laufzeit, Arbeitsspeicher für Bildbearbeitung, PHP- und WP-Version, Elementor, andere Bildoptimierer. Der Start ist nur gesperrt, wenn WebP fehlt oder der Upload-Ordner nicht beschreibbar ist.
- Konflikterkennung (`Conflict_Detector`): acht Plugins, Basenames an den aktuellen Versionen auf wordpress.org geprüft, FastPixel mit Stufe (ADR-013).
- Look: `assets/admin.css` mit Design-Tokens, nur unter `.akwu` und nur auf Plugin-Seiten geladen. Outfit 500 und Inter 400/500/600 lokal (Fontsource 5.3.0, OFL).
- `uninstall.php`: löscht `akwu_*`-Optionen, Transients und die Log-Tabelle.
- Testumgebung `bin/setup-env.sh`, Testdaten `tests/seed/seed.php`, Screenshots `bin/screenshots.cjs`. Befehle stehen in `CLAUDE.md`.
- Dev-Tools: Composer nur für Dev, PHPCS mit WPCS 3 und PHPCompatibilityWP, PHPUnit 9.6 (22 Tests), GitHub Actions.

**Entscheidungen:**
- ADR-003 und ADR-005 angenommen, ADR-007 durch ADR-009 ersetzt, neu ADR-010 bis ADR-013.
- Die Testbilder werden direkt in `uploads/2019/05` geschrieben und als Anhang registriert, nicht hochgeladen. So entsteht die Namenskollision `bild.png` + `bild.jpg` wie auf älteren Seiten, unabhängig davon, wie WordPress beim Upload Namen vergibt.
- Die Backup-Checkbox hat bis M3 keine Funktion. Die Pflichtprüfung kommt mit dem Start der Umwandlung.
- Der WordPress-Menüpunkt bleibt im Standard-Farbschema, obwohl das Mockup ihn grün zeigt. Das Briefing sagt: WP-Rahmen bleibt Standard.

**Erkenntnisse für die nächsten Meilensteine:**
- **FastPixel 2.0** hat keine Stufe „aus“ für die Bildkomprimierung. Die Option `fastpixel_images_optimization` kennt 1 Lossy (Standard), 2 Glossy und 3 Lossless, andere Werte behandelt FastPixel als Lossy. Bilder liefert FastPixel immer über sein CDN als WebP aus. Die Systemprüfung warnt bei Lossy und Glossy, bei Lossless gibt sie nur einen Hinweis.
- **Elementor 4.3.4** ist aktuell. Neben Sections und Containern gibt es „Atomic“-Elemente (`modules/atomic-widgets`), die Bilder als typisierte Werte speichern (`$$type`: `image`, `image-src`, `image-attachment-id`). Scan und Replacer müssen beide Formate kennen. Das Format vor M2 am Elementor-Quellcode prüfen.
- In der Cloud-Sitzung erreicht die WordPress-HTTP-API das Internet nicht (Proxy), deshalb laufen alle Downloads per curl. Die PageSpeed-API (M5) lässt sich deshalb hier nicht testen, auf Raidboxes betrifft das nicht.

**Offen / Nächster Schritt – M2 (Scan):**
- Bestandsaufnahme: Größen inklusive `original_image` und Elementor-Thumbs.
- Hochrechnung per Stichprobe.
- Verwendung ermitteln: `post_content`, `_elementor_data` als JSON (inklusive Atomic-Format), Page-Settings, Kit, Theme-Mods, Options, sonstige Postmeta.
- Warnungen: Customizer-CSS, Code Snippets, Theme-Dateien, Elementor-Custom-CSS.
- Ergebnis mit Zeitstempel speichern. Übersicht und „Alle Bilder“ mit echten Daten. Speicherplatz-Abgleich in der Systemprüfung.

**Fragen an Felix:**
1. **FastPixel:** Welche Version und welche Stufe der Bildkomprimierung laufen auf den Seiten? In Version 2.0 lässt sich die Komprimierung nicht ausschalten. Bei „Lossy“ oder „Glossy“ wird unser WebP noch einmal verlustbehaftet komprimiert. Vorschlag: auf „Lossless“ stellen.
2. **Standard-Branch:** Bitte auf GitHub unter Settings → General → Default branch auf `main` umstellen. Das kann ich von hier nicht. Im Moment ist noch `claude/new-session-02wmh1` der Standard.
3. **Imagick auf Raidboxes (optional):** Wenn du das Plugin auf einer Staging-Seite installierst, zeigt die Systemprüfung, ob Imagick verfügbar ist. Davon hängt ab, ob PNG mit Transparenz verlustfrei gespeichert wird.

**Getestet** (Cloud-Sitzung: WordPress 7.1.3 de_DE, PHP 8.3.6 mit GD, MariaDB 10.11, Elementor 4.3.4, Hello Elementor):
- `composer lint`: keine Fehler, keine Warnungen. `composer test`: 22 Tests, 43 Assertions, alle grün.
- Setup-Script zweimal hintereinander: Der zweite Lauf ersetzt die Testdaten sauber.
- Alle sieben Seiten im Browser (Playwright): laden, keine Konsolenfehler, keine 4xx/5xx-Antworten, Schriften lokal geladen. Auf Dashboard und Mediathek wird kein Plugin-CSS geladen.
- Screenshots gegen die Mockups verglichen: Kopfzeile, Navigation, Kennzahlen, „Vor dem Start“ und Start-Bereich entsprechen dem Design. Drei Screenshots liegen in `docs/screenshots/m1/`.
- Konflikte simuliert (FastPixel Stufe Glossy und Smush in `active_plugins`): je eine Warnung in Systemprüfung und Übersicht.
- Deinstallation (`wp plugin uninstall --skip-delete`): `akwu_*`-Option, Transient und Tabelle `wp_akwu_log` sind weg. Eine fremde Option und die Bilddateien (md5) sind unverändert.
- Multisite (eigene Testinstallation): Die Aktivierung pro Seite und netzwerkweit bricht mit Meldung ab. Zwangsweise aktiviert erscheint nur der Hinweis, die Admin-Klasse wird nicht geladen.
- Nicht getestet: PHP 7.4 (läuft in CI), Imagick (in der Cloud-Sitzung nicht vorhanden), eine echte FastPixel-Installation (nur simuliert).

---

## 2026-10-09 17:30 – Repo-Grundgerüst, Kontextdateien, Plan M1 (Claude Code)

**Stand:** M0 (Vorbereitung) – fertig · M1 – offen, wartet auf Okay

**Erledigt:**
- Kontextdateien angelegt: `CLAUDE.md`, `docs/BRIEFING.md` (unverändert übernommen), `docs/HANDOFF.md`, `docs/DECISIONS.md` (ADR-001 bis ADR-008).
- Mockups nach `design/` übernommen. `design/README.md` listet Details aus den Mockups, die über das Briefing hinausgehen (z. B. Fortschritt in der Admin-Bar, „Pausieren“, Typ „Externe Einbindung“ im Bericht).
- Repo-Dateien: `README.md`, `.gitignore`, `.gitattributes` (Dev-Dateien per `export-ignore` nicht im Plugin-ZIP), `.editorconfig`.
- Noch kein Plugin-Code, das Plugin-Grundgerüst ist M1.

**Entscheidungen:**
- Startversion 0.1.0, passend zum „v0.1“ im Mockup.
- Klassennamen im WordPress-Stil mit Unterstrich (`Cache_Purger`) und Dateinamen nach WPCS (`class-cache-purger.php`). So bleibt PHPCS ohne Ausnahmen sauber (ADR-003, vorgeschlagen).
- Ersetzungslogik in Klassen ohne WordPress-Abhängigkeit, damit die Pflicht-Tests aus §6 ohne Datenbank laufen (ADR-005, vorgeschlagen).
- Zeitangaben im Handoff in Europe/Berlin.

**Offen / Nächster Schritt – Plan M1 (Grundgerüst):**
1. **Bootstrap:** `akuma-webp-umwandler.php` mit Plugin-Header (WP ≥ 6.0, PHP ≥ 7.4), Konstanten, Autoloader, Klasse `Plugin`. Bei Multisite nur eine Admin-Notice, sonst nichts registrieren. Netzwerkweite Aktivierung mit Hinweis abbrechen. `uninstall.php` als Gerüst, löscht nur `akwu_*`-Optionen.
2. **Menü und Seiten:** Menüpunkt „WebP-Umwandler“ (`manage_options`) mit sieben Unterseiten: Übersicht, Umwandlung, Bericht, Alle Bilder, Einstellungen, Systemprüfung, Rückgängig. Gemeinsames Layout nach `design/Main.dc.html`: Kopfzeile mit Logo, Name, Version und „von Akuma Digital“, WordPress-Notices darunter, Panel mit eigener Navigation. Inhalte zunächst leer mit kurzem Platzhaltertext.
3. **Look:** `assets/admin.css` mit den Farben als CSS-Variablen, alles unter dem Plugin-Wrapper. Outfit und Inter als WOFF2 (Latin, nur benötigte Schnitte) mit OFL-Lizenz in `assets/fonts/`, einmalig aus den Fontsource-Paketen geholt und eingecheckt. Fokus sichtbar, echte Buttons und Labels.
4. **Systemprüfung** (`System_Check`, eigene Seite plus Kurzfassung „Vor dem Start“ auf der Übersicht):
   - WebP-Support über `wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) )`. Welcher Editor greift (Imagick bevorzugt, GD als Fallback), ermittle ich über die öffentlichen statischen Methoden `test()` und `supports_mime_type()` der Core-Editor-Klassen. Ohne WebP-Support ist der Start gesperrt, mit klarer Meldung.
   - PHP- und WP-Version, Schreibrechte im Upload-Ordner, freier Speicher (`disk_free_space`, falls verfügbar), `max_execution_time`, Memory-Limit. Den Vergleich „freier Speicher ≥ Originalgröße × 1,5“ gibt es erst mit den Scan-Daten in M2.
   - Konflikte mit aktiven Bildoptimierern erkennen: WebP Express, Converter for Media, Imagify, ShortPixel, EWWW, Smush, Optimole, FastPixel. Die Plugin-Slugs und die FastPixel-Einstellung für Bildkomprimierung prüfe ich vor dem Einbau im Quellcode der Plugins von wordpress.org. Was sich nicht verifizieren lässt, kommt hier als TODO rein. Es wird nur gewarnt, nichts umgestellt.
   - Checkbox „Backup ist erstellt“ im UI. Die serverseitige Pflichtprüfung beim Start kommt in M3 mit der Umwandlung.
5. **Testumgebung:**
   - `.wp-env.json` (aktuelles WordPress, PHP 8.1, Elementor free, dieses Plugin) und `package.json` mit Scripts für Start, Stopp und Seed.
   - Seed-Script `tests/seed/seed.php` (per `wp eval-file`, wiederholbar). Es erzeugt Testbilder per GD: PNG mit und ohne Transparenz, große JPG, ein Bild über 2560 px (ergibt `-scaled`), Namenskollision `bild.png` + `bild.jpg`. Dazu eine Elementor-Seite mit Image-Widget, Container-Hintergrundbild, Galerie und Bild im Text-Editor sowie eine Bild-URL im Customizer-CSS.
   - Dev-Tools: `composer.json` nur mit `require-dev` (PHPUnit 9.6, PHPUnit-Polyfills, WPCS 3, PHPCompatibilityWP), `phpcs.xml.dist` (WordPress-Regeln, Text-Domain, Prefixe, `testVersion 7.4-`), `phpunit.xml.dist`.
6. **Abschluss M1:** PHPCS ohne Fehler. Smoke-Test: Plugin aktivieren, alle sieben Seiten laden, Systemprüfung zeigt plausible Werte. Screenshots der Seiten gegen die Mockups prüfen. Handoff-Eintrag, Commit.

**Hinweis für M3, meine Lesart des Briefings (bitte widersprechen, falls falsch):** Customizer-CSS liegt technisch in `posts.post_content` (Post-Typ `custom_css`), Elementor-Custom-CSS in `_elementor_data` bzw. `_elementor_page_settings`. Laut §4.2, §8, der Seed-Vorgabe in §6 und dem Bericht-Mockup sollen diese Stellen nur gelistet werden. Der Replacer überspringt deshalb den Post-Typ `custom_css` und `custom_css`-Schlüssel in Elementor-Daten, obwohl er sonst `post_content` und Postmeta ersetzt.

**Fragen an Felix:**
1. **WP-Untermenü:** Das Briefing nennt sieben Unterseiten, das Mockup zeigt im WordPress-Menü nur Übersicht, Bericht und Einstellungen. Vorschlag: alle sieben als echte Seiten registrieren (eigene URL, Rechteprüfung), im WordPress-Menü aber nur die drei aus dem Mockup zeigen. Die übrigen sind über die Plugin-Navigation erreichbar. Okay?
2. **Quellsprache der Strings:** Vorschlag: deutsche Texte direkt in `__()`, ohne .po-Datei, weil die Oberfläche nur auf Deutsch gebraucht wird. Alternative nach WordPress-Konvention: englische Quelltexte plus deutsche Übersetzung. Das ist mehr Aufwand, funktioniert dafür auch bei englischer Admin-Sprache sauber.
3. **Testumgebung:** Läuft bei dir lokal Docker für wp-env? In dieser Cloud-Sitzung gibt es keinen Docker-Daemon, hier teste ich mit WordPress + SQLite + WP-CLI (ADR-007).
4. **CI:** Soll ich eine GitHub Action einrichten, die PHPCS und PHPUnit auf PHP 7.4 und 8.3 ausführt?
5. **Branch:** Das Repo war leer, die Arbeit liegt auf `claude/new-session-02wmh1`. Soll daraus `main` werden, und künftige Sitzungen gehen per Pull Request darauf?

**Getestet:**
- Cloud-Umgebung: PHP 8.3.6 mit GD (WebP-Support ja), kein Imagick. Composer, Node 22 und npm vorhanden. Docker-CLI vorhanden, aber kein Daemon. Zugriff auf packagist, npm und wordpress.org funktioniert.
- `docs/BRIEFING.md` per `cmp` gegen die gelieferte Datei geprüft: identisch.
- Noch kein Code, daher keine Tests.
