#!/bin/bash
# Marstek Venus E - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-marstekvenus}"
BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" 2>/dev/null
if [ ! -f "$BASE/config/plugins/$PFOLDER/marstek.json" ]; then
    echo '{}' > "$BASE/config/plugins/$PFOLDER/marstek.json"
fi
# Konfiguration aus Sicherung wiederherstellen (uebersteht Updates UND Neuinstallation)
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/marstek.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF"
        echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi
echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und konfigurieren."
exit 0
