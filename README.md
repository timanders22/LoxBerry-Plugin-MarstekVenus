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

## Funktionen

- Status, Passiv-Sollwert (+ = laden, − = entladen) und Modus-Rückgabe über die
  lokale UDP-JSON-API (Port 30000), mit Cache zum Schutz der Geräte
- **Mehrgeräte-Betrieb**: bis zu 4 Speicher, Auswahl per `&dev=N` (Standard 1),
  je Gerät eigene Leistungsgrenzen (wichtig beim Venus E Mini)
- **SOC-Tagesverlauf** als Mini-Grafik in der Plugin-Oberfläche (Messpunkte
  sammelt ein minutlicher Cron automatisch, Aufbewahrung 8 Tage)
- **Firmware- und Antwortzeit-Anzeige** im Status (auch als `FW=`/`MS=`-Felder
  für Loxone und per MQTT)
- **Auto-Fallback**: kommt X Minuten kein Sollwert mehr, wechselt das Gerät
  selbsttätig in den Auto-Modus (0 = aus)
- **Modbus TCP (optional, nur lesend)**: Venus E Gen 3.0 ab Firmware 144 direkt
  über das LAN-Kabel (Port 502, kein RS485-Adapter) — liefert die kWh-Zähler des
  Geräts (geladen/abgegeben gesamt/Tag/Monat), Zyklenzähler und Wirkungsgrad
  (`?energy`). Die Steuerung bleibt bewusst auf der UDP-API, weil nur deren
  Passiv-Modus einen Watchdog hat
- Spot-Stunden-Ranking der nächsten 24 h (aWATTar DE/AT, USt-Faktor einstellbar):
  `RANK` (1 = günstigste Stunde), `RANKD` (1 = teuerste), `NEG`, `CURP`
- Optionales MQTT-Publish über das LoxBerry MQTT Gateway
- Konfiguration und Log überleben Plugin-Updates und sogar eine Neuinstallation
  (Sicherungskopie außerhalb des Plugin-Ordners)

## Voraussetzungen

