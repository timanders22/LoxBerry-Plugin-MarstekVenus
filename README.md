# LoxBerry-Plugin: Marstek Venus E

Bindet **MARSTEK Venus E** Batteriespeicher lokal — ohne Cloud — an Loxone an.
Unterstützt werden bis zu **4 Geräte** der Venus-E-Serie: Venus E Gen 3.0
(5,12 kWh) und der **Venus E Mini** (2 kWh), gemischt möglich.

Loxone ist der Energiemanager: Das Plugin liefert Status (SOC, Leistung,
Temperatur, Firmware, Antwortzeit) und ein Spot-Stunden-Ranking (aWATTar), der
Miniserver entscheidet und sendet Leistungs-Sollwerte im Passiv-Modus — mit
doppeltem Sicherheitsnetz: Der Watchdog im Sollwert stoppt den Speicher, wenn
Loxone ausfällt, und der optionale **Auto-Fallback** gibt danach die Regie an
den Auto-Modus des Geräts zurück.

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, läuft mit PHP 7.4 und 8.x).

## Neu in 1.1.6

**Auf Anlagen, die mit 1.0.4 oder früher begonnen haben, lief der
minütliche Cron seit dem Update auf 1.0.5 nicht mehr.** Bis 1.0.4 lag
`cron/cron.01min` als Verzeichnis im Archiv, und LoxBerry legte es als
Verzeichnis `system/cron/cron.01min/marstekvenus/` an. Seit 1.0.5 ist es eine
Datei — aber der LoxBerry-Installer räumt beim Update nur die Datei ab, nie ein
Verzeichnis, und kopiert die neue Cron-Datei *hinein*. LoxBerry ruft jeden
Eintrag im Cron-Ordner direkt auf; ein Verzeichnis scheitert still. Gemessen
am 05.09.2026: kein `cron.err`, kein `herzschlag.json`, kein SOC-Verlauf, keine
kWh-Zähler, kein Auto-Fallback — seit dem 23.07.2026, bei jeder Fassung
dazwischen. `preupgrade.sh` räumt das Verzeichnis seit 1.1.6 vor dem Kopieren weg
(`<OK>`-Zeile im Installationsprotokoll), und `postinstall.sh` misst nach der
Installation nach, ob an dieser Stelle eine Datei liegt, und warnt sonst mit
`<WARNING>` und dem Handgriff für root. Wer nach dem Update in
`log/plugins/marstekvenus/` ein leeres `cron.err` und ein frisches
`herzschlag.json` sieht, hat den Beleg.

## Neu in 1.1.5

Diese Fassung behebt einunddreißig Befunde aus einer erneuten Durchsicht der
veröffentlichten 1.1.4. Vier davon wirkten im laufenden Betrieb.

**1. Ein verlorenes UDP-Paket setzte die Temperatur auf 0 — und die
Schutzschwelle sperrte daraufhin das Laden.** Der Rückfall auf die zuletzt
gemessenen Werte aus 1.1.0 griff nur, wenn *beide* Abfragen scheiterten.
Antwortete nur eine — und UDP verliert Pakete —, galt `OK=1`, und jedes Feld,
das die antwortende Abfrage nicht trägt, ging als 0 in den Zwischenspeicher.
Gemessen, mit einem vollständigen Abruf davor:

```
vorher : OK=1;SOC=73.5;BATP=0;TEMP=24.1;GRIDP=-120;FW=148
nachher: OK=1;SOC=73.5;BATP=-820;TEMP=0.0;GRIDP=-120;FW=148
```

Mit eingeschalteten Schutzschwellen sperrte die 0 anschließend jeden
Ladebefehl (`TEMP_MIN`, Vorgabe `temp_min = 0`) — bei `OK=1`, also in Loxone
unsichtbar. Jetzt gilt je Feld: kein frischer Messwert, alter Wert bleibt
stehen, und die Schutzschwellen urteilen nur über Felder, die in diesem
Durchgang wirklich gemessen wurden.

