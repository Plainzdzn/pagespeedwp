# Architektur-Entscheidungen

Dauerhafte Entscheidungen, nummeriert, je 3–5 Zeilen. Neue Einträge unten anhängen, alte nicht umschreiben, sondern bei Bedarf durch einen neuen Eintrag ersetzen („ersetzt ADR-00x“).

Status: **angenommen** (gilt) · **vorgeschlagen** (wartet auf Felix' Okay) · **ersetzt**

---

### ADR-001 – Repository ist die einzige Wahrheit
**Status:** angenommen (Briefing §9) · 2026-10-09
Claude Code und Claude Chat arbeiten parallel. Stand, Vorgaben und Entscheidungen stehen nur im Repo: `CLAUDE.md`, `docs/BRIEFING.md`, `docs/HANDOFF.md`, `docs/DECISIONS.md`. Änderungen an Anforderungen werden mit Datum unten im Briefing angehängt, das Original bleibt unverändert.

### ADR-002 – Ersetzen statt zusätzlich ausliefern
**Status:** angenommen (Briefing §1, §8) · 2026-10-09
Bilder werden in der Mediathek durch WebP ersetzt, die Attachment-ID bleibt gleich, Verweise in der Datenbank werden umgeschrieben. Keine Rewrite-Regeln, keine Auslieferungslogik zur Laufzeit. Nach Deaktivieren und Löschen des Plugins bleibt der umgewandelte Zustand bestehen.

### ADR-003 – Keine Laufzeit-Abhängigkeiten, WPCS-Dateinamen
**Status:** angenommen (Okay zum M1-Plan) · 2026-10-09
Eigener Autoloader statt Composer zur Laufzeit. Er bildet `Akuma\WebpUmwandler\Foo_Bar` auf `includes/class-foo-bar.php` ab und Unter-Namespaces auf Unterordner. So bleibt PHPCS mit der WordPress-Dateinamensregel sauber, ohne Ausnahmen. Composer nur für Dev-Tools (PHPUnit, PHPCS).

### ADR-004 – Eine Kernlogik, mehrere Eingänge
**Status:** angenommen (Briefing §3) · 2026-10-09
Admin-UI (über REST) und WP-CLI rufen dieselben Kernklassen auf (`Scanner`, `Converter`, `Replacer`, `Rollback`, `Report`, `Cache_Purger`). Controller und CLI-Command prüfen nur Rechte, lesen Parameter und formatieren die Ausgabe.

### ADR-005 – Ersetzungslogik ohne WordPress testbar
**Status:** angenommen (Okay zum M1-Plan) · 2026-10-09
URL-Mapping, serialisierungssicheres Ersetzen und JSON-Ersetzen in `_elementor_data` kommen in Klassen ohne Datenbank- und Dateizugriff. Sie lassen sich mit PHPUnit ohne WordPress-Installation testen. Datenbankzugriffe und WordPress-Aufrufe liegen in einer dünnen Schicht darüber, die mit der WP-Testsuite geprüft wird.

### ADR-006 – Fortschritt und Rollback-Zustand in eigener Tabelle
**Status:** angenommen (Briefing §4.8) · 2026-10-09
Status pro Attachment, alte Pfade, alte Metadaten und Byte-Zahlen liegen in `{prefix}akwu_log`, nicht in Postmeta. Läufe sind dadurch fortsetzbar. Folge: Mit dem Löschen des Plugins (Tabelle weg) ist kein Rollback mehr möglich, darauf weist die UI hin.

### ADR-007 – Testumgebung: wp-env, SQLite-Fallback ohne Docker
**Status:** ersetzt durch ADR-009 · 2026-10-09
Standard ist `@wordpress/env` mit Elementor (free) und Seed-Script. In Cloud-Sitzungen ohne Docker-Daemon läuft ein Smoke-Test mit WordPress + SQLite-Datenbank-Integration + WP-CLI + PHP-Builtin-Server. Seed-Bilder werden per GD erzeugt, nicht als Binärdateien eingecheckt.

### ADR-008 – Plugin-Look nur im Plugin-Bereich
**Status:** angenommen (Briefing §5) · 2026-10-09
CSS greift nur innerhalb eines Wrapper-Elements des Plugins, Assets werden nur auf den Plugin-Seiten geladen. Fonts (Outfit, Inter) liegen als WOFF2 mit OFL-Lizenz in `assets/fonts/`. Admin-Notices bleiben WordPress-Standard.

### ADR-009 – Testumgebung in der Cloud-Sitzung (ersetzt ADR-007)
**Status:** angenommen (Felix arbeitet nur in Claude-Code-Cloud-Sitzungen, kein lokales Docker) · 2026-10-09
`bin/setup-env.sh` richtet in jeder Sitzung MariaDB, WP-CLI, WordPress de_DE, Elementor und Hello Elementor ein, bindet das Repo per Symlink als Plugin ein und legt Testdaten an. MariaDB statt SQLite, weil Raidboxes MariaDB nutzt und die WP-Testsuite eine echte Datenbank braucht. Downloads per curl mit Wiederholung, weil WordPress selbst den Proxy der Cloud-Umgebung nicht nutzt.

### ADR-010 – Deutsche Texte direkt im Code
**Status:** angenommen (Felix) · 2026-10-09
Alle Texte stehen deutsch in `__()` und Co., ohne .po/.mo-Dateien. Zahlen und Größen formatiert `Format` immer deutsch („38,4 MB“), unabhängig von der Sprache der Website, damit Text und Zahlen zusammenpassen.

### ADR-011 – Sieben Unterseiten im WordPress-Menü
**Status:** angenommen (Felix) · 2026-10-09
Alle sieben Bereiche sind echte Unterseiten mit eigener URL und Rechteprüfung. WordPress zeigt sie selbst im Menü an, das Plugin blendet nichts aus. Alle nutzen denselben Render-Callback, die aktuelle Seite kommt aus `$plugin_page`.

### ADR-012 – main als Hauptzweig, Arbeit per Pull Request, CI
**Status:** angenommen (Felix) · 2026-10-09
`main` ist der stabile Stand. Jede Sitzung arbeitet auf einem eigenen Branch und öffnet einen Pull Request, der Merge ist Felix' Okay zum Meilenstein. GitHub Actions prüft jeden PR mit PHPCS (PHP 8.3) und PHPUnit samt Syntaxprüfung (PHP 7.4, 8.1, 8.3).

### ADR-013 – Konflikterkennung über geprüfte Plugin-Basenames
**Status:** angenommen · 2026-10-09
Andere Bildoptimierer werden über ihren exakten Basename in `active_plugins` erkannt, geprüft an den Plugins auf wordpress.org. Für FastPixel wird zusätzlich die Stufe aus `fastpixel_images_optimization` gelesen (FastPixel 2.0: 1 Lossy, 2 Glossy, 3 Lossless, Unbekanntes gilt als Lossy). Lossless ist nur ein Hinweis, alles andere eine Warnung. Es wird nie etwas umgestellt.
