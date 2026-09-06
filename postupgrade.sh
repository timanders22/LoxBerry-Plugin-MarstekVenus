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
if [ -f "$SICHER/marstek.json" ]; then
    cp -p "$SICHER/marstek.json" "$BASE/config/plugins/$PFOLDER/marstek.json"
fi
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/marstek.json"
if [ -f "$BK" ] && { [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; }; then
    cp -p "$BK" "$CF"
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


# Der Nachbar hat seinen Zweck erfuellt. Was neben dem Ordner liegt,
# raeumt niemand sonst weg - und er traegt die Zugangsdaten mit.
rm -rf "$SICHER" 2>/dev/null
exit 0