- Lokale API an jedem Gerät einmalig aktivieren (Marstek-App bzw.
  https://rweijnen.github.io/marstek-venus-monitor/ per Bluetooth), UDP-Port 30000.
- Aktuelle Geräte-Firmware (die lokale API reift mit den Updates).
- **Firmware-Eigenheit**: Manche Firmwaren (z. B. Venus E 3.0 mit FW 148)
  antworten nur auf **Broadcast**-Pakete statt auf Unicast — das Plugin erkennt
  und nutzt das automatisch. Der Reiter „Test" enthält eine **Diagnose**
  (Unicast/Broadcast/Modbus-Selbsttest) zur schnellen Fehlersuche. Hinweis:
  Bei mehreren Venus-Geräten im selben Netz erreichen Broadcast-Befehle alle
  Geräte — dann sollten alle eine Firmware haben, die Unicast beantwortet.

## Endpunkte (für Loxone)

| Aufruf | Zweck |
|---|---|
| `/plugins/marstekvenus/marstek.php?status[&dev=N]` | `MARSTEK;OK=..;SOC=..;BATP=..;TEMP=..;GRIDP=..;FW=..;MS=..` (BATP: + = lädt) |
| `/plugins/marstekvenus/marstek.php?ranks` | `RANKS;OK=..;N=..;RANK=..;RANKD=..;CURP=..;NEG=..` |
| `/plugins/marstekvenus/marstek.php?p=WATT&t=SEK[&dev=N]` | Passiv-Sollwert (+ = laden, − = entladen, 0 = Leerlauf; t = Watchdog) |
| `/plugins/marstekvenus/marstek.php?mode=auto\|ai[&dev=N]` | Regie an den Speicher zurückgeben |
| `/plugins/marstekvenus/marstek.php?energy[&dev=N]` | `ENERGY;OK=..;CHGT=..;DIST=..;CHGD=..;DISD=..;CHGM=..;DISM=..;CYC=..;EFF=..` (Modbus TCP, muss je Gerät aktiviert sein) |

Alle Ausgaben sind abwärtskompatibel zu Ein-Geräte-Installationen — ohne
`&dev=` wird immer Gerät 1 angesprochen.

## Oberfläche

Reiter **Einstellungen** (Geräte-Tabelle, Leistungsgrenzen, Status-Cache,
Auto-Fallback, aWATTar-Markt + USt-Faktor, MQTT), **Einbindung in Loxone**
(Schritt-für-Schritt-Anleitung für Laien inkl. Befehlserkennungen und
empfohlener Logik), **Test** (je Gerät Status/Leerlauf/Auto, Spot-Ranking)
und **Logdateien**. Über den Reitern: Statuszeile und SOC-Tagesgrafik je Gerät.

## Was 1.0.5 behebt

**`cron.php` liegt jetzt unter `bin/` statt unter `webfrontend/html/`.**

Aufgerufen wird die Datei ausschließlich vom Minutencron, und zwar über die
PHP-Kommandozeile — nie über HTTP. Im HTML-Verzeichnis war sie zusätzlich für
jeden im Heimnetz abrufbar, und ein Aufruf stößt einen vollständigen Durchgang
an: Abruf bei aWATTar, Statusabfrage aller Geräte, MQTT-Meldung — und über den
Auto-Fallback kann ein Speicher in den Auto-Modus wechseln. Die Sperre aus 1.0.4
begrenzt zwar das Stapeln, verhindert den Aufruf aber nicht.

- `cron/cron.01min` ruft die Datei jetzt über `REPLACELBPBINDIR` auf.
- `marstek_lib.php` bleibt im HTML-Verzeichnis, weil dort auch `marstek.php`
  liegt — der Endpunkt für den Miniserver. `cron.php` findet die Bibliothek über
  `REPLACELBPHTMLDIR`, mit Rückfall auf den Pfad relativ zur eigenen Datei für
  den Lauf aus dem ausgepackten Archiv. Bleibt beides erfolglos, bricht das
  Skript mit einer Meldung ab, statt still nichts zu tun.
- **`uninstall/uninstall` musste mit.** Es sucht einen laufenden Durchgang
  argumentweise über den vollen Pfad — der zeigte noch auf das HTML-Verzeichnis
  und hätte nach dem Umzug nie mehr getroffen. Jetzt werden beide Orte geprüft:
  nach einem Update aus einer älteren Fassung kann noch ein Durchgang vom alten
  Pfad laufen.
- `postupgrade.sh` entfernt eine aus 1.0.4 stehengebliebene `cron.php` aus dem
  HTML-Verzeichnis — sonst hinge der Zweck des Umzugs davon ab, dass das Update
  das alte Verzeichnis restlos ersetzt.

**An den Loxone-Adressen ändert sich nichts.** `marstek.php` bleibt, wo es ist,
mit denselben Parametern; das Token schützt weiterhin `?p=` und `?mode=`.

## Was 1.0.4 behebt

Fünf Befunde, jeder vor der Korrektur nachgemessen — nicht geschätzt.

**1. Der Miniserver-Endpunkt hing an aWATTar.**
`marstek.php?ranks` holte die Spotpreise selbst, wenn der Zwischenspeicher alt
war. War aWATTar nicht erreichbar, wartete der Aufruf auf die Zeitgrenze —
gemessen **20,0 Sekunden**, in denen der Miniserver auf eine Antwort wartete,
die ein Webserver-Prozess blockierte. Loxone lief in seine eigene Zeitgrenze und
sah einen Fehler, obwohl gültige Preise im Zwischenspeicher lagen.
*Jetzt*: Das Holen macht ausschließlich der Cron (`marstek_spot_fetch()`), der
Endpunkt liest die Datei nur noch. Antwortzeit auch bei totem aWATTar: unter
einer Zehntelsekunde, mit den zuletzt geholten Preisen.

**2. Der Cron stapelte sich.**
Er läuft jede Minute. Mit vier nicht erreichbaren Geräten dauerte ein Durchgang
**104 Sekunden** — jeder Durchgang startete also, bevor der vorige fertig war.
Nach einer Viertelstunde lagen ein Dutzend übereinander, jeder mit offenen
Sockets.
*Jetzt*: `flock(LOCK_EX | LOCK_NB)` auf `cron.lock`. Läuft schon einer, endet
der neue sofort. Protokolliert wird das höchstens stündlich, sonst liefe das
Log voll.

**3. Die Zwischenspeicher wurden nicht atomar geschrieben.**
`file_put_contents()` schreibt nicht in einem Zug; wer währenddessen liest,
bekommt eine halbe Datei. Ein Testlauf mit gleichzeitigem Lesen und Schreiben
ergab **240 557 unvollständige Lesevorgänge**. Nach der Umstellung auf
Zwischendatei + `rename()`: **0**.
Mit erledigt: `json_encode()` gibt bei ungültigem UTF-8 `false` zurück, und
`file_put_contents($p, false)` schreibt klaglos eine leere Datei.
`marstek_write_json()` fängt das ab, bevor der gute Zwischenspeicher durch
einen leeren ersetzt wird.

**4. Zwei fest verdrahtete `/tmp`-Pfade.**
In `index.php` stand `/tmp/marstekvenus/...` zweimal wörtlich, statt
`marstek_tmpdir()` zu nutzen. Auf einem LoxBerry stimmt das zufällig — auf
jedem System mit abweichendem Zwischenspeicherort nicht.

**5. `$q` wurde benutzt, bevor es gesetzt war** (eigener Fund).
Im Reiter „Test" hängen die Verweise ein `&dev=N` an, das aus `$q` kommt.
`$q` wurde aber erst in der Geräteschleife *darunter* gesetzt. Unter PHP 7.4
ist eine undefinierte Variable eine Notice, die verschluckt wird — die
Verweise stimmten zufällig. Unter PHP 8 ist es eine Warning, und die landet
sechsmal sichtbar mitten im HTML. Aufgefallen ist es erst beim Rendern gegen
**beide** Fassungen; `php -l` findet so etwas nicht. Beide Fassungen liefern
jetzt zeichengleiche Ausgabe ohne eine einzige Meldung.

Dazu Hausstandard: Reiter als echte Verweise mit serverseitigem `sm-active`
(funktioniert ohne JavaScript), `uninstall`, `prerelease.cfg`, fünf tote
Sprachschlüssel entfernt — 383 Schlüssel, deutsch und englisch deckungsgleich.

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten — IP-Adressen und alle
Einstellungen liegen ausschließlich in der lokalen Konfiguration
(`config/plugins/marstekvenus/marstek.json`). Externe Verbindungen gibt es nur
zur aWATTar-Preis-API (ohne Kennung).

## Lizenz

MIT — siehe [LICENSE](LICENSE).