**2. Der unangemeldete Endpunkt schrieb.** Ein einziges `?status` ohne Token
stellte die Konfiguration samt Aktionstoken aus der Zweitschrift wieder her
(gemessen, mit Gegenprobe ohne Zweitschrift). Der Endpunkt legt jetzt nichts
mehr an; die Selbstheilung gehört hinter die Anmeldung.

**3. Beim Zurückspielen einer Sicherung wurden nur die Schlüssel geprüft, nie
die Werte.** Sechs von sechs vergifteten Werten gingen unverändert in die
Konfiguration. Zwei wirkten: ein Aktionstoken als Liste ließ `?token=Array`
schalten (beide PHP-Fassungen gemessen), und ein Themen-Präfix mit
Zeilenumbruch schleuste eine zweite `publish`-Zeile in jedes Datagramm an das
MQTT-Gateway. Der Weg liegt jetzt in der Bibliothek
(`marstek_sicherung_lesen()`), prüft jeden Wert gegen dieselbe Positivliste
wie das Formular und schreibt bei einem Durchfall **gar nichts**.

**4. `?debug=1` war ohne Token erreichbar** und umging den Zwischenspeicher:
gemessen 30 Sekunden je Aufruf gegen ein stummes Gerät, dreimal
hintereinander, während `?status` warm 0 Sekunden brauchte. Genau mit dieser
Begründung ist `?diag` seit 1.1.0 tokenpflichtig; `?debug` steht jetzt mit in
der Liste, und `?debug=0` schaltet nichts mehr ein.

### Was sich sonst geändert hat

- **Ein Cron-Durchgang dauerte neun Sekunden**, auch wenn das Gerät sofort
  antwortete: die Horchschleife wartete immer die volle Zeitgrenze ab. Damit
  war auch das Feld `MS` keine Antwortzeit, sondern eine Konstante (gemessen
  3030 ms bei 3 s Zeitgrenze an einem Gerät auf `127.0.0.1`).
- **Der Doppelt-senden-Filter der Energiezähler griff nicht**, weil das Feld
  `ALTER` in seine Signatur einging: zehn Themen je Minute statt nur bei
  Änderung (28 → 12 Datagramme statt 28 → 2; per Rückbau geeicht).
- **`bin/cron.php` hatte keinen Rückfall für die installierte Lage.** Blieb
  der Platzhalter unersetzt, fand es die Bibliothek nicht — `bin/healthcheck`
  daneben schon.
- **Die Tagesbilanz wurde bei jedem Durchgang neu geschrieben**, auch ohne
  Änderung, und zwar auch bei jedem `?energy`-Abruf aus Loxone.
- **Der Auto-Fallback meldete jede Minute weiter**, wenn er nicht durchkam.
- **Eine Anlage aus der Ein-Geräte-Zeit konnte ihre eigene Sicherung nicht
  zurückspielen** (`ip`, `port`, `pmax_*` blieben nach der Migration stehen).
- **Ließ sich das Formularmerkmal nicht ablegen**, wies die Oberfläche jedes
  Formular ab, ohne dass irgendwo stand, warum.
- Die Konfigurationsdatei bekommt **0600** wie alle ihre Kopien.
- Die Baustein-Liste: `#27` verlangte drei Eingänge an einem UND (aufgelöst in
  eine Kaskade), und die Soll/Ist-Meldung `#44` bekommt eine Schwelle davor —
  ohne sie meldete sie im Auto-Modus dauerhaft eine Störung, weil
  `MARSTEK_STATUS_SOLL` dann den Fehlwert `-32768` trägt. Zwei
  Vergleicher-Parameter trugen denselben Text, obwohl sie das Gegenteil
  bedeuten.
- Der Reiter „Einbindung in Loxone" hat **sieben feste Schritte**; bis 1.1.4
  begann die Zählung bei „Schritt 2", und die Zahl der Kästen hing an der
  Gerätezahl.
