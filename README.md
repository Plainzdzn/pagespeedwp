# WebP-Umwandler

WordPress-Plugin von Akuma Digital. Ersetzt PNG- und JPG-Bilder in der Mediathek durch WebP, **mit derselben Attachment-ID**, und zieht alle Verweise in der Datenbank mit, auch in Elementor. Danach kann das Plugin entfernt werden, der Zustand bleibt.

- Kein Rewrite, keine `.htaccess`, keine Auslieferungslogik. Die Bilder sind danach einfach WebP.
- Bild-IDs, Beitragsbilder, Galerien und Elementor-Widgets bleiben verknüpft.
- Rückgängig ist möglich, solange die Originale auf dem Server liegen.

## Voraussetzungen

- WordPress 6.0 oder neuer, PHP 7.4 bis 8.3, keine Multisite.
- Der Server muss WebP erzeugen können (Imagick oder GD mit WebP). Die Systemprüfung im Plugin zeigt das.
- Admin-Rechte (`manage_options`).

## Installation

1. Die ZIP-Datei `akuma-webp-umwandler-<version>.zip` aus den [Releases](https://github.com/Plainzdzn/pagespeedwp/releases) laden.
2. In WordPress unter **Plugins → Installieren → Plugin hochladen** die ZIP auswählen und aktivieren.
3. Das Menü **WebP-Umwandler** erscheint in der linken Leiste.

## Ablauf für Niovo

**Immer zuerst auf einer Staging-Seite** (bei Raidboxes per Klick angelegt). Erst wenn dort alles passt, auf der Live-Seite.

1. **Systemprüfung** öffnen. Rote Punkte verhindern den Start, gelbe bitte lesen. Andere Bildoptimierer wie FastPixel mit Bildkomprimierung werden angezeigt, aber nicht umgestellt.
2. **Backup** von Datenbank und Uploads anlegen (Raidboxes: Backup im Dashboard). Ohne Häkchen „Backup ist erstellt“ startet nichts.
3. **Übersicht → Scan starten.** Der Scan liest nur. Er zeigt Größe heute, erwartete Größe danach, die größten Dateien und die Stellen, die von Hand zu prüfen sind.
4. **Erst 10 testen.** Wandelt die zehn meistgenutzten Bilder um. Danach die betroffenen Seiten im Frontend ansehen, am besten auch mobil.
5. **Umwandlung starten.** Läuft in Paketen, das Fenster bitte offen lassen. Bei einer Unterbrechung geht es beim nächsten Öffnen der Seite weiter. Pausieren und „Abbrechen und zurücksetzen“ sind jederzeit möglich.
6. **Bericht** prüfen: vorher und nachher, jedes Bild, CSV-Export. Unter **Bitte prüfen** stehen Stellen, die das Plugin bewusst nicht ändert, dort lädt noch das Original:
   - Zusätzliches CSS im Customizer
   - Custom CSS in Elementor
   - Code Snippets
   - Dateien im Theme

   Diese Adressen von Hand auf `.webp` ändern.
7. **Cache:** Das Plugin leert Elementor-CSS, den WordPress-Objekt-Cache und erkannte Cache-Plugins (FastPixel, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, Autoptimize). Auf Raidboxes ohne FastPixel den Server-Cache im Raidboxes-Dashboard leeren, die Systemprüfung weist darauf hin.
8. Auf der **Live-Seite** dieselben Schritte durchführen. Staging nach der Umwandlung nicht auf Live übertragen, wenn sich auf Live inzwischen etwas geändert hat.
9. **Nach einigen Tagen ohne Auffälligkeiten:** Im Bericht **Originale löschen**. Zur Bestätigung die angezeigte Zahl eintippen. Vorher läuft automatisch eine Gegenprobe. Originale, deren alte Adresse noch irgendwo steht, bleiben. Danach ist für diese Bilder kein Rückgängig mehr möglich.
10. **Plugin deaktivieren und löschen.** Die Bilder bleiben WebP, die Verweise bleiben ersetzt. Gelöscht werden nur die Einstellungen und das Protokoll des Plugins.

Optional misst das Plugin die Startseite mobil mit PageSpeed Insights vorher und nachher. Dafür unter **Einstellungen** einen API-Schlüssel eintragen. Ohne Schlüssel stellt das Plugin keine Anfragen nach außen.

## Rückgängig

Unter **Rückgängig** lassen sich alle Bilder oder einzelne zurücksetzen: Datei, Metadaten und Verweise wie vorher, die WebP-Dateien werden gelöscht, die IDs bleiben. Das geht, solange die Originale existieren und das Plugin installiert ist. Mit dem Löschen des Plugins verschwindet das Protokoll, danach ist kein Rückgängig mehr möglich.

## Was das Plugin nie tut

- Die `guid` von Anhängen ändern.
- Customizer-CSS, Custom CSS in Elementor, Code Snippets oder Theme-Dateien ändern. Diese Stellen werden nur gelistet.
- Adressen fremder Domains ändern, z. B. Live-URLs auf einer Staging-Seite.
- Dateien löschen, bevor der Zustand für Rückgängig gesichert ist.
- Einstellungen anderer Plugins umstellen.

## WP-CLI

Gleiche Logik wie in der Oberfläche, praktisch per SSH auf Raidboxes.

```bash
wp akwu scan                         # Bestandsaufnahme, ändert nichts
wp akwu convert --dry-run            # zeigt, was umgewandelt würde
wp akwu convert --test               # Testlauf mit den 10 meistgenutzten Bildern
wp akwu convert [--limit=<n>] [--ids=<ids>] [--yes]
wp akwu convert --resume             # offenen Lauf fortsetzen
wp akwu report [--format=summary|table|csv|json]
wp akwu rollback [--ids=<ids>] [--dry-run] [--yes]
wp akwu purge-originals [--dry-run] [--yes]
wp akwu pagespeed vorher|nachher     # optional, mit API-Schlüssel
```

## Entwicklung

- Anforderungen: [`docs/BRIEFING.md`](docs/BRIEFING.md)
- Stand und Testberichte: [`docs/HANDOFF.md`](docs/HANDOFF.md)
- Architektur-Entscheidungen: [`docs/DECISIONS.md`](docs/DECISIONS.md)
- Arbeitsweise, Tests, Release: [`CLAUDE.md`](CLAUDE.md)
- Design-Referenz: [`design/`](design/)
