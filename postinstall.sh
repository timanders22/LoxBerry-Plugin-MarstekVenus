#!/bin/bash
# Marstek Venus E - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
ARGV3=$3
ARGV5=$5
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
    echo "<WARNING> Es wurde nichts eingerichtet und nichts zurueckgespielt."
    exit 1
fi
mkdir -p "$BASE/config/plugins/$PFOLDER" 2>/dev/null
if [ ! -f "$BASE/config/plugins/$PFOLDER/marstek.json" ]; then
    echo '{}' > "$BASE/config/plugins/$PFOLDER/marstek.json"
fi
# Konfiguration aus der Zweitschrift wiederherstellen (uebersteht Updates UND
# Neuinstallation) - nach INHALT, nicht nach Form.
#
# BERICHTIGT 25.09.2026. Bis 1.1.15 stand hier die Formfrage "leer oder {}" an
# die Konfiguration, und die Zweitschrift wurde gar nicht angesehen. Gemessen
# in WSL (Pruefung-MarstekVenus-1.1.16): eine Zweitschrift "{}" wurde kopiert
# und als "wiederhergestellt" gemeldet (Fall Z1); eine ABGESCHNITTENE
# marstek.json blieb stehen, obwohl die Zweitschrift das Token trug (Z2, Z3).
# "Inhalt" heisst: lesbares JSON-Objekt mit Aktionstoken - dieselbe Frage wie
# marstek_config_hat_inhalt() und mv_hat_token() in postupgrade.sh.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/marstek.json"
MV_PHP="$(command -v php 2>/dev/null)"
mv_hat_token() {
    [ -f "$1" ] || return 1
    if [ -n "$MV_PHP" ]; then
        "$MV_PHP" -r '$d = json_decode((string) @file_get_contents($argv[1]), true); exit(is_array($d) && isset($d["aktionstoken"]) && trim((string) $d["aktionstoken"]) !== "" ? 0 : 1);' "$1" >/dev/null 2>&1
        return $?
    fi
    # Ohne PHP bleibt nur die Form - schwaecher; die Entscheidung faellt im
    # Plugin noch einmal (marstek_config()).
    [ -s "$1" ] && [ "$(cat "$1" 2>/dev/null)" != "{}" ]
}
if mv_hat_token "$BK" && ! mv_hat_token "$CF"; then
    # Der verdraengte Stand bleibt lesbar liegen, wenn er ueberhaupt etwas
    # traegt - es kann darin stehen, was nur der Bediener wiederherstellen kann.
    if [ -s "$CF" ] && [ "$(tr -d ' \t\r\n' < "$CF" 2>/dev/null)" != "{}" ]; then
        cp -p "$CF" "$CF.kaputt" 2>/dev/null
        chmod 600 "$CF.kaputt" 2>/dev/null
        echo "<INFO> Der vorherige Inhalt der Konfiguration liegt unter $CF.kaputt."
    fi
    if cp -p "$BK" "$CF"; then
        chmod 600 "$CF" 2>/dev/null
        echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt."
    else
        echo "<WARNING> Die Konfiguration liess sich nicht aus der Zweitschrift $BK zurueckholen."
    fi
elif [ -f "$BK" ] && ! mv_hat_token "$BK" && ! mv_hat_token "$CF"; then
    echo "<INFO> Die Zweitschrift $BK traegt kein lesbares Aktionstoken - es wurde nichts zurueckgespielt."
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
# Die Erstanleitung nur, wenn keine eingerichtete Konfiguration vorliegt.
# postinstall.sh laeuft auch bei jedem Upgrade (Regeln/06); danach war der
# Rat falsch und legte nahe, die Einstellungen seien verloren.
# "Eingerichtet" heisst: mindestens ein Speicher mit Adresse (devices[].ip,
# oder ip auf oberster Ebene aus der Ein-Geraete-Zeit) - dieselbe Frage wie
# marstek_devices(). Das Aktionstoken allein reicht nicht; es entsteht beim
# ersten Oeffnen der Oberflaeche ohne jeden Speicher. Gleichlautend in
# postupgrade.sh. Ohne php gilt die Konfiguration als nicht eingerichtet,
# und die Anleitung erscheint wie bisher.
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
if mv_eingerichtet "$CF"; then
    echo "<OK> Installation abgeschlossen, Einstellungen uebernommen."
elif mv_eingerichtet "$BASE/data/plugins/$PFOLDER.upgrade_sicherung/marstek.json"; then
    # Ohne Zweitschrift holt erst postupgrade.sh die Konfiguration aus der
    # Update-Sicherung zurueck und meldet dort, ob es gelang.
    echo "<OK> Installation abgeschlossen. Die Einstellungen holt postupgrade.sh gleich aus der Update-Sicherung zurueck."
else
    echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und konfigurieren."
fi
exit 0