- Der `Comment` der erzeugten Vorlagen wird in Loxone Config zum Kachelnamen
  und ist jetzt kurz (bis 1.1.4 bis zu 112 Zeichen); die Nachkommastellen
  kommen aus dem Zahlenformat des Feldes.
- Der Reiter Test misst **wirklich**, ob Themenliste und Sendecode
  übereinstimmen — bis 1.1.4 verglich die Zeile zwei Rechnungen aus derselben
  Quelle und konnte gar nicht rot werden. Dazu drei neue Zeilen: das
  serverseitige `sm-active`, der eigene Cron-Eintrag und die
  Rückwärtsverweise der Baustein-Liste.
- `php bin/cron.php --selbsttest` prüft den Rechenkern (229 Fälle) und weist
  unbekannte Schalter ab, statt einen vollen Durchgang zu starten.
- `cron.err` wird gekappt, und beide Protokolle werden vom Ende gelesen.

## Neu in 1.1.4

Der Reiter MQTT unterscheidet die beiden Gateway-Fassungen. Ist
`Mqtt.Gatewayversion` in `config/system/general.json` nicht lesbar, stehen
beide Fälle nebeneinander, mit einem eigenen Satz dazu — vorher galt
stillschweigend der Wortlaut für Fassung 1, und der schickt jeden
V2-Anwender zu einem Eingabefeld, das der Kern dort abschaltet.

## Neu in 1.1.0

Diese Fassung behebt zweiundzwanzig Befunde aus einer zeilenweisen Durchsicht
und bringt die Funktionen dazu. Fünf Befunde wirkten im laufenden Betrieb:

**1. Eine Störung zog die Messwerte auf null.** Gemessen an 1.0.16, ein
einziger misslungener Abruf:

```
VORHER : soc 73.5  batp -820  temp 24.1  fw 148
NACHHER: soc 0     batp 0     temp 0     fw 0
```

In Loxone sprang damit der Ladezustand auf 0 % — und zwar in genau der Logik,
die dieses Plugin selbst empfiehlt (Entladesperre unter 12 %, Ladestopp über
97 %). Jetzt bleiben die zuletzt gemessenen Werte stehen, `OK` fällt auf 0,
und das **neue Feld `ALTER`** sagt, wie alt sie sind. Beides zusammen — sonst
wäre ein eingefrorener Wert von einem frischen nicht zu unterscheiden.

**2. Der Ausfall stand in keinem Protokoll.** Die Signaturbildung blendete vor
dem Vergleich *alle Zahlen* aus, und die Statuszeile besteht nur aus Zahlen:
`OK=1 …` und `OK=0 …` ergaben dieselbe Signatur. Nach dem ersten Lauf schrieb
das Plugin nie wieder eine Statuszeile. Zustandsfelder gehen jetzt wörtlich in
die Signatur ein, Messwerte weiterhin nicht.

**3. Eine krumme Zeile verwarf das ganze Formular.** Eine unvollständige IP in
Zeile 2 warf Gerätename, Status-Cache, Auto-Fallback und Markt gleich mit weg.
Jetzt wird die betroffene Zeile übergangen, alles Übrige gespeichert und die
Beanstandung daneben gemeldet.

**4. `?p=abc` schickte einen Sollwert 0 W an das Gerät, `?mode=quatsch` stellte
es auf Auto.** Ein Tippfehler in einer Loxone-Adresse gab damit die Regie an
den Speicher ab. Jetzt wird abgewiesen statt zurechtgebogen: `SET;OK=0;ERR=P`
beziehungsweise `MODE;OK=0;ERR=MODE`, ohne das Gerät anzufassen.

**5. Ohne die PHP-Erweiterung `sockets` antwortete der Endpunkt mit einer
leeren Seite** (HTTP 500, Rückgabewert 255). Der gesamte UDP-Verkehr hing an
`socket_create()`, und deren Fehlen ist kein abfangbarer Fehler, sondern ein
fataler; ein `@` davor hilft dagegen nicht. Der Regelweg — Unicast an das Gerät,
MQTT an das Gateway — läuft jetzt über `stream_socket_*` aus dem PHP-Kern. Nur
der Rundruf braucht die Erweiterung noch; fehlt sie, fallen ausschließlich
Rundruf und Gerätesuche aus, und der Reiter Test sagt das ausdrücklich.

