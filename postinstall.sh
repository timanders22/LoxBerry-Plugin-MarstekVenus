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
# Nachmessung: liegt die Cron-Datei als DATEI dort, wo LoxBerry sie aufruft?
# Ein Verzeichnis an dieser Stelle (Altlast aus 1.0.4 und frueher, s. preupgrade.sh)
# ueberlebt auch eine Deinstallation (uninstall: rm -fv ohne -r). Liegt die
# frisch kopierte Datei darin, wird sie herausgehoben - genau der Zustand, den
# der Installer ohne Altlast erzeugt haette. Sonst wird gewarnt: ohne diese
# Datei laeuft nichts von dem, was das Plugin minuetlich tut.
PNAME="${2:-marstekvenus}"
CRON="$BASE/system/cron/cron.01min/$PNAME"
if [ -d "$CRON" ]; then
    if [ -f "$CRON/cron.01min" ]; then
        T="$(mktemp 2>/dev/null || echo "/tmp/marstek_cron.$$")"
        if mv "$CRON/cron.01min" "$T" && rm -r "$CRON" && mv "$T" "$CRON" && chmod 755 "$CRON"; then
            echo "<OK> Cron-Datei aus dem alten Verzeichnis (Altlast aus 1.0.4 und frueher) an ihren Platz gelegt."
        else
            echo "<WARNING> $CRON ist ein Verzeichnis und liess sich nicht ersetzen - der minuetliche Cron laeuft NICHT. Als root: rm -r $CRON, danach das Plugin erneut installieren."
        fi
    else
        echo "<WARNING> $CRON ist ein Verzeichnis - der minuetliche Cron laeuft NICHT. Als root: rm -r $CRON, danach das Plugin erneut installieren."
    fi
elif [ ! -f "$CRON" ]; then
    echo "<WARNING> Cron-Datei $CRON fehlt - der minuetliche Cron (Verlauf, Zaehler, Auto-Fallback, Herzschlag) laeuft NICHT."
fi
echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und konfigurieren."
exit 0
