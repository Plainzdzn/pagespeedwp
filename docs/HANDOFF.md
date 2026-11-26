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

## 2026-11-26 21:50 – 1.2.1: Kopfzeilen-Fix ohne Nebenwirkung auf andere Bilder, Serie 5 (Claude Code)

**Stand:** Entwurfs-PR #6, nicht gemergt, nicht veröffentlicht. Build **1.2.1 aus `bad5309`**, ZIP-SHA-256 `eb85942174c7ae24b0513b48b2a27abd772977cda120e3af71891f6028561edb`. Danach nur Doku (`fe04340` ADR-041, dieser Eintrag) und eine Ausrichtung im Integrationstest, die PHPCS in der CI bemängelt hat (Tests sind nicht im ZIP). CI für `0927c00` grün.

**Erledigt:**
- Serie 5, Kontrollmessung auf der Demokopie Solarengel #299741: 1.2.0 aktiv gegen deaktiviert, Server-Cache durchgehend ein, Reihenfolge aktiv, deaktiviert, aktiv. Je Arm 6 PSI-Läufe für Startseite und Formularseite. Das ausgelieferte HTML ist in beiden Armen gleich, wenn man die je Aufruf wechselnden Werte abzieht (Kennungen des Consent-Banners, Zeitstempel, CSS-Versionsstempel von Elementor).
- 1.2.1 (ADR-041): Der Fix aus 1.2.0 setzte am Logo `loading="eager"`. WordPress und Elementor zählten das Logo dadurch nicht mehr zu den ersten Bildern, das nächste Inhaltsbild verlor sein `loading="lazy"`. Auf Solarengel wurde so die Karte unten auf der Startseite vor dem Scrollen geladen (46 KB Desktop, 24 KB Mobil). 1.2.1 setzt `eager` nur noch, wo vorher `lazy` stand. Eine Fix-Datei aus 1.2.0 meldet die Seite „Ladeverhalten“ als veraltet.
- Ursache der Solarengel-Werte gesucht und einen Versuch auf der Demo gemessen (siehe Ergebnisse). Das Versuchs-Plugin `akwu-demo-versuch-heyflow` gehört nicht ins Repo, sein Quelltext liegt im Nachweispaket.
- Drei weitere Demokopien geprüft, je drei Baselines vor jeder Installation (Regel: Plugin nur bei Desktop-Median unter 80).
- Nachweise im Claude-Projekt „Pagespeed WordPress“ (`claude/akwu-serie5-abnahme-2026-11-26.md`).

**Ergebnisse (PSI, Median, Einzelwerte in Klammern):**

| Seite | 1.2.0 aktiv, Fix aus | 1.2.0 deaktiviert | Versuch Heyflow-Lader | Einordnung |
|---|---|---|---|---|
| Solarengel Start, Desktop | 74 (75/77/77/72/51/60) | 68 (65/63/72/74/70/59) | **89** (87/89/89) | TBT 168 → 26 ms, CLS 0,088 → 0,002, LCP 3,0 → 2,1 s |
| Solarengel Start, Mobil | 62 (64/58/60/72/58/66) | 69 (71/66/79/67/74/63) | **88** (88/96/77) | TBT 440 → 48 ms, LCP 3,7 → 2,6 s |
| Solarengel Formular, Desktop | 81 (91/82/77/80/70/93) | 80 (70/75/78/86/82/87) | **89** (96/86/89) | CLS 0,221 → 0,100, TBT unverändert |
| Solarengel Formular, Mobil | 50 (39/67/74/36/59/41) | 54 (40/28/77/69/37/70) | **68** (68/68/62) | CLS 0,296 → 0,001, LCP bleibt bei rund 9 s |

- Aktiv gegen deaktiviert: kein Unterschied durch das Plugin. Die Mediane folgen dem Google-Messrechner (`benchmarkIndex` je Arm und Seite 630–1092 im Median, Einzelläufe 378–1341).
- Ursache: Das Heyflow-Skript `webview.js` steht ohne `async` oder `defer` im `<head>` (Kopf-Code über ASE). Sein Anteil an der Blockierzeit der langen Aufgaben liegt auf der Formularseite bei 96–100 %, auf der Startseite am Desktop bei 61–100 % (Median 89 %) und mobil bei 48–91 % (Median 63 %), je 15–16 Berichte. Es lädt bei jedem Aufruf 27–31 Dateien und schickt 9 Meldungen (POST) an Heyflow, obwohl das Formular auf der Startseite erst bei rund 2260 px steht. Das Formular wächst nach dem Laden von 16 auf 548–684 px und schiebt den Inhalt darunter weg.
- Versuch: Das Skript lädt erst, wenn das Formular näher als 800 px am sichtbaren Bereich ist, und der Platz für das Formular ist reserviert. Auf der Formularseite lädt es sofort. Consent-Dialog, Formular (14 Schaltflächen, 28 Eingabefelder) und Fremd-Hosts vor der Einwilligung sind geprüft. Geprüfte Nebenwirkungen: In Messungen mit ausgeschaltetem Cache (PageSpeed) überträgt die Formularseite die Heyflow-Schrift fünfmal statt einmal (rund 124 KB mehr), im Browser mit Cache bleibt es bei einer Übertragung. Von 9 Heyflow-Meldungen je Aufruf fehlt eine Protokollzeile („Page Show Event“), Besuch und Darstellung werden gemeldet. Beim langsamsten Messrechner (Wert 496) kam die Startseite auf dem Desktop auf 87, ohne Versuch bei 513 auf 51.
- Kinberger #299754 (Start): Desktop 82/77/81, Median 81. **Ausgelassen**, kein Plugin installiert.
- Finanzierungswerkstatt #299756 (`/geld-verleihen-privat/`): Desktop 82/81/83, Median 82. **Ausgelassen**, kein Plugin installiert.
- Thomas Oelsner #299755 (`/tilgungsrechner/`): Desktop 64/75/92, Median 75, also qualifiziert. 1.2.0 installiert: 349 Bilder, alle schon WebP, 0 Kandidaten, Kopfzeilenbild nicht verzögert, HTML der Zielseite unverändert. Danach 91/66/94. Der Wert hängt am eingebetteten Rechner von ehyp (Hauptthread 243–677 ms in den Läufen mit 91–94, 1093–1543 ms in den Läufen mit 64–75), das Plugin hat dort keinen Hebel.

**Entscheidungen:**
- Kein `loading="eager"` mehr an Bildern ohne `loading`-Attribut (ADR-041). `data-no-lazy` und `skip-lazy` bleiben.
- Der Heyflow-Lader ist eine Einstellung der einzelnen Website, kein Teil von WebP-Umwandler. Er bleibt als Versuch auf der Demo.
- Mediane stehen weiter mit Einzelwerten und Messrechner-Wert in den Nachweisen. Zuschreibungen nur, wo HTML oder Rohdaten sie tragen.

**Offen / Nächster Schritt:**
- Merge und Release nur auf Felix' Freigabe.
- 1.2.1 mit WP Rocket nachmessen (DarumPflanzlich war kein Ziel dieser Serie). Dass `data-no-lazy` und `skip-lazy` ohne `eager` reichen, ist dort nur am Quelltext von WP Rocket belegt.
- Heyflow-Lader auf Solarengel live: Entscheidung von Felix und dem Kunden. Folge: Heyflow zählt einen Aufruf des Formulars auf der Startseite erst, wenn es in die Nähe des sichtbaren Bereichs kommt.
- Der Versuch hat 3 Läufe je Seite, die Vergleichsarme je 6. Kein Rücktausch nach dem Versuch gemessen.
- Formularseite mobil bleibt unter 80: Das LCP-Element ist die Überschrift im Heyflow-Formular, sie erscheint erst nach Skript und Formulardaten (simuliert rund 9 s).
- Jeder Aufruf der Solarengel-Demo meldet einen Besuch an das Heyflow-Konto des Kunden (Host der Demo), auch jeder PSI-Lauf. Das war schon in Serie 4 so und liegt am Skript der Seite, nicht am Plugin. Mit dem Versuch entfällt es auf der Startseite, solange niemand zum Formular scrollt.
- Aus Serie 4 weiter offen: LCP-Bild sofort laden, Elementor-Seiten-CSS auf Achtstein live prüfen.

