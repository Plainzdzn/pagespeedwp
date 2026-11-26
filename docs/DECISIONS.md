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

### ADR-014 – Meilensteine ohne Zwischenstopp, Release-ZIP
**Status:** angenommen (Felix) · 2026-10-09
Claude Code arbeitet die Meilensteine nacheinander ab, ohne auf ein Okay zu warten: Pull Request, CI grün, dann selbst nach `main` mergen. Ziel ist eine ZIP-Datei, die Felix nur noch installiert. Der Workflow `release.yml` baut bei jedem Merge nach `main` die ZIP (`git archive`, ohne Dev-Dateien) und legt sie als GitHub-Release zur Version im Plugin-Header ab. Lokal baut `bin/build-zip.sh` dieselbe Datei.

### ADR-015 – URLs erkennen und ersetzen über den Pfad im Upload-Ordner
**Status:** angenommen · 2026-10-09
`Url_Matcher` erkennt Upload-URLs per regulärem Ausdruck (absolut, protokollrelativ, relativ, JSON-Slashes, URL-kodiert) nur für den eigenen Host und dessen www-Variante. Gefunden wird der Pfad im Upload-Ordner (`2019/05/bild.png`), der über einen Index aller Dateien dem Anhang zugeordnet wird. Beim Ersetzen wird nur dieser Pfad getauscht, Protokoll, Host und Schreibweise bleiben. URLs fremder Hosts (z. B. Live-Domain auf Staging) werden nie geändert.

### ADR-016 – Scan-Ergebnis in einer Option
**Status:** angenommen · 2026-10-09
Stand und Ergebnis des Scans liegen in der Option `akwu_scan` (ohne Autoload): Datensätze je Bild mit Dateien, Größen, bis zu 25 Fundstellen, Warnungen und Hochrechnung. Für Mediatheken mit einigen tausend Bildern reicht das, eine eigene Tabelle wäre mehr Aufwand ohne Nutzen. Die Log-Tabelle `akwu_log` bleibt für die Umwandlung (ADR-006).

### ADR-017 – FastPixel: immer die neueste Version
**Status:** angenommen (Felix) · 2026-10-09
Ist FastPixel installiert, gilt die neueste Version (2.0.0, Stand 2026-10-09). Dort gibt es keine Stufe „aus“ für die Bildkomprimierung, nur Lossy, Glossy und Lossless. Ohne FastPixel gibt es keine Warnung, bei Lossless nur einen Hinweis, sonst eine Warnung.


### ADR-018 – MIME-Typ direkt in der Datenbank setzen
**Status:** angenommen · 2026-10-09
Abweichend von Briefing §4.3.6 setzt der `Converter` `post_mime_type` per `$wpdb->update()` und leert danach den Beitrags-Cache, statt `wp_update_post()` zu nutzen. `wp_update_post()` würde `save_post` und Co. auslösen (andere Plugins speichern dann mit) und den Inhalt des Anhangs je nach Benutzerrecht durch kses filtern. Ergebnis ist dasselbe: gleiche ID, MIME `image/webp`, `guid` unberührt. Datei-Pointer und Metadaten laufen weiter über `update_attached_file()` und `wp_generate_attachment_metadata()`.

### ADR-019 – Serialisierte Werte als Text umschreiben, ohne unserialize
**Status:** angenommen · 2026-10-09
`Value_Replacer` liest serialisierte Werte mit einem eigenen Tokenizer und ersetzt nur in Strings, die Längenangaben werden neu berechnet. Es wird nichts deserialisiert, damit keine Objekte fremder Klassen entstehen (`__wakeup`, `__PHP_Incomplete_Class`) und alles außerhalb der ersetzten Strings Byte für Byte gleich bleibt. Erfüllt Briefing §4.4 (nie `REPLACE()` auf serialisierte Daten). JSON (`_elementor_data`) wird decodiert, ersetzt und mit `wp_json_encode()` direkt geschrieben, ohne `wp_slash()`, weil der Weg nicht über `update_post_meta()` läuft. Der Schlüssel `custom_css` bleibt überall unangetastet.

### ADR-020 – Status je Bild und Ablauf eines Laufs
**Status:** angenommen · 2026-10-09
Zusätzlich zu den Status aus Briefing §4.8 gibt es `working` (Zustand gesichert, Umwandlung begonnen), `converted` (Anhang umgestellt, Verweise noch offen) und `cancelled` (nie begonnen, Lauf abgebrochen). Ein abgebrochener Request wird beim nächsten Schritt aufgeräumt: `working` wird aus dem gesicherten Zustand wiederhergestellt und einmal neu versucht, beim zweiten Abbruch übersprungen (`error`). `converted` bekommt seine Verweise nachgeholt. Der Lauf selbst liegt in der Option `akwu_run` (Phasen convert → finalize → verify → done, beim Abbrechen rollback). Pausieren und Abbrechen aus einem anderen Request gehen nicht verloren, weil jeder Schritt vor dem Speichern den Status frisch liest.

