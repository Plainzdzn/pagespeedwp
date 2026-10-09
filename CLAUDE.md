# CLAUDE.md – WebP-Umwandler

WordPress-Plugin, das PNG/JPG in der Mediathek **1:1 durch WebP ersetzt, mit derselben Attachment-ID**, alle Verweise in der Datenbank mitzieht und danach ohne Rückstände entfernt werden kann. Für PageSpeed-Optimierung von Elementor-Seiten (Niovo Web Studios, Hosting meist Raidboxes). Vollständige Anforderungen: [`docs/BRIEFING.md`](docs/BRIEFING.md).

## Jede Sitzung so beginnen

1. Diese Datei lesen.
2. Die obersten 3 Einträge in [`docs/HANDOFF.md`](docs/HANDOFF.md) lesen. Einträge „(Claude Chat)“ sind Vorgaben aus der Planung und gelten.
3. Bei Architekturfragen [`docs/DECISIONS.md`](docs/DECISIONS.md) prüfen.

Nach jeder Sitzung: neuen Eintrag **oben** in `docs/HANDOFF.md` (Format steht dort), Uhrzeit in Europe/Berlin. Nach jedem Meilenstein: Pull Request, CI grün, selbst nach `main` mergen und weitermachen (ADR-014, Felix 2026-10-09).

## Kennungen

| Was | Wert |
|---|---|
| Plugin-Slug / Text-Domain | `akuma-webp-umwandler` |
| Prefix (Funktionen, Optionen, Hooks) | `akwu_` |
| PHP-Namespace | `Akuma\WebpUmwandler` |
| Log-Tabelle | `{$wpdb->prefix}akwu_log` |
| Capability | `manage_options` |
| WP-CLI | `wp akwu scan\|convert\|report\|rollback\|purge-originals` |

## Architektur (Stand M2)

- `akuma-webp-umwandler.php` – Bootstrap, Konstanten (`AKWU_VERSION`, `AKWU_DIR`, `AKWU_URL`), eigener Autoloader (keine Composer-Laufzeitabhängigkeit).
- `includes/` – Kernklassen:
  - `Scanner` (Phasen inventory → usage → theme → estimate → finalize, Stand in Option `akwu_scan`), `Inventory`, `Usage_Finder`, `Estimator`, `Scan_Result` (Lesezugriff für UI und CLI).
  - `Url_Matcher` (URLs finden und ersetzen, ADR-015), `Attachment_Files` (Dateien eines Anhangs), `Encoder` + `Imagick_Webp_Editor` (WebP erzeugen), `Png_Info`, `Settings` (Option `akwu_settings`), `Lock` (Sperre `akwu_lock`).
  - Eingänge: `Admin` (Menü, sieben Seiten, Assets), `Rest_Controller` (`akwu/v1`), `Cli` (`wp akwu …`). Admin-UI und WP-CLI rufen **dieselben** Kernklassen auf, keine Logik in Controllern.
  - `System_Check`, `Conflict_Detector`, `Format` (deutsche Zahlen), `View`, `Icons`, `Plugin`.
  - Geplant: `Converter`, `Replacer`, `Rollback`, `Report`, `Cache_Purger`.
- `includes/views/` – Templates, eingebunden über `View::render( $name, $data )`. Im Template steht nur `$data` bereit. Variablen dort nicht wie WP-Globals benennen (`$page`, `$pages`, `$paged`, `$title`, `$status`, `$link`, `$totals`, `$per_page` …), PHPCS meldet das.
- `assets/` – `admin.css` (Design-Tokens als CSS-Variablen unter `.akwu`), `admin.js` (Vanilla JS, REST mit Nonce), `fonts/` (Outfit 500, Inter 400/500/600, WOFF2, OFL).
- `uninstall.php` – löscht nur `akwu_*`-Optionen, Transients und die Log-Tabelle, nie Bilder.
- `design/` – Referenz-Mockups, siehe `design/README.md`.
- `tests/unit/` – PHPUnit ohne WordPress, `tests/seed/seed.php` – Testdaten.
- `bin/setup-env.sh` – Testinstallation in der Cloud-Sitzung, `bin/screenshots.cjs` – Screenshots aller Seiten und Mockups, `bin/build-zip.sh` – installierbare ZIP.

## Harte Regeln

