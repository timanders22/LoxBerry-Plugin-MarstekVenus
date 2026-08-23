#!/bin/bash
# Marstek Venus E - preupgrade: Konfiguration + Log sichern
ARGV1=$1
ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-marstekvenus}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Die Sicherung liegt NEBEN dem Ordner. Zwei Gruende, beide gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026):
#   1. $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
#      Zufallskennung aus &generate(10). "cp ... $1/datei" schrieb bisher in
#      einen Unterordner, den niemand angelegt hat - es ist nie etwas
#      gesichert worden, und die Meldung sagte das Gegenteil.
#   2. Der Installer loescht zwischen preupgrade und postinstall
#      config/plugins/<x>/, bin/, data/, templates/ und beide webfrontend/
#      (&purge_installation im Upgrade-Zweig, :886 -> :1629 ff.). Nur der
#      Nachbar mit dem Punkt bleibt stehen.
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
mkdir -p "$SICHER" 2>/dev/null
chmod 0700 "$SICHER" 2>/dev/null

if [ -f "$BASE/config/plugins/$PFOLDER/marstek.json" ]; then
    if cp -p "$BASE/config/plugins/$PFOLDER/marstek.json" "$SICHER/marstek.json" \
       && [ -s "$SICHER/marstek.json" ]; then
        chmod 600 "$SICHER/marstek.json" 2>/dev/null
        echo "<OK> Konfiguration gesichert."
    else
        echo "<INFO> Die Konfiguration liess sich nicht sichern."
    fi
fi
# log/plugins/<x>/ ueberlebt ein Update ohnehin - die Kopie traegt nur den
# Fall, dass das Update dazwischen abbricht.
if [ -f "$BASE/log/plugins/$PFOLDER/marstek.log" ]; then
    cp -p "$BASE/log/plugins/$PFOLDER/marstek.log" "$SICHER/marstek.log" 2>/dev/null
fi
exit 0