**Fragen an Felix:** 1.2.1 statt 1.2.0 als Stand für die Freigabe? Soll „Fremdskript erst laden, wenn sein Bereich sichtbar wird“ ein eigener Fix in der Fix-Datei werden, oder bleibt das eine Einstellung je Website?

**Getestet:**
- PHPCS sauber, PHPUnit 110 Tests und 266 Prüfungen. Integration `header-images.php` mit dem neuen Block lokal bestanden, gegen den Fix-Code aus 1.2.0 schlägt er fehl.
- Solarengel #299741: Ladeattribute aller Bilder im gerenderten HTML und Netzwerk vor dem Scrollen in drei Zuständen (Fix aus, Fix 1.2.0, Fix 1.2.1), Desktop und Mobil. Versuch: HTML unterscheidet sich vom Vergleichszustand nur im ersetzten Skript-Tag. Funktions-Stichprobe ohne Fehler. Formular nicht durchgeklickt und nicht abgeschickt.
- Oelsner #299755: HTML der Zielseite vor und nach der Installation gleich bis auf CSS-Stempel und Zufallswerte im Formular. Funktions-Stichprobe ohne Fehler, kein Formular abgeschickt.
- Endstand der Demos: Solarengel 1.2.1 aktiv, Fix aus, Versuchs-Plugin aktiv, Server-Cache ein. Oelsner 1.2.0 aktiv, nichts umgewandelt. Kinberger und Finanzierungswerkstatt nur mit Isolations-Plugin.
- Grenzen: Die Baselines der drei neuen Demos sind je 3 Läufe. Kinberger (77–82) und Finanzierungswerkstatt (81–83) liegen knapp über der Schwelle, auf Google lagen die Live-Seiten laut Felix bei 77 und 78.

---

## 2026-11-26 19:45 – 1.2.0: Bilder der Kopfzeile sofort laden, Serie 4 auf drei neuen Demokopien (Claude Code)

**Stand:** Entwurfs-PR #6, nicht gemergt, nicht veröffentlicht. Build **1.2.0 aus `4296e76`**, ZIP-SHA-256 `c5ae91a0b7541d89dd5f833b7263f08a3c166bb290701efaedb0f4a35f2df01e`. Danach nur Doku (`e8fcfde`, dieser Eintrag). CI grün.

