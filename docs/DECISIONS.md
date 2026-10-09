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
**Status:** vorgeschlagen · 2026-10-09
Eigener Autoloader statt Composer zur Laufzeit. Er bildet `Akuma\WebpUmwandler\Foo_Bar` auf `includes/class-foo-bar.php` ab und Unter-Namespaces auf Unterordner. So bleibt PHPCS mit der WordPress-Dateinamensregel sauber, ohne Ausnahmen. Composer nur für Dev-Tools (PHPUnit, PHPCS).

### ADR-004 – Eine Kernlogik, mehrere Eingänge
**Status:** angenommen (Briefing §3) · 2026-10-09
Admin-UI (über REST) und WP-CLI rufen dieselben Kernklassen auf (`Scanner`, `Converter`, `Replacer`, `Rollback`, `Report`, `Cache_Purger`). Controller und CLI-Command prüfen nur Rechte, lesen Parameter und formatieren die Ausgabe.

### ADR-005 – Ersetzungslogik ohne WordPress testbar
**Status:** vorgeschlagen · 2026-10-09
URL-Mapping, serialisierungssicheres Ersetzen und JSON-Ersetzen in `_elementor_data` kommen in Klassen ohne Datenbank- und Dateizugriff. Sie lassen sich mit PHPUnit ohne WordPress-Installation testen. Datenbankzugriffe und WordPress-Aufrufe liegen in einer dünnen Schicht darüber, die mit der WP-Testsuite geprüft wird.

### ADR-006 – Fortschritt und Rollback-Zustand in eigener Tabelle
**Status:** angenommen (Briefing §4.8) · 2026-10-09
Status pro Attachment, alte Pfade, alte Metadaten und Byte-Zahlen liegen in `{prefix}akwu_log`, nicht in Postmeta. Läufe sind dadurch fortsetzbar. Folge: Mit dem Löschen des Plugins (Tabelle weg) ist kein Rollback mehr möglich, darauf weist die UI hin.

### ADR-007 – Testumgebung: wp-env, SQLite-Fallback ohne Docker
**Status:** vorgeschlagen · 2026-10-09
Standard ist `@wordpress/env` mit Elementor (free) und Seed-Script. In Cloud-Sitzungen ohne Docker-Daemon läuft ein Smoke-Test mit WordPress + SQLite-Datenbank-Integration + WP-CLI + PHP-Builtin-Server. Seed-Bilder werden per GD erzeugt, nicht als Binärdateien eingecheckt.

### ADR-008 – Plugin-Look nur im Plugin-Bereich
**Status:** angenommen (Briefing §5) · 2026-10-09
CSS greift nur innerhalb eines Wrapper-Elements des Plugins, Assets werden nur auf den Plugin-Seiten geladen. Fonts (Outfit, Inter) liegen als WOFF2 mit OFL-Lizenz in `assets/fonts/`. Admin-Notices bleiben WordPress-Standard.
