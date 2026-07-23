#!/bin/bash
# Marstek Venus E - postupgrade: Konfiguration + Log wiederherstellen
ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-marstekvenus}"
BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" 2>/dev/null
if [ -f "$ARGV1/marstek.json.backup" ]; then
    cp -p "$ARGV1/marstek.json.backup" "$BASE/config/plugins/$PFOLDER/marstek.json"
fi
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/marstek.json"
if [ -f "$BK" ] && { [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; }; then
    cp -p "$BK" "$CF"
fi
if [ -f "$ARGV1/marstek.log.backup" ]; then
    mkdir -p "$BASE/log/plugins/$PFOLDER"
    cp -p "$ARGV1/marstek.log.backup" "$BASE/log/plugins/$PFOLDER/marstek.log"
fi
exit 0