### ADR-021 – Verweise je Paket ersetzen, Gegenprobe am Ende
**Status:** angenommen · 2026-10-09
Verweise werden direkt nach jedem Paket ersetzt, nicht erst am Ende. So zeigt die Website auch bei einem abgebrochenen Lauf nie auf fehlende Dateien, und das Rückgängig betrifft immer ganze Bilder. SQL `LIKE` filtert vor (`%dateiname%.endung%`). Danach erneuern `Cache_Purger` Elementor-CSS und Caches, und die Gegenprobe sucht mit dem `Usage_Finder` des Scans nach alten Adressen der umgewandelten Bilder. Treffer über die ID (Beitragsbild, Logo, Elementor-4-Bilder) zählen nicht, sie zeigen von selbst auf das WebP.

### ADR-022 – Bericht über alle Läufe
**Status:** angenommen · 2026-10-09
Der Bericht (`Report`) nimmt je Anhang die letzte Zeile im Protokoll, ohne `pending` und `cancelled`. Testlauf und späterer Gesamtlauf ergeben so einen gemeinsamen Bericht. In der Mediathek gelöschte Anhänge fallen heraus. Die Gegenprobe am Ende jedes Laufs prüft alle umgewandelten Bilder, nicht nur die des Laufs, und legt ihre Restfundstellen in der Option `akwu_leftovers` ab. Ein abgebrochener Lauf überschreibt sie nicht. CSV-Export über `admin-post.php` mit Nonce und Rechteprüfung, Semikolon, UTF-8 mit BOM, Formel-Anfänge mit Hochkomma entschärft.

### ADR-023 – Rückgängig und Originale löschen als Job
**Status:** angenommen · 2026-10-09
Beides läuft als `Job` in Schritten per REST oder WP-CLI. Die Ziele (Log-Zeilen) stehen beim Start fest, der Stand liegt in der Option `akwu_job`. Bereits erledigte Zeilen werden nach einem Abbruch erkannt. Während eines Jobs startet keine Umwandlung und umgekehrt. Originale löschen verlangt das Eintippen der Anzahl (Briefing §4.7). Vor dem Löschen läuft eine frische Gegenprobe (`Verifier`, dieselbe wie am Ende der Umwandlung), und jedes Original, dessen alte Adresse noch irgendwo steht, bleibt. Die Menge dieser Bilder wird ungekappt gespeichert. Lässt sich eine Datei nicht löschen, wird das Bild nicht als bereinigt markiert. Gelöscht wird nur, was der Anhang nicht mehr nutzt und kein anderer Anhang als Datei führt. Elementor-Thumbs nur, wenn ihr Dateiname eindeutig einem Anhang gehört.

### ADR-024 – Gelöschte Bilder nehmen ihre Originale mit
**Status:** angenommen · 2026-10-09
Wird ein umgewandeltes Bild aus der Mediathek gelöscht, löscht WordPress nur die WebP-Dateien, denn die alten JPG/PNG stehen nicht mehr in den Metadaten. Der Hook `delete_attachment` löscht deshalb die alten Originale mit (gleiche Regeln wie ADR-023), damit keine verwaisten Dateien bleiben. Nach der Deinstallation greift das nicht mehr. Wer die Originale behalten will, löscht das Bild nicht.

### ADR-025 – PageSpeed nur mit Schlüssel und auf Anlass
**Status:** angenommen · 2026-10-09
`PageSpeed` ruft die PageSpeed Insights API v5 (`runPagespeed`, `strategy=mobile`, `category=performance`) für die Startseite auf. Gespeichert werden Punktzahl, LCP und Seitengewicht vorher und nachher in `akwu_pagespeed`. Das ist die einzige Anfrage nach außen. Sie läuft nur mit API-Schlüssel und nur auf Knopfdruck oder einmal beim Start der Umwandlung, wenn noch kein Vorher-Wert existiert. Schlägt die Messung fehl, startet die Umwandlung trotzdem. Die Seite muss öffentlich erreichbar sein, eine Staging-Seite mit Passwortschutz liefert eine Fehlermeldung der API.