**Erledigt:**
- 1.2.0 (ADR-040): neue Seite „Ladeverhalten“. Sie findet die Bilder der Elementor-Kopfzeile und schreibt auf Knopfdruck die Fix-Datei `wp-content/mu-plugins/akuma-pagespeed-fixes.php`. Die Datei setzt für genau diese Bild-IDs `loading="eager"`, `data-no-lazy` und die Klasse `skip-lazy`, damit ein Lazy-Loader sie nicht verzögert (WP Rocket 3.20.1.2 geprüft, Ausschlüsse im Quelltext belegt). „Zurücknehmen“ entfernt die Datei. Eine fremde Datei gleichen Namens wird nie überschrieben oder gelöscht.
- Tests: `tests/unit/test-fix-file.php` (6 Tests), `tests/integration/header-images.php` (29 Prüfungen), beide in der CI.
- Serie 4 auf drei neuen Raidboxes-Demos (#299741 Solarengel, #299742 Achtstein Invest, #299743 DarumPflanzlich): je Box Backup und Isolations-Plugin vor der Baseline, dann Baseline, 1.1.5 und, wo anwendbar, der Fix aus 1.2.0. Je Phase, Seite und Gerät mindestens drei PSI-Läufe. Nachweise im Claude-Projekt „Pagespeed WordPress“ (`claude/akwu-serie4-abnahme-2026-11-26.md`).

**Ergebnisse (PSI Desktop, Median, Ziel ≥ 80):**

| Seite | Ausgangszustand | mit Plugin, gleicher Cache-Zustand | Einordnung |
|---|---|---|---|
| DarumPflanzlich Start | 77 | 1.1.5: 74, 1.2.0-Fix: **93** | CLS 0,322 → 0,000 durch den Fix |
| DarumPflanzlich Shop | 80 | 1.1.5: 81, 1.2.0-Fix: **96** | CLS 0,323 → 0,001 durch den Fix |
| Achtstein Start | 91 (Vergleichsbasis) | 1.1.5: **95** | Unterschied innerhalb der Streuung, LCP 1,3 → 1,1 s |
| Achtstein Investieren | 94 (Vergleichsbasis) | 1.1.5: **92** | Unterschied innerhalb der Streuung, LCP 1,3 → 1,1 s |
| Solarengel Start | 68 | 1.1.5: keine Kandidaten | unter 80, Hosting-Variante Cache ein ebenfalls 68 |
| Solarengel Formular | 65 | 1.1.5: keine Kandidaten | Hosting-Variante Cache ein 82, bei ähnlich schnellem Messrechner aber 78–79 gegenüber 76–91 |

- DarumPflanzlich: Ursache war WP Rocket LazyLoad auf dem Kopfzeilen-Logo. Der Platzhalter ist 300 px breit, das Bild 1215 px, das CSS rechnet 20 % der Eigenbreite. Beim Nachladen verschob sich die ganze Seite um 115 px. Mobil unverändert (64 → 68 → 65, Shop 71 → 71 → 70).
- Achtstein: Der zuerst gemessene Ausgangszustand der Kopie (Start 69, Investieren 82) ist nicht vergleichbar. Mehrere Elementor-Seiten-CSS (`post-990.css`, `post-927.css` und weitere) antworteten mit 301 statt CSS, das Hero-Hintergrundbild wurde nicht geladen. Der Schritt „Elementor-CSS neu erzeugen“ der Umwandlung hat die Dateien nebenbei wiederhergestellt. Für den gleichwertigen Vergleich wurden die 6 Bilder der beiden Messseiten über „Einzeln zurücksetzen“ ins Original gesetzt (CSS intakt), gemessen und wieder umgewandelt. Mobil mit intaktem CSS: Start 57 → 60, Investieren 51 → 54.
- Solarengel: alle 240 Bilder schon WebP. Startseite unter 80, weil der Consent-Dialog das LCP-Element ist (simuliert rund 3,0 s), dazu TBT 57–380 ms je nach Messrechner und CLS meist 0,07–0,10 (Schrift des Heyflow-Formulars lädt nach und verschiebt den Hero). Nichts davon liegt im Wirkungsbereich des Plugins.

**Entscheidungen:**
- Die Fix-Datei ist eine bewusste Ausnahme von „keine dauerhafte Auslieferungslogik“ (ADR-040). Sie läuft ohne das Plugin weiter und wird nur auf Knopfdruck geschrieben.
- Auf Solarengel und Achtstein wurde der Fix nicht eingeschaltet: Dort verzögert kein Lazy-Loader die Kopfzeilenbilder, es gäbe nichts zu messen.
- Der Google-Messrechner (`benchmarkIndex` 340–1350) bestimmt TBT und Score stark mit. Mediane stehen deshalb immer mit Spanne und Messrechner-Wert in den Nachweisen, Zuschreibungen nur, wo sie auch bei gleichem Messrechner halten.

**Offen / Nächster Schritt:**
- Merge und Release nur auf Felix' Freigabe.
- Kandidat für die nächste Verbesserung: das LCP-Bild der Seite sofort laden. Mobil ist es auf DarumPflanzlich das erste Inhaltsbild (Start) bzw. das erste Produktbild (Shop), beide von WP Rocket verzögert und deshalb nicht im HTML auffindbar (LCP simuliert 6–8,6 s). Auf Achtstein ist es das Hintergrundbild des ersten Abschnitts, das nur im CSS steht und mit Priorität „Low“ lädt (LCP simuliert 9 s auf der Startseite, 20 s auf „Investieren“ mit 30 Bildern und 5,0 MB). Naheliegend: denselben Ausschluss wie für die Kopfzeile auf das erste Inhaltsbild ausdehnen und Hintergrundbilder vorladen. Nicht umgesetzt, nicht gemessen.
- Website-Einstellungen, nicht getestet: Heyflow-Skript auf Solarengel mit `defer` und fester Mindesthöhe für das Formular, feste Logobreite auf DarumPflanzlich.
- Auf den Live-Seiten prüfen, ob Achtstein die Elementor-Seiten-CSS ausliefert. Auf der Kopie fehlten sie, Ursache nicht geklärt.
- Aus Serie 3 weiter offen: echtes Verlustfrei behalten oder near-lossless anbieten; Vorschaugrößen mit Core-Qualität.

**Fragen an Felix:** Fix-Datei als Ausnahme freigeben (ADR-040)? Soll „LCP-Bild sofort laden“ als nächster Fix in die Fix-Datei?

**Getestet:**
- PHPCS sauber, PHPUnit 110 Tests. Integration lokal `header-images.php` 29 Prüfungen, CI für `e8fcfde` grün.
- DarumPflanzlich #299743: 1.1.5 wandelte 156 um (191,1 → 54,6 MB, 10.966 Verweise), 9 übersprungen, 1 Fehler (ID 39, Größe `elementor_custom_350x` nicht erzeugbar, Bild bleibt im Original). 1.2.0: zwei Kopfzeilenbilder gefunden (IDs 6375, 6378), Fix eingeschaltet, HTML zeigt die drei Merkmale nur an diesen Bildern. Funktions-Stichprobe (Start, Shop, Produkt, Warenkorb, Kontakt) ohne Fehler, kein Bestelltest.
- Achtstein #299742: 1.1.5 wandelte 966 um (936,8 → 294,4 MB, 24.086 Verweise), 82 übersprungen, 2 Fehler (IDs 7929 und 10634, Größe `elementor_custom_x300`), 1 Hinweis „Bitte prüfen“ (ID 10514). Einzeln zurückgesetzt und wieder umgewandelt: 12 Einträge (6 Dateien). Endstand geprüft: HTML beider Messseiten gleich groß wie bei der Messung, alle 36 dort geladenen Bilder mit gleicher Länge. Funktions-Stichprobe (Start, Investieren, Neukundenbonus, Über uns, Blog) ohne fehlende Bilder oder eigene Fehlanfragen; der Skriptfehler von „HandL UTM Grabber“ bestand schon vorher.
- Solarengel #299741: Scan 240 Bilder, 0 Kandidaten. Hosting-Variante Raidboxes-Server-Cache aus/ein je 6 Läufe. Funktions-Stichprobe (Start mit Video und Heyflow, Formularseite, Kontakt) ohne Fehler.
- Grenzen: Mobil zeigen die PSI-Rohdaten zwei Lademuster (erster Bildaufbau früh oder erst bei rund 2,3 s, dann alles auf einmal). Das Muster bestimmt mobile LCP- und CLS-Werte stärker als die Eingriffe, die Ursache ist nicht geklärt. Die Hinweise zu Heyflow, Logobreite und Vorladen sind nicht gemessen.

---

## 2026-11-26 14:50 – 1.1.5: erstes Speichern abgesichert (Claude Code)

**Stand:** Entwurfs-PR #6, nicht gemergt. Build **1.1.5 aus `1909fe4`**, ZIP-SHA-256 `426e874a32a984d6573eb7b9c80799dcd5c8569d4d2f05629f8a0b82ea8ace84`. CI grün.

**Erledigt:**
- ADR-039: Das erste Speichern schreibt nur in eine Zieldatei, die es vorher nicht gab, prüft den Formatfilter vorab (Imagick und GD) und übernimmt nur ein vollständiges WebP genau an der Zieldatei in den Maßen der Quelle, bei Transparenz mit Alphakanal. Sonst wird nur die eigene Zieldatei entfernt, eine abweichend gemeldete Datei bleibt und steht in der Meldung.
- Tests: `encoder-guards.php` Teil 3 (Test-Editor mit `$target_mode`, GD-Fall). Gegen den Encoder von 1.1.4 21 Fehler, mit 1.1.5 keiner. Vorher übernahm 1.1.4 dort unter anderem einen fremden Pfad, ein WebP ohne Alphakanal, falsche Maße und abgeschnittene Dateien.
- Demos: 1.1.5 auf allen drei installiert, statische Dateien hashgleich zur ZIP (12/12). Ergebnis siehe „Getestet“.

**Entscheidungen:** Kodierung im ersten Speichern wird nicht vorgeschrieben (ImageMagick kodiert bei Qualität 100 selbst verlustfrei). GD erhält den Alphakanal transparenter PNG, die Alpha-Prüfung bricht dort nichts ab (geprüft).

**Offen / Nächster Schritt:**
- Merge und Release nur auf Felix' Freigabe.
- Entscheidung: echtes Verlustfrei (seit 1.1.4, 3–12 % größer) behalten oder bewusst near-lossless anbieten.
- Entscheidung: Vorschaugrößen entstehen weiter mit der Core-Qualität (WebP 86 oder Filterwert der Seite), nicht mit der Einstellung des Plugins (Briefing 4.3, Schritt 6).
- Quelle der 82 auf Öko und VAPODOR: Auf beiden ist „Admin and Site Enhancements Pro“ mit „Image Upload Control“ aktiv (WebP-Umwandlung beim Upload, WebP-Qualität 82, max. 1920 px). Das ist der wahrscheinlichste Kandidat, aber nicht belegt: Quellcode nicht geprüft (kommerziell), ASE meldet auf den Kopien Safe Mode (Duplikat-Erkennung), ob das Modul dann greift, ist offen. Keine Snippets mit „quality“.

**Fragen an Felix:** die zwei Entscheidungen oben.

**Getestet:**
- PHPCS sauber, PHPUnit 104 Tests. Integration lokal (WordPress 7.1.5, PHP 8.3, SQLite): Imagick 389 Prüfungen, erzwungenes GD 196.
- Öko-Zinsen #299684: alle 84 zurückgenommen (Originale per HEAD 84/84 unverändert), mit 1.1.5 neu umgewandelt (13:40–13:47 UTC). 84 umgewandelt, 7 übersprungen wie mit 1.1.4. Bytes, Kodierung und ersetzte Verweise aller 84 Zeilen gleich wie mit 1.1.4 (89.514.130 B). Startseite: 56 eigene Bildadressen, alle WebP, alle 200.
- VAPODOR #299685: acht Gruppen neu umgewandelt (sechs Standard, zwei Opt-in Freisteller), alle bytegleich zu 1.1.4. Einstellung danach wieder auf verlustfrei.
- Ludwigsfelder #299686: Scan 111 Bilder, 0 Kandidaten, Kernseiten fehlerfrei.

---

## 2026-11-26 14:30 – 1.1.3 und 1.1.4: PNG nach Transparenz, Qualität am Kodierpunkt, Testreihe auf drei Demokopien (Claude Code)

**Stand:** Entwurfs-PR #6, nicht gemergt, nicht veröffentlicht. Build **1.1.4 aus `d1d544b`**, ZIP-SHA-256 `e649856d1f8ba3937361f6e37a807da08367ff4aed7fe14d4f5e9586de96761f`. Danach nur Tests und Doku (`01ade5a`, `0d826b0`, dieser Eintrag), Plugin-Inhalt dateiweise identisch.

**Erledigt:**
- 1.1.3 (`9c951dc`, ADR-037): PNG mit Alphakanal, aber ohne durchsichtiges Pixel, wird per Imagick-Pixelprüfung verlustbehaftet kodiert. Opt-in „automatisch“ für Freisteller (zweite Fassung verlustbehaftet mit verlustfreiem Alphakanal, nur bei höchstens halber Größe).
- 1.1.4 (`d1d544b`, ADR-038), nach Abnahmebefunden von Felix: Qualität für genau einen `save()` über `wp_editor_set_quality` festgehalten (der Core setzte sie in `get_output_format()` auf seinen Standard zurück). Zweite PNG-Fassung nur aus vorher neu angelegter eigener Zwischendatei, Formatfilter vorab geprüft, Übernahme nur als vollständiges verlustbehaftetes WebP mit Alphakanal in PNG-Maßen (`Webp_Info`). Sonst bleibt die verlustfreie Datei, nur die eigene Zwischendatei wird entfernt.
- Tests: `encoder-guards.php` mit Test-Editor (Fixture), Gegenprobe gegen 1.1.3-Code 40 Fehler. Q95-Erwartung nach echter Größe (Windows Q16). `purge.php` mit deterministischem Skip-Bild (Rauschen, JPG Qualität 8), Skip über Scan oder Umwandlung belegt, läuft jetzt auch mit Imagick.
- Testreihe Serie 3 auf #299684 Öko-Zinsen, #299685 VAPODOR, #299686 Ludwigsfelder (Raidboxes-Demos aus Backups vom 26.11.). Je Box Isolations-Plugin vor der Baseline (keine externen Purge-/Optimierungsaufträge, keine Mails), Baseline, 1.1.2, 1.1.4-Endstand. Nachweise, Tabellen und Screenshots im Projekt (Claude-Projekt „Pagespeed WordPress“).

**Ergebnisse (Kurzfassung):**
- Öko: 84 umgewandelt, 7 übersprungen. 17 deckende RGBA-PNG 12,61 → 3,39 MB (1.1.2 → 1.1.4). 45 JPG bytegleich zu 1.1.2, weil der Core-Rücksetzwert auf dieser Kopie schon 82 lieferte (Filter der Seite, nicht identifiziert). Gegenprobe 40/95 an echten Demobildern: Einstellung wirkt jetzt.
- VAPODOR: 420 Einträge (108 eigene Dateien). Endstand 38,57 MB statt 40,60 MB (1.1.2). Opt-in auf genau 13 belegten Freisteller-Gruppen: Standard 1.1.4 3,94 MB → Opt-in 0,87 MB (−78,0 %), Alphakanal in allen 13 Hauptdateien bitgleich zum PNG.
- Ludwigsfelder: 111 Bilder, alle schon WebP, 0 Kandidaten mit 1.1.2 und 1.1.4. Keine Medienänderung, Plugin-Seiten ohne PHP-Meldungen.
- Wiederherstellung vor der Neuumwandlung mit 1.1.4: Öko 84/84, VAPODOR 108/108 Originale bei Länge, ETag und Last-Modified unverändert, alle WebP-Hauptdateien entfernt.

**Entscheidungen:**
- Verlustfrei heißt ab 1.1.4 wirklich verlustfrei: ImageMagick setzt bei `webp:lossless` `near_lossless` auf die Qualität. Bis 1.1.3 war „verlustfrei“ auf den Demos near-lossless 82/86. Transparente PNGs werden dadurch 3–12 % größer (gemessen). Opt-in „automatisch“ bleibt der Weg für Freisteller.
- VAPODOR: Zwischenstufe 1.1.3 ausgelassen, weil deren Freisteller-Ausgabe den Qualitätsfehler trug. Vergleich 1.1.3 → 1.1.4 an echten Bildern auf Öko (17 PNG).
- PSI-Endstand mit je 3 Läufen pro Kernseite, weil die ausgelieferten Bytes auf VAPODOR sich gegenüber 1.1.2 deutlich änderten. Ein Öko-Lauf parallel zur Headless-Messung derselben Box wurde verworfen.

**Offen / Nächster Schritt:**
- Score-Rückgänge mit steigendem TBT (Öko-Startseite mobil Median 55 → 45 → 38, TBT 191 → 532 → 1.639 ms; VAPODOR Raumdesinfektion Desktop 69 → 62 → 45) bei identischen Skriptdateien und -bytes. Nicht der Bildumstellung zugeschrieben. Der PSI-Messrechner meldete in diesen Läufen niedrigere `benchmarkIndex`-Werte (Öko mobil 1082 → 444).
- Unverändert außerhalb des Plugins: WPML-Client-Weiterleitung (VAPODOR lädt `/en/` nach), Elementor-`::before`-Overlays ohne Lazy Load, rund 50 render-blockierende CSS-Dateien, OMGF-Schriften von der Live-Domain (Ludwigsfelder), Turnstile, JetElements-Akkordeonbilder ohne `srcset`.
- Der Formatprüfpfad des **ersten** Speicherns (`encode()`, nicht WebP → Datei löschen) ist unverändert, außerhalb des Auftrags.
- Filter `wp_editor_set_quality` auf den Demokopien nicht identifiziert.
- Demoboxen laufen laut Raidboxes-Banner in 14 Tagen ab.

**Fragen an Felix:** keine offenen.

**Getestet:**
- PHPCS sauber, PHPUnit 104 Tests (239 Prüfungen). Integration lokal (WordPress 7.1.5, PHP 8.3, SQLite): mit Imagick 6.9.12-98 339 Prüfungen inkl. `purge`, mit erzwungenem GD 196. CI für `0d826b0` grün.
- Demos: installierte statische Plugin-Dateien (README, admin.js, admin.css, POT) auf allen drei Boxen hashgleich zur ZIP.

---

## 2026-11-26 03:35 – Version 1.1.2: Fingerabdruck je Datei, verschachteltes Löschen (Claude Chat)

**Stand:** Folge-Build im selben Entwurfs-PR (#6). Nicht gemergt, nicht veröffentlicht.

**Anlass:** Zwei weitere bestätigte Datenverlustrisiken im Stand `6040f8c` (1.1.1) aus der unabhängigen Prüfung.

**Erledigt:**
- **Inhaltsnachweis je Datei** (ADR-035): Neue Umwandlungen speichern Größe und SHA-256 jeder erzeugten Datei. Rückgängig prüft jede vorhandene Datei jedes Gruppenmitglieds vor dem ersten Schreiben, auch wenn andere fehlen. Ersatz unter gleichem Pfad, Typ und Länge wird erkannt. Ältere Läufe ohne Fingerabdruck: widerlegbare Fälle werden abgelehnt, sonst werden die WebP-Dateien beim Rückgängig beiseitegelegt statt gelöscht.
- **Verschachteltes Löschen** (ADR-036): Der Löschschutz entscheidet am Stand der Datenbank statt über einen einzelnen statischen Vorgang. Löscht ein anderes Plugin im Hook von A ein weiteres Bild C, behält B seine Dateien.
- Tabelle Version 2 (neue Spalte `new_hashes`), wird beim nächsten Admin-Aufruf und vor jedem Umwandlungsschritt angelegt.

**Entscheidungen:** ADR-035, ADR-036.

**Plattformgrenze (unabhängige Prüfung, Windows, WordPress 7.1.5, PHP 8.3, GD, SQLite):** 1.1.1 bestand dort 178 von 179 Prüfungen. Nach dem endgültigen Löschen beider Einträge einer Gruppe bleiben drei unreferenzierte WebP-Größen und der HEIC-Master liegen. Gegenprobe dort: Auch bei einem nie umgewandelten einzelnen JPG lässt der Kern unter Windows alle vier Vorschaubilder liegen. Das verbleibende Bild beim Löschen nur eines Eintrags und das Rückgängig sind auch unter Windows geschützt. Unter Linux bleibt nichts zurück. Kein Patch am Kern.

**Offen / Nächster Schritt:**
- 1.1.2 ist unter Windows noch nicht gelaufen.
- Für die sechs Bilder der JBZ-Demo (Lauf mit 1.1.0, ohne Fingerabdruck) gilt die Regel für ältere Läufe: Ein Rückgängig würde ihre WebP-Dateien nach `uploads/akwu-sicherung/` verschieben statt löschen.
- **Bekannte Grenze beim Beiseitelegen:** Lässt sich eine Datei eines älteren Laufs nicht in den Sicherungsordner verschieben (Ordner nicht anlegbar, Zielname schon belegt), bleibt sie an ihrem ursprünglichen Platz erhalten. Gelöscht wird sie nicht. Die Protokollmeldung kann trotzdem den Sicherungsordner nennen, sobald dieser existiert. Die Sicherung deshalb immer am tatsächlichen Pfad prüfen.
- Übrige offene Punkte wie im Eintrag zu 1.1.1.

**Getestet:**
- PHPCS sauber, PHPUnit 91 Tests, 203 Prüfungen.
- Integration (WordPress 7.1.5, PHP 8.3, SQLite, GD) über `bin/integration-sqlite.sh`, frisch aufgesetzt: siehe Protokoll im Nachweispaket. `alias-guards.php` hat jetzt 74 Prüfungen, darunter ein echter Kern-Test, der aus dem Hook von A ein unabhängiges C löscht (vor und nach dem Hook des Plugins), und die Fälle „gleiche Länge“, „Vorschau fehlt“, „alles fehlt“ mit und ohne Fingerabdruck.
- Zeilen aus 1.1.0 nach dem Update auf 1.1.2 geprüft (Tabelle von Version 1 auf 2), 12 Prüfungen bestanden.
- Frischer Lauf über `bin/integration-sqlite.sh`: 194 Prüfungen bestanden (`roundtrip` 26, `purge` 29, `heic-alias` 42, `embeds` 23, `alias-guards` 74). CI für `c4295c1`: alle fünf Jobs grün.
- Unabhängige Schlussabnahme unter Windows (WordPress 7.1.5): Ersetzungen im Editor in frischen 1.1.2-Läufen blockiert, beim verschachtelten Löschen bleiben alle sechs Dateien von B bytegleich, drei echte Altzeilen aus 1.1.1 verlustfrei (zweimal Sicherung mit der Prüfsumme der bearbeiteten Datei, einmal Konflikt).
- JBZ-Demo: Update 1.1.0 → 1.1.1 → 1.1.2 ohne Schreibtest. Vor und nach jedem Update dieselben 14 öffentlichen Dateien bytegleich, Tour-Markup bytegleich, alle sechs Bilder weiter zum Zurücksetzen angeboten. Die PSI-Einzelläufe dazu sind kein Leistungsnachweis.

## 2026-11-26 03:15 – Version 1.1.1: Schutz bei gemeinsamen Dateien, Befunde der unabhängigen Prüfung (Claude Chat)

**Stand:** Folgeversion im selben Entwurfs-PR (#6). Nicht gemergt, nicht veröffentlicht.

**Anlass:** Unabhängige Prüfung von 1.1.0 (Code, Protokolle, eigener Lauf unter Windows). Fünf Befunde, alle bestätigt und behoben.

**Erledigt:**
- **Löschen eines Eintrags** (ADR-031): Löscht jemand einen von mehreren Einträgen derselben umgewandelten Datei endgültig, bleiben WebP-Datei, Größen und Master für die anderen liegen. Der verbliebene Eintrag lässt sich weiter zurücksetzen. Ohne aktives Plugin gilt das Verhalten von WordPress wie vor der Umwandlung, das ist dokumentiert und getestet.
- **Rückgängig nur aus dem erwarteten Zustand** (ADR-032): Die ganze Gruppe wird geprüft, bevor irgendetwas geschrieben wird. Ersetztes Medium, zusätzliche Dateien oder geänderter Inhalt: Abbruch ohne Änderung. Gilt auch für die Wiederaufnahme nach Abbruch und für Zeilen aus 1.0.0 und 1.1.0.
- **Größenzuordnung** (ADR-033): Gemeinsam umgestellt wird nur bei gleichen Größennamen und Pixelmaßen.
- **`loading="eager"`** (ADR-034): Ausdrücklich gesetztes `loading` wird angezeigt und nie geändert, kein Erfolg ohne Änderung.
- **Windows:** `Attachment_Files::relative()` erkennt normalisierte Laufwerkspfade (`C:/…`) als absolut. Vorher stand der Upload-Ordner doppelt davor und der HEIC-Master fehlte nach der Umwandlung in `original_image` (Datei und Rückgängig waren intakt). Pfade außerhalb des Upload-Ordners und fremde Laufwerke bleiben außen vor.
- `bin/integration-sqlite.sh` und CI-Job: Die Integrationstests laufen jetzt bei jedem Push auf WordPress mit SQLite.

**Entscheidungen:** ADR-031 bis ADR-034.

**Zahlen aus dem Test von 1.1.0, abgeglichen (Rohdaten):**
- Die drei Hauptbilder der Mechatroniker-Seite: **Dateigröße auf dem Server** 1.493.926 → 863.770 B (630.156 B weniger). **Übertragen im Desktop-Browser**, einschließlich Antwortkopf: 1.496.627 → 865.777 B (630.850 B weniger). Beides ist richtig, es sind verschiedene Messgrößen.
- Mechatroniker PSI mobil 64 → 73 bei 94 → 95 Anfragen und praktisch gleichem Transfer beim Aufruf (1.371.440 → 1.371.393 B): Schwankung. Der nachgewiesene Bildgewinn beginnt erst beim Scrollen.
- Startseite mobil, LCP: simuliert 26,4 → 9,0 s, im selben Lauf beobachtet 2.346 → 2.343 ms (Median aus je 3). **Beide Werte sind Laborwerte aus Lighthouse.** Felddaten von Besuchern liegen für die Demo nicht vor.

**Offen / Nächster Schritt:**
- Unter Windows hat die unabhängige Prüfung 1.1.0 laufen lassen (38 von 39). 1.1.1 ist dort noch nicht gelaufen, die Pfadkorrektur ist hier nur über Unit-Tests mit Windows-Pfaden und unter Linux geprüft.
- Wie WPML selbst beim Löschen eines Duplikats mit gemeinsamen Dateien umgeht, ist nicht am Quellcode geprüft. Lokal steht kein WPML zur Verfügung.
- Ein Bildoptimierer, der die WebP-Dateien nachträglich neu komprimiert, sperrt Rückgängig für diese Bilder (ADR-032, gewollt).
- Weiter offen aus 1.1.0: Unterbrechung genau zwischen Bild und Zweiteintrag, Zweiteinträge aus Läufen mit 1.0.0.

**Getestet:**
- PHPCS sauber, PHPUnit 91 Tests, 203 Prüfungen.
- Integration (WordPress 7.1.5, PHP 8.3, SQLite, GD), frisch aufgesetzt über `bin/integration-sqlite.sh`: 175 Prüfungen bestanden (`roundtrip` 26, `purge` 29, `heic-alias` 42, `embeds` 23, neu `alias-guards` 55).
- Protokollzeilen aus 1.1.0: Gruppen mit dem Code von `04fa996` umgewandelt, danach mit 1.1.1 geprüft, 11 Prüfungen bestanden.
- Destruktive Fälle nur mit eigens erzeugten Testbildern in der Wegwerf-Installation, nicht auf der Demo.

## 2026-11-26 02:45 – Version 1.1.0: HEIC-Uploads, gemeinsame Dateien, Tour erst bei Bedarf (Claude Chat)

**Stand:** Feature-Stand auf Basis von `20677fb` (v1.0.0). Nicht gemergt, nicht veröffentlicht.

**Anlass:** Test auf der JBZ-Kopie (25.11.2026): 903 Mediathek-Einträge, nichts umwandelbar. Sechs als HEIC hochgeladene Fotos lagen als JPG auf dem Server und wurden nicht erkannt, WPML führt viele Dateien unter zwei Bild-IDs. Auf der Startseite kam der größte Teil der Bytes von einer 3D-Tour weit unten.

**Erledigt:**
- **HEIC-Einträge mit echter JPG-Datei** werden erkannt und umgewandelt, der HEIC-Master bleibt (ADR-027). Echte HEIC bleibt unberührt.
- **Gemeinsame Dateien** (WPML-Duplikate): Scan, Umwandlung, Verweise, Wiederholung nach Abbruch, Rückgängig und „Originale löschen“ behandeln die Einträge als Gruppe oder lassen sie mit Grund aus (ADR-028).
- **Neue Seite „Einbettungen“:** Matterport-Tour weit unten erst bei Bedarf laden, je Tour einzeln, zeichengenau zurücknehmbar (ADR-029).
- Scan-Format 3: Nach dem Update ist ein neuer Scan nötig.

- **Pixelmaße bleiben** (ADR-030): Die Hauptdatei wird bei der Umwandlung nicht mehr nach der aktuellen Obergrenze der Website neu verkleinert. Auf der JBZ-Kopie gefunden und behoben.

**Entscheidungen:** ADR-027 bis ADR-030.

**Offen / Nächster Schritt:**
- Wird ein Lauf genau zwischen der Umstellung eines Bildes und seiner Zweiteinträge unterbrochen, wandelt der zweite Versuch den Zweiteintrag einzeln um (eigene WebP-Dateien). Das ist sicher und einzeln rückgängig zu machen, aber nicht deckungsgleich. Abhilfe wäre, einem schon umgestellten Eintrag nachträglich zu folgen.
- Sites, die mit 1.0.0 umgewandelt wurden und Zweiteinträge haben: „Originale löschen“ ist jetzt geschützt, die Zweiteinträge selbst werden nicht nachgezogen.
- Kein CSV-Export des Scans. Der LCP-Hintergrund der Startseite (späte Entdeckung im CSS) bleibt Sache des Themes bzw. eines Laufzeit-Plugins.

**Getestet:**
- PHPCS sauber, PHPUnit 83 Tests.
- Integration lokal (WordPress 7.1.5, PHP 8.3, SQLite, GD): `roundtrip.php` 26, `purge.php` 29, neu `heic-alias.php` 39 und `embeds.php` 19 Prüfungen, alle bestanden.
- Demo-Kopie JBZ (Raidboxes, WordPress 7.1.5, WPML 5.2.0, Imagick), Build aus `04fa996`:
  - Scan: 903 Bilder, 6 bereit (mit 1.0.0: 0). Alle sechs sind HEIC-Uploads mit JPG-Datei, je Datei zwei Bild-IDs.
  - Umwandlung: 6 umgestellt, 3 Dateien, gleiche Pixelmaße, Hauptdateien 1.493.926 → 863.770 Bytes (−42 %). HEIC-Master und JPG-Originale per SHA-256 unverändert.
  - Rückgängig für eine Bild-ID stellt beide Einträge zurück. Nach dem Rückgängig alle Dateien identisch, WebP entfernt, Elementor-CSS wieder auf JPG.
  - Einbettungen: 6 Touren gefunden, die zwei der Startseite umgestellt, Zurücknehmen zeichengenau.
  - PageSpeed Startseite (Median aus 3): mobil 49 → 63, Gewicht 5,57 → 1,92 MB, TBT 344 → 0 ms. Computer 79 → 94. Mechatroniker: PSI unverändert (Bilder laden erst beim Scrollen), im Browser 631 KB weniger nach dem Durchscrollen.
  - Der erste Lauf auf der Demo deckte die erneute Verkleinerung auf (ADR-030), wurde zurückgenommen und mit dem korrigierten Build wiederholt.

## 2026-10-09 20:47 – M5 Abschluss, Version 1.0.0 (Claude Code)

**Stand:** M5 – fertig. Version 1.0.0, alle Meilensteine abgeschlossen.

**Für Felix:** Die ZIP liegt nach dem Merge unter Releases (`v1.0.0`). Installation: Plugins → Installieren → Plugin hochladen. Ablauf für Niovo steht in `README.md` (Staging zuerst).

**Erledigt:**
- M4 gemergt ([Plainzdzn/pagespeedwp#4](https://github.com/Plainzdzn/pagespeedwp/pull/4)).
- **PageSpeed (optional):** Mit API-Schlüssel misst das Plugin die Startseite mobil (Punktzahl, LCP, Seitengewicht).
  - Vorher: automatisch beim Start der Umwandlung oder auf der Übersicht per Knopf.
  - Nachher: im Bericht per Knopf.
  - WP-CLI: `wp akwu pagespeed vorher|nachher`.
- **Cache:** Die Systemprüfung zeigt die erkannten Cache-Plugins und auf Raidboxes ohne FastPixel den Hinweis zum Server-Cache.
- **Revisionen des Customizer-CSS** werden nicht mehr ersetzt (ADR-026). „Seiten und Beiträge“ im Bericht zählt keine Revisionen mehr.
- **Scan:** Bilder ohne Metadaten heißen „Metadaten fehlen“ und werden nicht eingeplant. „Alle Bilder“ weist nach einer Umwandlung auf den alten Scan hin.
- **i18n:** POT-Datei in `languages/` (487 Texte), `Domain Path` im Header, `load_plugin_textdomain`. Keine Ausrufezeichen in den Texten.
- **README** mit Anleitung für Niovo, WP-CLI und dem, was das Plugin nie tut.
- **Review (Codex) umgesetzt:** Eine neue Vorher-Messung oder ein neuer Lauf verwirft den alten Nachher-Wert von PageSpeed. Das Scan-Format ist auf Version 2 erhöht, ältere Scans ohne Metadaten-Prüfung verlangen einen neuen Scan.
- **Deinstallation** geprüft: Optionen und Protokoll weg, Bilder bleiben WebP, alle Bilder im Frontend erreichbar.

**Entscheidungen:** ADR-025 (PageSpeed nur mit Schlüssel und auf Anlass), ADR-026 (Revisionen).

**TODO (nicht verifiziert):**
- Raidboxes-Server-Cache ohne FastPixel, siehe M3.
- Die PageSpeed-API ist aus der Cloud-Sitzung nicht erreichbar. Getestet wurde mit einer simulierten Antwort im Format der API v5. Beim ersten echten Einsatz bitte einmal „Jetzt messen“ klicken und den Wert mit pagespeed.web.dev vergleichen.

**Offen / Nächster Schritt:** Einsatz auf einer Staging-Seite. Ideen für später: Anleitung und Protokoll als Kopfzeilen-Knöpfe auf der Übersicht (Mockup), AVIF.

**Fragen an Felix:** keine.

**Getestet** (Cloud-Sitzung, WordPress 7.1.3, Elementor 4.3.4, GD):
- PHPCS sauber, PHPUnit 71 Tests grün (neu: Auslesen der PageSpeed-Antwort).
- `tests/integration/roundtrip.php` (jetzt auch: Revisionen des Customizer-CSS unverändert, Bild ohne Metadaten nicht angefasst) und `tests/integration/purge.php` bestanden.
- Browser: PageSpeed vorher automatisch beim Start, nachher per Knopf, „54 → 81“ im Bericht. Rundgang über alle sieben Seiten ohne Konsolenfehler.
- `wp plugin uninstall --skip-delete` nach einer Umwandlung: keine `akwu_*`-Optionen, keine Tabelle, 7 Anhänge WebP, alle 25 Bilder der Testseiten vorhanden.
- Screenshots: `docs/screenshots/m5/`.

---

## 2026-10-09 20:30 – M4 Bericht, Rückgängig, Originale löschen (Claude Code)

**Stand:** M4 – fertig (Version 0.4.0). M5 folgt direkt.

**Erledigt:**
- M3 gemergt ([Plainzdzn/pagespeedwp#3](https://github.com/Plainzdzn/pagespeedwp/pull/3)), erstes Release v0.3.0 vom Workflow gebaut.
- **Bericht** wie im Mockup:
  - Vorher/nachher als Balken mit Kennzahlen (umgewandelt, übersprungen, Verweise ersetzt samt Seitenzahl). Die Kachel „PageSpeed mobil“ ist vorbereitet, die Messung kommt mit M5.
  - „Größte Ersparnis“ mit „Alle anzeigen“, daneben „Bitte prüfen“ mit Links zu den Fundstellen.
  - In der Kopfzeile „CSV exportieren“ (Semikolon, UTF-8 mit BOM) und „Rückgängig machen“.
- **Originale löschen** im Bericht:
  - Bestätigung durch Eintippen der Anzahl, falsche Zahl wird abgelehnt.
  - Gelöscht werden Original, `-scaled`-Quelle, alte Größen und eindeutige Elementor-Thumbs.
  - Bleiben: Originale, deren alte Adresse noch in CSS, Snippets oder Theme steht, und Dateien, die ein anderer Anhang führt.
  - Danach ist Rückgängig für diese Bilder gesperrt, mit Grund in der Liste.
- **Rückgängig** als eigene Seite: alle Bilder auf einmal oder einzeln, mit Fortschritt. Läuft nach einer Unterbrechung beim Öffnen der Seite weiter.
- **Bild löschen** in der Mediathek nimmt jetzt auch die alten Originale mit (ADR-024). Seine Protokollzeilen werden dabei entfernt.
- **Review (Codex) umgesetzt:**
  - Vor dem Löschen der Originale läuft immer eine frische Gegenprobe (`Verifier`). Wurde eine alte Adresse nach der Umwandlung wieder eingefügt, bleibt das Original.
  - Die Menge der noch verwendeten Bilder wird vollständig gespeichert, nur die Fundstellen für die Anzeige sind auf 500 gekappt.
  - Lässt sich eine Datei nicht löschen, gilt das Bild nicht als bereinigt und kann erneut versucht werden.
- **WP-CLI:** `wp akwu report [--format=summary|table|csv|json]`, `wp akwu rollback [--ids] [--dry-run] [--yes]`, `wp akwu purge-originals [--dry-run] [--yes]`.

**Entscheidungen:** ADR-022 bis ADR-024. Ergänzung zum Briefing: Das Löschen eines Bilds nimmt die Originale mit. Ohne das blieben nach der Umwandlung verwaiste JPG/PNG auf dem Server.

**Offen / Nächster Schritt:** M5 – PageSpeed-API (optional), Feinschliff Cache-Purger und Raidboxes-Hinweis, Deinstallation, i18n-Prüfung, README für Niovo, Release-ZIP.

**Fragen an Felix:** keine.

**Getestet** (Cloud-Sitzung, WordPress 7.1.3, Elementor 4.3.4, GD):
- PHPCS sauber, PHPUnit 67 Tests grün (neu: CSV-Felder mit Formel-Schutz).
- `tests/integration/roundtrip.php`: Rückgängig jetzt über den Job wie in der Oberfläche, wieder Byte für Byte wie vorher.
- `tests/integration/purge.php`:
  - Originale von 4 Bildern gelöscht (auch `-scaled`-Quelle und die Größe aus dem alten Theme). `bild.png` und `bild.jpg` bleiben, weil sie in CSS stehen.
  - Alle WebP-Dateien sind noch da, alle Bilder der Testseiten abrufbar.
  - Rückgängig ist für gelöschte Originale gesperrt, für die behaltenen möglich.
  - Eine nach der Umwandlung eingefügte alte Adresse findet die Gegenprobe vor dem Löschen, das Original bleibt.
- Im Browser:
  - Bericht, CSV-Download (BOM geprüft).
  - Originale löschen mit falscher, dann richtiger Zahl.
  - Rückgängig einzeln und für alle. Keine Konsolenfehler außer der erwarteten 400 bei falscher Zahl.
- Ein umgewandeltes Bild per `wp post delete` gelöscht: Danach liegen weder WebP noch alte PNG auf dem Server.
- Screenshots: `docs/screenshots/m4/`.

---

## 2026-10-09 20:10 – M3 Umwandlung (Claude Code)

**Stand:** M3 – fertig (Version 0.3.0). M4 folgt direkt.

**Erledigt:**
- M2 gemergt ([Plainzdzn/pagespeedwp#2](https://github.com/Plainzdzn/pagespeedwp/pull/2)).
- **Umwandlung** (`Conversion`, in Paketen per REST oder `wp akwu convert`):
  - Je Bild (`Converter`): Zustand in `akwu_log` sichern, WebP erzeugen, bei zu wenig Ersparnis überspringen, Anhang mit derselben ID umstellen, alle Größen neu erzeugen, `Url_Map` alt → neu je Größenname. Namenskollision `bild.png` + `bild.jpg` ergibt `bild.webp` und `bild-1.webp`.
  - Verweise je Paket (`Replacer`, `Value_Replacer`): Beiträge (Inhalt, Auszug), Postmeta, Termmeta, Optionen. Serialisiert ohne unserialize, `_elementor_data` als JSON. Nie `guid`, nie `custom_css`, nie Customizer-CSS, Snippets oder Theme-Dateien.
  - Abschluss: Elementor-CSS und Caches (`Cache_Purger`), dann Gegenprobe nach alten Adressen.
  - Pausieren, Fortsetzen, „Abbrechen und zurücksetzen“ (nimmt alle Bilder des Laufs zurück). Abgebrochene Requests werden beim nächsten Paket aufgeräumt, ein Bild, das zweimal abbricht, wird übersprungen.
- **Oberfläche:** Umwandlungsseite wie im Mockup (Fortschritt, Kacheln mit „Bild-IDs verändert“, Ablauf, Live-Protokoll), nach dem Lauf „Erneuert“ und „Gegenprobe“. Knöpfe in der Kopfzeile, Fortschritt in der Admin-Leiste, Hinweis auf allen Plugin-Seiten, solange ein Lauf offen ist. Auf der Übersicht „Erst 10 testen“ (die 10 meistgenutzten Bilder) und „Umwandlung starten“, beides nur mit Backup-Häkchen und bestandener Systemprüfung.
- **WP-CLI:** `wp akwu convert [--dry-run] [--limit=<n>] [--ids=<ids>] [--test] [--resume] [--yes]`.
- **Review (Codex) umgesetzt:** Bilder ohne Metadaten werden vor jeder Änderung abgelehnt (sonst kein Rückgängig möglich). Alte Größen, die WordPress nicht mehr registriert (z. B. nach Theme-Wechsel), bekommen eine eigene WebP-Datei in denselben Maßen, damit jede alte Datei genau eine neue hat und Rückgängig exakt bleibt. Seed und Rundlauf decken beide Fälle ab.
- **Release-Workflow repariert:** `release.yml` war kein gültiges YAML (`: ` in einem ungequoteten Wert), deshalb gab es nach M2 kein Release v0.2.0. Das erste Release entsteht mit dem Merge von M3 (v0.3.0).

**Entscheidungen:** ADR-018 bis ADR-021, im Briefing unter „Änderungen“ vermerkt:
- MIME-Typ direkt per `$wpdb` statt `wp_update_post()` (keine fremden Hooks, kein kses).
- Serialisierte Werte per Tokenizer statt `maybe_unserialize()`.
- Zusätzliche Status `working`, `converted`, `cancelled` für die Fortsetzbarkeit.
- Verweise je Paket statt am Ende, damit die Seite auch bei Abbruch nie auf fehlende Dateien zeigt.

**TODO (nicht verifiziert):** Raidboxes-Server-Cache ohne FastPixel. Dafür gibt es keine dokumentierte Schnittstelle. Das Plugin zeigt auf Raidboxes ohne FastPixel einen Hinweis, den Cache im Dashboard zu leeren (Erkennung: Hostname `box-…` und Ordner `rb-plugins`). FastPixel leert den Raidboxes-Cache laut eigener Doku mit.

**Offen / Nächster Schritt:** M4 – Bericht mit CSV, Rückgängig (alles oder je Bild), Originale löschen, `wp akwu report|rollback|purge-originals`.

**Fragen an Felix:** keine.

**Getestet** (Cloud-Sitzung, WordPress 7.1.3, Elementor 4.3.4, GD):
- PHPCS sauber, PHPUnit 59 Tests grün (neu: `Url_Map`, `Value_Replacer` mit serialisierten, verschachtelten und JSON-Werten).
- `tests/integration/roundtrip.php`: Seed, Scan, alle 6 Bilder umwandeln, prüfen (IDs gleich, WebP-Dateien da, `_elementor_data` gültig und ersetzt, Custom CSS und Customizer-CSS unverändert, Theme-Mod ersetzt, Gegenprobe ohne unerwartete Reste, alle Bilder der Testseite abrufbar), dann alles zurück: Beiträge, Postmeta, Theme-Mods, Customizer-CSS und Upload-Ordner Byte für Byte wie vorher. Bestanden.
- Im Browser (Playwright, Paketgröße 1): Start ohne Backup-Häkchen wird abgelehnt, Start, Pausieren nach dem ersten Paket, Hinweis auf anderen Seiten, Fortsetzen, Abbrechen (3 Bilder zurückgesetzt, 3 nie begonnen), neuer Lauf bis „Fertig“, Admin-Leiste zeigt den Fortschritt. Keine Konsolenfehler.
- `wp akwu convert --dry-run`, `--test --yes`, `--ids` mit ungültigen IDs, `--resume` ohne offenen Lauf.
- Screenshots: `docs/screenshots/m3/`.

---

## 2026-10-09 19:36 – M2 Scan (Claude Code)

**Stand:** M2 – fertig (Version 0.2.0). M3 folgt direkt.

**Vorgaben von Felix (nach M1):** M1 mergen, dann ohne Zwischenstopp weiterarbeiten und selbst nach `main` mergen. Ziel ist eine ZIP, die er nur noch installiert (ADR-014). Den Standard-Branch muss niemand umstellen. FastPixel: Es gilt immer die neueste Version, nicht jede Seite hat FastPixel oder Bildkomprimierung aktiv (ADR-017).

**Erledigt:**
- M1 gemergt ([Plainzdzn/pagespeedwp#1](https://github.com/Plainzdzn/pagespeedwp/pull/1)).
- **Scan** (`Scanner`, nur lesend, in Schritten per REST oder `wp akwu scan`):
  - Bestandsaufnahme mit Original, `-scaled`, allen Größen und eindeutig zuordenbaren Elementor-Thumbs. PNG-Transparenz liest `Png_Info` aus dem Dateikopf.
  - Verwendung: Beiträge (Inhalt, Auszug), Postmeta (`_elementor_data` als JSON, `_elementor_page_settings` strukturiert, sonst roh), Optionen, Termmeta, Beitragsbilder und Logo über die ID, Elementor-4-Atomic-Bilder über die ID.
  - Warnungen: Customizer-CSS, Custom CSS in Elementor, Code-Snippets-Tabelle, Theme-Dateien.
  - Hochrechnung aus bis zu 20 Stichproben, gemischt nach JPG, PNG, PNG mit Transparenz und vom kleinsten bis zum größten Bild. Die Umwandlung läuft dabei in ein temporäres Verzeichnis.
- **Encoder** nach Briefing §4.3: JPG und PNG ohne Transparenz mit Qualität 82, PNG mit Transparenz mit Imagick verlustfrei, sonst 90. EXIF-Drehung wird angewendet, Metadaten entfernt (Imagick).
- **Oberfläche:**
  - Übersicht mit echten Zahlen: Kennzahlen, Verteilung nach Format, größte Dateien, „Vor dem Start“ mit Warnungen und Speicher, Scan-Start mit Fortschritt.
  - „Alle Bilder“ mit Filtern, Fundstellen zum Aufklappen und Links zum Bearbeiten.
  - Einstellungen als Formular. Die Systemprüfung vergleicht den freien Speicher mit dem 1,5-Fachen.
- **Sperre** `Lock` gegen parallele Läufe (Admin, zweites Fenster, WP-CLI).
- **Release:** `bin/build-zip.sh` und Workflow `release.yml` (ZIP als GitHub-Release je Version).

**Entscheidungen:** ADR-014 bis ADR-017. Dazu:
- Fremde Hosts werden nie ersetzt (ADR-015). Staging mit Live-URLs im Inhalt bleibt unverändert.
- Gezählt werden Verweise auf Größen, die nicht in den Metadaten stehen (z. B. `bild-640x480.png` nach einem Theme-Wechsel). Sie landen unter „Bitte prüfen“.
- Elementor-Caches (`_elementor_css`, `_elementor_element_cache`, `_elementor_page_assets`) zählen nicht als Verwendung, weil `clear_cache()` sie nach der Umwandlung löscht (am Elementor-Quellcode 4.3.4 geprüft).
- Code Snippets: Tabelle `{prefix}snippets` mit `id`, `name`, `code`. Der Bearbeiten-Link kommt über `code_snippets()->get_menu_url( 'edit' )`, beides am Plugin-Quellcode geprüft.

**Offen / Nächster Schritt:** M3 – Umwandlung, Replacer, Log-Tabelle, Pakete, „Erst 10 testen“, `wp akwu convert`.

**Fragen an Felix:** keine.

**Getestet** (Cloud-Sitzung, WordPress 7.1.3, Elementor 4.3.4, GD):
- PHPCS sauber, PHPUnit 44 Tests grün (neu: `Url_Matcher`, `Png_Info`, `Settings`, `Estimator`).
- Scan gegen die Seed-Daten: Alle Fundstellen des Seeds werden erkannt (Section-, Container- und Kit-Hintergrund, Galerie, Text-Editor, Bildblock samt Link, Auszug, serialisiertes Postmeta, Theme-Mod). Customizer-CSS und Elementor-Custom-CSS erscheinen als Warnung. Die bereits komprimierte JPG wird als „Überspringen“ erkannt (WebP wäre 2 % größer).
- Im Browser: Scan per Knopf (2 s), Übersicht, „Alle Bilder“ mit Filter, Einstellungen speichern. Keine Konsolenfehler.
- `wp akwu scan` mit Zusammenfassung und Warnungsliste.

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
