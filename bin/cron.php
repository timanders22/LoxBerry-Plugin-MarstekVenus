<?php
/**
 * Marstek Venus E - minutlicher Cron-Lauf (wird von cron/cron.01min aufgerufen)
 *
 * 1. Holt die Spotpreise bei aWATTar in den Zwischenspeicher. Das geschieht
 *    NUR hier - der Miniserver-Endpunkt marstek.php liest die Datei bloss
 *    noch. Siehe marstek_spot_fetch() in der Bibliothek.
 * 2. Fragt den Status aller konfigurierten Geraete ab (Cache-schonend) ->
 *    fuellt den SOC-Tagesverlauf auch dann, wenn Loxone ein Geraet nicht pollt,
 *    und haelt MQTT aktuell.
 * 3. Auto-Fallback: kam laenger als die eingestellte Zeit kein Passiv-Sollwert
 *    mehr (z. B. Loxone ausgefallen), geht das Geraet zurueck in den Auto-Modus.
 *
 * ===================================================================
 * WARUM DIE SPERRE
 * ===================================================================
 *
 * Ein Durchgang dauert normalerweise Bruchteile einer Sekunde. Sind die
 * Geraete aber nicht erreichbar - Sicherung gefallen, WLAN weg, Speicher im
 * Standby -, laeuft jede Abfrage in ihre Zeitgrenze:
 *
 *   marstek_rpc(): 2 Versuche x 2 Ziele (Unicast und Broadcast) x 3 s = 12 s,
 *   und das mehrfach je Geraet (ES.GetStatus, Bat.GetStatus, ...), dazu
 *   Modbus TCP mit 2 Versuchen x 3 s.
 *
 * Nachgemessen mit vier nicht erreichbaren Geraeten:
 *
 *     ein Durchgang von cron.php: 104 Sekunden
 *
 * Der Cron startet aber jede Minute neu. Ohne Sperre laegen nach einer
 * Viertelstunde ein Dutzend Durchgaenge uebereinander, jeder mit offenen
 * Sockets - das legt am Ende nicht das Plugin lahm, sondern den LoxBerry.
 *
 * flock() mit LOCK_NB: laeuft schon einer, endet dieser hier sofort. Kein
 * Warten, kein Aufstauen. Die Sperrdatei bleibt liegen, das ist richtig so -
 * massgeblich ist die Sperre auf dem Dateiverbund, und die gibt das
 * Betriebssystem beim Ende des Prozesses von selbst frei, auch wenn er
 * abgeschossen wird.
 */

/*
 * WARUM DIESE DATEI IN bin/ LIEGT UND NICHT MEHR IN webfrontend/html/
 *
 * Aufgerufen wird sie ausschliesslich vom Minutencron, und zwar ueber die
 * PHP-Kommandozeile - nicht ueber HTTP. Im HTML-Verzeichnis war sie darueber
 * hinaus fuer jeden im Heimnetz abrufbar, und ein Aufruf stoesst einen
 * vollstaendigen Durchgang an: Abruf bei aWATTar, Statusabfrage aller
 * Geraete, MQTT-Meldung - und ueber den Auto-Fallback kann ein Speicher in
 * den Auto-Modus wechseln. Die Sperre weiter unten begrenzt zwar das
 * Stapeln, verhindert den Aufruf aber nicht.
 *
 * marstek_lib.php bleibt im HTML-Verzeichnis: dort liegt auch marstek.php,
 * der Endpunkt fuer den Miniserver. LoxBerry ersetzt die Marke bei der
 * Installation durch den Plugin-HTML-Pfad; laeuft dieses Skript aus dem
 * ausgepackten Archiv heraus, steht sie noch unveraendert da, und der Pfad
 * wird relativ zu dieser Datei gebildet.
 */
$mv_htmldir = 'REPLACELBPHTMLDIR';
if (strpos($mv_htmldir, 'REPLACE') === 0 || !is_file($mv_htmldir . '/marstek_lib.php')) {
    $mv_htmldir = dirname(__DIR__) . '/webfrontend/html';
}
if (!is_file($mv_htmldir . '/marstek_lib.php')) {
    fwrite(STDERR, "marstek_lib.php nicht gefunden (gesucht in $mv_htmldir)\n");
    exit(1);
}
require_once $mv_htmldir . '/marstek_lib.php';

$sperre = marstek_tmpdir() . '/cron.lock';
$fp = @fopen($sperre, 'c');
if ($fp === false) {
    // Ohne Sperre lieber gar nicht laufen, als sich zu stapeln.
    marstek_log('Cron: Sperrdatei ' . $sperre . ' nicht anlegbar - Durchgang uebersprungen.');
    exit(1);
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    // Kein Fehler, sondern der Normalfall bei nicht erreichbaren Geraeten.
    // Nur gebremst protokollieren, sonst laeuft das Log voll.
    $merker = marstek_tmpdir() . '/cron.overlap';
    if (!is_file($merker) || time() - filemtime($merker) > 3600) {
        @touch($merker);
        marstek_log('Cron: ein Durchgang laeuft noch - dieser wird uebersprungen. '
                  . 'Das deutet auf nicht erreichbare Geraete hin.');
    }
    fclose($fp);
    exit(0);
}

// Ab hier laeuft genau ein Durchgang.
marstek_spot_fetch();

foreach (marstek_devices() as $n => $d) {
    marstek_status($n); // nutzt den Status-Cache; pollt also nicht haeufiger als noetig
    if (!empty($d['modbus'])) {
        marstek_energy($n); // kWh-Zaehler via Modbus TCP (Cache 300 s)
    }
}
marstek_fallback_check();

flock($fp, LOCK_UN);
fclose($fp);
echo "OK\n";