- Nie Dateien überschreiben oder löschen, bevor der Rollback-Zustand gesichert ist.
- Kein Rewrite-/`.htaccess`-Ansatz, keine dauerhafte Auslieferungslogik. Nach Deinstallation bleibt der Zustand.
- Fundstellen, die das Briefing als Warnung führt (Theme-Dateien, Code-Snippets-Tabelle, Customizer-CSS, Custom CSS in Elementor), nur listen, nie ändern, auch wenn sie technisch in der Datenbank liegen.
- `guid` nie anfassen. Serialisierte Daten nie per SQL-`REPLACE()`, `_elementor_data` nur als JSON.
- Keine erfundenen Plugin-APIs. Was nicht im Quellcode des Fremd-Plugins verifiziert ist: TODO in `docs/HANDOFF.md` und Rückfrage.
- Keine externen Requests aus dem Admin außer der optionalen PageSpeed-API. Kein Google-Fonts-CDN.
- Multisite: sauber abbrechen mit Hinweis.
- Vor Abweichungen von Anforderungen nachfragen.

## Konventionen

- WordPress Coding Standards (PHPCS, WPCS 3), keine Fehler. Dateinamen nach WPCS: `Akuma\WebpUmwandler\Scanner` → `includes/class-scanner.php`, `Cache_Purger` → `class-cache-purger.php` (WP-Klassennamen mit Unterstrich).
- PHP-Syntax kompatibel zu 7.4 (keine `match`, Enums, Union-Types, Named Arguments, `readonly`), muss auf 8.1–8.3 laufen. WordPress ≥ 6.0.
- Alle Strings deutsch direkt in `__()` und Co. mit Text-Domain `akuma-webp-umwandler`, keine .po-Dateien (ADR-010). Ruhig und kurz, keine Ausrufezeichen, keine Emojis. Zahlen und Größen über `Format`.
- Jeder AJAX-/REST-Aufruf: Capability-Prüfung und Nonce.
- Plugin-CSS nur innerhalb des Plugin-Wrappers, Assets nur auf Plugin-Seiten laden. WP-Rahmen bleibt Standard.
- Farben: Grün `#1c805d`, dunkel `#176247`, Headline-Akzent `#408062`, Überschriften `#263730`, Text `#53615a`, Linien `#dde0de` / `#e1e4e2`, Flächen `#f8f9f9`. Outfit 500 (letter-spacing −0.03em) für Überschriften, Inter für Text.

## Testen

```bash
composer install                 # Dev-Tools (PHPUnit, PHPCS). Composer-Plugins dürfen fehlen, das Ruleset setzt die Pfade selbst.
composer lint                    # PHPCS, muss ohne Fehler und Warnungen durchlaufen
composer test                    # PHPUnit (Unit-Tests ohne WordPress)
bin/build-zip.sh                 # ZIP aus dem letzten Commit nach dist/

bin/setup-env.sh --serve         # MariaDB, WordPress de_DE, Elementor, Testdaten; Server auf http://localhost:8080 (admin/admin)
NODE_PATH="$(npm root -g)" node bin/screenshots.cjs <ordner>   # Screenshots aller Plugin-Seiten und Mockups
php ~/akwu-env/wp-cli.phar --path=$HOME/akwu-env/wordpress --allow-root <befehl>   # WP-CLI in der Testinstallation, z. B. akwu scan
```

- Die Testumgebung liegt außerhalb des Repos in `~/akwu-env` und geht mit der Cloud-Sitzung verloren. Bei jeder Sitzung neu einrichten, das Script ist wiederholbar.
- Das Plugin ist per Symlink eingebunden. `wp plugin uninstall` nur mit `--skip-delete`, sonst löscht WordPress das Repo.
- Imagick gibt es in der Cloud-Sitzung nicht, getestet wird mit GD.
- CI: GitHub Actions (`.github/workflows/ci.yml`) mit PHPCS und PHPUnit auf PHP 7.4, 8.1 und 8.3.

## Git und Release

`main` ist der stabile Stand. Jede Sitzung arbeitet auf ihrem Branch, öffnet einen Pull Request nach `main` und merged selbst, sobald CI grün ist (ADR-014). Bei jedem Merge baut `.github/workflows/release.yml` die ZIP und legt sie als Release zur Version im Plugin-Header ab. Version deshalb pro Meilenstein erhöhen (Header und `AKWU_VERSION`).