### ADR-026 – Revisionen: ersetzen, außer beim Customizer-CSS
**Status:** angenommen · 2026-10-09
Revisionen von Beiträgen und Elementor-Seiten werden mit ersetzt. Eine wiederhergestellte Revision bringt so keine alten Adressen zurück, die nach dem Löschen der Originale ins Leere zeigen würden. Revisionen des Customizer-CSS bleiben wie das CSS selbst unangetastet (Briefing §4.2). In Bericht und Scan zählen Revisionen nicht als Verwendung.

### ADR-027 – HEIC-Einträge nach Dateiinhalt beurteilen
**Status:** angenommen · 2026-11-26
WordPress wandelt HEIC-Uploads in JPG um (`…-scaled.jpg` plus Größen), lässt den MIME-Typ des Anhangs aber auf `image/heic` und führt den HEIC-Master in `original_image`. Version 1.0.0 hielt solche Bilder für „Sonstige“. Jetzt entscheidet bei HEIC-Einträgen der Dateiinhalt (erste Bytes): Ist die Hauptdatei nachweislich JPG oder PNG, wird sie umgewandelt. Der HEIC-Master ist nie die Quelle, bleibt als `original_image` eingetragen und überlebt auch „Originale löschen“. Echte HEIC-Dateien, auch mit JPG-Endung, bleiben unberührt. Für gewöhnliche JPG- und PNG-Anhänge ändert sich nichts.

### ADR-028 – Anhänge mit gemeinsamer Datei gehen nur zusammen
**Status:** angenommen · 2026-11-26
Mehrere Anhänge können auf dieselbe Datei zeigen (WPML legt je Sprache einen Eintrag an). Wird nur einer umgestellt, zeigen die anderen auf Dateien, die „Originale löschen“ später entfernt. Deshalb: Vor dem Schreiben werden alle Einträge mit derselben Hauptdatei gesucht. Haben sie dieselben Dateien, bekommt jeder eine eigene Protokollzeile mit eigenem altem Zustand, die Datei wird einmal umgewandelt und alle zeigen danach auf dieselben WebP-Dateien. Passen die Metadaten nicht zusammen, wird nichts geschrieben und der Grund steht im Protokoll. Rückgängig nimmt immer die ganze Gruppe (gleicher Lauf, gleiche neue Datei), der letzte Eintrag löscht die WebP-Dateien. „Originale löschen“ lässt alte Dateien liegen, solange ein anderer Anhang darauf zeigt. Keine neue Tabellenspalte. Der Scan zählt die Bytes gemeinsamer Dateien einmal (Scan-Format 3, ein neuer Scan ist nötig).

### ADR-029 – 3D-Tour weit unten erst bei Bedarf laden, als gespeicherte Änderung
**Status:** angenommen · 2026-11-26
Messung auf einer Kundenkopie: Eine Matterport-Tour im neunten Abschnitt der Startseite machte beim Seitenaufruf rund zwei Drittel der übertragenen Bytes und Anfragen aus, zweimal geladen (Desktop- und Handy-Variante). Das größte sichtbare Element (LCP) war dagegen ein CSS-Hintergrund, der schon WebP ist. Ein Preload dafür bräuchte Logik bei jeder Auslieferung und je Gerät andere Dateien, das widerspricht dem Grundsatz „kein Eingriff zur Laufzeit“ und wurde verworfen. Stattdessen setzt die neue Seite „Einbettungen“ auf Wunsch `loading="lazy"` an genau dem einen iframe im Elementor-HTML-Widget: je Tour einzeln, nur Matterport (Filter `akwu_embed_hosts`), nur ab dem dritten Abschnitt, mit gesichertem alten Tag und zeichengenauem Zurücknehmen. Kein Platzhalter, kein Klick, kein Skript: Das echte iframe bleibt, der Browser lädt es von selbst in der Nähe des Sichtbereichs. Die Änderung wirkt auch ohne das Plugin weiter.

### ADR-030 – Die Pixelmaße der Hauptdatei bleiben
**Status:** angenommen · 2026-11-26
Test auf einer Kundenkopie: Die Website hatte inzwischen eine kleinere Obergrenze für große Bilder (1920 statt 2560 Pixel). WordPress verkleinerte die neue WebP-Datei deshalb beim Erzeugen der Metadaten noch einmal, aus 1920 × 2560 wurde 1440 × 1920. Ein Teil der „Ersparnis“ war also weniger Auflösung. Das ist nicht Aufgabe dieses Plugins. Während der Umstellung gilt jetzt eine feste Obergrenze: Stammt die WebP-Datei aus dem Original, entsteht die verkleinerte Fassung wieder in den alten Maßen. War die Hauptdatei selbst die Quelle (kein Original oder HEIC-Master), wird nicht verkleinert. Der Filter `big_image_size_threshold` wird nur für diesen einen Aufruf gesetzt und danach wieder entfernt.

