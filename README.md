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

## HEIC-Uploads und doppelte Einträge (ab 1.1.0)

- Fotos, die als HEIC hochgeladen wurden, liegen in WordPress als JPG auf dem Server. Sie werden wie JPGs umgewandelt, in der Liste steht „als HEIC hochgeladen, Datei ist JPG“. Die HEIC-Datei selbst bleibt immer erhalten.
- Zeigen mehrere Mediathek-Einträge auf dieselbe Datei (WPML legt je Sprache einen an), werden sie gemeinsam umgestellt und gemeinsam zurückgesetzt. In der Liste steht „gleiche Datei wie ID …“. Passen die Einträge nicht zusammen, bleibt das Bild unverändert, der Grund steht im Protokoll.
- Nach dem Update von 1.0.0 einmal neu scannen.

## Sicherheit bei doppelten Einträgen (ab 1.1.1)

- Wird einer von mehreren Einträgen derselben Datei endgültig gelöscht, behalten die anderen ihre WebP-Datei, alle Größen und das Original. Das gilt, solange dieses Plugin aktiv ist. Ohne das Plugin verhält sich WordPress wie vor der Umwandlung und löscht gemeinsame Dateien mit.
- Wurde ein umgewandeltes Bild danach ersetzt oder bearbeitet, lehnt „Rückgängig“ ab und ändert nichts, auch nicht bei den anderen Einträgen derselben Datei. Der Grund steht in der Liste. Ab 1.1.2 wird dafür jede erzeugte Datei mit Größe und Prüfsumme festgehalten.
- Bilder aus Läufen vor 1.1.2 haben diesen Nachweis nicht. Beim Rückgängig werden ihre WebP-Dateien deshalb nicht gelöscht, sondern nach `uploads/akwu-sicherung/` verschoben. Der Ordner kann danach von Hand gelöscht werden.
- Einträge, die dieselbe Datei unter verschiedenen Größennamen führen, werden nicht gemeinsam umgestellt.

## Qualität am Kodierpunkt (ab 1.1.4)

- Bis 1.1.3 kam die eingestellte Qualität beim Kodieren nicht an. WordPress setzt beim Speichern in ein anderes Format (JPG oder PNG → WebP) die Qualität in `get_output_format()` auf seinen Standard zurück, für WebP 86. Gemeldet wurde trotzdem der eingestellte Wert. Betroffen waren alle verlustbehafteten WebP, mit Imagick und mit GD, und die verlustfreien mit Imagick (Stufe 86 statt 100).
- Ab 1.1.4 gilt beim Kodieren genau die eingestellte Qualität, auch für deckende PNGs und die zweite Fassung von Freistellern. Andere Bildbearbeitungen in WordPress behalten den Standard des Core.
- Schon umgewandelte Bilder bleiben, wie sie sind. Mit 82 statt 86 werden neue WebP-Dateien etwas kleiner.

## Geschützte Zieldatei (ab 1.1.5)

- Das Plugin schreibt eine neue WebP-Datei nur in eine Zieldatei, die es vorher noch nicht gab. Verlangt ein Filter (`image_editor_output_format`) dafür ein anderes Format als WebP, wird gar nicht gespeichert.
- Übernommen wird das Ergebnis nur, wenn der Bildeditor genau diese Datei als WebP meldet und sie ein vollständiges WebP in den Maßen der Quelle ist, bei transparenten PNG mit Alphakanal. Sonst bleibt das Bild im Original, die eigene Zieldatei wird entfernt, und eine abweichend gemeldete Datei bleibt unberührt und steht im Protokoll.

## PNG nach echter Transparenz (ab 1.1.3)

- Viele PNGs (Canva, Photoshop) haben einen Alphakanal, obwohl kein Pixel durchsichtig ist. Bis 1.1.2 wurden sie wie transparente PNGs verlustfrei gespeichert. Ab 1.1.3 prüft das Plugin mit Imagick die Pixel: Ist keines durchsichtig, wird das PNG wie ein PNG ohne Transparenz verlustbehaftet mit der JPG-Qualität gespeichert, ohne leeren Alphakanal. Im Protokoll steht dann „PNG mit Alphakanal, aber ohne durchsichtige Pixel“.
- Unter **Einstellungen → PNG mit Transparenz kodieren** gibt es zusätzlich „Automatisch“ (Opt-in, Standard bleibt verlustfrei): PNGs mit durchsichtigen Pixeln werden dann zweimal kodiert, und die verlustbehaftete Fassung mit verlustfreiem Alphakanal wird nur genommen, wenn sie höchstens halb so groß ist. Das trifft Fotos mit freigestelltem Hintergrund. Logos und flache Grafiken bleiben verlustfrei.
- Bereits umgewandelte Bilder ändern sich dadurch nicht. Wer sie neu kodieren will, setzt sie unter **Rückgängig** zurück und wandelt sie erneut um.
- Die Hochrechnung im Scan liest weiter nur den Dateikopf und bleibt für solche PNGs vorsichtig (zu groß).
- Die zweite Fassung wird nur übernommen, wenn sie die eigene Zwischendatei des Plugins ist und ein vollständiges, verlustbehaftetes WebP mit Alphakanal in den Maßen des PNG. Ändert ein Filter (`image_editor_output_format`) das Format, meldet der Editor einen anderen Pfad oder bricht das Speichern ab, bleibt die verlustfreie Datei. Entfernt wird dann nur die eigene Zwischendatei (`akwu-alpha-…webp`), nie ein anderer Pfad (ab 1.1.4).
- Ohne Imagick ändert sich nichts.

## Einbettungen (ab 1.1.0)

Eine Matterport-Tour lädt beim Seitenaufruf mehrere Megabyte, auch wenn sie weit unten steht. Unter **WebP-Umwandler → Einbettungen** lässt sich je Tour festlegen, dass der Browser sie erst lädt, wenn man in ihre Nähe scrollt. Die Tour bleibt unverändert bedienbar, es gibt keinen Platzhalter und keinen zusätzlichen Klick. „Zurücknehmen“ stellt den gespeicherten Inhalt zeichengenau wieder her. Ein im Widget ausdrücklich gesetztes `loading` (auch `eager`) wird angezeigt und nie geändert. Danach den Server-Cache leeren (Raidboxes: im Dashboard).

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
