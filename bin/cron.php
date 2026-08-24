<?php
/**
 * Marstek Venus E - minutlicher Cron-Lauf (wird von cron/cron.01min aufgerufen)
 *
 * 1. Vervollstaendigt die Konfiguration, falls nach einem Update Schluessel
 *    fehlen. Einmalig, nicht bei jedem Lauf.
 * 2. Holt die Spotpreise bei aWATTar in den Zwischenspeicher. Das geschieht
 *    NUR hier - der Miniserver-Endpunkt marstek.php liest die Datei bloss
 *    noch. Siehe marstek_spot_fetch() in der Bibliothek.
 * 3. Fragt den Status aller konfigurierten Geraete ab (Cache-schonend) ->
 *    fuellt den SOC-Tagesverlauf auch dann, wenn Loxone ein Geraet nicht pollt,
 *    und haelt MQTT aktuell.
 * 4. Schreibt die Tagesbilanz fort (in marstek_energy enthalten).
 * 5. Auto-Fallback: kam laenger als die eingestellte Zeit kein Passiv-Sollwert
 *    mehr (z. B. Loxone ausgefallen), geht das Geraet zurueck in den Auto-Modus.
 * 6. Setzt den Herzschlag. Das ist die einzige Stelle, an der er entsteht:
 *    alles, was ZWEI Momentaufnahmen braucht, ist aus einem Seitenaufruf
 *    grundsaetzlich nicht erreichbar. Der Endpunkt liest ihn, der Takt
 *    schreibt ihn.
 *
 * ===================================================================
 * WARUM DIE SPERRE
 * ===================================================================
 *
 * Ein Durchgang dauert normalerweise Bruchteile einer Sekunde. Sind die
 * Geraete aber nicht erreichbar - Sicherung gefallen, WLAN weg, Speicher im
 * Standby -, laeuft jede Abfrage in ihre Zeitgrenze:
 *
 *   marstek_rpc(): 2 Versuche x 2 Wege (Unicast und Rundruf) x 3 s = 12 s,
 *   und das mehrfach je Geraet (ES.GetStatus, Bat.GetStatus, ...), dazu
 *   Modbus TCP mit 2 Versuchen x 3 s.
 *
 * Nachgemessen mit vier nicht erreichbaren Geraeten:
 *
 *     ein Durchgang von cron.php: 104 Sekunden
 *
 * und mit einem einzigen nicht erreichbaren Geraet immer noch 38 Sekunden.
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
    fwrite(STDERR, "Sperrdatei $sperre nicht anlegbar\n");
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
//
// Dieser Merker sagt der Bibliothek, dass sie den SOC-Verlauf fortschreiben
// darf. Der Miniserver-Endpunkt setzt ihn nicht: der Endpunkt liest, der Takt
// schreibt. Sonst laegen rund 360 Schreibvorgaenge je Tag und Geraet auf der
// Speicherkarte, ausgeloest von einem Aufruf, der nur lesen sollte.
$GLOBALS['marstek_ist_takt'] = true;

// Fehlende Schluessel EINMAL nachtragen. Das ist der Zustand jeder
// bestehenden Anlage nach einem Update - und der einzige Fall, den eine
// Neuinstallation nie durchlaeuft.
marstek_cfg_vervollstaendigen();

marstek_spot_fetch();

// Raenge einmal je Durchgang bilden - das meldet sie zugleich per MQTT
// (seit 1.0.14). Ohne diesen Aufruf kaemen sie nur dann in den Broker, wenn
// Loxone zufaellig ?ranks abfragt; wer auf MQTT umstellt, tut genau das nicht
// mehr. Der Abruf ist billig: er liest nur die Datei, die marstek_spot_fetch()
// eben abgelegt hat, und geht selbst nicht ins Netz.
marstek_ranks();

foreach (marstek_devices() as $n => $d) {
    marstek_status($n); // nutzt den Status-Cache; pollt also nicht haeufiger als noetig
    if (!empty($d['modbus'])) {
        marstek_energy($n); // kWh-Zaehler via Modbus TCP (Cache 300 s), schreibt die
                            // Tagesbilanz fort und meldet per MQTT
    }
}
marstek_fallback_check();

// Der Herzschlag steht am ENDE: er sagt "ein Durchgang ist vollstaendig
// durchgelaufen", nicht "einer hat angefangen". Ein Durchgang, der in der
// Mitte abbricht, soll den Takt nicht als gesund ausweisen.
$mv_z = marstek_herzschlag();
marstek_mqtt_publish_takt($mv_z);

flock($fp, LOCK_UN);
fclose($fp);
echo "OK\n";