### ADR-031 – Löscht jemand einen Eintrag, bleiben die gemeinsamen Dateien für die anderen
**Status:** angenommen · 2026-11-26
Nach einer gemeinsamen Umstellung zeigen mehrere Mediathek-Einträge auf dieselbe WebP-Datei, dieselben Größen und denselben Master. WordPress merkt sich beim endgültigen Löschen eines Anhangs dessen Dateien, bevor ein Plugin gefragt wird, und löscht sie danach ohne Rücksicht auf andere Einträge. Der einzige Punkt zum Eingreifen ist der Filter `wp_delete_file`. Das Plugin merkt sich bei `delete_attachment` die Dateien, die ein anderer Eintrag derselben umgewandelten Datei noch führt, und lässt genau diese liegen. Der Schutz endet mit dem letzten Schritt von `wp_delete_attachment()`. Er gilt nur für Dateien aus einer Umwandlung dieses Plugins. Die alten Originale bleiben, solange ein Eintrag der Gruppe sie für Rückgängig braucht.
**Ohne aktives Plugin** gilt wieder das Verhalten von WordPress (bzw. das eines Mehrsprachen-Plugins): Die gemeinsamen Dateien werden mit gelöscht. Das war für die gemeinsamen JPG-Dateien vor der Umwandlung genauso, die Umwandlung schafft also keine neue Abhängigkeit. Die alten Originale rührt WordPress dabei nicht an. Ist das Plugin später wieder aktiv, stellt Rückgängig den verbliebenen Eintrag auf sie zurück, sofern auch sein Original (bei HEIC-Uploads der Master) noch existiert.

### ADR-032 – Rückgängig nur aus dem Zustand, den die Umwandlung hinterlassen hat
**Status:** angenommen · 2026-11-26
Wird ein umgewandeltes Bild danach ersetzt oder bearbeitet, hätte Rückgängig die neue Fassung überschrieben und ihre Dateien gelöscht, bei gemeinsamen Dateien auch über den anderen Eintrag. Jetzt wird jede Zeile und jede Gruppe vollständig geprüft, bevor Verweise, Datenbank oder Dateien angefasst werden: Der Anhang muss auf die protokollierte WebP-Datei zeigen, `image/webp` sein, darf keine Dateien führen, die nicht aus der Umwandlung stammen, und die Summe der Dateigrößen muss der direkt nach der Umwandlung entsprechen. Fehlende WebP-Dateien sind kein Hindernis. Weicht ein Eintrag ab, bleibt die ganze Gruppe unverändert, der Grund steht in der Liste. Gelöscht werden beim Rückgängig nur noch protokollierte Dateien. Zeilen, deren Mediathek-Eintrag es nicht mehr gibt, zählen nicht zur Gruppe. Die Prüfung nutzt nur vorhandene Spalten und gilt damit auch für Zeilen aus 1.0.0 und 1.1.0. Die Wiederaufnahme nach einem Abbruch lässt ein Bild in Ruhe, das inzwischen auf eine unbekannte Datei zeigt.
**Folge:** Verändert ein anderes Plugin die WebP-Dateien nachträglich (Bildoptimierer, neu erzeugte Vorschaubilder mit anderer Kompression), wird Rückgängig für diese Bilder abgelehnt. Das ist gewollt, es gibt keinen Schalter zum Übergehen.

### ADR-033 – Gemeinsam umgestellt wird nur bei gleicher Größenzuordnung
**Status:** angenommen · 2026-11-26
Bisher genügten gleiche Dateipfade. Führt ein Eintrag eine Datei als `medium` und der andere als eigene Größe `poster`, hätte der zweite nach der Umstellung das Schema des ersten gehabt, und WordPress wäre bei `poster` auf das ganze Bild zurückgefallen. Verglichen werden jetzt Größenname → Datei und die Pixelmaße je Größe. Passt das nicht, wird die Gruppe mit Grund ausgelassen und nichts geschrieben.

### ADR-034 – Ein ausdrücklich gesetztes `loading` bleibt
**Status:** angenommen · 2026-11-26
Die Seite „Einbettungen“ bot ein iframe mit `loading="eager"` wie eines ohne Attribut an, änderte dann nichts und meldete trotzdem Erfolg. Jetzt wird unterschieden: ohne Attribut (umstellbar), hier umgestellt, im Widget ausdrücklich festgelegt (wird angezeigt, nie geändert). Ohne tatsächliche Änderung wird nichts geschrieben und nichts gemeldet. Für iframes ohne Attribut bleibt das erzeugte Tag Zeichen für Zeichen wie in 1.1.0.