### Neue Funktionen

| Funktion | Was sie bringt |
|---|---|
| **Herzschlag des Minutentakts** (`ZAEHLER`, `takt_zaehler`) | Ein toter Cron war bisher von einem ruhigen Haus nicht zu unterscheiden. Der Zähler läuft 0…999 um; bleibt er stehen, sind alle übrigen Werte eingefroren. `-1`, wenn der Takt nicht läuft. |
| **`bin/healthcheck`** | Roter Punkt am Plugin-Symbol auf der LoxBerry-Startseite — und, ohne eine Zeile Zusatzarbeit, das Ergebnis zusätzlich **retained** unter `<rechnername>/healthcheck/…` im Broker. |
| **Benachrichtigungszentrum** | Meldet beim n-ten Fehlschlag in Folge und gibt beim ersten Erfolg Entwarnung. Ab Werk **aus**. |
| **Soll/Ist-Quittung** (`SOLL`, `SOLLALTER`, `FBREST`) | Beantwortet die wichtigste Betriebsfrage: Nimmt der Speicher den Sollwert an und setzt er ihn um? `FBREST` macht den Auto-Fallback sichtbar, bevor er zuschlägt. |
| **Mehr aus den Spotpreisen** (`MINP`, `MAXP`, `SPREAD`, `NEXTP`, `HBIS`, `HBISMAX`, `ERRC`) | Alles aus derselben Liste, die ohnehin gebaut und bisher weggeworfen wurde. `HBIS` sagt, in wie vielen Stunden es am billigsten ist. `ERRC` sagt, **warum** es keine Ränge gibt — bis 1.0.16 kam stumm `RANK=99`. |
| **Aufschlag in ct/kWh** | Netzentgelte, Abgaben und Anbieteraufschlag. Damit ist `CURP` der Preis, den Sie wirklich zahlen. Auf den Rang wirkt sich ein gleichbleibender Summand nicht aus. |
| **Summe über alle Speicher** (`?summe`) | Ladezustand **nach Kapazität gewichtet** — bei einem Gen 3.0 neben einem Mini ist ein ungewichteter Mittelwert falsch. Fail closed: fehlt bei einem die Kapazität oder antwortet einer nicht, kommt `-1` statt einer Teilsumme. |
| **Tagesbilanz** | Das Gerät setzt seine Tages- und Monatszähler zurück; der Wert vom 31. ist am 1. weg. Das Plugin schreibt die Tagesabschlüsse jetzt selbst fort. Ein Tag ohne Messung bekommt **keine** Nullzeile. |
| **Gerätesuche im Netz** | Rundruf in das eigene Netz, Liste der Antworten mit Adresse, Modell und Firmware, je Zeile ein Knopf „in die Geräteliste übernehmen“. Ersetzt den Gang in die Geräteliste des Routers. |
| **Trockenlauf** (`&dry=1`) | Rechnet einen Sollwert vollständig fertig — Grenzen, Totzone, Vorzeichen, Watchdog, Schutzschwellen — und sendet ihn **nicht**. Derselbe Programmcode wie im Ernstfall, nur ohne die letzte Zeile. |
| **Schutzschwellen** | Temperatur- und SOC-Grenzen, die einen Sollwert abweisen. Bewertet wird **nur, was gemessen vorliegt**; ein fehlender oder über 15 Minuten alter Wert erzeugt weder Sperre noch Freigabe. Ab Werk aus. |
| **Hauptschalter „Steuerung aktiv“** | Den Speicher vorübergehend in Ruhe lassen, ohne alle Loxone-Adressen anzufassen. |
| **Sollwert auf alle Speicher verteilen** (`&dev=alle`) | Im Verhältnis der Leistungsgrenzen. Ab Werk aus. |
| **Verlauf: Tagesauswahl, zweite Kurve, CSV** | Die Messpunkte lagen schon acht Tage da und waren nach Mitternacht unerreichbar. Die Batterieleistung stand seit jeher in der dritten Spalte und wurde vom Bild ignoriert. |
| **Konfiguration sichern und zurückspielen** | Zwei Knöpfe im Reiter Einstellungen. Die Datei trägt das Aktionstoken — ohne es wäre sie nach dem Zurückspielen wertlos. |
| **Alle Vorlagen als Archiv** | Bei vier Speichern sonst dreizehn Klicks. |
| **Selbstprüfung im Reiter Test** | Achtzehn Zeilen, die **ohne Loxone** beantworten, ob die Einrichtung trägt — bis hin zu einem echten Aufruf des eigenen Endpunkts über 127.0.0.1. |

