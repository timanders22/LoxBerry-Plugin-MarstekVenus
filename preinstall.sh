#!/bin/bash
# Marstek Venus E - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# NEU (Durchgang 30.09.2026, I1 und I2; Entscheidung 1 vom 29.09.2026),
# Bauform audi_bau/preinstall.sh (AudiConnect 0.9.22). Der Installer ruft
# dieses Skript bei JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung
# und VOR dem Kopieren von Konfiguration, Cron-Datei und Oberflaeche
# (sbin/plugininstall.pl: preupgrade :846, purge :874, preinstall :877,
# Cron :990, HTML :1066 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich).
#
# AKTUALISIERUNG (I2): die Konfiguration kommt schon HIER aus der
# Update-Sicherung zurueck, vor der Cron- und HTML-Kopie. Bis 1.1.17 lag
# zwischen purge und postinstall rund eine Minute ohne Konfiguration: der
# Endpunkt wies jeden Sollwert aus Loxone mit HTTP 403 ab (in WSL gemessen,
# Installer-Pruefer E1), und ein Takt in der Luecke heilte aus der
# Zweitschrift oder schrieb blanke Vorgaben hinein (Befunde 6, 7). Der Takt
# selbst pausiert, solange die Marke liegt (bin/cron.php).
#
# NEUINSTALLATION (I1): liegengebliebene Zweitschrift, Verlauf und
# Update-Sicherung einer frueheren Installation gehen nach <name>.alt,
# gemeldet mit genau einer <WARNING>. Bis 1.1.17 spielte postinstall.sh sie
# ungefragt zurueck - Aktionstoken, Geraete und Tagesbilanz der frueheren
# Anlage, und der erste Takt fragte deren Speicher ab (gemessen N2, N3). Die
# Selbstheilung der Bibliothek liest .alt nie; die Deinstallation raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-marstekvenus}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzelsuche wie in den uebrigen Hakenskripten: ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt und nichts zurueckgespielt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
MV_PHP="$(command -v php 2>/dev/null)"
mv_lesbar() {
    # Lesbares JSON-Objekt? Dieselbe Frage wie preupgrade.sh (I6).
    [ -s "$1" ] || return 1
    [ -n "$MV_PHP" ] || return 0
    "$MV_PHP" -r '$d = json_decode((string) @file_get_contents($argv[1]), true); exit(is_array($d) && $d !== array() ? 0 : 1);' "$1" >/dev/null 2>&1
}

if [ -f "$MARKE" ]; then
    S="$BASE/data/plugins/$PFOLDER.upgrade_sicherung/marstek.json"
    CFD="$BASE/config/plugins/$PFOLDER"
    CF="$CFD/marstek.json"
    if mv_lesbar "$S"; then
        mkdir -p "$CFD" 2>/dev/null
        T="$CF.preinstall.$$"
        # Rechte VOR dem Inhalt (C12): leer anlegen, 0600, dann fuellen.
        if ( umask 077 && : > "$T" ) 2>/dev/null && chmod 600 "$T" && cat "$S" > "$T" \
           && cmp -s "$S" "$T" && mv -f "$T" "$CF"; then
            echo "<OK> Aktualisierung: Konfiguration schon vor der Cron-Kopie aus der Update-Sicherung zurueckgespielt - der Endpunkt nimmt waehrend des Updates Sollwerte an."
        else
            rm -f "$T" 2>/dev/null
            echo "<WARNING> Aktualisierung: die Konfiguration liess sich nicht vorab zurueckspielen - postinstall.sh und postupgrade.sh versuchen es noch einmal."
        fi
    else
        echo "<INFO> Aktualisierung: die Update-Sicherung traegt keine lesbare Konfiguration - postinstall.sh und postupgrade.sh entscheiden."
    fi
    exit 0
fi

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/data/plugins/$PFOLDER.verlauf" \
            "$BASE/data/plugins/$PFOLDER.upgrade_sicherung"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
A="$BASE/config/plugins/$PFOLDER.backup.json.alt"
[ -f "$A" ] && [ ! -L "$A" ] && chmod 600 "$A" 2>/dev/null
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen, Aktionstoken und Verlauf einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
