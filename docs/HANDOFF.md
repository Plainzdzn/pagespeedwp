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
