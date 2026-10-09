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
