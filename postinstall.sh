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