### Was sich für bestehende Anlagen ändert

* **Die Statuszeile ist länger geworden.** Angehängt sind `ALTER`, `ZAEHLER`,
  `SOLL`, `SOLLALTER`, `FBREST`. Vorhandene virtuelle Eingänge suchen weiter
  ihre eigenen Felder und laufen unverändert.
* **Die empfohlene Befehlserkennung trägt jetzt ein Semikolon**
  (`\i;SOC=\i\v` statt `\iSOC=\i\v`). Bestehende Eingänge müssen **nicht**
  geändert werden — ohne das Trennzeichen hängt es aber allein an der
  Reihenfolge der Zeile, dass der richtige Wert ankommt, und das ist eine
  Zusicherung, die beim nächsten neuen Feld still fällt.
* **`?diag` ist tokenpflichtig geworden.** Ein Durchgang dauert bei einem
  stummen Gerät gemessene 24 Sekunden und schickt Rundrufe ins Netz.
* **`MS` heißt jetzt richtig „Antwortzeit“** und hat den Bereich 0…10000
  statt 0…10. Wer die Vorlage neu einliest, bekommt den brauchbaren Eingang.
* **Verlauf, Tagesbilanz und Herzschlag liegen jetzt NEBEN dem Plugin-Ordner**
  (`data/plugins/<ordner>.verlauf`). Der Installer räumt
  `data/plugins/<ordner>/` bei **jedem** Update vollständig ab — dort hätte die
  neue Tagesbilanz keine Woche überlebt. Vorhandene Verlaufsdateien werden beim
  ersten Start einmalig hinübergezogen.

## Funktionen

- Status, Passiv-Sollwert (+ = laden, − = entladen) und Modus-Rückgabe über die
  lokale UDP-JSON-API (Port 30000), mit Cache zum Schutz der Geräte
- **Mehrgeräte-Betrieb**: bis zu 4 Speicher, Auswahl per `&dev=N` (Standard 1),
  je Gerät eigene Leistungsgrenzen und Kapazität (wichtig beim Venus E Mini)
- **SOC-Tagesverlauf** als Grafik in der Plugin-Oberfläche, mit Tagesauswahl,
  Batterieleistung als zweiter Kurve und CSV-Ausfuhr
- **Firmware-, Antwortzeit- und Altersanzeige** im Status
- **Auto-Fallback**: kommt X Minuten kein Sollwert mehr, wechselt das Gerät
  selbsttätig in den Auto-Modus (0 = aus); `FBREST` zählt sichtbar herunter
- **Modbus TCP (optional, nur lesend)**: Venus E Gen 3.0 ab Firmware 144 direkt
  über das LAN-Kabel (Port 502, kein RS485-Adapter) — liefert die kWh-Zähler des
  Geräts, Zyklenzähler und Wirkungsgrad (`?energy`). Die Steuerung bleibt
  bewusst auf der UDP-API, weil nur deren Passiv-Modus einen Watchdog hat
- Spot-Stunden-Ranking der nächsten 24 h (aWATTar DE/AT, USt-Faktor und
  Aufschlag einstellbar)
