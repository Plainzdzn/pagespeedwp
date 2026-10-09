# Design-Referenz

Statische Mockups aus dem Design-Tool, unverändert übernommen. Sie sind Vorlage für Layout und Look, kein Code zum Übernehmen.

| Datei | Zeigt | Meilenstein |
|---|---|---|
| `Main.dc.html` | Übersicht nach dem Scan | M1 (Rahmen), M2 (Daten) |
| `Umwandlung.dc.html` | Umwandlung läuft | M3 |
| `Bericht.dc.html` | Bericht nach dem Lauf | M4 |

## Hinweise

- Die Mockups laden Google Fonts und ein `support.js` aus dem Design-Tool (liegt nicht bei). Im Plugin werden Outfit und Inter lokal ausgeliefert (DSGVO), `support.js` wird nicht gebraucht.
- Alle Zahlen und Dateinamen in den Mockups sind Beispieldaten.
- Der WordPress-Rahmen (Admin-Bar, linke Menüleiste) ist nur zur Orientierung nachgebaut. Das Plugin stylt ihn nicht.

## Details aus den Mockups, die im Briefing nicht ausdrücklich stehen

Für die späteren Meilensteine vorgemerkt, Umsetzung nach Rücksprache:

- **Kopfzeile, Buttons je Seite:** Übersicht „Anleitung“ und „Protokoll“, Umwandlung „Pausieren“ und „Abbrechen und zurücksetzen“, Bericht „CSV exportieren“ und „Rückgängig machen“.
- **Admin-Bar:** Während der Umwandlung zeigt der Plugin-Eintrag den Fortschritt („WebP-Umwandler · 58 %“).
- **Übersicht:** Pro Bild eine geschätzte WebP-Größe und ein Status („Bereit“, „Hintergrundbild“, „Überspringen“ bei zu wenig Ersparnis). Hinweis „Bild-IDs bleiben erhalten“ im Notice.
- **Umwandlung:** Kachel „Bild-IDs verändert: 0“ als Kontrollwert, Ablauf in vier Schritten (umwandeln, Verweise ersetzen, Elementor-CSS, Cache), Live-Protokoll neueste zuerst.
- **Bericht:** PageSpeed mobil vorher/nachher als Kachel. „Bitte prüfen“ kennt die Typen Code Snippet, Zusätzliches CSS und „Externe Einbindung“ (Original „behalten“). Originale löschen mit Hinweis „erst nach einigen Tagen ohne Auffälligkeiten“.
- **WP-Menü:** Das Mockup zeigt im WordPress-Untermenü nur Übersicht, Bericht und Einstellungen. Entschieden (ADR-011): Alle sieben Bereiche erscheinen im WordPress-Menü, WordPress zeigt sie selbst an.
- **WP-Rahmen:** Im Mockup ist der aktive WordPress-Menüpunkt grün. Laut Briefing bleibt der WP-Rahmen Standard, im Plugin ist er deshalb im Farbschema des Benutzers (meist blau).
- **Breiten:** Die Mockups rechnen Breiten ohne Innenabstand (`content-box`). Im Plugin gilt `border-box`, die Navigation ist deshalb 248 px und die Seitenspalte 298 px breit.
