# CLAUDE.md – WebP-Umwandler

WordPress-Plugin, das PNG/JPG in der Mediathek **1:1 durch WebP ersetzt, mit derselben Attachment-ID**, alle Verweise in der Datenbank mitzieht und danach ohne Rückstände entfernt werden kann. Für PageSpeed-Optimierung von Elementor-Seiten (Niovo Web Studios, Hosting meist Raidboxes). Vollständige Anforderungen: [`docs/BRIEFING.md`](docs/BRIEFING.md).

## Jede Sitzung so beginnen

1. Diese Datei lesen.
2. Die obersten 3 Einträge in [`docs/HANDOFF.md`](docs/HANDOFF.md) lesen. Einträge „(Claude Chat)“ sind Vorgaben aus der Planung und gelten.
3. Bei Architekturfragen [`docs/DECISIONS.md`](docs/DECISIONS.md) prüfen.

Nach jeder Sitzung: neuen Eintrag **oben** in `docs/HANDOFF.md` (Format steht dort), Uhrzeit in Europe/Berlin. Nach jedem Meilenstein: committen, zusammenfassen, auf Felix' Okay warten.

## Kennungen

| Was | Wert |
|---|---|
| Plugin-Slug / Text-Domain | `akuma-webp-umwandler` |
| Prefix (Funktionen, Optionen, Hooks) | `akwu_` |
| PHP-Namespace | `Akuma\WebpUmwandler` |
| Log-Tabelle | `{$wpdb->prefix}akwu_log` |
| Capability | `manage_options` |
| WP-CLI | `wp akwu scan\|convert\|report\|rollback\|purge-originals` |

## Architektur (geplant, Stand M0)

- `akuma-webp-umwandler.php` – Bootstrap, Konstanten, eigener Autoloader (keine Composer-Laufzeitabhängigkeit).
- `includes/` – Kernklassen: `Scanner`, `Converter`, `Replacer`, `Rollback`, `Report`, `Cache_Purger`, `System_Check`; Eingänge `Rest_Controller`, `Admin`, `Cli`. Admin-UI und WP-CLI rufen **dieselben** Kernklassen auf, keine Logik in Controllern.
- `assets/` – `admin.css`, `admin.js` (Vanilla JS, kein Build), `fonts/` (Outfit, Inter lokal, WOFF2).
- `uninstall.php` – löscht nur Plugin-Optionen und die Log-Tabelle, nie Bilder.
- `design/` – Referenz-Mockups (Main, Umwandlung, Bericht), siehe `design/README.md`.
- `tests/` – PHPUnit, Seed-Script für Testdaten.

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
- Alle Strings übersetzbar mit Text-Domain `akuma-webp-umwandler`. UI-Sprache Deutsch, ruhig und kurz, keine Ausrufezeichen, keine Emojis.
- Jeder AJAX-/REST-Aufruf: Capability-Prüfung und Nonce.
- Plugin-CSS nur innerhalb des Plugin-Wrappers, Assets nur auf Plugin-Seiten laden. WP-Rahmen bleibt Standard.
- Farben: Grün `#1c805d`, dunkel `#176247`, Headline-Akzent `#408062`, Überschriften `#263730`, Text `#53615a`, Linien `#dde0de` / `#e1e4e2`, Flächen `#f8f9f9`. Outfit 500 (letter-spacing −0.03em) für Überschriften, Inter für Text.

## Testen

Noch keine Tests (M0). Ab M1 kommen hier die Befehle für Testumgebung, Seed-Daten, PHPUnit und PHPCS hin.