- Optionales MQTT-Publish über das LoxBerry MQTT Gateway — Status,
  Energiezähler (`energie_*`), Spotpreis-Ränge (`rang_*`) und der Takt
- Anschluss an den LoxBerry-Healthcheck und an das Benachrichtigungszentrum
- Die **Konfiguration** übersteht Plugin-Updates und eine Neuinstallation
  (Zweitschrift außerhalb des Plugin-Ordners, `postinstall.sh` holt sie
  zurück). Das **Protokoll** übersteht ein Update — es wird von
  `preupgrade.sh` gesichert und von `postupgrade.sh` zurückgespielt —, eine
  Neuinstallation aber nicht; bis 1.1.4 stand hier beides in einem Satz

## Voraussetzungen

- Lokale API an jedem Gerät einmalig aktivieren (Marstek-App bzw.
  https://rweijnen.github.io/marstek-venus-monitor/ per Bluetooth), UDP-Port 30000.
- Aktuelle Geräte-Firmware (die lokale API reift mit den Updates).
- **Firmware-Eigenheit**: Manche Firmwaren (z. B. Venus E 3.0 mit FW 148)
  antworten nur auf **Rundruf**-Pakete statt auf Unicast — das Plugin erkennt
  und nutzt das automatisch. Der Rundruf ist die einzige Stelle, die die
  PHP-Erweiterung `sockets` braucht; sie steht in `dpkg/apt` und wird bei der
  Installation nachgezogen. Hinweis: Bei mehreren Venus-Geräten im selben Netz
  erreichen Rundruf-Befehle alle Geräte — dann sollten alle eine Firmware haben,
  die Unicast beantwortet.

## Endpunkte (für Loxone)

| Aufruf | Zweck |
|---|---|
| `?status[&dev=N]` | `MARSTEK;OK=..;SOC=..;BATP=..;TEMP=..;GRIDP=..;FW=..;MS=..;ALTER=..;ZAEHLER=..;SOLL=..;SOLLALTER=..;FBREST=..` |
| `?ranks` | `RANKS;OK=..;N=..;RANK=..;RANKD=..;CURP=..;NEG=..;MINP=..;MAXP=..;SPREAD=..;NEXTP=..;HBIS=..;HBISMAX=..;ERRC=..` |
| `?energy[&dev=N]` | `ENERGY;OK=..;CHGT=..;DIST=..;CHGD=..;DISD=..;CHGM=..;DISM=..;CYC=..;EFF=..;ALTER=..` (Modbus TCP, je Gerät zu aktivieren) |
| `?summe` | `SUMME;OK=..;N=..;NOK=..;SOC=..;KAPAZ=..;RESTKWH=..;BATP=..;ALTER=..` |
| `?p=WATT&t=SEK&token=T[&dev=N\|&dev=alle][&dry=1]` | Passiv-Sollwert (+ = laden, − = entladen, 0 = Leerlauf; t = Watchdog) |
| `?mode=auto\|ai&token=T[&dev=N][&dry=1]` | Regie an den Speicher zurückgeben |
| `?selftest=1&token=T` | prüft nur das Token, ohne den Speicher anzufassen |
| `?diag=1&token=T` | Diagnose: Unicast, Rundruf, Modbus einzeln |
| `?debug=1&token=T` | Rohdaten. Tokenpflichtig seit 1.1.5: der Schalter umgeht den Zwischenspeicher, gemessen 30 s je Aufruf gegen ein stummes Gerät |

Alle Ausgaben sind abwärtskompatibel zu Ein-Geräte-Installationen — ohne
`&dev=` wird immer Gerät 1 angesprochen. **Welche Felder ein Satz trägt, steht
seit 1.1.0 an genau einer Stelle** (`marstek_felder()`); Antwortzeile,
Loxone-Vorlage, MQTT-Themenliste und die Tabellen in der Oberfläche entstehen
daraus. Eine Zeile, die der Vorlage widerspricht, kann so nicht mehr entstehen.

## Oberfläche

Fünf Reiter: **Einstellungen** (Gerätetabelle mit Kapazität, Leistungsgrenzen,
Status-Cache, Auto-Fallback, Verlaufsdauer, Hauptschalter, Schutzschwellen,
Benachrichtigungen, aWATTar-Markt, USt-Faktor und Aufschlag, Sicherung),
**MQTT** (Haken, Präfix, Abo-Hinweis je nach Gateway-Fassung und die Tabelle
**aller** veröffentlichten Themen), **Einbindung in Loxone** (Schritt für
Schritt, mit durchnummerierter Baustein-Liste und Gegenprobe), **Test**
(Selbstprüfung, Gerätesuche, technische Auskunft, Trockenlauf, Schalten) und
**Logdateien** (Protokoll und die Fehlerausgabe des Minutentakts). Über den
Reitern: Statuszeile und Verlaufsgrafik je Gerät.

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten. IP-Adressen,
Einstellungen und das Aktionstoken bleiben auf dem LoxBerry; externe
Verbindungen gibt es nur zur aWATTar-Preis-API (ohne Kennung).

Sie liegen dabei an **drei** Orten, alle mit Rechten 0600 — das ist Absicht
und in 1.1.5 richtiggestellt, denn bis dahin stand hier „ausschließlich in
der lokalen Konfiguration":

| Ort | wozu |
|---|---|
| `config/plugins/marstekvenus/marstek.json` | die Konfiguration selbst |
| `config/plugins/marstekvenus.backup.json` | die Zweitschrift; sie liegt **neben** dem Ordner, den der Installer bei jedem Update abräumt |
| `data/plugins/marstekvenus.upgrade_sicherung/` | nur **während** eines Updates; `postupgrade.sh` entfernt sie danach |

`uninstall/uninstall` räumt alle drei weg und sagt das auch.

## Ältere Fassungen

**1.0.16** hat den Aktualisierungsweg berichtigt: `preupgrade.sh` schrieb seine
Sicherung bis dahin in einen Ordner, den niemand angelegt hatte — es ist nie
etwas gesichert worden, und die Meldung sagte das Gegenteil. Seither liegt sie
unter `data/plugins/<ordner>.upgrade_sicherung`, also **neben** dem Ordner, den
der Installer zwischen `preupgrade` und `postinstall` abräumt.

**1.0.14** brachte Energiezähler und Spotpreis-Ränge auch über MQTT. Sie standen
bis dahin nur in den HTTP-Antworten; wer auf MQTT umstellte, verlor die gesamte
Energiebilanz des Speichers.

**1.0.13** machte das Token prüfbar, ohne den Speicher anzufassen
(`?selftest=1`).

**1.0.5** hat `cron.php` von `webfrontend/html/` nach `bin/` verlegt. Im
HTML-Verzeichnis war die Datei für jeden im Heimnetz abrufbar, und ein Aufruf
stieß einen vollständigen Durchgang an.

**1.0.4** behob fünf gemessene Befunde: der Endpunkt hing an aWATTar (20,0 s
Blockade), der Cron stapelte sich (104 s je Durchgang bei vier stummen
Geräten), die Zwischenspeicher wurden nicht atomar geschrieben (240 557
unvollständige Lesevorgänge im Testlauf, danach 0), zwei fest verdrahtete
`/tmp`-Pfade, und `$q` wurde benutzt, bevor es gesetzt war.

## Fassung 1.1.3 — der Stat-Zwischenspeicher
Die Protokollkappung stand in `webfrontend/html/marstek_lib.php:398`
(512 000 Byte, Protokoll) und `webfrontend/html/marstek_lib.php:504`
(262 144 Byte, Mitschnitt) — in 1.1.4 waren die beiden Zahlen in diesem
Satz vertauscht. PHP merkt sich aber die Antworten
von `stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste
Größe und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)`
macht den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

## Lizenz

MIT — siehe [LICENSE](LICENSE).
