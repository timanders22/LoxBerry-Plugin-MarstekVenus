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
# Die Wurzel: $5 (vom Installer) oder $LBHOMEDIR, wenn dort config/plugins
# und data/plugins liegen - sonst vom eigenen Ablageort AUFWAERTS SUCHEN, bis
# ein Verzeichnis config/plugins, data/plugins UND config/system/general.json
# traegt. Kein fest verdrahteter Systempfad danach, keine feste Ebenenzahl.
# BERICHTIGT 25.09.2026: bis 1.1.15 stand hier nur BASE="${5:-$LBHOMEDIR}"
# ohne jede Pruefung; ohne beides wurden die Pfade ab der Laufwerkswurzel
# gebildet (/config/plugins/..., /data/plugins/...), und das Skript meldete
# trotzdem <OK> (in WSL gemessen, Pruefung-MarstekVenus-1.1.16, Faelle H3 bis
# H5, C8 bis C10). Ohne Wurzel wird GEWARNT statt vollzogen. Bauart AWM-Abfuhr
# 1.4.13.
mv_wurzel_suchen() {
    mv_v=$(cd "$1" 2>/dev/null && pwd -P) || return 1
    mv_i=0
    while [ -n "$mv_v" ] && [ "$mv_v" != "/" ] && [ "$mv_i" -lt 8 ]; do
        if [ -d "$mv_v/config/plugins" ] && [ -d "$mv_v/data/plugins" ] \
           && [ -f "$mv_v/config/system/general.json" ]; then
            echo "$mv_v"
            return 0
        fi
        mv_v=$(dirname "$mv_v")
        mv_i=$((mv_i + 1))
    done
    return 1
}
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    BASE=$(mv_wurzel_suchen "$(dirname "$(readlink -f "$0")")") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: weder als"
    echo "<WARNING> fuenftes Argument noch in \$LBHOMEDIR, und oberhalb von"
    echo "<WARNING> $(dirname "$(readlink -f "$0")") traegt kein Verzeichnis"
    echo "<WARNING> config/plugins, data/plugins und config/system/general.json."
    echo "<WARNING> Es wurde nichts gesichert."
    exit 1
fi
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

# Seit 1.1.6. Altlast aus 1.0.4 und frueher: dort lag cron/cron.01min als VERZEICHNIS im
# Archiv, und plugininstall.pl legte es als Verzeichnis
# system/cron/cron.01min/<name>/ an. Seit 1.0.5 ist cron.01min eine Datei -
# aber der Installer raeumt beim Update nur mit "rm -fv" (ohne -r) ab, das
# Verzeichnis bleibt stehen, und "cp -r" kopiert die neue Cron-Datei HINEIN.
# LoxBerry ruft jeden Eintrag im Cron-Ordner direkt auf; ein Verzeichnis
# scheitert still (beide Ausgaben nach /dev/null). Gemessen am 05.09.2026 an
# einer Anlage, auf der 1.0.x installiert war: der minuetliche Cron lief seit
# dem 23.07.2026 kein einziges Mal (kein cron.err, kein herzschlag.json).
# Dieses Skript laeuft als loxberry - dem das Verzeichnis gehoert - und VOR
# dem Kopieren der Cron-Datei (plugininstall.pl der LoxBerry-Fassung 4.0.0.15: preupgrade :845,
# Cron-Kopie :990). Der zweite Argument ist der Plugin-NAME aus plugin.cfg;
# unter ihm legt der Installer die Cron-Datei ab.
PNAME="${2:-marstekvenus}"
CRONALT="$BASE/system/cron/cron.01min/$PNAME"
if [ -d "$CRONALT" ]; then
    if rm -r "$CRONALT" 2>/dev/null; then
        echo "<OK> Altes Cron-Verzeichnis entfernt (Altlast aus 1.0.4 und frueher); der minuetliche Cron laeuft nach diesem Update wieder."
    else
        echo "<WARNING> Altes Cron-Verzeichnis $CRONALT liess sich nicht entfernen - der minuetliche Cron laeuft dann NICHT. Als root: rm -r $CRONALT, danach das Plugin erneut installieren."
    fi
fi

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
