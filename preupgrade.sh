#!/bin/bash
# Marstek Venus E - preupgrade: Konfiguration + Log sichern
ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-marstekvenus}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -f "$BASE/config/plugins/$PFOLDER/marstek.json" ]; then
    cp -p "$BASE/config/plugins/$PFOLDER/marstek.json" "$ARGV1/marstek.json.backup"
fi
if [ -f "$BASE/log/plugins/$PFOLDER/marstek.log" ]; then
    cp -p "$BASE/log/plugins/$PFOLDER/marstek.log" "$ARGV1/marstek.log.backup"
fi
exit 0
