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
# ---------------------------------------------------------------------------
# ZUERST die Marke "Aktualisierung laeuft" (I1, Durchgang 30.09.2026).
#
# Sie ist das einzige Zeichen, an dem preinstall.sh und postinstall.sh ein
# Update von einer Neuinstallation unterscheiden (Entscheidung 1, kein
# Altersvergleich). Bis 1.1.17 gab es sie nicht, und die Linie konnte beides
# nicht auseinanderhalten: eine Neuinstallation spielte die Zweitschrift der
# frueheren Anlage ein. Der Minutentakt pausiert, solange sie liegt (hoechstens
# eine Stunde, bin/cron.php). Sie liegt NEBEN dem Datenordner - ein
# "rm -rf <ordner>/" des Installers trifft den Nachbarn mit dem Punkt nicht.
# Laesst sie sich nicht anlegen, bricht das Upgrade ab: sonst legte
# preinstall.sh die eben gesicherten Einstellungen beiseite.
# ---------------------------------------------------------------------------
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
mkdir -p "$BASE/data/plugins" 2>/dev/null
date +%s > "$MARKE" 2>/dev/null
if [ -s "$MARKE" ]; then
    echo "<OK> Aktualisierung vermerkt - der Minutentakt pausiert bis zum Ende der Installation."
else
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen."
    echo "<FAIL> Ohne sie hielte die Installation das Update fuer eine Neuinstallation und legte die"
    echo "<FAIL> Einstellungen beiseite. Das Upgrade wird abgebrochen."
    exit 2
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
#
# I3 (Durchgang 30.09.2026): ein ALTER Bestand geht vorher nach .alt, und der
# neue entsteht unter .neu und wird erst am Ende umbenannt. Bis 1.1.17 legte
# "mkdir -p" in einen liegengebliebenen Bestand hinein - gemessen (U5): ein
# Protokoll vom 01.07. aus einem frueheren Vorgang erschien nach dem Upgrade
# als das aktuelle. Entscheidung 1: bei einem Upgrade wird nie ein Bestand aus
# einem frueheren Vorgang eingespielt.
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
NEU="$SICHER.neu"
if [ -e "$SICHER" ] || [ -L "$SICHER" ]; then
    rm -rf "${SICHER:?}.alt" 2>/dev/null
    if mv -f "$SICHER" "$SICHER.alt" 2>/dev/null; then
        echo "<WARNING> Eine Update-Sicherung aus einem frueheren Vorgang lag noch. Sie wird nicht eingespielt und liegt jetzt unter $SICHER.alt (die Deinstallation raeumt sie ab)."
    else
        rm -rf "${SICHER:?}" 2>/dev/null
        if [ -e "$SICHER" ]; then
            echo "<FAIL> Die alte Update-Sicherung $SICHER liess sich weder beiseitelegen noch entfernen."
            echo "<FAIL> Sie wuerde nach dem Upgrade eingespielt. Das Upgrade wird abgebrochen."
            exit 2
        fi
        echo "<WARNING> Eine Update-Sicherung aus einem frueheren Vorgang lag noch und wurde entfernt."
    fi
fi
rm -rf "${NEU:?}" 2>/dev/null
if ! mkdir -p "$NEU" 2>/dev/null || ! chmod 0700 "$NEU" 2>/dev/null; then
    echo "<FAIL> Der Ordner $NEU liess sich nicht anlegen - es wurde nichts gesichert. Das Upgrade wird abgebrochen."
    exit 2
fi

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

# I6 (Durchgang 30.09.2026): gesichert wird nach LESBARKEIT, nicht nach
# Groesse. Bis 1.1.17 genuegte "-s": eine abgeschnittene marstek.json (120 B)
# wurde als "<OK> Konfiguration gesichert." gemeldet und nach dem Upgrade als
# Konfiguration eingespielt (gemessen U4, U4b). Jetzt liegt sie als
# marstek.json.kaputt in der Sicherung - eingespielt wird sie nicht; die
# Zweitschrift heilt danach, wenn es eine gibt.
MV_PHP="$(command -v php 2>/dev/null)"
mv_lesbar() {
    [ -s "$1" ] || return 1
    [ -n "$MV_PHP" ] || return 0
    "$MV_PHP" -r '$d = json_decode((string) @file_get_contents($argv[1]), true); exit(is_array($d) && $d !== array() ? 0 : 1);' "$1" >/dev/null 2>&1
}
CF="$BASE/config/plugins/$PFOLDER/marstek.json"
if [ -f "$CF" ]; then
    if mv_lesbar "$CF"; then
        if cp -p "$CF" "$NEU/marstek.json" && chmod 600 "$NEU/marstek.json" 2>/dev/null \
           && cmp -s "$CF" "$NEU/marstek.json"; then
            echo "<OK> Konfiguration gesichert (als lesbar geprueft)."
        else
            echo "<WARNING> Die Konfiguration liess sich nicht sichern."
        fi
    else
        cp -p "$CF" "$NEU/marstek.json.kaputt" 2>/dev/null
        chmod 600 "$NEU/marstek.json.kaputt" 2>/dev/null
        echo "<WARNING> Die Konfiguration $CF ist nicht lesbar (abgeschnitten oder leer). Sie wird NICHT eingespielt und liegt nach dem Update als marstek.json.kaputt im Konfigurationsordner (dort steht womoeglich das alte Aktionstoken); liegt eine Zweitschrift daneben, stellt postinstall.sh die Einstellungen daraus wieder her."
    fi
fi
# log/plugins/<x>/ ueberlebt ein Update ohnehin - die Kopie traegt den Fall,
# dass das Protokoll dazwischen verlorengeht (RAM-Platte, Protokollpflege).
# postupgrade.sh fuegt sie VOR die Zeilen aus der Luecke, statt sie zu
# ersetzen (I5).
if [ -f "$BASE/log/plugins/$PFOLDER/marstek.log" ]; then
    cp -p "$BASE/log/plugins/$PFOLDER/marstek.log" "$NEU/marstek.log" 2>/dev/null
fi
if mv -f "$NEU" "$SICHER" 2>/dev/null; then
    :
else
    echo "<FAIL> Die Update-Sicherung liess sich nicht von $NEU nach $SICHER umbenennen. Das Upgrade wird abgebrochen."
    exit 2
fi
exit 0
