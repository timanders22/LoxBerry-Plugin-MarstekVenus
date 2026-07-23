<?php
/**
 * Marstek Venus E - minutlicher Cron-Lauf (wird von cron/cron.01min aufgerufen)
 *
 * 1. Fragt den Status aller konfigurierten Geraete ab (Cache-schonend) ->
 *    fuellt den SOC-Tagesverlauf auch dann, wenn Loxone ein Geraet nicht pollt,
 *    und haelt MQTT aktuell.
 * 2. Auto-Fallback: kam laenger als die eingestellte Zeit kein Passiv-Sollwert
 *    mehr (z. B. Loxone ausgefallen), geht das Geraet zurueck in den Auto-Modus.
 */

require_once __DIR__ . '/marstek_lib.php';

foreach (marstek_devices() as $n => $d) {
    marstek_status($n); // nutzt den Status-Cache; pollt also nicht haeufiger als noetig
    if (!empty($d['modbus'])) {
        marstek_energy($n); // kWh-Zaehler via Modbus TCP (Cache 300 s)
    }
}
marstek_fallback_check();
echo "OK\n";
