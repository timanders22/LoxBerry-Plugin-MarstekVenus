#!/bin/bash
# Marstek Venus E - postupgrade: Konfiguration + Log wiederherstellen
ARGV1=$1
ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-marstekvenus}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Dort hat preupgrade.sh gesichert - NEBEN dem Ordner, weil der
# Installer data/plugins/<x>/ zwischen beiden Skripten loescht.
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
mkdir -p "$BASE/config/plugins/$PFOLDER" 2>/dev/null
# Dieselbe Frage wie in postinstall.sh: mindestens ein Speicher mit Adresse.
mv_eingerichtet() {
    [ -s "$1" ] || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d)) { exit(1); }
        if (isset($d["ip"]) && is_string($d["ip"]) && trim($d["ip"]) !== "") { exit(0); }
        foreach ((isset($d["devices"]) && is_array($d["devices"])) ? $d["devices"] : array() as $g) {
            if (is_array($g) && isset($g["ip"]) && is_string($g["ip"]) && trim($g["ip"]) !== "") { exit(0); }
        }
        exit(1);' "$1" 2>/dev/null
}
MV_VORHER=0; mv_eingerichtet "$BASE/config/plugins/$PFOLDER/marstek.json" && MV_VORHER=1
MV_GESICHERT=0; mv_eingerichtet "$SICHER/marstek.json" && MV_GESICHERT=1
if [ -f "$SICHER/marstek.json" ]; then
    cp -p "$SICHER/marstek.json" "$BASE/config/plugins/$PFOLDER/marstek.json"
fi
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/marstek.json"
# Entschieden wird nach INHALT, nicht nach Form.
#
# BERICHTIGT 18.09.2026. Bis 1.1.14 stand hier
#     if [ -f "$BK" ] && { [ ! -s "$CF" ] || [ "$(cat "$CF")" = "{}" ]; }
# also dieselbe Formfrage wie in marstek_lib.php - "nicht leer" und "nicht {}".
# Eine ABGESCHNITTENE marstek.json ist weder das eine noch das andere und blieb
# stehen. Gemessen in WSL (Pruefung-MarstekVenus-1.1.14, Fall I "hook_kaputt"):
#     M1 Konfiguration traegt altes Token: NEIN (erwartet JA)
# Die Frage lautet: steht in der Datei ein Aktionstoken, das sich auch lesen
# laesst? Genau das kann nur die Zweitschrift zurueckbringen; alles andere
# traegt der Bediener in der Oberflaeche noch einmal ein.
# Gefragt wird nach LESBARKEIT, nicht nach einer Zeichenkette: eine
# abgeschnittene Datei ENTHAELT das alte Token und ist trotzdem unbrauchbar.
# Ein grep-Muster faerbte die Messung am 18.09.2026 zunaechst gruen, obwohl
# nichts wiederhergestellt wurde. Deshalb antwortet PHP - es liegt auf jedem
# LoxBerry, und genau dieser Leser entscheidet spaeter auch im Plugin.
MV_PHP="$(command -v php 2>/dev/null)"
mv_hat_token() {
    [ -f "$1" ] || return 1
    if [ -n "$MV_PHP" ]; then
        "$MV_PHP" -r '$d = json_decode((string) @file_get_contents($argv[1]), true); exit(is_array($d) && isset($d["aktionstoken"]) && trim((string) $d["aktionstoken"]) !== "" ? 0 : 1);' "$1" >/dev/null 2>&1
        return $?
    fi
    # Ohne PHP bleibt nur die Form. Das ist schwaecher - und der Grund, warum
    # die Entscheidung im Plugin selbst noch einmal faellt.
    [ -s "$1" ] && [ "$(cat "$1" 2>/dev/null)" != "{}" ]
}
if mv_hat_token "$BK" && ! mv_hat_token "$CF"; then
    # Der verdraengte Stand bleibt lesbar - es kann etwas darin stehen, das
    # nur der Bediener wiederherstellen kann.
    if [ -s "$CF" ]; then
        cp -p "$CF" "$CF.kaputt" 2>/dev/null
        chmod 600 "$CF.kaputt" 2>/dev/null
        echo "<INFO> Der vorherige Inhalt der Konfiguration liegt unter $CF.kaputt."
    fi
    cp -p "$BK" "$CF"
    chmod 600 "$CF" 2>/dev/null
    echo "<OK> Die Konfiguration trug kein lesbares Aktionstoken und wurde aus der Zweitschrift wiederhergestellt."
fi
if [ -f "$SICHER/marstek.log" ]; then
    mkdir -p "$BASE/log/plugins/$PFOLDER"
    cp -p "$SICHER/marstek.log" "$BASE/log/plugins/$PFOLDER/marstek.log"
fi

# Altlast aus 1.0.4 und frueher: cron.php lag im HTML-Verzeichnis und war damit
# fuer jeden im Heimnetz per HTTP abrufbar - ein Aufruf stiess einen ganzen
# Durchgang an (aWATTar, Statusabfrage, MQTT, Auto-Fallback). Seit 1.0.5 liegt
# die Datei unter bin/ und wird nur noch vom Cron ueber die Kommandozeile
# aufgerufen.
#
# Diese Zeilen stehen hier, weil sie nichts kosten und der Zweck des Umzugs
# sonst davon abhinge, dass das Update das alte HTML-Verzeichnis restlos
# ersetzt.
ALT="$BASE/webfrontend/html/plugins/$PFOLDER/cron.php"
if [ -f "$ALT" ]; then
    rm -f "$ALT"
    echo "<OK> Alte, ueber HTTP erreichbare cron.php entfernt."
fi


# Das Schlusswort zur Konfiguration steht hier nur, wenn postinstall.sh es
# hierher verwiesen hat: dort stand noch kein Speicher in marstek.json, in
# der Update-Sicherung aber schon.
if [ $MV_VORHER = 0 ] && [ $MV_GESICHERT = 1 ]; then
    if mv_eingerichtet "$CF"; then
        echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen."
    else
        echo "<WARNING> Die Einstellungen liessen sich nicht aus der Update-Sicherung zurueckholen."
        echo "<WARNING> Bitte Plugin-Oberflaeche oeffnen und konfigurieren."
    fi
fi

# Der Nachbar hat seinen Zweck erfuellt. Was neben dem Ordner liegt,
# raeumt niemand sonst weg - und er traegt die Zugangsdaten mit.
#
# Aber nur, wenn die Rueckholung nach INHALT gelungen ist. Bis 1.1.14 fiel
# er ohne Bedingung, auch wenn das Zurueckkopieren oben gescheitert war -
# dann gab es weder Konfiguration noch Sicherung (Pruefung-MarstekVenus-1.1.15,
# Fall e2). Liegen bleibt er, wenn seine marstek.json einen Speicher oder ein
# Aktionstoken traegt, die im Konfigordner aber weder das eine noch das andere.
mv_inhalt() { mv_eingerichtet "$1" || mv_hat_token "$1"; }
if [ -d "$SICHER" ] && mv_inhalt "$SICHER/marstek.json" && ! cmp -s "$SICHER/marstek.json" "$CF" \
   && ! mv_inhalt "$CF"; then
    echo "<WARNING> Die Update-Sicherung bleibt liegen - ihre Konfiguration ist nicht angekommen:"
    echo "<WARNING>   $SICHER"
else
    rm -rf "$SICHER" 2>/dev/null
fi
exit 0
