<?php
/**
 * Marstek Venus E - gemeinsame Bibliothek
 *
 * Steuert MARSTEK Venus E Batteriespeicher (Gen 3.0, 5,12 kWh - und baugleiche
 * Geraete der Venus-E-Serie wie der Venus E Mini, 2 kWh) ueber die lokale
 * UDP-JSON-API (Standard-Port 30000) und liefert Spot-Stunden-Rankings
 * (aWATTar) fuer preisgesteuertes Laden/Entladen.
 *
 * Funktionen: Mehrgeraete-Betrieb (bis 4 Speicher), Status mit Cache,
 * Firmware-/Antwortzeit-Erfassung, SOC-Tagesverlauf (History), Passiv-Sollwert
 * mit Watchdog, Auto-Fallback (Rueckgabe an Auto-Modus, wenn laenger kein
 * Sollwert kam), MQTT-Publish ueber das LoxBerry MQTT Gateway, Herzschlag des
 * Minutentakts, Geraetesuche im Netz, Trockenlauf, Tagesbilanz, Summe ueber
 * alle Speicher.
 *
 * Keine persoenlichen Daten im Code - alles kommt aus der Plugin-Konfiguration
 * ($LBHOMEDIR/config/plugins/<plugin>/marstek.json).
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

$GLOBALS['marstek_last_ms'] = 0; // Antwortzeit des letzten RPC in Millisekunden

/* Ab wann gilt der Minutentakt als stehengeblieben?
 *
 * EINE Zahl fuer alle. Bis 1.1.4 standen drei nebeneinander: 180 s im Feld
 * ZAEHLER (Loxone bekam -1), 180 s im Reiter Test (Kreuz) und 300 s im
 * Healthcheck. Zwischen drei und fuenf Minuten sagten Loxone und die
 * Selbstpruefung "Takt steht", waehrend der Healthcheck gruen blieb.
 * 180 s ist der Wert, mit dem auch der empfohlene Baustein #40 arbeitet.
 */
if (!defined('MARSTEK_TAKT_SCHRANKE')) {
    /**
 * Ab wie vielen Vollzyklen der Wirkungsgrad ueberhaupt einer ist.
 *
 * Zehn ist keine gemessene Grenze, sondern eine bewusst gesetzte: bei zehn
 * Zyklen ist rund das Zehnfache der Kapazitaet durch den Speicher gelaufen,
 * und was gerade darin steht, verzerrt den Quotienten nicht mehr wesentlich.
 * Wer sie aendert, aendert sie hier - EINE Quelle, wie bei MARSTEK_TAKT_SCHRANKE.
 */
define('MARSTEK_EFF_ZYKLEN', 10);

define('MARSTEK_TAKT_SCHRANKE', 180);
}


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function marstek_paths() {
    $lbhomedir = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
    $plugindir = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    // BERICHTIGT 04.09.2026: dieselben drei Kandidaten wie in index.php. Bis
    // 1.1.4 kannte die Bibliothek nur zwei; existierte je ein Verzeichnis
    // config/plugins/webfrontend/, arbeiteten Oberflaeche und Bibliothek auf
    // VERSCHIEDENEN Konfigurationsdateien, ohne dass es eine Meldung gab.
    if ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $plugindir) === false) {
        $plugindir = basename(dirname(__DIR__));
        if (is_dir($lbhomedir . '/config/plugins/' . $plugindir) === false) {
            $plugindir = 'marstekvenus';
        }
    }
    if ($lbhomedir) {
        return array(
            'config' => $lbhomedir . '/config/plugins/' . $plugindir . '/marstek.json',
            'backup' => $lbhomedir . '/config/plugins/' . $plugindir . '.backup.json',
            'log' => $lbhomedir . '/log/plugins/' . $plugindir . '/marstek.log',
            // Der Verlauf liegt NEBEN dem Ordner, nicht darin.
            // plugininstall.pl ruft bei JEDEM Update purge_installation, und
            // das entfernt data/plugins/<ordner>/ vollstaendig - ohne
            // Bedingung. Der SOC-Verlauf, die Tagesbilanz, der Herzschlag und
            // das Formularmerkmal waeren nach jeder Aktualisierung weg
            // gewesen, und niemand haette den Zusammenhang gesehen. Der Punkt
            // im Namen ist kein Zufall: der Nachbar liegt im selben
            // Verzeichnis, wird aber von einem rm -rf <ordner>/ nicht
            // getroffen. uninstall/uninstall raeumt ihn selbst weg.
            'datadir' => $lbhomedir . '/data/plugins/' . $plugindir . '.verlauf',
            'data_alt' => $lbhomedir . '/data/plugins/' . $plugindir,
            'tmp' => '/tmp/marstekvenus',
            'lbhome' => $lbhomedir,
        );
    }
    return array(
        'config' => dirname(dirname(__DIR__)) . '/config/marstek.json',
        'backup' => dirname(dirname(__DIR__)) . '/config/marstek.backup.json',
        'log' => sys_get_temp_dir() . '/marstekvenus/marstek.log',
        'datadir' => sys_get_temp_dir() . '/marstekvenus/data',
        'data_alt' => '',
        'tmp' => sys_get_temp_dir() . '/marstekvenus',
        'lbhome' => '',
    );
}

/* ---------------- Konfiguration ---------------- */

/**
 * Die Vorgabewerte stehen an GENAU EINER Stelle.
 *
 * Bis 1.0.16 standen sie zweimal - hier und wortgleich in index.php. Beide
 * Listen stimmten ueberein, aber beim naechsten neuen Schluessel haette
 * jemand nur eine nachgezogen, und dann stuende auf jeder bestehenden Anlage
 * in der Oberflaeche ein anderer Wert als im Cron.
 *
 * Zu den Vorgaben der neuen Schalter (1.1.0):
 *   melden_ein, schutz_ein, verteilen_ein stehen auf 0 - eine neue Funktion
 *   ist ab Werk aus, sonst aendert ein Update das Verhalten einer laufenden
 *   Anlage ungefragt.
 *   steuerung_ein steht dagegen auf 1: eine 0 wuerde nach dem Update JEDE
 *   bestehende Anlage stilllegen. Ein Vorgabewert, der bedient, ist der
 *   Fehler - hier bedient die 1 nicht, sie laesst nur alles beim Alten.
 */
function marstek_vorgaben() {
    return array(
        'devices'        => array(),
        'cache_sec'      => 40,
        'vat'            => 1.19,
        'aufschlag_ct'   => 0,      // ct/kWh netto auf den Boersenpreis (Netzentgelte, Abgaben, Anbieter)
        'awattar'        => 'de',   // de oder at
        'mqtt_enabled'   => 0,
        'mqtt_topic'     => 'marstek',
        'fallback_min'   => 30,     // Standard 30 min; 0 = Auto-Fallback aus
        'aktionstoken'   => '',     // schuetzt ?p= und ?mode= (unangemeldeter Endpunkt)
        'melden_ein'     => 0,      // Benachrichtigungszentrum des LoxBerry
        'melden_ab'      => 3,      // erst beim n-ten Fehlschlag in Folge melden
        'steuerung_ein'  => 1,      // Hauptschalter: 0 nimmt jeden Schaltbefehl an und sendet ihn nicht
        'schutz_ein'     => 0,      // Schutzschwellen unten beachten
        'temp_min'       => 0,      // unterhalb dieser Temperatur nicht laden
        'temp_max'       => 45,     // ab dieser Temperatur weder laden noch entladen
        'soc_min'        => 5,      // bis zu diesem Ladezustand nicht entladen
        'soc_max'        => 98,     // ab diesem Ladezustand nicht laden
        'verlauf_tage'   => 8,      // Aufbewahrung des SOC-Verlaufs
        'verteilen_ein'  => 0,      // &dev=alle verteilt einen Sollwert auf alle Speicher
    );
}

/** Zustand der Konfigurationsdatei - fuer die Selbstpruefung im Reiter Test.
 *  Jeder Zustand, den der Code erzeugen kann, braucht seinen Satz:
 *  ok | leer | zweitschrift | kaputt | fehlt */
function marstek_config_zustand() {
    $p = marstek_paths();
    if (!is_file($p['config'])) {
        return is_file($p['backup']) ? 'zweitschrift' : 'fehlt';
    }
    $roh = trim((string) @file_get_contents($p['config']));
    if ($roh === '' || $roh === '{}') {
        return is_file($p['backup']) ? 'zweitschrift' : 'leer';
    }
    return json_decode($roh, true) === null ? 'kaputt' : 'ok';
}

/**
 * Die Konfiguration lesen.
 *
 * $erzeugen = false schaltet die Selbstheilung ab. Der unangemeldete
 * Endpunkt ruft die Funktion SO.
 *
 * BERICHTIGT 04.09.2026. Bis 1.1.4 hatte diese Funktion keinen Schalter, und
 * marstek.php rief sie zweimal VOR der Tokenpruefung. Gemessen an 1.1.4, ein
 * einziger Aufruf ohne jedes Token:
 *
 *     Lage    marstek.json = "{}", marstekvenus.backup.json liegt daneben
 *     Aufruf  GET /plugins/marstekvenus/marstek.php?status      HTTP 200
 *     danach  marstek.json = {"devices":[...],"aktionstoken":"...",...}
 *
 *     Gegenprobe ohne Zweitschrift: marstek.json bleibt "{}"
 *
 * Jedes Geraet im Heimnetz konnte damit einen Schreibvorgang auf der
 * Speicherkarte ausloesen und eine bewusst geleerte Konfiguration samt
 * Aktionstoken zurueckholen. Die Selbstheilung ist richtig - sie gehoert
 * hinter die Anmeldung, nicht davor.
 */
function marstek_config($erzeugen = true) {
    // Der Schalter allein genuegt nicht: marstek_status(), marstek_dev() und
    // marstek_devices() rufen marstek_config() selbst, und der Endpunkt ruft
    // sie. Deshalb ein Merker fuer den ganzen Prozess, den marstek.php einmal
    // setzt - so gilt "nichts anlegen" auf JEDEM Weg und nicht nur dort, wo
    // jemand daran gedacht hat.
    if (!empty($GLOBALS['marstek_nur_lesen'])) {
        $erzeugen = false;
    }
    $p = marstek_paths();
    // Selbstheilung: fehlende/leere Konfiguration aus Sicherung wiederherstellen.
    // Entschieden wird nach INHALT, nicht nach Form - eine Datei mit "{}" ist
    // so leer wie keine.
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if ($erzeugen && ($roh === '' || $roh === '{}') && is_file($p['backup'])) {
        if (!is_dir(dirname($p['config']))) { @mkdir(dirname($p['config']), 0775, true); }
        @copy($p['backup'], $p['config']);
        @chmod($p['config'], 0600);
        marstek_log('Konfiguration war leer - aus der Zweitschrift wiederhergestellt.');
        $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    }
    $cfg = $roh !== '' ? json_decode($roh, true) : array();
    if (!is_array($cfg)) { $cfg = array(); }
    $cfg += marstek_vorgaben();
    // Migration: alte Ein-Geraete-Konfiguration (ip/port/pmax_* auf oberster Ebene)
    if (empty($cfg['devices']) && !empty($cfg['ip'])) {
        $cfg['devices'] = array(array(
            'name' => 'Venus E',
            'ip' => (string) $cfg['ip'],
            'port' => isset($cfg['port']) ? (int) $cfg['port'] : 30000,
            'pmax_charge' => isset($cfg['pmax_charge']) ? (int) $cfg['pmax_charge'] : 2500,
            'pmax_discharge' => isset($cfg['pmax_discharge']) ? (int) $cfg['pmax_discharge'] : 2500,
            'modbus' => 1,
        ));
        // BERICHTIGT 04.09.2026: die alten Schluessel werden nach der
        // Migration ENTFERNT. Bis 1.1.4 blieben sie auf oberster Ebene
        // stehen, wurden beim naechsten Speichern mitgeschrieben und
        // wanderten in die Sicherungsdatei. Gemessen: die eigene Einfuhr
        // wies die eigene, Minuten alte Ausfuhr ab -
        //     "Abgelehnt: die Datei enthaelt unbekannte Schluessel
        //      (ip, port, pmax_charge, pmax_discharge)".
        // Betroffen war jede Anlage aus der Ein-Geraete-Zeit.
        foreach (array('ip', 'port', 'pmax_charge', 'pmax_discharge') as $veraltet) {
            unset($cfg[$veraltet]);
        }
    }
    return $cfg;
}

/** Konfiguration schreiben - mit Zweitschrift daneben. Rueckgabe true/false. */
function marstek_cfg_schreiben(array $cfg) {
    $p = marstek_paths();
    // is_dir vor mkdir: ein mkdir auf ein vorhandenes Verzeichnis scheitert
    // mit "File exists". Das @ unterdrueckt nur die AUSGABE, nicht den Fehler -
    // in einem Prueflauf mit eigenem Fehler-Aufnehmer steht er dann da und
    // sieht aus wie ein Befund.
    if (!is_dir(dirname($p['config']))) { @mkdir(dirname($p['config']), 0775, true); }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    // 0600: in der Datei stehen das Aktionstoken und die Geraeteadressen.
    // BERICHTIGT 04.09.2026 - bis 1.1.4 schrieb marstek_write_atomic() sie mit
    // 0644, waehrend die Zweitschrift (unten), die Update-Sicherung
    // (preupgrade.sh) und die Wiederherstellung alle 0600 trugen. Die Kopie
    // war strenger als das Original.
    if (!marstek_write_atomic($p['config'], $json, 0600)) {
        return false;
    }
    @copy($p['config'], $p['backup']);   // Sicherung ausserhalb des Plugin-Ordners
    @chmod($p['backup'], 0600);
    return true;
}

/**
 * Die Konfiguration wird VERVOLLSTAENDIGT, nicht ergaenzt.
 *
 * Ergaenzen heisst: beim Lesen tritt fuer einen fehlenden Schluessel seine
 * Vorgabe ein. Die Datei bleibt lueckenhaft, und "fehlt" ist von "steht auf
 * dem Vorgabewert" nicht mehr zu unterscheiden.
 *
 * Rueckgabe: die Namen der Schluessel, die gefehlt haben. Ohne diese Liste
 * liesse sich die Zeile im Reiter Test nicht schreiben.
 *
 * array_key_exists statt isset: isset haelt einen leeren Wert fuer nicht
 * vorhanden und wuerde eine bewusst geleerte Angabe jedes Mal zurueckschreiben.
 */
function marstek_cfg_vervollstaendigen() {
    $p = marstek_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    $cfg = $roh !== '' ? json_decode($roh, true) : array();
    if (!is_array($cfg)) {
        return array();   // kaputt - das ist ein Fehler, kein Ergaenzungsfall
    }
    $fehlten = array();
    foreach (marstek_vorgaben() as $k => $v) {
        if (!array_key_exists($k, $cfg)) {
            $cfg[$k] = $v;
            $fehlten[] = $k;
        }
    }
    if ($fehlten) {
        // EINMAL schreiben, nicht bei jedem Lauf - sonst ist das Protokoll
        // voll und die Datei aendert sich ohne Anlass.
        marstek_cfg_schreiben($cfg);
        marstek_log('Konfiguration ergaenzt: ' . implode(', ', $fehlten));
    }
    return $fehlten;
}

/* ================= Sichern und Zurueckspielen =======================
 *
 * NEU in 1.1.5. Bis 1.1.4 stand der ganze Weg inline in index.php, und er
 * prueft nur die SCHLUESSEL. Gemessen an 1.1.4 gingen sechs von sechs
 * vergifteten Werten unveraendert in die Konfiguration; zwei davon wirkten:
 *
 *   aktionstoken als Feld   -> (string) array ergibt "Array", und
 *                              ?token=Array durfte schalten (beide
 *                              PHP-Fassungen gemessen). Mit display_errors=1
 *                              ging die Warnung "Array to string conversion"
 *                              VOR http_response_code() hinaus, und die
 *                              Abweisung kam als HTTP 200 an.
 *   mqtt_topic mit Umbruch  -> am UDP-Tor gemessen:
 *                              publish marstek<LF>publish fremd/x 1/soc 0
 *
 * Dass der Weg in index.php lag, hatte eine zweite Folge: die drei
 * Hauswerkzeuge (sicherung_pruefen.py, sicherung_wirkung.py,
 * sicherung_verdrahtung.py) suchen eine benannte Lesefunktion und haben
 * diese Linie deshalb ueberhaupt nicht gemessen - eines meldete "1 in
 * Ordnung" mit leerer Tabelle.
 */

/**
 * Taugt der Wert ueberhaupt fuer diese Datei?
 *
 * Erste von zwei Stufen: hier geht es nur um die Gestalt - kein Objekt,
 * kein Wahrheitswert, kein Steuerzeichen, nicht endlos lang. Ob der Wert
 * fuer SEINE Einstellung zulaessig ist, entscheidet marstek_wert_pruefen().
 */
function marstek_wert_taugt($v)
{
    if (is_object($v) || is_bool($v) || is_null($v)) {
        return false;
    }
    if (is_array($v)) {
        return true;   // nur 'devices' darf eine Liste sein - siehe unten
    }
    $s = (string) $v;
    if (strlen($s) > 4096) {
        return false;
    }
    return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $s) !== 1;
}

/** Eine ganze Zahl in Grenzen? */
function marstek_wert_zahl($v, $min, $max)
{
    if (is_array($v) || is_bool($v) || is_null($v)) { return false; }
    if (!is_numeric($v)) { return false; }
    $z = (float) $v;
    return $z >= $min && $z <= $max;
}

/**
 * Ist der Wert fuer DIESE Einstellung zulaessig? Rueckgabe '' oder der Grund.
 *
 * Dieselbe Positivliste wie das Formular. Das Muster fuer das Aktionstoken
 * bleibt bewusst WEIT: ein zu enges verwirft ein von Hand gesetztes oder aus
 * einer aelteren Fassung uebernommenes Token, und der Schaden ist derselbe
 * wie bei einem verlorenen (VolkswagenID 0.9.11 -> 0.9.12). Zugelassen ist,
 * was ohne Kodierung in eine Adresse passt.
 */
function marstek_wert_pruefen($schluessel, $wert)
{
    if (!marstek_wert_taugt($wert)) {
        return 'unzulaessige Gestalt';
    }
    // Eine Liste ist NUR bei 'devices' zulaessig. Ohne diese Zeile fiel
    // ein Feld als Aktionstoken durch: (string) array ergibt "Array",
    // und "Array" passt auf das Muster. Gefunden vom Rechenkern-Selbsttest
    // im ersten Lauf, gemeldet als "Wert abgewiesen: aktionstoken:
    // erwartet true, gemessen false" samt der Warnung von PHP 8.4.
    if (is_array($wert) && $schluessel !== 'devices') {
        return 'eine Liste ist hier nicht zulaessig';
    }
    switch ($schluessel) {
        case 'devices':
            if (!is_array($wert)) { return 'muss eine Liste sein'; }
            if (count($wert) > 4) { return 'mehr als vier Speicher'; }
            foreach ($wert as $g) {
                if (!is_array($g)) { return 'ein Eintrag ist kein Geraet'; }
                $ip = isset($g['ip']) ? (string) $g['ip'] : '';
                if ($ip !== '' && marstek_ip_gueltig($ip) === false) {
                    return 'unzulaessige Adresse ' . substr($ip, 0, 20);
                }
                foreach (array('name' => 64, 'ip' => 64) as $k => $len) {
                    if (isset($g[$k]) && (!marstek_wert_taugt($g[$k])
                        || strlen((string) $g[$k]) > $len)) {
                        return 'unzulaessiger Wert bei ' . $k;
                    }
                }
                if (isset($g['port']) && !marstek_wert_zahl($g['port'], 1, 65535)) {
                    return 'Port ausserhalb 1..65535';
                }
                foreach (array('pmax_charge', 'pmax_discharge') as $k) {
                    if (isset($g[$k]) && !marstek_wert_zahl($g[$k], 100, 3600)) {
                        return $k . ' ausserhalb 100..3600';
                    }
                }
                if (isset($g['kwh']) && !marstek_wert_zahl($g['kwh'], 0, 1000)) {
                    return 'kwh ausserhalb 0..1000';
                }
                if (isset($g['modbus']) && !marstek_wert_zahl($g['modbus'], 0, 1)) {
                    return 'modbus ist weder 0 noch 1';
                }
            }
            return '';
        case 'aktionstoken':
            return preg_match('/^[A-Za-z0-9_.\-]{0,64}$/', (string) $wert) === 1
                ? '' : 'unzulaessige Zeichen im Aktionstoken';
        case 'mqtt_topic':
            return preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', (string) $wert) === 1
                ? '' : 'unzulaessige Zeichen im Themen-Praefix';
        case 'awattar':
            return in_array((string) $wert, array('de', 'at'), true)
                ? '' : 'Markt ist weder de noch at';
        case 'mqtt_enabled': case 'steuerung_ein': case 'verteilen_ein':
        case 'melden_ein': case 'schutz_ein':
            return marstek_wert_zahl($wert, 0, 1) ? '' : 'weder 0 noch 1';
        case 'cache_sec':    return marstek_wert_zahl($wert, 5, 300)    ? '' : 'ausserhalb 5..300';
        case 'vat':          return marstek_wert_zahl($wert, 0.5, 2)    ? '' : 'ausserhalb 0,5..2';
        case 'aufschlag_ct': return marstek_wert_zahl($wert, -50, 100)  ? '' : 'ausserhalb -50..100';
        case 'fallback_min': return marstek_wert_zahl($wert, 0, 1440)   ? '' : 'ausserhalb 0..1440';
        case 'melden_ab':    return marstek_wert_zahl($wert, 1, 20)     ? '' : 'ausserhalb 1..20';
        case 'temp_min':     return marstek_wert_zahl($wert, -20, 20)   ? '' : 'ausserhalb -20..20';
        case 'temp_max':     return marstek_wert_zahl($wert, 20, 80)    ? '' : 'ausserhalb 20..80';
        case 'soc_min':      return marstek_wert_zahl($wert, 0, 50)     ? '' : 'ausserhalb 0..50';
        case 'soc_max':      return marstek_wert_zahl($wert, 50, 100)   ? '' : 'ausserhalb 50..100';
        case 'verlauf_tage': return marstek_wert_zahl($wert, 1, 365)    ? '' : 'ausserhalb 1..365';
    }
    return '';   // ein Schluessel, den die Vorgaben kennen, aber diese Liste nicht
}

/**
 * Eine IPv4-Adresse mit Wertebereich - jede Stelle einzeln.
 * "999.999.999.999" passte bis 1.0.16 durch, weil nur die Form geprueft wurde.
 */
function marstek_ip_gueltig($ip)
{
    $teile = explode('.', (string) $ip);
    if (count($teile) !== 4) { return false; }
    foreach ($teile as $t) {
        if (preg_match('/^\d{1,3}$/', $t) !== 1 || (int) $t > 255) { return false; }
    }
    return true;
}

/**
 * Der lesbare Kopf der Sicherungsdatei.
 *
 * NEU in 1.1.5. Bis 1.1.4 hatte die Datei keinen, und eine Datei MIT einem
 * solchen Kopf wurde beim Zurueckspielen abgewiesen - gemessen:
 * "Abgelehnt: die Datei enthaelt unbekannte Schluessel (_hinweis, _stand)".
 */
function marstek_sicherung_schreiben()
{
    $cfg = marstek_config();
    $kopf = array(
        '_hinweis' => 'Sicherung der Einstellungen des LoxBerry-Plugins Marstek Venus E. '
                    . 'Diese Datei enthaelt das Aktionstoken und die Geraeteadressen - '
                    . 'sie ist wie ein Passwort zu behandeln.',
        '_stand'   => date('Y-m-d H:i'),
        '_fassung' => marstek_fassung(),
    );
    return $kopf + $cfg;
}

/**
 * Eine hochgeladene Sicherung einlesen.
 *
 * Rueckgabe in der Hausform: array(Konfiguration|null, Maengel, Anzahl).
 * null heisst abgelehnt, und dann wird GAR NICHTS geschrieben - alle
 * Beanstandungen werden gesammelt, nicht die erste gemeldet.
 *
 * Die Form ist nicht beliebig: sicherung_wirkung.py stellt sie fest, bevor
 * es urteilt, und meldet fuer eine andere "andere Bauart - von Hand
 * ansehen". Genau das war der Grund, warum die drei Hauswerkzeuge diese
 * Linie bis 1.1.4 ueberhaupt nicht gemessen haben.
 */
function marstek_sicherung_lesen($roh)
{
    $meldungen = array();
    $neu = json_decode((string) $roh, true);
    if (!is_array($neu)) {
        return array(null, array('KEIN_JSON'), 0);
    }
    // Der lesbare Kopf wird UEBERGANGEN, nicht beanstandet.
    foreach (array_keys($neu) as $k) {
        if ($k !== '' && $k[0] === '_') {
            unset($neu[$k]);
        }
    }
    if (!array_key_exists('devices', $neu)) {
        return array(null, array('FREMD'), 0);
    }
    $vorgaben = marstek_vorgaben();
    $fremd = array_diff(array_keys($neu), array_keys($vorgaben));
    if ($fremd) {
        return array(null, array('UNBEKANNT:' . implode(', ', array_slice($fremd, 0, 8))),
                     count($neu));
    }
    foreach ($neu as $k => $v) {
        $grund = marstek_wert_pruefen($k, $v);
        if ($grund !== '') {
            $meldungen[] = 'WERT:' . $k . ': ' . $grund;
        }
    }
    if ($meldungen) {
        return array(null, $meldungen, count($neu));   // fail closed
    }
    $vollstaendig = $neu + $vorgaben;
    if (!marstek_cfg_schreiben($vollstaendig)) {
        return array(null, array('SCHREIBEN'), count($neu));
    }
    marstek_log('Konfiguration aus einer hochgeladenen Datei zurueckgespielt.');
    return array($vollstaendig, array(), count($neu));
}

/** Die Fassung aus der plugin.cfg - eine Quelle, kein zweiter Ort. */
function marstek_fassung()
{
    $p = marstek_paths();
    foreach (array($p['lbhome'] . '/config/plugins/'
                   . basename(dirname($p['config'])) . '/plugin.cfg',
                   dirname(dirname(dirname(__DIR__))) . '/plugin.cfg',
                   dirname(dirname(__DIR__)) . '/plugin.cfg') as $k) {
        if (is_file($k) && preg_match('/^VERSION\s*=\s*(\S+)/m',
                                      (string) @file_get_contents($k), $m)) {
            return trim($m[1]);
        }
    }
    return '';
}

/** Geraete-Liste (nur Eintraege mit IP), 1-basiert indiziert. */
function marstek_devices() {
    $cfg = marstek_config();
    $out = array();
    $n = 0;
    foreach ((array) $cfg['devices'] as $d) {
        if (!is_array($d) || trim((string) (isset($d['ip']) ? $d['ip'] : '')) === '') {
            continue;
        }
        $n++;
        $out[$n] = array(
            'name' => trim((string) (isset($d['name']) ? $d['name'] : '')) !== '' ? trim((string) $d['name']) : ('Speicher ' . $n),
            'ip' => trim((string) $d['ip']),
            'port' => max(1, min(65535, (int) (isset($d['port']) ? $d['port'] : 30000))),
            'pmax_charge' => max(100, min(3600, (int) (isset($d['pmax_charge']) ? $d['pmax_charge'] : 2500))),
            'pmax_discharge' => max(100, min(3600, (int) (isset($d['pmax_discharge']) ? $d['pmax_discharge'] : 2500))),
            'modbus' => isset($d['modbus']) ? (empty($d['modbus']) ? 0 : 1) : 1, // kWh-Zaehler via Modbus TCP; Standard EIN
            // Kapazitaet in kWh. LEER heisst leer - kein Vorgabewert 5.12:
            // ein gewichteter Gesamt-Ladezustand ohne die echte Kapazitaet
            // waere eine Zahl, die richtig aussieht und es nicht ist.
            'kwh' => isset($d['kwh']) && (float) $d['kwh'] > 0 ? round((float) $d['kwh'], 2) : 0.0,
        );
    }
    return $out;
}

/** Konfiguration eines Geraets (1-basiert) oder null. */
function marstek_dev($n) {
    $devs = marstek_devices();
    $n = max(1, (int) $n);
    return isset($devs[$n]) ? $devs[$n] : null;
}

/**
 * Zufallstoken fuer die schaltenden Aufrufe (?p=, ?mode=).
 *
 * Der Endpunkt liegt im unangemeldeten Bereich, damit Loxone ihn ohne
 * Zugangsdaten erreicht. Ohne Token koennte jedes Geraet im Netz den
 * Speicher fernsteuern.
 */
function marstek_token_erzeugen($laenge = 24) {
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/**
 * Merkmal gegen fremde Absender - EIN Wachposten, nicht acht Abfragen.
 *
 * Bis 1.0.16 trug keines der sechs Formulare ein solches Merkmal. Der
 * gefaehrlichste davon ist "Neues Token erzeugen": ein Klick auf einer
 * fremden Seite bei geoeffneter LoxBerry-Sitzung haette ein neues
 * Aktionstoken erzeugt. Ab dann beantwortet der Endpunkt JEDEN Sollwert aus
 * Loxone mit HTTP 403 - und ein virtueller Ausgang wertet die Antwort nicht
 * aus. Der Speicher steht still, der Watchdog stoppt ihn nach vier Minuten,
 * und in der Visualisierung sieht alles normal aus.
 *
 * Der Wert liegt unter data/ und nicht in der Konfiguration: er soll die
 * Konfigurationsausfuhr (Reiter Einstellungen) NICHT mitwandern, und er ist
 * jederzeit neu erzeugbar.
 */
function marstek_formtoken() {
    $f = marstek_datadir() . '/formtoken';
    $t = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if (strlen($t) < 16) {
        $t = marstek_token_erzeugen(32);
        // BERICHTIGT 04.09.2026: der Rueckgabewert wird ausgewertet. Bis
        // 1.1.4 lieferte jeder Aufruf ein NEUES Merkmal, wenn sich die Datei
        // nicht ablegen liess - die Seite rendert eines in das Formular, die
        // Pruefung beim Abschicken erzeugt das naechste, hash_equals()
        // schlaegt fehl. Gemessen, mit einem Verzeichnis an der Stelle der
        // Datei: erster=69ek7re3dp zweiter=dex8ynkvyk, Formular geht durch:
        // False - fuer JEDES Formular, dauerhaft, und die Meldung riet zum
        // Neuladen, was nie half.
        if (@file_put_contents($f, $t) !== strlen($t)) {
            $GLOBALS['marstek_formtoken_ablage'] = $f;
            marstek_log_if_changed('formtoken',
                'Das Formularmerkmal laesst sich nicht ablegen: ' . $f
                . ' - solange das so ist, wird JEDES Formular abgewiesen.', 'ablage:nein');
            return '';
        }
        @chmod($f, 0600);
    }
    return $t;
}

/** Liegt eine Ablage-Stoerung des Formularmerkmals vor? Pfad oder ''. */
function marstek_formtoken_stoerung() {
    marstek_formtoken();
    return isset($GLOBALS['marstek_formtoken_ablage'])
        ? (string) $GLOBALS['marstek_formtoken_ablage'] : '';
}

/** Traegt die Anfrage das Merkmal? Nur fuer POST-Handler. */
function marstek_formtoken_ok() {
    $ist = isset($_POST['formtoken']) && is_string($_POST['formtoken']) ? $_POST['formtoken'] : '';
    $soll = marstek_formtoken();
    return $soll !== '' && hash_equals($soll, $ist);
}

function marstek_tmpdir() {
    $p = marstek_paths();
    if (!is_dir($p['tmp'])) {
        @mkdir($p['tmp'], 0775, true);
    }
    return $p['tmp'];
}

/**
 * Datei atomar schreiben.
 *
 * Bis 1.0.3 wurde ueberall unmittelbar geschrieben:
 *     file_put_contents($cache, json_encode($out));
 * Zwischen dem Anlegen und dem Fertigschreiben liegt aber ein Zeitfenster, in
 * dem die Datei leer oder halb gefuellt ist. Genau dann kann ein anderer
 * Prozess lesen - und dieses Plugin hat drei, die sich staendig begegnen: der
 * Minutencron, der Miniserver-Endpunkt und die Oberflaeche. Der Leser bekommt
 * dann eine halbe JSON-Datei, json_decode() liefert null, und der Wert faellt
 * fuer diesen Durchgang aus.
 *
 * rename() ist auf demselben Dateisystem atomar: der Leser sieht entweder die
 * alte oder die neue Datei, nie etwas dazwischen.
 *
 * Der Zwischenname enthaelt PID und Zufall, sonst faellt sich der Cron mit dem
 * Endpunkt selbst ins Gehege, wenn beide gleichzeitig schreiben.
 *
 * Zurueckgegeben wird true/false - und zwar richtig: json_encode() liefert bei
 * ungueltigem UTF-8 false, und file_put_contents($p, false) schreibt 0 Bytes
 * und meldet 0, nicht false. Deshalb wird der Inhalt vorher geprueft.
 */
function marstek_write_atomic($datei, $inhalt, $rechte = 0644) {
    if ($inhalt === false || $inhalt === null) {
        return false;
    }
    $inhalt = (string) $inhalt;
    $tmp = $datei . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, $inhalt) !== strlen($inhalt)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, $rechte);       // Rechte VOR dem Umbenennen setzen
    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** JSON atomar schreiben. Gibt false zurueck, wenn schon das Kodieren scheitert. */
function marstek_write_json($datei, $daten) {
    return marstek_write_atomic($datei, json_encode($daten));
}

function marstek_datadir() {
    $p = marstek_paths();
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
        // Einmalige Umsiedlung: bis 1.0.16 lag der Verlauf IM Ordner, den der
        // Installer bei jedem Update abraeumt. Wer von dort aktualisiert, hat
        // die Dateien noch - solange das Update noch nicht gelaufen ist.
        if (!empty($p['data_alt']) && is_dir($p['data_alt'])) {
            foreach (glob($p['data_alt'] . '/*.csv') ?: array() as $f) {
                @rename($f, $p['datadir'] . '/' . basename($f));
            }
        }
    }
    return $p['datadir'];
}

/* ---------------- Logging ---------------- */

function marstek_logfile() {
    $p = marstek_paths();
    $dir = dirname($p['log']);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $p['log'];
}

/**
 * Eine Protokolldatei kappen. NEU in 1.1.5 als eigene Funktion, weil es zwei
 * Verbraucher gibt: marstek.log und cron.err. Bis 1.1.4 wurde nur die erste
 * gekappt; die Fehlerausgabe des Minutentakts wuchs ungebremst auf der
 * Ramdisk, und index.php las sie mit file() vollstaendig ein.
 *
 * clearstatcache als erste Zeile: PHP haelt stat()-Antworten im
 * Zwischenspeicher, und file_put_contents(..., FILE_APPEND) macht den
 * Eintrag nicht ungueltig. Unter 7.4 faellt die Kappung sonst still aus.
 */
function marstek_log_kappen($f, $grenze = 512000, $behalten = 200) {
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > $grenze) {
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -$behalten);
        @file_put_contents($f, implode("\n", $tail) . "\n");
        return true;
    }
    return false;
}

/**
 * Die letzten $n Zeilen einer Datei - rueckwaerts ueber fseek, nicht ueber
 * file(). Hausmuster; file() zieht die ganze Datei in den Speicher.
 */
function marstek_log_ende($f, $n = 300) {
    if (!is_file($f)) { return array(); }
    clearstatcache(true, $f);
    $fh = @fopen($f, 'rb');
    if (!$fh) { return array(); }
    $puffer = '';
    $groesse = filesize($f);
    $pos = $groesse;
    $zeilen = 0;
    while ($pos > 0 && $zeilen <= $n) {
        $schritt = min(8192, $pos);
        $pos -= $schritt;
        fseek($fh, $pos);
        $stueck = (string) fread($fh, $schritt);
        $puffer = $stueck . $puffer;
        $zeilen = substr_count($puffer, "\n");
    }
    fclose($fh);
    $aus = preg_split('/\r?\n/', $puffer);
    $aus = array_values(array_filter($aus, 'strlen'));
    return array_slice($aus, -$n);
}

function marstek_log($msg) {
    $f = marstek_logfile();
    marstek_log_kappen($f);
    // Die Fehlerausgabe des Minutentakts liegt daneben und hat keinen
    // eigenen Schreiber - gekappt wird sie deshalb hier mit.
    marstek_log_kappen(dirname($f) . '/cron.err', 262144, 200);
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/**
 * Nur schreiben, wenn sich etwas Strukturelles geaendert hat.
 *
 * BERICHTIGT 24.08.2026. Bis 1.0.16 lautete die Signatur schlicht
 *
 *     preg_replace('/-?\d+(\.\d+)?/', '#', $line)
 *
 * und blendete damit ALLE Zahlen aus. Die Statuszeile besteht aber
 * ausschliesslich aus Zahlen:
 *
 *     "OK=1 SOC=73.5 BATP=-820 FW=148 MS=41"  ->  "OK=# SOC=# BATP=# FW=# MS=#"
 *     "OK=0 SOC=0 BATP=0 FW=0 MS=0"           ->  "OK=# SOC=# BATP=# FW=# MS=#"
 *
 * Beide Signaturen sind gleich. Der Wechsel von "erreichbar" auf "gestoert"
 * und zurueck ist deshalb NIE protokolliert worden; nach dem ersten Lauf
 * schrieb status_devN nie wieder eine Zeile. Das Protokoll ist der einzige
 * Ort, an dem eine Stoerungsgeschichte stehen koennte - und er konnte sie
 * nicht zeigen.
 *
 * Der dritte Parameter traegt jetzt die ZUSTANDSfelder. Sie gehen woertlich
 * in die Signatur ein, die Messwerte weiterhin nicht: ein Ladezustand, der
 * sich um ein Prozent bewegt, soll keine Zeile erzeugen, ein Ausfall schon.
 */
function marstek_log_if_changed($key, $line, $zustand = '') {
    $f = marstek_tmpdir() . '/last_' . $key . '.txt';
    $sig = $zustand . '|' . preg_replace('/-?\d+(\.\d+)?/', '#', $line);
    $prev = is_file($f) ? (string) file_get_contents($f) : '';
    if ($sig !== $prev) {
        marstek_log($key . ': ' . $line);
        @file_put_contents($f, $sig);
    }
}

/* ---------------- UDP: Datenstroeme statt einer Erweiterung ----------------
 *
 * BERICHTIGT 24.08.2026. Bis 1.0.16 lief der gesamte UDP-Verkehr ueber
 * socket_create() - eine Erweiterung, die nicht zugesichert ist. Fehlt sie,
 * ist das kein abfangbarer Fehler, sondern ein fataler; ein @ davor hilft
 * gegen "Call to undefined function" nicht. Gemessen mit demselben Aufruf
 * einmal mit und einmal ohne die Erweiterung:
 *
 *     mit sockets  : MARSTEK;OK=0;SOC=0.0;...        Rueckgabewert 0
 *     ohne sockets : (keine Ausgabe)                 Rueckgabewert 255
 *
 * Der Miniserver sieht dann HTTP 500 ohne Text, der virtuelle Eingang behaelt
 * seinen letzten Wert, und in der App sieht alles normal aus.
 *
 * Seither traegt der Regelweg - Unicast an das Geraet, MQTT an das Gateway -
 * ausschliesslich stream_socket_*, und das ist Kernbestandteil von PHP.
 *
 * EINE Ausnahme bleibt: der Rundruf an x.y.z.255. Ein Rundruf verlangt unter
 * Linux SO_BROADCAST, und die Datenstrom-Schnittstelle von PHP kann diese
 * Option nicht setzen. Er steckt deshalb in genau EINER Funktion, die vorher
 * fragt, ob es die Erweiterung gibt - und die sagt, wenn nicht. Ohne
 * php-sockets verliert das Plugin nur den Rundruf: die Firmware-Eigenheit
 * mancher Venus-Geraete und die Geraetesuche. Alles Uebrige laeuft.
 */

/* ---------------- Mitschnitt des Datenverkehrs ----------------
 *
 * Bei einem Speicher, der nur SPORADISCH schweigt, liegt man mit einem
 * Debug-Aufruf zufaellig daneben. Der Mitschnitt laeuft deshalb ueber eine
 * Zeitspanne und schaltet sich SELBST AB - ab Werk ist er aus, und der Reiter
 * Test erinnert daran, dass er laeuft.
 *
 * Er haengt an denselben Funktionen, die den Verkehr wirklich fuehren; ein
 * Mitschnitt, der eine eigene Kopie mitschriebe, protokollierte sich selbst
 * und nicht den Ernstfall.
 */
function marstek_mitschnitt_bis() {
    $f = marstek_tmpdir() . '/mitschnitt_bis';
    $t = is_file($f) ? (int) @file_get_contents($f) : 0;
    return $t > time() ? $t : 0;
}

/** Fuer $sekunden einschalten; 0 schaltet ab. Rueckgabe: Endzeitpunkt oder 0. */
function marstek_mitschnitt_schalten($sekunden) {
    $f = marstek_tmpdir() . '/mitschnitt_bis';
    $sekunden = max(0, min(3600, (int) $sekunden));
    if ($sekunden === 0) {
        @unlink($f);
        marstek_log('Mitschnitt abgeschaltet.');
        return 0;
    }
    $bis = time() + $sekunden;
    @file_put_contents($f, (string) $bis);
    marstek_log('Mitschnitt eingeschaltet fuer ' . $sekunden . ' Sekunden.');
    return $bis;
}

/** Eine Zeile mitschreiben. $richtung: '->' gesendet, '<-' empfangen. */
function marstek_mitschnitt($richtung, $ziel, $roh) {
    if (!marstek_mitschnitt_bis()) {
        return;
    }
    $f = marstek_tmpdir() . '/mitschnitt.log';
    // Der Mitschnitt liegt unter /tmp: er ist eine Momentaufnahme fuer die
    // Fehlersuche und soll einen Neustart NICHT ueberleben.
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 262144) {
        return;   // gekappt statt gewachsen - eine Ramdisk ist klein
    }
    @file_put_contents($f, date('H:i:s') . ' ' . $richtung . ' ' . $ziel . ' '
        . marstek_mqtt_wert_saeubern(substr((string) $roh, 0, 400)) . "
", FILE_APPEND);
}

/** Den Mitschnitt lesen, neueste zuletzt. */
function marstek_mitschnitt_lesen($max = 200) {
    $f = marstek_tmpdir() . '/mitschnitt.log';
    if (!is_file($f)) {
        return array();
    }
    return array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array(), -$max);
}

/** Ist ein Rundruf moeglich? (php-sockets vorhanden) */
function marstek_broadcast_moeglich() {
    return function_exists('socket_create') && function_exists('socket_sendto');
}

/** Einen ungebundenen UDP-Port oeffnen. Rueckgabe: Handle oder null.
 *  Ungebunden statt verbunden, damit die Absenderadresse jeder Antwort
 *  lesbar bleibt - FW 148 spiegelt die id nicht zurueck, die Zuordnung
 *  laeuft dann ueber den Absender. */
function marstek_udp_oeffnen() {
    $nr = 0; $txt = '';
    $h = @stream_socket_server('udp://0.0.0.0:0', $nr, $txt, STREAM_SERVER_BIND);
    if ($h === false) {
        return null;
    }
    stream_set_blocking($h, false);
    return $h;
}

/** Ueber einen offenen Port senden. Rueckgabe: true/false. */
function marstek_udp_senden($h, $ip, $port, $text) {
    if (!$h) { return false; }
    marstek_mitschnitt('->', $ip . ':' . (int) $port, $text);
    $n = @stream_socket_sendto($h, $text, 0, $ip . ':' . (int) $port);
    return $n !== false && $n >= strlen($text);
}

/**
 * Bis zu $sek Sekunden auf Antworten horchen.
 * Rueckgabe: Liste von array('von' => 'ip', 'roh' => '...').
 *
 * Der Port ist nicht blockierend; ohne die kurze Pause liefe die Schleife
 * heiss und braeuchte eine ganze CPU fuer nichts.
 */
function marstek_udp_horchen($h, $sek, $max = 20) {
    $out = array();
    if (!$h) { return $out; }
    $anfang = microtime(true);
    $ende = $anfang + $sek;
    while (microtime(true) < $ende && count($out) < $max) {
        $von = '';
        $roh = @stream_socket_recvfrom($h, 8192, 0, $von);
        if ($roh === false || $roh === '') {
            usleep(20000);
            continue;
        }
        // Die Antwortzeit wird HIER gestellt, beim Empfang des ersten
        // Datagramms. BERICHTIGT 04.09.2026: bis 1.1.4 stand sie erst nach
        // dem Ruecksprung aus dieser Schleife, und die Schleife lief immer
        // bis zur Zeitgrenze - $max ist 8, ein Geraet antwortet einmal.
        // Gemessen an einem Geraet auf 127.0.0.1, das SOFORT antwortet:
        // MS = 3030 bei einer Zeitgrenze von 3 s. Das Feld hiess seit 1.1.0
        // richtig "Antwortzeit" und war trotzdem eine Konstante.
        if (!count($out)) {
            $GLOBALS['marstek_last_ms'] = (int) round((microtime(true) - $anfang) * 1000);
        }
        $von = preg_replace('/:\d+$/', '', (string) $von);
        marstek_mitschnitt('<-', $von, $roh);
        $out[] = array('von' => $von, 'roh' => $roh);
    }
    return $out;
}

/**
 * Rundruf. Die EINZIGE Stelle, die php-sockets braucht.
 * Rueckgabe: array(Antworten, Meldung). Fehlt die Erweiterung, ist die Liste
 * leer und die Meldung sagt warum - sie wird nicht verschwiegen.
 */
function marstek_udp_rundruf($broadcast_ip, $port, $text, $sek = 3, $max = 20) {
    if (!marstek_broadcast_moeglich()) {
        return array(array(), 'Rundruf nicht moeglich: die PHP-Erweiterung sockets fehlt. '
            . 'Unicast und MQTT sind davon nicht betroffen.');
    }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        return array(array(), 'Rundruf nicht moeglich: der UDP-Port liess sich nicht oeffnen.');
    }
    @socket_set_option($s, SOL_SOCKET, SO_BROADCAST, 1);
    @socket_set_option($s, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 1, 'usec' => 0));
    marstek_mitschnitt('->', $broadcast_ip . ':' . (int) $port . ' (Rundruf)', $text);
    @socket_sendto($s, $text, strlen($text), 0, $broadcast_ip, (int) $port);
    $out = array();
    $ende = microtime(true) + $sek;
    while (microtime(true) < $ende && count($out) < $max) {
        $buf = ''; $von = ''; $rp = 0;
        $r = @socket_recvfrom($s, $buf, 8192, 0, $von, $rp);
        if ($r === false || $buf === '') {
            continue;   // Zeitgrenze des Sockets, nicht der Schleife
        }
        marstek_mitschnitt('<-', (string) $von, (string) $buf);
        $out[] = array('von' => (string) $von, 'roh' => (string) $buf);
    }
    @socket_close($s);
    return array($out, '');
}

/**
 * Die eigenen Rundruf-Adressen ermitteln - ohne Shell, ohne Erweiterung.
 *
 * Ein UDP-"connect" auf eine Adresse aus dem Dokumentationsbereich schickt
 * KEIN Paket; das Betriebssystem schlaegt nur die Route nach und legt die
 * Quelladresse fest. stream_socket_get_name() liest sie ab. Daraus wird die
 * Rundruf-Adresse des eigenen Netzes.
 *
 * Dazu kommen die Netze der schon eingetragenen Geraete - wer ein zweites
 * Netz betreibt, findet dort sonst nichts.
 */
/**
 * Die Rundrufadresse eines Netzes. EINE Stelle - bis 1.1.4 stand dieselbe
 * Ersetzung dreimal wortgleich im Quelltext.
 *
 * GRENZE, ausdruecklich: das trifft ein /24-Netz. Die Netzmaske liefert
 * weder die UDP-API noch PHP ohne Erweiterung; wer ein /16 betreibt, traegt
 * das Geraet mit seiner IP ein und braucht den Rundruf nur fuer die Suche.
 */
function marstek_broadcast_zu($ip) {
    return preg_replace('/\.\d+$/', '.255', (string) $ip);
}

function marstek_rundruf_adressen() {
    $out = array();
    $nr = 0; $txt = '';
    $s = @stream_socket_client('udp://192.0.2.1:9', $nr, $txt, 1);
    if ($s) {
        $eigen = preg_replace('/:\d+$/', '', (string) stream_socket_get_name($s, false));
        fclose($s);
        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $eigen)) {
            $out[] = marstek_broadcast_zu($eigen);
        }
    }
    foreach (marstek_devices() as $d) {
        $bc = marstek_broadcast_zu($d['ip']);
        if (!in_array($bc, $out, true)) { $out[] = $bc; }
    }
    return $out;
}

/* ---------------- Lokale UDP-JSON-API ---------------- */

function marstek_rpc($method, $params = null, $dev = 1, $tries = 2, $tmo = 3) {
    $d = marstek_dev($dev);
    if ($d === null) {
        return array('_error' => 'Geraet ' . (int) $dev . ' nicht konfiguriert (Plugin-Oberflaeche oeffnen).');
    }
    if ($params === null) {
        $params = array('id' => 0);
    }
    $id = rand(1, 999999);
    $payload = json_encode(array('id' => $id, 'method' => $method, 'params' => $params));
    /*
     * FIRMWARE-EIGENHEIT (live verifiziert an Venus E 3.0, FW 148):
     * Manche Firmwaren antworten NUR auf Pakete an die BROADCAST-Adresse
     * (x.y.z.255), nicht auf Unicast an die Geraete-IP - und spiegeln dabei
     * nicht einmal die gesendete id zurueck. Deshalb: beide Wege probieren,
     * Antworten notfalls ueber die Absender-IP zuordnen und den
     * funktionierenden Weg als Merker speichern (spart den Timeout-Umweg).
     * ACHTUNG bei MEHREREN Venus-Geraeten im selben Netz: Broadcast-Befehle
     * erreichen ALLE Geraete - dann sollten alle Geraete eine Firmware haben,
     * die Unicast beantwortet.
     */
    $bc = marstek_broadcast_zu($d['ip']);
    $modef = marstek_tmpdir() . '/udpmode_dev' . (int) $dev;
    $mode = is_file($modef) ? trim((string) file_get_contents($modef)) : 'uni';
    $wege = $mode === 'bc' ? array('bc', 'uni') : array('uni', 'bc');
    for ($a = 0; $a < $tries; $a++) {
        foreach ($wege as $weg) {
            $tsend = microtime(true);
            $antworten = array();
            if ($weg === 'uni') {
                $h = marstek_udp_oeffnen();
                if ($h === null) {
                    return array('_error' => 'UDP-Port liess sich nicht oeffnen');
                }
                marstek_udp_senden($h, $d['ip'], $d['port'], $payload);
                // EINE Antwort genuegt: die Frage ging an genau ein Geraet.
                // Bis 1.1.4 stand hier 8, und die Schleife wartete deshalb
                // jedes Mal die vollen drei Sekunden ab - gemessen kostete
                // ein Cron-Durchgang 9,3 s, obwohl das Geraet sofort
                // antwortete. Der Rundruf unten sammelt weiterhin bis zu 8,
                // denn dort koennen mehrere Geraete antworten.
                $antworten = marstek_udp_horchen($h, $tmo, 1);
                fclose($h);
            } else {
                // Ohne php-sockets faellt nur dieser Weg aus, nicht der Abruf.
                if (!marstek_broadcast_moeglich()) {
                    continue;
                }
                list($antworten, ) = marstek_udp_rundruf($bc, $d['port'], $payload, $tmo, 8);
            }
            foreach ($antworten as $ant) {
                $j = @json_decode($ant['roh'], true);
                // id-Treffer ODER Antwort vom richtigen Geraet (id-Spiegelung fehlt bei FW 148)
                if (is_array($j) && ((isset($j['id']) && $j['id'] == $id)
                        || ($ant['von'] === $d['ip'] && (isset($j['result']) || isset($j['error']))))) {
                    if ($weg === 'bc') {   // der Rundruf stellt sie nicht selbst
                        $GLOBALS['marstek_last_ms'] = (int) round((microtime(true) - $tsend) * 1000);
                    }
                    @file_put_contents($modef, $weg);
                    if (isset($j['error'])) {
                        return array('_error' => json_encode($j['error']));
                    }
                    return isset($j['result']) ? $j['result'] : null;
                }
            }
        }
    }
    return null; // Timeout
}

/** Geraete-Info (Modell, Firmware) - Cache 6 h, stoert das Geraet praktisch nie. */
function marstek_devinfo($dev = 1, $refresh = false) {
    $cache = marstek_tmpdir() . '/devinfo_dev' . (int) $dev . '.json';
    if (!$refresh && is_file($cache) && time() - filemtime($cache) < 21600) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c)) {
            return $c;
        }
    }
    $r = marstek_rpc('Marstek.GetDevice', array('ble_mac' => '0'), $dev);
    $out = array('model' => '', 'fw' => 0);
    if (is_array($r) && !isset($r['_error'])) {
        if (isset($r['device'])) { $out['model'] = (string) $r['device']; }
        if (isset($r['ver'])) { $out['fw'] = (int) $r['ver']; }
        marstek_write_json($cache, $out);
    } elseif (is_file($cache)) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c)) {
            return $c;
        }
    }
    return $out;
}

/**
 * Status (ES.GetStatus + Bat.GetStatus) mit Cache. Rueckgabe: assoziatives Array.
 *
 * BERICHTIGT 24.08.2026: eine Stoerung ueberschreibt die zuletzt gemessenen
 * Werte nicht mehr. Gemessen an 1.0.16, ein einziger misslungener Abruf:
 *
 *     VORHER : soc 73.5  batp -820  temp 24.1  fw 148
 *     NACHHER: soc 0     batp 0     temp 0     fw 0
 *
 * In Loxone sprang damit der Ladezustand auf 0 % - und zwar in genau der
 * Logik, die dieses Plugin selbst empfiehlt (Entladesperre unter 12 %,
 * Ladestopp ueber 97 %). Die Energiezaehler machen es seit jeher richtig,
 * mit einer Begruendung im Quelltext; hier stand das Gegenteil.
 *
 * Neu ist deshalb auch 'mess': der Zeitpunkt der letzten ECHTEN Messung. Ein
 * behaltener Wert ohne sein Alter ist von einem frischen nicht zu
 * unterscheiden, und das waere schlechter als der alte Zustand.
 */
function marstek_status($dev = 1, $force = false) {
    $cfg = marstek_config();
    $dev = max(1, (int) $dev);
    $cache = marstek_tmpdir() . '/status_dev' . $dev . '.json';
    $alt = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
    if (!is_array($alt)) { $alt = null; }
    if (!$force && $alt !== null && time() - filemtime($cache) < max(5, (int) $cfg['cache_sec'])) {
        return $alt;
    }
    $es = marstek_rpc('ES.GetStatus', null, $dev);
    $ms = (int) $GLOBALS['marstek_last_ms'];
    $bat = marstek_rpc('Bat.GetStatus', null, $dev);
    $es_ok = is_array($es) && !isset($es['_error']);
    $bat_ok = is_array($bat) && !isset($bat['_error']);
    $ok = ($es_ok || $bat_ok) ? 1 : 0;

    if (!$ok) {
        // Kein Wert ist besser als eine erfundene Null. Steht ein frueherer
        // Stand da, bleibt er stehen; nur ok und ts werden angefasst. 'mess'
        // ist der Zeitpunkt der letzten ECHTEN Messung und bleibt unberuehrt.
        $out = $alt !== null ? $alt : array('soc' => 0, 'batp' => 0, 'temp' => 0, 'gridp' => 0,
                                            'fw' => 0, 'model' => '', 'ms' => 0, 'mess' => 0);
        $out['ok'] = 0;
        $out['ts'] = time();
        if (!isset($out['mess'])) { $out['mess'] = 0; }
        // In diesem Durchgang wurde nichts gemessen. 'feldzeit' bleibt
        // stehen - daran sieht die Schutzpruefung, wie alt jedes Feld ist.
        $out['gemessen'] = array();
        if (!isset($out['feldzeit']) || !is_array($out['feldzeit'])) { $out['feldzeit'] = array(); }
        marstek_write_json($cache, $out);
        marstek_ausfall_zaehlen($dev, true);
        marstek_log_if_changed('status_dev' . $dev,
            'OK=0 (keine Antwort) - letzte Messung '
            . ($out['mess'] ? date('d.m. H:i:s', $out['mess']) : 'nie'), 'ok=0');
        marstek_mqtt_publish($out, $dev);
        return $out;
    }

    /* BERICHTIGT 04.09.2026 - der halbe Abruf.
     *
     * Bis 1.1.4 griff der Rueckfall auf die zuletzt gemessenen Werte nur,
     * wenn BEIDE Aufrufe scheiterten. Antwortete nur einer - und UDP verliert
     * Pakete -, galt ok = 1, und jedes Feld, das der antwortende Aufruf nicht
     * traegt, ging als 0 in den Zwischenspeicher. Gemessen an 1.1.4 mit einem
     * Geraet, das nur ES.GetStatus beantwortet, mit einem vollstaendigen
     * Abruf im Zwischenspeicher davor:
     *
     *     vorher : OK=1;SOC=73.5;BATP=0;TEMP=24.1;GRIDP=-120;FW=148
     *     nachher: OK=1;SOC=73.5;BATP=-820;TEMP=0.0;GRIDP=-120;FW=148
     *
     * Das ist derselbe Fehler, den 1.1.0 behoben hat - nur mit OK=1, also in
     * Loxone unsichtbar. Und mit eingeschalteten Schutzschwellen sperrte die
     * 0 anschliessend jeden Ladebefehl (TEMP_MIN, Vorgabe temp_min = 0).
     *
     * Jetzt gilt je Feld: kein frischer Messwert -> der alte bleibt stehen,
     * und 'gemessen' sagt, welche Felder in DIESEM Durchgang wirklich
     * gemessen wurden. Nur auf die urteilen die Schutzschwellen.
     */
    $alt_w = is_array($alt) ? $alt : array();
    $gemessen = array();
    $behalten = function ($feld, $vorgabe) use ($alt_w) {
        return array_key_exists($feld, $alt_w) ? $alt_w[$feld] : $vorgabe;
    };

    $soc = $behalten('soc', 0); $batp = $behalten('batp', 0);
    $temp = $behalten('temp', 0); $gridp = $behalten('gridp', 0);
    if (is_array($bat) && isset($bat['soc'])) {
        $soc = $bat['soc'];
        $gemessen[] = 'soc';
    } elseif (is_array($es) && isset($es['bat_soc'])) {
        $soc = $es['bat_soc'];
        $gemessen[] = 'soc';
    }
    if (is_array($es) && isset($es['bat_power'])) {
        $batp = $es['bat_power']; // + = laedt
        $gemessen[] = 'batp';
    } else {
        $d = marstek_dev($dev);
        if (is_array($d) && !empty($d['modbus'])) {
            // FW 148 liefert in ES.GetStatus KEIN bat_power-Feld (live verifiziert) ->
            // Batterieleistung ersatzweise per Modbus lesen (Register 30001, int32, W).
            $regs = marstek_modbus_read($d['ip'], 30001, 2);
            if (is_array($regs)) {
                $v = $regs[0] * 65536 + $regs[1];
                if ($v >= 2147483648) {
                    $v -= 4294967296; // int32 mit Vorzeichen
                }
                if (abs($v) > 20000) { // unplausibel -> als einzelnes int16 interpretieren
                    $v = $regs[0] >= 32768 ? $regs[0] - 65536 : $regs[0];
                }
                $batp = $v; // Vorzeichen-Konvention beim ersten echten Ladevorgang verifizieren
                $gemessen[] = 'batp';
            }
        }
    }
    if (is_array($bat) && isset($bat['bat_temp'])) {
        $temp = $bat['bat_temp'];
        if ($temp > 100) {
            $temp = $temp / 10; // alte BMS-Firmware liefert 10x
        }
        $gemessen[] = 'temp';
    }
    if (is_array($es) && isset($es['ongrid_power'])) {
        $gridp = $es['ongrid_power'];
        $gemessen[] = 'gridp';
    }
    $info = marstek_devinfo($dev);
    // Modell und Firmware kommen aus einem eigenen Zwischenspeicher (6 h).
    // Antwortet er nicht, gilt derselbe Grundsatz: der alte Wert bleibt.
    $fw = (int) $info['fw'];
    $model = (string) $info['model'];
    if ($fw === 0 && (int) $behalten('fw', 0) > 0) { $fw = (int) $behalten('fw', 0); }
    if ($model === '' && (string) $behalten('model', '') !== '') { $model = (string) $behalten('model', ''); }
    $out = array('ok' => 1, 'soc' => round((float) $soc, 1), 'batp' => round((float) $batp),
                 'temp' => round((float) $temp, 1), 'gridp' => round((float) $gridp),
                 'fw' => $fw, 'model' => $model,
                 'ms' => $ms, 'ts' => time(), 'mess' => time(),
                 // Welche Felder DIESER Durchgang wirklich gemessen hat.
                 'gemessen' => array_values(array_unique($gemessen)),
                 // Zeitpunkt der letzten echten Messung JE FELD - daran
                 // entscheidet marstek_schutz_pruefen(), worueber es urteilt.
                 'feldzeit' => array());
    $fz = is_array($alt) && isset($alt['feldzeit']) && is_array($alt['feldzeit'])
        ? $alt['feldzeit'] : array();
    foreach ($out['gemessen'] as $feld) { $fz[$feld] = time(); }
    $out['feldzeit'] = $fz;
    marstek_write_json($cache, $out);
    marstek_ausfall_zaehlen($dev, false);
    marstek_log_if_changed('status_dev' . $dev,
        'OK=1 SOC=' . $out['soc'] . ' BATP=' . $out['batp'] . ' FW=' . $out['fw'] . ' MS=' . $out['ms'],
        'ok=1');
    marstek_history_add($dev, $out['soc'], $out['batp']);
    marstek_mqtt_publish($out, $dev);
    return $out;
}

/**
 * Fehlschlaege in Folge zaehlen - Grundlage der Meldung (marstek_melden).
 *
 * Gemeldet wird beim n-ten Fehlschlag in Folge, nicht bei jedem: eine
 * Meldung je Minute ist keine Meldung, sondern Rauschen - und wer sie
 * abstellt, stellt auch die echte ab. Beim ersten Erfolg danach kommt die
 * Entwarnung.
 */
function marstek_ausfall_zaehlen($dev, $fehlschlag) {
    $cfg = marstek_config();
    $f = marstek_tmpdir() . '/ausfall_dev' . (int) $dev . '.json';
    $s = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($s)) { $s = array('folge' => 0, 'gemeldet' => 0, 'heute' => 0, 'tag' => '', 'letzter' => 0); }
    if ((string) $s['tag'] !== date('Ymd')) { $s['tag'] = date('Ymd'); $s['heute'] = 0; }
    $ab = max(1, (int) $cfg['melden_ab']);
    $d = marstek_dev($dev);
    $name = $d ? $d['name'] : ('Geraet ' . (int) $dev);
    if ($fehlschlag) {
        if ((int) $s['folge'] === 0) { $s['heute'] = (int) $s['heute'] + 1; $s['letzter'] = time(); }
        $s['folge'] = (int) $s['folge'] + 1;
        if ($s['folge'] >= $ab && empty($s['gemeldet'])) {
            $s['gemeldet'] = 1;
            marstek_melden(3, $name . ' antwortet seit ' . $s['folge'] . ' Abfragen nicht mehr.');
        }
    } else {
        if (!empty($s['gemeldet'])) {
            marstek_melden(6, $name . ' antwortet wieder.');
        }
        $s['folge'] = 0;
        $s['gemeldet'] = 0;
    }
    marstek_write_json($f, $s);
}

/** Ausfallzahlen eines Geraets: array(folge, heute, letzter). */
function marstek_ausfall_stand($dev) {
    $f = marstek_tmpdir() . '/ausfall_dev' . (int) $dev . '.json';
    $s = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($s)) { return array('folge' => 0, 'heute' => 0, 'letzter' => 0); }
    if (isset($s['tag']) && (string) $s['tag'] !== date('Ymd')) { $s['heute'] = 0; }
    return array('folge' => (int) $s['folge'], 'heute' => (int) $s['heute'],
                 'letzter' => (int) (isset($s['letzter']) ? $s['letzter'] : 0));
}

/* ---------------- Herzschlag des Minutentakts ----------------
 *
 * Alles, was ZWEI Momentaufnahmen braucht, ist aus einem Seitenaufruf
 * grundsaetzlich nicht erreichbar. Ohne diese Datei ist ein toter Cron von
 * einem ruhigen Haus nicht zu unterscheiden: der Zwischenspeicher liefert
 * weiter alte Werte, und ein virtueller Eingang behaelt bei
 * Nichterreichbarkeit ohnehin seinen letzten Wert.
 *
 * Der Zaehler laeuft 0..999 um. Loxone braucht einen Wert, der sich
 * ZUVERLAESSIG aendert, um eine Aenderungsueberwachung darauf zu setzen -
 * ein Zeitstempel allein reicht dafuer nicht, weil er im Broker stehenbleibt.
 *
 * Ablage in data/, nicht in log/: Letzteres ist auf dem LoxBerry eine
 * Ramdisk. Eine Zweitschrift braucht es nicht - der Herzschlag ist neu
 * erzeugbar.
 */
function marstek_herzschlag() {
    $f = marstek_datadir() . '/herzschlag.json';
    $s = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    $z = is_array($s) && isset($s['zaehler']) ? ((int) $s['zaehler'] + 1) % 1000 : 0;
    marstek_write_json($f, array('zaehler' => $z, 'ts' => time()));
    return $z;
}

/** Wann lief der Minutentakt zuletzt? array(zaehler, ts). ts = 0 heisst: noch nie. */
function marstek_herzstand() {
    $f = marstek_datadir() . '/herzschlag.json';
    $s = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($s) || !isset($s['ts'])) {
        return array('zaehler' => -1, 'ts' => 0);
    }
    return array('zaehler' => (int) $s['zaehler'], 'ts' => (int) $s['ts']);
}

/* ---------------- Benachrichtigungszentrum des LoxBerry ----------------
 *
 * notify_ext() erzeugt den roten Punkt am Plugin-Symbol auf der Startseite.
 * Die Wache auf function_exists() gehoert dazu: die Funktion steckt in einer
 * Bibliothek, die nicht jede LoxBerry-Fassung gleich bestueckt, und ein @
 * hilft gegen "undefined function" nicht.
 *
 * Schwere nach der LoxBerry-Skala: 3 Fehler, 4 Warnung, 5 in Ordnung,
 * 6 Hinweis.
 *
 * Ab Werk AUS. Wer das Plugin aktualisiert, soll nicht ungefragt Meldungen
 * bekommen, die er nie bestellt hat. Protokolliert wird trotzdem - eine
 * Meldung, die nicht hinausgeht, ist kein Grund, sie auch nicht
 * aufzuschreiben.
 */
function marstek_melden($schwere, $text) {
    $cfg = marstek_config();
    if (empty($cfg['melden_ein'])) {
        return false;
    }
    $p = marstek_paths();
    if ($p['lbhome'] !== '' && !function_exists('notify_ext')) {
        $lib = $p['lbhome'] . '/libs/phplib/loxberry_log.php';
        if (is_file($lib)) { require_once $lib; }
    }
    marstek_log('Meldung (' . (int) $schwere . '): ' . $text);
    if (!function_exists('notify_ext')) {
        return false;   // kein Bedienelement ohne Wirkung: es wird protokolliert, nicht behauptet
    }
    $ordner = getenv('LBPPLUGINDIR') ?: 'marstekvenus';
    notify_ext(array(
        'PACKAGE'  => $ordner,
        'NAME'     => 'Marstek Venus E',
        'MESSAGE'  => (string) $text,
        'SEVERITY' => (int) $schwere,
    ));
    return true;
}

/* ---------------- Der Befund: EINE Funktion fuer drei Verbraucher ----------------
 *
 * Oberflaeche, Healthcheck und Benachrichtigung benutzen dieselbe Auskunft.
 * Drei Stellen, die dasselbe anders sagen, sind zwei zu viel.
 *
 * Rueckgabe: array(schwere, text). Schwere nach der LoxBerry-Skala:
 * 3 Fehler, 4 Warnung, 5 in Ordnung, 6 Hinweis.
 */
function marstek_befund() {
    $zustand = marstek_config_zustand();
    if ($zustand === 'kaputt') {
        return array('schwere' => 3, 'text' => 'Die Konfigurationsdatei ist beschädigt und '
            . 'konnte nicht gelesen werden. Die Zweitschrift liegt daneben.');
    }
    if ($zustand === 'fehlt' || $zustand === 'leer') {
        return array('schwere' => 4, 'text' => 'Das Plugin ist noch nicht eingerichtet - '
            . 'bitte die Plugin-Oberflaeche oeffnen und mindestens einen Speicher eintragen.');
    }
    // BERICHTIGT 04.09.2026: 'zweitschrift' fiel bis 1.1.4 durch und wurde wie
    // 'ok' behandelt. Der Healthcheck blieb gruen, obwohl die
    // Konfigurationsdatei verlorengegangen und aus der Zweitschrift
    // wiederhergestellt worden war - waehrend der Reiter Test fuer denselben
    // Zustand ein Kreuz zeigte. Zwei Verbraucher derselben Funktion duerfen
    // nicht verschieden urteilen.
    if ($zustand === 'zweitschrift') {
        return array('schwere' => 4, 'text' => 'Die Konfigurationsdatei war leer und ist aus '
            . 'der Zweitschrift neben dem Plugin-Ordner wiederhergestellt worden. Bitte im '
            . 'Reiter Einbindung in Loxone pruefen, ob das Aktionstoken noch zu den Adressen '
            . 'im Miniserver passt.');
    }
    $devs = marstek_devices();
    if (!$devs) {
        return array('schwere' => 4, 'text' => 'Es ist kein Speicher eingetragen.');
    }
    // Ueber einen Takt, der gar nicht laeuft, wird kein Geraeteurteil gefaellt:
    // die Zwischenspeicher waeren dann ohnehin alt.
    $h = marstek_herzstand();
    if ($h['ts'] <= 0) {
        return array('schwere' => 4, 'text' => 'Der Minutentakt ist noch nie gelaufen. '
            . 'Nach der Installation dauert das bis zu einer Minute; bleibt es dabei, '
            . 'steht der Grund in log/plugins/<ordner>/cron.err.');
    }
    if (time() - $h['ts'] > MARSTEK_TAKT_SCHRANKE) {
        return array('schwere' => 3, 'text' => 'Der Minutentakt lief zuletzt vor '
            . (int) round((time() - $h['ts']) / 60) . ' Minuten. Der Cron-Eintrag fehlt, '
            . 'oder cron.php bricht ab - der Grund steht in log/plugins/<ordner>/cron.err.');
    }
    $stumm = array();
    foreach ($devs as $n => $d) {
        $c = marstek_tmpdir() . '/status_dev' . $n . '.json';
        $st = is_file($c) ? json_decode((string) @file_get_contents($c), true) : null;
        if (!is_array($st) || empty($st['ok'])) {
            $stumm[] = $d['name'];
        }
    }
    if ($stumm) {
        return array('schwere' => 3, 'text' => (count($stumm) === count($devs) ? 'Kein Speicher antwortet' : 'Nicht erreichbar')
            . ': ' . implode(', ', $stumm) . '. Lokale API aktiviert? Geraet im Standby?');
    }
    return array('schwere' => 5, 'text' => count($devs) . ' Speicher erreichbar, Minutentakt laeuft.');
}

/* ---------------- SOC-Tagesverlauf (History) ---------------- */

/**
 * Messpunkt anhaengen (max. alle 240 s ein Punkt; Aufbewahrung einstellbar).
 *
 * NUR AUS DEM MINUTENTAKT. Bis 1.0.16 schrieb auch der Miniserver-Endpunkt
 * hier mit - er ruft marstek_status(), und die rief diese Funktion. Das sind
 * rund 360 Schreibvorgaenge je Tag und Geraet auf die SPEICHERKARTE, ausgeloest
 * von einem Aufruf, der nur lesen sollte. Der Zwischenspeicher unter /tmp ist
 * eine Ramdisk und kostet nichts; data/ ist es nicht.
 *
 * Der Takt ruft marstek_status() ohnehin jede Minute - der Verlauf fuellt sich
 * also unveraendert, nur eben aus der Stelle, die dafuer da ist.
 */
function marstek_history_add($dev, $soc, $batp) {
    if (empty($GLOBALS['marstek_ist_takt'])) {
        return;
    }
    $cfg = marstek_config();
    $dir = marstek_datadir();
    $f = $dir . '/history_dev' . (int) $dev . '_' . date('Ymd') . '.csv';
    $stamp = marstek_tmpdir() . '/hist_ts_dev' . (int) $dev;
    $last = is_file($stamp) ? (int) file_get_contents($stamp) : 0;
    if (time() - $last < 240) {
        return;
    }
    @file_put_contents($f, time() . ';' . $soc . ';' . $batp . "\n", FILE_APPEND);
    @file_put_contents($stamp, (string) time());
    if (rand(0, 50) === 0) { // Aufraeumen gelegentlich
        $tage = max(1, min(365, (int) $cfg['verlauf_tage']));
        foreach (glob($dir . '/history_dev*_*.csv') ?: array() as $old) {
            if (time() - (int) filemtime($old) > $tage * 86400) {
                @unlink($old);
            }
        }
    }
}

/** Messpunkte eines Tages lesen: Array von [ts, soc, batp]. $day = 'Ymd' (Standard heute). */
function marstek_history_read($dev, $day = '') {
    if ($day === '') {
        $day = date('Ymd');
    }
    $f = marstek_datadir() . '/history_dev' . (int) $dev . '_' . preg_replace('/\D/', '', $day) . '.csv';
    $out = array();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
            $c = explode(';', $line);
            if (count($c) >= 2) {
                $out[] = array((int) $c[0], (float) $c[1], isset($c[2]) ? (float) $c[2] : 0);
            }
        }
    }
    return $out;
}

/** Welche Tage liegen fuer dieses Geraet vor? Neueste zuerst, als 'Ymd'. */
function marstek_history_tage($dev) {
    $out = array();
    foreach (glob(marstek_datadir() . '/history_dev' . (int) $dev . '_*.csv') ?: array() as $f) {
        if (preg_match('/_(\d{8})\.csv$/', $f, $m)) {
            $out[] = $m[1];
        }
    }
    rsort($out);
    return $out;
}

/**
 * Kennzahlen eines Tages aus dem Verlauf.
 *
 * Der Verlauf liegt ohnehin da und wurde bisher nur fuer das Bild benutzt.
 * Ein leerer Tag liefert KEINE Nullen, sondern ok=0 - eine 0 als
 * Tagestiefstwert waere eine stille Falschaussage.
 */
function marstek_history_kennzahlen($dev, $day = '') {
    $p = marstek_history_read($dev, $day);
    if (count($p) < 2) {
        return array('ok' => 0, 'n' => count($p), 'socmin' => 0, 'socmax' => 0, 'hub' => 0);
    }
    $min = 101.0; $max = -1.0;
    foreach ($p as $x) {
        if ($x[1] < $min) { $min = $x[1]; }
        if ($x[1] > $max) { $max = $x[1]; }
    }
    return array('ok' => 1, 'n' => count($p), 'socmin' => round($min, 1),
                 'socmax' => round($max, 1), 'hub' => round($max - $min, 1));
}

/* ---------------- Sollwert / Modus ---------------- */

/** Merker des zuletzt ANGENOMMENEN Sollwerts: array(p, t, ts). */
function marstek_soll_lesen($dev = 1) {
    $f = marstek_tmpdir() . '/passive_dev' . (int) $dev . '.json';
    $s = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (is_array($s) && isset($s['ts'])) {
        return array('p' => isset($s['p']) ? (int) $s['p'] : null,
                     't' => (int) $s['t'], 'ts' => (int) $s['ts']);
    }
    // Fassungen bis 1.0.16 legten hier eine reine Zahlendatei ohne Endung ab.
    // Sie traegt nur den Zeitpunkt - der Wert ist verloren, aber der
    // Auto-Fallback soll nach dem Update nicht neu anlaufen.
    $alt = marstek_tmpdir() . '/passive_dev' . (int) $dev;
    if (is_file($alt)) {
        $t = (int) @file_get_contents($alt);
        if ($t > 0) { return array('p' => null, 't' => 0, 'ts' => $t); }
    }
    return array('p' => null, 't' => 0, 'ts' => 0);
}

/** Restzeit bis zum Auto-Fallback in Sekunden. -1 = aus, -2 = kein Passivbetrieb. */
function marstek_fallback_rest($dev = 1) {
    $cfg = marstek_config();
    $min = (int) $cfg['fallback_min'];
    if ($min <= 0) { return -1; }
    $s = marstek_soll_lesen($dev);
    if ($s['ts'] <= 0) { return -2; }
    return max(0, $min * 60 - (time() - $s['ts']));
}

/**
 * Schutzschwellen pruefen. Rueckgabe: '' = frei, sonst der Grund.
 *
 * NUR bewerten, was gemessen vorliegt: ein fehlender oder alter Messwert
 * erzeugt keine Sperre und auch keine Freigabe. Ab Werk ist die Pruefung aus.
 */
function marstek_schutz_pruefen($p, $dev = 1) {
    $cfg = marstek_config();
    if (empty($cfg['schutz_ein']) || (int) $p === 0) {
        return '';
    }
    $cache = marstek_tmpdir() . '/status_dev' . (int) $dev . '.json';
    $st = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
    if (!is_array($st) || empty($st['mess'])) {
        return '';   // nie gemessen - es wird nicht geraten
    }
    /* BERICHTIGT 04.09.2026 - JE FELD, nicht fuer den ganzen Datensatz.
     *
     * Bis 1.1.4 fragte diese Funktion nur, OB ueberhaupt je gemessen wurde.
     * Zusammen mit dem halben Abruf (siehe marstek_status()) hiess das:
     * ein verlorenes UDP-Paket setzte temp auf 0, und die Vorgabe
     * temp_min = 0 sperrte daraufhin jeden Ladebefehl. Gemessen an 1.1.4:
     *
     *     halber Abruf -> TEMP=0.0, dann Schutz bei p=+800: 'TEMP_MIN'
     *
     * Der Kopfkommentar dieser Funktion hat das immer schon anders
     * versprochen: "ein fehlender oder alter Messwert erzeugt keine Sperre
     * und auch keine Freigabe".
     */
    $fz = isset($st['feldzeit']) && is_array($st['feldzeit']) ? $st['feldzeit'] : array();
    $frisch = function ($feld) use ($fz, $st) {
        // Ohne 'feldzeit' (Zwischenspeicher aus 1.1.4 oder aelter) gilt der
        // gemeinsame Zeitpunkt - so verhaelt es sich wie bisher, statt nach
        // einem Update stumm gar nicht mehr zu schuetzen.
        $t = array_key_exists($feld, $fz) ? (int) $fz[$feld] : (int) $st['mess'];
        return $t > 0 && time() - $t <= 900;
    };
    $temp = (float) $st['temp'];
    $soc = (float) $st['soc'];
    if ($frisch('temp') && $temp >= (float) $cfg['temp_max']) {
        return 'TEMP_MAX';
    }
    if ($p > 0 && $frisch('temp') && $temp <= (float) $cfg['temp_min']) {
        return 'TEMP_MIN';
    }
    if ($p > 0 && $frisch('soc') && $soc >= (float) $cfg['soc_max']) {
        return 'SOC_MAX';
    }
    if ($p < 0 && $frisch('soc') && $soc <= (float) $cfg['soc_min']) {
        return 'SOC_MIN';
    }
    return '';
}

/**
 * Passiv-Sollwert setzen. $p: Loxone-Konvention + = LADEN (API-intern gedreht).
 *
 * $trocken = true rechnet alles fertig und sendet NICHTS. Es ist derselbe
 * Code bis zur letzten Zeile - ein Trockenlauf, der eine eigene Kopie waere,
 * pruefte sich selbst und nicht den Ernstfall.
 *
 * Rueckgabe: array(ok, p, t, hinweis)
 */
function marstek_set_passive($p, $t, $dev = 1, $trocken = false) {
    $cfg = marstek_config();
    $d = marstek_dev($dev);
    $p = (int) $p;
    $pc = $d ? $d['pmax_charge'] : 2500;
    $pd = $d ? $d['pmax_discharge'] : 2500;
    if ($p > $pc) { $p = $pc; }
    if ($p < -$pd) { $p = -$pd; }
    if (abs($p) < 25) { $p = 0; }
    $t = (int) $t;
    if ($t < 30) { $t = 30; }
    if ($t > 3600) { $t = 3600; }

    if (empty($cfg['steuerung_ein'])) {
        marstek_log_if_changed('set_dev' . (int) $dev, 'p=' . $p . ' t=' . $t . ' - Steuerung ist abgeschaltet', 'aus');
        return array(0, $p, $t, 'STEUERUNG_AUS');
    }
    $sperre = marstek_schutz_pruefen($p, $dev);
    if ($sperre !== '') {
        marstek_log_if_changed('set_dev' . (int) $dev, 'p=' . $p . ' t=' . $t . ' - gesperrt (' . $sperre . ')', 'sperre:' . $sperre);
        return array(0, $p, $t, $sperre);
    }
    if ($trocken) {
        return array(1, $p, $t, 'TROCKEN');
    }

    $res = marstek_rpc('ES.SetMode', array('id' => 0, 'config' => array(
        'mode' => 'Passive',
        'passive_cfg' => array('power' => -$p, 'cd_time' => $t),
    )), $dev);
    $ok = (is_array($res) && !empty($res['set_result'])) ? 1 : 0;
    if ($ok) { // Auto-Fallback und Soll/Ist: Wert UND Zeitpunkt merken
        marstek_write_json(marstek_tmpdir() . '/passive_dev' . (int) $dev . '.json',
            array('p' => $p, 't' => $t, 'ts' => time()));
        @unlink(marstek_tmpdir() . '/passive_dev' . (int) $dev);   // Altlast bis 1.0.16
    }
    marstek_log_if_changed('set_dev' . (int) $dev, 'p=' . $p . ' t=' . $t . ' ok=' . $ok, 'ok=' . $ok);
    if (!$ok) {
        marstek_log('SET fehlgeschlagen (Geraet ' . (int) $dev . '): p=' . $p . ' t=' . $t
            . (is_array($res) && isset($res['_error']) ? ' (' . $res['_error'] . ')' : ''));
    }
    return array($ok, $p, $t, '');
}

/**
 * Einen Sollwert auf ALLE Speicher verteilen.
 *
 * Verteilt wird im Verhaeltnis der Leistungsgrenzen. Ab Werk ist das aus.
 *
 * Ausdruecklich KEINE Alles-oder-nichts-Regel: dass ein teilweise
 * angenommener Sollwert schlechter waere als gar keiner, ist im Bestand
 * nirgends belegt und waere eine Auslegung, keine Messung. Was jedes Geraet
 * angenommen hat, steht einzeln in der Antwort.
 *
 * Rueckgabe: array(ok, angenommen, gesamt, Zeilen)
 */
function marstek_set_passive_alle($p, $t, $trocken = false) {
    $devs = marstek_devices();
    $p = (int) $p;
    $summe = 0;
    foreach ($devs as $d) {
        $summe += $p >= 0 ? $d['pmax_charge'] : $d['pmax_discharge'];
    }
    $rest = $p;
    $zeilen = array();
    $ok_ges = 0;
    $i = 0;
    $anzahl = count($devs);
    foreach ($devs as $n => $d) {
        $i++;
        $grenze = $p >= 0 ? $d['pmax_charge'] : $d['pmax_discharge'];
        // Der letzte bekommt den Rest, sonst geht durch das Runden Leistung verloren.
        $anteil = ($i === $anzahl || $summe <= 0) ? $rest : (int) round($p * $grenze / $summe);
        if (abs($anteil) > abs($rest)) { $anteil = $rest; }
        $rest -= $anteil;
        list($ok, $pw, , $hinweis) = marstek_set_passive($anteil, $t, $n, $trocken);
        $ok_ges += $ok;
        $zeilen[] = array('n' => $n, 'p' => $pw, 'ok' => $ok, 'hinweis' => $hinweis);
    }
    return array($ok_ges > 0 ? 1 : 0, $ok_ges, $anzahl, $zeilen);
}

/**
 * Betriebsmodus an das Geraet zurueckgeben (auto|ai).
 *
 * BERICHTIGT 24.08.2026: unbekannte Werte werden ABGEWIESEN. Bis 1.0.16
 * hatte diese Funktion zwei Ausgaenge - alles, was nicht 'ai' hiess, war
 * 'Auto'. Ein Tippfehler in einer Loxone-Adresse gab damit die Regie an den
 * Speicher ab, und die Antwort OK=0 kam vom Geraet, nicht von der
 * Eingangspruefung.
 *
 * Rueckgabe: array(ok, modus, hinweis)
 */
function marstek_set_mode($m, $dev = 1, $trocken = false) {
    $cfg = marstek_config();
    $m = strtolower(trim((string) $m));
    if (!in_array($m, array('auto', 'ai'), true)) {
        return array(0, '', 'MODE');
    }
    $m = $m === 'ai' ? 'AI' : 'Auto';
    if (empty($cfg['steuerung_ein'])) {
        return array(0, $m, 'STEUERUNG_AUS');
    }
    if ($trocken) {
        return array(1, $m, 'TROCKEN');
    }
    $cfgkey = $m === 'AI' ? 'ai_cfg' : 'auto_cfg';
    $res = marstek_rpc('ES.SetMode', array('id' => 0, 'config' => array('mode' => $m, $cfgkey => array('enable' => 1))), $dev);
    $ok = (is_array($res) && !empty($res['set_result'])) ? 1 : 0;
    if ($ok) {
        @unlink(marstek_tmpdir() . '/passive_dev' . (int) $dev . '.json'); // Fallback-Merker loeschen
        @unlink(marstek_tmpdir() . '/passive_dev' . (int) $dev);
    }
    marstek_log('Modus ' . $m . ' gesetzt (Geraet ' . (int) $dev . '): ok=' . $ok);
    return array($ok, $m, '');
}

/* ---------------- Auto-Fallback (Cron, minutlich) ---------------- */

/**
 * Kam laenger als fallback_min Minuten kein Passiv-Sollwert mehr (z. B. Loxone
 * ausgefallen), wird das Geraet in den Auto-Modus zurueckgegeben, damit es sich
 * selbst managt. 0 = aus. Der 240-s-Watchdog stoppt nur; das hier gibt die Regie ab.
 */
function marstek_fallback_check() {
    $cfg = marstek_config();
    $min = (int) $cfg['fallback_min'];
    if ($min <= 0) {
        return;
    }
    foreach (marstek_devices() as $n => $d) {
        $s = marstek_soll_lesen($n);
        if ($s['ts'] <= 0) {
            continue; // kein Passiv-Betrieb aktiv
        }
        if (time() - $s['ts'] > $min * 60) {
            /* BERICHTIGT 04.09.2026 - einmal melden, nicht jede Minute.
             *
             * marstek_set_mode() loescht den Sollwert-Merker nur bei ok = 1.
             * Kommt der Fallback nicht durch - Geraet stumm, oder der
             * Hauptschalter steht auf aus -, blieb der Merker liegen, und der
             * naechste Minutentakt machte dasselbe noch einmal. Gemessen,
             * drei Durchgaenge mit steuerung_ein = 0: drei gleichlautende
             * Protokollzeilen, Merker danach noch da. Mit eingeschalteten
             * Benachrichtigungen waere das eine Meldung je Minute - genau das,
             * was marstek_ausfall_zaehlen() zwei Bildschirmseiten weiter oben
             * ausdruecklich vermeidet.
             */
            $cfg_h = marstek_config();
            if (empty($cfg_h['steuerung_ein'])) {
                marstek_log_if_changed('fallback_dev' . $n,
                    'Auto-Fallback faellig, aber der Hauptschalter steht auf aus.', 'aus');
                continue;
            }
            $gemeldet = marstek_tmpdir() . '/fallback_gemeldet_dev' . (int) $n;
            list($ok, ) = marstek_set_mode('auto', $n);
            $minuten = (int) round((time() - $s['ts']) / 60);
            if ($ok) {
                @unlink($gemeldet);
                marstek_log('Auto-Fallback (Geraet ' . $n . '): ' . $minuten
                    . ' min kein Sollwert -> Auto-Modus (ok=1)');
                marstek_melden(4, $d['name'] . ': seit ' . $minuten . ' Minuten kein Sollwert aus Loxone - '
                    . 'der Speicher wurde in den Auto-Modus zurueckgegeben.');
            } elseif (!is_file($gemeldet)) {
                @touch($gemeldet);
                marstek_log('Auto-Fallback (Geraet ' . $n . '): ' . $minuten
                    . ' min kein Sollwert, aber der Speicher nimmt den Auto-Modus nicht an '
                    . '(ok=0). Weitere Versuche werden nicht mehr gemeldet.');
                marstek_melden(3, $d['name'] . ': seit ' . $minuten . ' Minuten kein Sollwert aus '
                    . 'Loxone, und der Speicher nimmt den Auto-Modus nicht an.');
            }
        }
    }
}

/* ---------------- Modbus TCP (nur LESEND - Energiezaehler) ----------------
 * Venus E Gen 3.0 bietet ab Firmware 144 Modbus TCP direkt ueber das LAN-Kabel
 * (Port 502, Unit-ID 1) - ohne RS485-Adapter. Das Plugin nutzt Modbus bewusst
 * NUR LESEND fuer die kWh-Zaehler des Geraets (gesamt/Tag/Monat), Zyklen und
 * Wirkungsgrad. Die STEUERUNG bleibt auf der UDP-API, weil nur deren
 * Passiv-Modus einen Watchdog (cd_time) hat - der Modbus-Force-Modus liefe
 * bei einem Loxone-Ausfall ungebremst weiter.
 * Registerbelegung (Venus E v3): siehe github.com/ViperRNMC/marstek_venus_modbus
 */

/** Holding-Register lesen (FC3). Rueckgabe: Array von uint16 oder null.
 *  Mit automatischem Wiederholversuch - das Geraet verweigert gern schnell
 *  aufeinanderfolgende TCP-Verbindungen. */
function marstek_modbus_read($ip, $addr, $count, $port = 502, $unit = 1, $tmo = 3) {
    for ($try = 0; $try < 2; $try++) {
        $r = marstek_modbus_read_once($ip, $addr, $count, $port, $unit, $tmo);
        if ($r !== null) {
            return $r;
        }
        usleep(400000);
    }
    return null;
}

function marstek_modbus_read_once($ip, $addr, $count, $port = 502, $unit = 1, $tmo = 3) {
    $errno = 0; $errstr = '';
    $fp = @fsockopen($ip, $port, $errno, $errstr, $tmo);
    if (!$fp) {
        return null;
    }
    stream_set_timeout($fp, $tmo);
    $tid = rand(1, 60000);
    // MBAP: TransaktionsID, Protokoll 0, Laenge 6, UnitID | PDU: FC3, Adresse, Anzahl
    fwrite($fp, pack('nnnCCnn', $tid, 0, 6, $unit, 3, $addr, $count));
    $need = 9 + 2 * $count; // MBAP(7) + FC(1) + ByteCount(1) + Daten
    $buf = '';
    while (strlen($buf) < $need) {
        $chunk = fread($fp, $need - strlen($buf));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buf .= $chunk;
    }
    fclose($fp);
    if (strlen($buf) < 9 || ord($buf[7]) !== 3) {
        return null; // Fehlerantwort oder unvollstaendig
    }
    $regs = array();
    for ($i = 0; $i < $count && 9 + 2 * $i + 1 < strlen($buf); $i++) {
        $regs[] = (ord($buf[9 + 2 * $i]) << 8) | ord($buf[9 + 2 * $i + 1]);
    }
    return count($regs) === $count ? $regs : null;
}

/** uint32 aus zwei Registern (High-Word zuerst). */
function marstek_u32($regs, $i) {
    return isset($regs[$i], $regs[$i + 1]) ? $regs[$i] * 65536 + $regs[$i + 1] : 0;
}

/**
 * Die kWh-Zaehler ermitteln, die Tagesbilanz fortschreiben und das Ergebnis
 * per MQTT melden.
 *
 * Die Meldung sitzt bewusst in dieser Huelle und nicht in der Ermittlung:
 * die hat fuenf Ruecksprungstellen (Modbus aus, Zwischenspeicher, keine
 * Antwort mit und ohne alten Stand, frische Werte), und an vier davon haette
 * man die Meldung vergessen koennen. Hier gibt es nur eine.
 */
function marstek_energy($dev = 1, $force = false) {
    $e = marstek_energy_ermitteln($dev, $force);
    marstek_bilanz_fortschreiben($dev, $e);
    marstek_mqtt_publish_energy($e, $dev);
    return $e;
}

function marstek_energy_ermitteln($dev = 1, $force = false) {
    $d = marstek_dev($dev);
    $off = array('ok' => 0, 'chgt' => 0, 'dist' => 0, 'chgd' => 0, 'disd' => 0,
                 'chgm' => 0, 'dism' => 0, 'cyc' => 0, 'eff' => 0, 'ts' => time(), 'mess' => 0);
    if ($d === null || empty($d['modbus'])) {
        return $off; // Modbus fuer dieses Geraet nicht aktiviert
    }
    $cache = marstek_tmpdir() . '/energy_dev' . (int) $dev . '.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 300) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c)) {
            return $c;
        }
    }
    // 33000..33011: Laden/Entladen gesamt, Tag, Monat (je uint32, 0,01 kWh)
    $e = marstek_modbus_read($d['ip'], 33000, 12);
    usleep(300000); // Verbindungspause - das Geraet mag keine schnellen Folgeverbindungen
    // 34002: SOC, 34003: Zyklenzaehler
    $c2 = marstek_modbus_read($d['ip'], 34002, 2);
    // Rohwerte fuer die Debug-Ansicht merken (KEIN erneutes Lesen im Debug -
    // das Geraet mag keine schnell aufeinanderfolgenden Modbus-Verbindungen)
    @file_put_contents(marstek_tmpdir() . '/energy_raw_dev' . (int) $dev . '.json',
        json_encode(array('regs_33000' => $e, 'regs_34002' => $c2, 'ts' => time())));
    if ($e === null) {
        marstek_log_if_changed('energy_dev' . (int) $dev, 'Modbus TCP keine Antwort (Port 502)', 'ok=0');
        // WICHTIG: letzte bekannte Zaehlerstaende behalten (ok=0), NICHT auf 0 fallen -
        // sonst wuerden Monats-/Wochenbilanzen in Loxone bei einem Ausfall kippen.
        if (is_file($cache)) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c) && !empty($c['chgt'])) {
                $c['ok'] = 0;
                $c['ts'] = time();
                if (!isset($c['mess'])) { $c['mess'] = 0; }
                marstek_write_json($cache, $c);
                return $c;
            }
        }
        marstek_write_json($cache, $off);
        return $off;
    }
    $out = array(
        'ok' => 1,
        'chgt' => round(marstek_u32($e, 0) * 0.01, 2),
        'dist' => round(marstek_u32($e, 2) * 0.01, 2),
        'chgd' => round(marstek_u32($e, 4) * 0.01, 2),
        'disd' => round(marstek_u32($e, 6) * 0.01, 2),
        'chgm' => round(marstek_u32($e, 8) * 0.01, 2),
        'dism' => round(marstek_u32($e, 10) * 0.01, 2),
        'cyc' => is_array($c2) && isset($c2[1]) ? (int) $c2[1] : 0,
        'ts' => time(),
        'mess' => time(),
    );
    // BERICHTIGT 06.09.2026 - "Wirkungsgrad gesamt" war keiner.
    //
    // Gerechnet wird abgegeben durch geladen, beides Lebensdauerzaehler. Am
    // 05.09.2026 an der Anlage gemessen: CHGT=35,34 DIST=7,80 CYC=4, macht
    // EFF=22,1. In Loxone stand also die Kachel Wirkungsgrad gesamt auf
    // 22,1 Prozent, waehrend eine LiFePO4 bei rund 90 % liegt. Die Zahl war
    // nicht
    // ungenau - sie beantwortete eine andere Frage: nach vier Zyklen steckt
    // der groesste Teil der geladenen Energie noch im Speicher.
    //
    // Der Quotient naehert sich dem Wirkungsgrad erst, wenn der Durchsatz die
    // gespeicherte Menge deutlich uebersteigt. Deshalb die Schwelle. Bis
    // dahin der Fehlwert -1, wie bei jedem anderen Feld, das noch nichts
    // sagen kann - lieber "weiss ich nicht" als eine falsche Zahl.
    $out['eff'] = ($out['cyc'] >= MARSTEK_EFF_ZYKLEN && $out['chgt'] > 0)
        ? round($out['dist'] / $out['chgt'] * 100, 1)
        : -1;
    marstek_write_json($cache, $out);
    marstek_log_if_changed('energy_dev' . (int) $dev,
        'OK CHGD=' . $out['chgd'] . ' DISD=' . $out['disd'] . ' CYC=' . $out['cyc'], 'ok=1');
    return $out;
}

/* ---------------- Tagesbilanz fortschreiben ----------------
 *
 * CHGD/DISD (Tag) und CHGM/DISM (Monat) kommen aus den Geraeteregistern, und
 * das Geraet setzt sie zurueck: der Wert vom 31. ist am 1. weg. Wer in Loxone
 * keine Statistik gebaut hat, hat keine Historie.
 *
 * Fortgeschrieben wird der TAGESHOECHSTSTAND, nicht der Momentanwert - der
 * Zaehler eines Tages waechst monoton, der Hoechststand ist damit sein
 * Abschluss. Ein Tag, an dem gar nichts gemessen wurde, bekommt KEINE
 * Nullzeile: eine 0 hiesse "nichts geladen", und das ist etwas anderes als
 * "nicht gemessen".
 */
function marstek_bilanz_fortschreiben($dev, array $e) {
    if (empty($e['ok'])) {
        return;
    }
    /* BERICHTIGT 04.09.2026 - zwei Wachen, beide aus demselben Grund wie bei
     * marstek_history_add(): der Endpunkt liest, der Takt schreibt.
     *
     * Bis 1.1.4 schrieb diese Funktion die Datei bei JEDEM Aufruf neu, auch
     * wenn sich kein Zaehlerstand geaendert hatte - und Aufrufer waren der
     * Minutencron UND jeder ?energy-Abruf aus Loxone. Gemessen, dreimal
     * derselbe Datensatz: drei verschiedene Inodes, gleiche Pruefsumme.
     * Das sind 1440 Schreibvorgaenge je Tag und Geraet auf die
     * Speicherkarte, dazu die Abrufe des Miniservers.
     */
    if (empty($GLOBALS['marstek_ist_takt'])) {
        return;
    }
    $f = marstek_datadir() . '/bilanz_dev' . (int) $dev . '.csv';
    $heute = date('Y-m-d');
    $zeilen = marstek_bilanz_lesen($dev);
    $chg = (float) $e['chgd'];
    $dis = (float) $e['disd'];
    if (isset($zeilen[$heute])) {
        // Hoechststand halten. Ein kleinerer Wert heisst Tageswechsel im
        // Geraet oder ein Neustart - beides darf den Abschluss nicht kuerzen.
        $chg = max($chg, $zeilen[$heute][0]);
        $dis = max($dis, $zeilen[$heute][1]);
    }
    $zeilen[$heute] = array(round($chg, 2), round($dis, 2));
    ksort($zeilen);
    if (count($zeilen) > 800) {
        $zeilen = array_slice($zeilen, -800, null, true);
    }
    $text = '';
    foreach ($zeilen as $tag => $w) {
        $text .= $tag . ';' . $w[0] . ';' . $w[1] . "\n";
    }
    // Nur schreiben, wenn sich wirklich etwas geaendert hat.
    clearstatcache(true, $f);
    if (is_file($f) && (string) @file_get_contents($f) === $text) {
        return;
    }
    marstek_write_atomic($f, $text);
}

/** Tagesbilanz lesen: array('YYYY-MM-DD' => array(geladen, abgegeben)). */
function marstek_bilanz_lesen($dev) {
    $f = marstek_datadir() . '/bilanz_dev' . (int) $dev . '.csv';
    $out = array();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $z) {
            $c = explode(';', $z);
            if (count($c) >= 3) { $out[$c[0]] = array((float) $c[1], (float) $c[2]); }
        }
    }
    return $out;
}

/**
 * Summe ueber einen Zeitraum: 'woche', 'monat', 'jahr', 'gesamt'.
 * Rueckgabe: array(tage, chg, dis, eff). eff nur, wenn BEIDE Reihen tragen -
 * eine Arbeitszahl aus einer halben Bilanz sieht richtig aus und ist es nicht.
 */
function marstek_bilanz_summe($dev, $zeitraum = 'monat') {
    $von = '0000-00-00';
    if ($zeitraum === 'woche') { $von = date('Y-m-d', strtotime('-6 days')); }
    if ($zeitraum === 'monat') { $von = date('Y-m-01'); }
    if ($zeitraum === 'jahr')  { $von = date('Y-01-01'); }
    $chg = 0.0; $dis = 0.0; $tage = 0;
    foreach (marstek_bilanz_lesen($dev) as $tag => $w) {
        if (strcmp((string) $tag, $von) < 0) { continue; }
        $chg += $w[0]; $dis += $w[1]; $tage++;
    }
    $eff = ($chg >= 1.0 && $dis >= 1.0) ? round($dis / $chg * 100, 1) : 0;
    return array('tage' => $tage, 'chg' => round($chg, 2), 'dis' => round($dis, 2), 'eff' => $eff);
}

/* ---------------- Summe ueber alle Speicher ----------------
 *
 * Bei mehreren Speichern brauchte Loxone bisher je einen virtuellen Eingang
 * und musste selbst rechnen - ungewichtet, denn die Kapazitaet kennt es
 * nicht. Bei einem Venus E Gen 3.0 (5,12 kWh) neben einem Venus E Mini
 * (2 kWh) ist ein ungewichteter Mittelwert falsch.
 *
 * FAIL CLOSED: fehlt bei EINEM Speicher die Kapazitaet oder antwortet einer
 * nicht, gibt es keinen gewichteten Ladezustand (-1) und keine Restmenge.
 * Eine Teilsumme ist schlimmer als kein Wert.
 *
 * Das Alter der Summe ist das GROESSTE der Teilalter.
 */
function marstek_summe() {
    $devs = marstek_devices();
    $n = count($devs);
    $nok = 0; $kapaz = 0.0; $gewicht = 0.0; $batp = 0; $alter = 0;
    $alle_kwh = $n > 0;
    foreach ($devs as $i => $d) {
        $cache = marstek_tmpdir() . '/status_dev' . $i . '.json';
        $st = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
        if (!is_array($st) || empty($st['ok'])) {
            $nok++;
            continue;
        }
        $mess = (int) (isset($st['mess']) ? $st['mess'] : 0);
        $alter = max($alter, $mess > 0 ? time() - $mess : 999999);
        $batp += (int) $st['batp'];
        if ($d['kwh'] > 0) {
            $kapaz += $d['kwh'];
            $gewicht += $d['kwh'] * (float) $st['soc'];
        } else {
            $alle_kwh = false;
        }
    }
    $ok = ($n > 0 && $nok === 0) ? 1 : 0;
    $soc = ($ok && $alle_kwh && $kapaz > 0) ? round($gewicht / $kapaz, 1) : -1;
    return array(
        'ok'      => $ok,
        'n'       => $n,
        'nok'     => $nok,
        'soc'     => $soc,
        'kapaz'   => ($ok && $alle_kwh) ? round($kapaz, 2) : -1,
        'restkwh' => ($soc >= 0 && $kapaz > 0) ? round($kapaz * $soc / 100, 2) : -1,
        'batp'    => $ok ? $batp : 0,
        'alter'   => $ok ? $alter : -1,
    );
}

/* ---------------- Geraetesuche im Netz ----------------
 *
 * Die halbe Arbeit stand seit jeher in der Diagnose: ein Rundruf mit
 * Marstek.GetDevice und das Ablesen der Absenderadresse. Nur leitete sich das
 * Rundruf-Ziel aus der SCHON EINGETRAGENEN IP ab - also genau dann nutzlos,
 * wenn man die IP noch nicht kennt.
 *
 * Rueckgabe: array(Liste, Meldung). Je Eintrag ip, model, fw.
 */
function marstek_suche($sekunden = 3, $port = 30000) {
    if (!marstek_broadcast_moeglich()) {
        return array(array(), 'Die Suche braucht einen Rundruf, und der braucht die PHP-Erweiterung '
            . 'sockets. Sie fehlt auf diesem LoxBerry. Die Geraete-IP laesst sich stattdessen in der '
            . 'Geraeteliste des Routers ablesen.');
    }
    $frage = json_encode(array('id' => 77777, 'method' => 'Marstek.GetDevice',
                               'params' => array('ble_mac' => '0')));
    $liste = array();
    $meldungen = array();
    foreach (marstek_rundruf_adressen() as $bc) {
        list($antworten, $fehler) = marstek_udp_rundruf($bc, $port, $frage, $sekunden, 20);
        if ($fehler !== '') { $meldungen[] = $fehler; continue; }
        foreach ($antworten as $a) {
            $j = @json_decode($a['roh'], true);
            if (!is_array($j)) { continue; }
            $r = isset($j['result']) && is_array($j['result']) ? $j['result'] : array();
            $ip = $a['von'];
            $liste[$ip] = array(
                'ip'    => $ip,
                'model' => isset($r['device']) ? (string) $r['device'] : '',
                'fw'    => isset($r['ver']) ? (int) $r['ver'] : 0,
            );
        }
    }
    ksort($liste);
    return array(array_values($liste), implode(' ', $meldungen));
}

/* ---------------- Diagnose (Selbsttest fuer die Fehlersuche) ---------------- */

function marstek_diag($dev = 1) {
    $out = array();
    $d = marstek_dev($dev);
    if ($d === null) {
        return array('Geraet ' . (int) $dev . ' ist nicht konfiguriert (Plugin-Oberflaeche oeffnen).');
    }
    $out[] = 'Geraet ' . (int) $dev . ': ' . $d['name'] . ' - IP ' . $d['ip'] . ', UDP-Port ' . $d['port']
           . ', kWh-Zaehler (Modbus): ' . ($d['modbus'] ? 'ein' : 'aus');
    $out[] = 'UDP ueber Datenstroeme (Kern-PHP): verfuegbar';
    $out[] = 'Rundruf (PHP-Erweiterung sockets): ' . (marstek_broadcast_moeglich() ? 'verfuegbar'
        : 'FEHLT - Unicast und MQTT laufen trotzdem, nur Rundruf und Geraetesuche fallen aus');
    // 1) UDP-Unicast mit langem Timeout
    $r = marstek_rpc('ES.GetStatus', null, $dev, 1, 5);
    if (is_array($r) && !isset($r['_error'])) {
        $out[] = 'UDP-Unicast ES.GetStatus: ANTWORT OK nach ' . (int) $GLOBALS['marstek_last_ms'] . ' ms -> lokale API funktioniert';
    } elseif (is_array($r)) {
        $out[] = 'UDP-Unicast ES.GetStatus: FEHLER: ' . $r['_error'];
    } else {
        $out[] = 'UDP-Unicast ES.GetStatus an ' . $d['ip'] . ':' . $d['port'] . ': KEINE ANTWORT (5 s Timeout)';
    }
    // 2) UDP-Rundruf (manche Firmwaren antworten nur darauf; findet auch falsche IPs)
    $bc = marstek_broadcast_zu($d['ip']);
    $frage = json_encode(array('id' => 77777, 'method' => 'Marstek.GetDevice', 'params' => array('ble_mac' => '0')));
    list($ant, $fehler) = marstek_udp_rundruf($bc, $d['port'], $frage, 5, 8);
    if ($fehler !== '') {
        $out[] = 'UDP-Rundruf an ' . $bc . ':' . $d['port'] . ': ' . $fehler;
    } elseif ($ant) {
        $out[] = 'UDP-Rundruf an ' . $bc . ':' . $d['port'] . ': ANTWORT von ' . $ant[0]['von']
               . ' -> ' . substr($ant[0]['roh'], 0, 160);
    } else {
        $out[] = 'UDP-Rundruf an ' . $bc . ':' . $d['port'] . ': keine Antwort';
    }
    // 3) Modbus TCP: Verbindung + Geraetename-Register 31000
    $errno = 0; $errstr = '';
    $fp = @fsockopen($d['ip'], 502, $errno, $errstr, 3);
    if ($fp) {
        fclose($fp);
        usleep(300000); // dem Geraet Luft lassen - es mag keine schnellen Folgeverbindungen
        $regs = marstek_modbus_read($d['ip'], 31000, 10);
        if (is_array($regs)) {
            $name = '';
            foreach ($regs as $w) {
                $name .= chr(($w >> 8) & 0xff) . chr($w & 0xff);
            }
            $name = trim($name);
            $out[] = 'Modbus TCP Port 502: Verbindung OK, Geraetename-Register 31000: "' . ($name !== '' ? $name : '(leer)') . '"';
        } else {
            $out[] = 'Modbus TCP Port 502: Verbindung OK, aber Register-Antwort fehlgeschlagen (Unit-ID? Firmware?)';
        }
    } else {
        $out[] = 'Modbus TCP Port 502: KEINE Verbindung (' . (int) $errno . ')';
    }
    return $out;
}

/* ---------------- Spot-Ranking (aWATTar) ---------------- */

/**
 * Spotpreise bei aWATTar holen und in den Zwischenspeicher legen.
 *
 * NUR AUS DEM CRON AUFRUFEN - niemals aus marstek.php.
 *
 * Bis 1.0.3 stand dieser Abruf mitten in marstek_ranks(), und die wird vom
 * Miniserver-Endpunkt ?ranks aufgerufen. Ist aWATTar langsam oder haengt
 * (Verbindung wird angenommen, aber nicht beantwortet), blockierte der
 * Endpunkt bis zum Zeitablauf - und zwar ZWEIMAL, weil die Schleife heute und
 * morgen abfragt. Nachgemessen gegen einen Server, der annimmt und schweigt:
 *
 *     marstek.php?ranks blockiert 20,0 Sekunden
 *
 * Loxone fragt den virtuellen Eingang im Minutentakt ab. Zwanzig Sekunden
 * Blockade je Abruf belegen einen HTTP-Eingangsstrang des Miniservers, und
 * davon hat er wenige. Ein Endpunkt, den eine fremde Webseite lahmlegen kann,
 * gehoert nicht an den Miniserver.
 *
 * Seither: der Cron holt, der Endpunkt liest nur noch die Datei. Faellt
 * aWATTar aus, bleibt der letzte Stand stehen und ?ranks antwortet weiter in
 * Millisekunden - mit aelteren, aber brauchbaren Zahlen.
 */
function marstek_spot_fetch() {
    $cfg = marstek_config();
    $tld = $cfg['awattar'] === 'at' ? 'at' : 'de';
    $geholt = 0;
    foreach (array(strtotime('today 00:00'), strtotime('tomorrow 00:00')) as $startTs) {
        $cache = marstek_tmpdir() . '/spot_' . date('Ymd', $startTs) . '.cache';
        if (is_file($cache) && time() - filemtime($cache) < 900) {
            continue;
        }
        $start = $startTs * 1000;
        $end = $start + 24 * 3600 * 1000;
        $url = 'https://api.awattar.' . $tld . '/v1/marketdata?start=' . $start . '&end=' . $end;
        $ctx = stream_context_create(array('http' => array(
            'timeout' => 10, 'user_agent' => 'LoxBerry Marstek')));
        $neu = @file_get_contents($url, false, $ctx);
        if ($neu !== false && strpos($neu, 'marketprice') !== false) {
            marstek_write_atomic($cache, $neu);
            $geholt++;
        }
        // Kein else: eine vorhandene aeltere Datei bleibt unangetastet
        // stehen. Sie ist besser als gar nichts.
    }
    @file_put_contents(marstek_tmpdir() . '/spot_lauf', (string) time());
    return $geholt;
}

/**
 * Rang der aktuellen Stunde - und das Ergebnis per MQTT melden.
 *
 * Wie bei den Energiezaehlern sitzt die Meldung in einer Huelle: die
 * Ermittlung hat mehrere Ruecksprungstellen, und eine davon ist der Fall
 * "keine Preise da". Auch der gehoert gemeldet, sonst steht im Broker der
 * Rang von gestern und sieht aus wie der von heute.
 */
function marstek_ranks($debug = false) {
    $r = marstek_ranks_ermitteln($debug);
    marstek_mqtt_publish_ranks($r);
    return $r;
}

/**
 * Rang der aktuellen Stunde im rollenden 24-Stunden-Fenster.
 *
 * Liest ausschliesslich den Zwischenspeicher - siehe marstek_spot_fetch().
 *
 * NEU in 1.1.0:
 *   - ERRC sagt, WARUM es keine Raenge gibt. Bis 1.0.16 kam stumm
 *     ok=0, rank=99 - jeden Abend, an dem die Preise fuer morgen nicht
 *     geholt werden konnten, und ohne einen Hinweis darauf.
 *   - MINP/MAXP/SPREAD/NEXTP/HBIS/HBISMAX fallen aus derselben Liste ab, die
 *     ohnehin gebaut und bisher weggeworfen wurde.
 *   - aufschlag_ct macht CURP zum wirklichen Arbeitspreis. Auf den RANG
 *     wirkt sich ein konstanter Summand nicht aus - auf jede
 *     Wirtschaftlichkeitsrechnung sehr wohl.
 */
function marstek_ranks_ermitteln($debug = false) {
    $cfg = marstek_config();
    $vat = (float) $cfg['vat'];
    if ($vat <= 0) { $vat = 1.0; }
    $aufschlag = (float) $cfg['aufschlag_ct'] / 100.0;   // ct/kWh netto -> EUR/kWh netto
    $leer = array('ok' => 0, 'n' => 0, 'rank' => 99, 'rankd' => 99, 'curp' => 0, 'neg' => 0,
                  'minp' => 0, 'maxp' => 0, 'spread' => 0, 'nextp' => 0,
                  'hbis' => -1, 'hbismax' => -1, 'errc' => 1, 'list' => array());
    $rows = array();
    $dateien = 0;
    foreach (array(strtotime('today 00:00'), strtotime('tomorrow 00:00')) as $startTs) {
        // NUR LESEN. Der Abruf bei aWATTar geschieht ausschliesslich im Cron -
        // siehe marstek_spot_fetch() und den Kommentar dort.
        $cache = marstek_tmpdir() . '/spot_' . date('Ymd', $startTs) . '.cache';
        if (!is_file($cache)) {
            continue;
        }
        $dateien++;
        $d = @json_decode((string) @file_get_contents($cache), true);
        if (isset($d['data']) && is_array($d['data'])) {
            $rows = array_merge($rows, $d['data']);
        }
    }
    if ($dateien === 0) {
        return $leer;                       // errc 1: noch gar keine Preise geholt
    }
    $now = time(); $hstart = $now - ($now % 3600); $list = array(); $cur = null;
    foreach ($rows as $r) {
        if (!isset($r['start_timestamp'], $r['marketprice'])) { continue; }
        $ts = (int) ($r['start_timestamp'] / 1000);
        if ($ts < $hstart || $ts >= $hstart + 24 * 3600) {
            continue;
        }
        $pr = round(($r['marketprice'] / 1000 + $aufschlag) * $vat, 5); // EUR/MWh netto -> EUR/kWh inkl. USt
        $list[$ts] = $pr;
        if ($ts == $hstart) {
            $cur = $pr;
        }
    }
    if ($cur === null) {
        $leer['errc'] = 3;                  // Preise da, aber nicht fuer die laufende Stunde
        return $leer;
    }
    if (count($list) < 6) {
        $leer['errc'] = 2;                  // Fenster zu kurz - die Preise fuer morgen fehlen
        $leer['curp'] = $cur;
        $leer['neg'] = $cur < 0 ? 1 : 0;
        return $leer;
    }
    ksort($list);
    $vals = array_values($list);
    sort($vals);
    $rank = 1;
    foreach ($vals as $v) {
        if ($v < $cur) {
            $rank++;
        }
    }
    $minp = $vals[0];
    $maxp = $vals[count($vals) - 1];
    // Stunden bis zum Tiefst- bzw. Hoechstpreis. 0 heisst: das ist jetzt.
    $hbis = -1; $hbismax = -1;
    foreach ($list as $ts => $pr) {
        if ($hbis < 0 && $pr === $minp) { $hbis = (int) round(($ts - $hstart) / 3600); }
        if ($hbismax < 0 && $pr === $maxp) { $hbismax = (int) round(($ts - $hstart) / 3600); }
    }
    $nextp = isset($list[$hstart + 3600]) ? $list[$hstart + 3600] : $cur;
    return array('ok' => 1, 'n' => count($vals), 'rank' => $rank, 'rankd' => count($vals) + 1 - $rank,
                 'curp' => $cur, 'neg' => $cur < 0 ? 1 : 0,
                 'minp' => $minp, 'maxp' => $maxp, 'spread' => round($maxp - $minp, 5),
                 'nextp' => $nextp, 'hbis' => $hbis, 'hbismax' => $hbismax, 'errc' => 0,
                 'list' => $debug ? $list : array());
}

/** Klartext zu errc - fuer den Reiter Test und die Debug-Ansicht, NICHT fuer Loxone. */
function marstek_ranks_grund($errc) {
    $t = array(
        0 => 'in Ordnung',
        1 => 'Noch keine Preise geholt - laeuft der Minutentakt?',
        2 => 'Weniger als sechs Stunden im Fenster. Die Preise fuer morgen fehlen; '
           . 'aWATTar stellt sie ueblicherweise am Nachmittag ein.',
        3 => 'Preise vorhanden, aber keiner fuer die laufende Stunde. Stimmt die Uhr des LoxBerry?',
    );
    return isset($t[(int) $errc]) ? $t[(int) $errc] : 'unbekannt';
}

/* ---------------- MQTT (LoxBerry MQTT Gateway, UDP-Relay) ---------------- */

/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt.
 */
function marstek_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

/**
 * Den UDP-Eingangsport des MQTT-Gateways aus der general.json lesen.
 * 0 = nicht eingerichtet.
 *
 * Diese Ermittlung stand bis 1.0.16 ZWEIMAL in dieser Datei - einmal mit der
 * is_array-Wache und einem sechszeiligen Kommentar, der sie begruendet, und
 * 106 Zeilen weiter ohne sie. Jetzt steht sie einmal.
 */
function marstek_mqtt_udpport()
{
    $p = marstek_paths();
    if ($p['lbhome'] === '') {
        return 0;
    }
    $gj = $p['lbhome'] . '/config/system/general.json';
    if (!is_file($gj)) {
        return 0;
    }
    $gen = json_decode((string) @file_get_contents($gj), true);
    $udpport = 0;
    // is_array vor dem verschachtelten Zugriff: waere $gen['Mqtt'] eine
    // Zeichenkette mit Inhalt, verrechnete PHP 'Udpinport' zu Position 0,
    // isset waere WAHR, und der Port ergaebe sich aus dem ersten Buchstaben.
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt']) && isset($gen['Mqtt']['Udpinport'])) {
        $udpport = (int) $gen['Mqtt']['Udpinport'];
    }
    if (!$udpport && isset($gen['mqtt']) && is_array($gen['mqtt']) && isset($gen['mqtt']['udpinport'])) {
        $udpport = (int) $gen['mqtt']['udpinport'];
    }
    return ($udpport >= 1 && $udpport <= 65535) ? $udpport : 0;
}

/**
 * Wird dieses Thema mit Retain gesendet?  NEU 06.09.2026.
 *
 * Der Hausstandard vom 03.09.2026 (Regeln/07, Abschnitt 3) teilt in drei:
 * ZUSTAENDE mit Retain, damit Loxone nach einem Neustart des Miniservers oder
 * des Gateways sofort den Stand hat; MESSWERTE MIT ZEITBEZUG ohne, damit nach
 * einem Ausfall kein alter Wert als aktuell erscheint; das LEBENSZEICHEN nie,
 * denn retained zeigt es immer "lebt".
 *
 * Die Entscheidung steht je Feld in marstek_felder() - eine Quelle, dieselbe
 * wie fuer Einheit, Zahlenformat und Kachelname. Wer ein Feld hinzufuegt, muss
 * sich entscheiden; marstek_selbsttest() weist eine Tabelle ohne 'retain' ab.
 *
 * WARUM DAS UEBERHAUPT GEHT: bis 1.1.6 schrieb das Plugin an dieser Stelle
 * immer 'publish '. Am laufenden Gateway gemessen (05.09.2026): unter
 * marstek/# lag NICHTS retained, obwohl gesendet wurde. Der UDP-Weg kann es
 * aber - sbin/mqttgateway.pl:227 fuehrt "retain my/topic data" auf, :354
 * ruft dafuer $mqtt->retain(). Mit zwei Beweisdatagrammen nachgemessen: das
 * mit 'publish' blieb fluechtig, das mit 'retain' lag im Broker.
 *
 * Zusammen mit der Aenderungssperre (marstek_mqtt_senden_bei_aenderung) war
 * das eine Luecke: was sich selten aendert, geht selten hinaus - und war nach
 * einem Neustart beliebig lange gar nicht da. Im Mitschnitt ueber 150 s ging
 * rang_* kein einziges Mal hinaus.
 *
 * $schluessel ist das Thema OHNE Praefix, also 'soc', 'energie_chgt',
 * 'rang_curp', 'ts', 'takt_zaehler'.
 */
function marstek_mqtt_retain($schluessel)
{
    // Das Lebenszeichen und die Zeitstempel: nie. Sie stehen in keiner
    // Feldtabelle, deshalb hier - und deshalb zuerst.
    if ($schluessel === 'ts' || strpos($schluessel, 'takt_') === 0) {
        return false;
    }
    if (strpos($schluessel, 'energie_') === 0) {
        $satz = 'energy'; $feld = substr($schluessel, 8);
    } elseif (strpos($schluessel, 'rang_') === 0) {
        $satz = 'ranks';  $feld = substr($schluessel, 5);
    } else {
        $satz = 'status'; $feld = $schluessel;
    }
    $felder = marstek_felder($satz);
    $feld = strtoupper($feld);
    // Unbekanntes Thema: nicht retained. Ein fluechtiger Wert ist der
    // harmlosere Fehler - ein retained Wert bliebe fuer immer stehen.
    if (!isset($felder[$feld]) || !isset($felder[$feld]['retain'])) {
        return false;
    }
    return !empty($felder[$feld]['retain']);
}

/**
 * Themen unter einem Praefix senden - gemeinsamer Unterbau aller
 * Veroeffentlichungen (Status, Energiezaehler, Spotpreis-Raenge, Takt).
 *
 * Rueckgabe: true, wenn gesendet wurde.
 */
function marstek_mqtt_senden(array $werte, $prefix)
{
    $udpport = marstek_mqtt_udpport();
    if (!$udpport) {
        return false; // MQTT-Gateway nicht konfiguriert
    }
    $nr = 0; $txt = '';
    $s = @stream_socket_client('udp://127.0.0.1:' . $udpport, $nr, $txt, 2);
    if ($s === false) {
        return false;
    }
    foreach ($werte as $k => $v) {
        @fwrite($s, (marstek_mqtt_retain($k) ? 'retain ' : 'publish ')
                  . $prefix . '/' . $k . ' ' . marstek_mqtt_wert_saeubern($v));
    }
    fclose($s);
    return true;
}

/**
 * Nur senden, wenn sich etwas geaendert hat - mindestens aber halbstuendlich
 * als Lebenszeichen. Genau das Verhalten der uebrigen Linien des Hauses.
 *
 * Warum nicht wie beim Status jedes Mal: die Energiezaehler und die
 * Spotpreis-Raenge aendern sich im Stundentakt, der Cron laeuft aber jede
 * Minute. Ohne diese Bremse stuenden Themen je Minute im Broker, die
 * sechzigmal hintereinander denselben Wert tragen.
 */
function marstek_mqtt_senden_bei_aenderung(array $werte, $prefix, $merkername, ?array $sigwerte = null)
{
    // BERICHTIGT 04.09.2026: die Signatur darf keinen Wert enthalten, der
    // sich von selbst aendert. Bis 1.1.4 ging das Feld ALTER (= jetzt minus
    // Messzeitpunkt) mit ein, und der Filter war damit wirkungslos.
    // Gemessen am UDP-Tor, zwei Durchgaenge ohne jede Aenderung am
    // Zaehlerstand:  Durchgang 1: 38 Datagramme, Durchgang 2: 12 - davon
    // zehn energie_*. Nach dem Rueckbau: Durchgang 2: 2.
    $sig = json_encode($sigwerte === null ? $werte : $sigwerte);
    if ($sig === false) { $sig = 'unlesbar'; }
    $sigf = marstek_tmpdir() . '/mqtt_sig_' . $merkername . '.txt';
    $beat = marstek_tmpdir() . '/mqtt_beat_' . $merkername;
    $alt = is_file($sigf) ? (string) @file_get_contents($sigf) : '';
    if ($sig === $alt && is_file($beat) && time() - filemtime($beat) <= 1800) {
        return false;
    }
    if (!marstek_mqtt_senden($werte, $prefix)) {
        return false;
    }
    @file_put_contents($sigf, $sig);
    @touch($beat);
    return true;
}

/**
 * Ein Themen-Praefix unschaedlich machen. EINE Stelle fuer alle Wege.
 *
 * BERICHTIGT 04.09.2026. Bis 1.1.4 saeuberte nur das Formular
 * (index.php), und zwar STILL. Der Weg ueber eine zurueckgespielte
 * Sicherung ging daran vorbei; gemessen am UDP-Tor des Gateways:
 *
 *     publish marstek<LF>publish fremd/eingeschleust 1/rang_ok 0
 *
 * Der Kommentar ueber marstek_mqtt_wert_saeubern() sagt es selbst: das
 * Gateway liest ZEILENWEISE. Gesaeubert wurde der Wert, nicht das Thema -
 * und beide stehen in derselben Zeile desselben Datagramms.
 */
function marstek_mqtt_thema_saeubern($t)
{
    $t = preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $t);
    $t = trim((string) $t, '/');
    return $t !== '' ? substr($t, 0, 64) : 'marstek';
}

/** Praefix aus der Konfiguration, bei Geraet 2..9 mit angehaengter Nummer. */
function marstek_mqtt_prefix($dev = 1)
{
    $cfg = marstek_config();
    $prefix = marstek_mqtt_thema_saeubern($cfg['mqtt_topic']);
    if ((int) $dev > 1) { // Geraet 1 behaelt die kurzen Topics (Abwaertskompatibilitaet)
        $prefix .= '/' . (int) $dev;
    }
    return $prefix;
}

/**
 * Die Themenliste - EINE Quelle fuer die Tabelle im Reiter MQTT, fuer die
 * Selbstpruefung und fuer die Deinstallation.
 *
 * Bis 1.0.16 stand die Anleitung von Hand in der Oberflaeche und nannte
 * sieben von zweiundzwanzig Themen: die neun energie_* und die sechs rang_*
 * aus 1.0.14 fehlten. Wer auf MQTT umstellte, legte sieben Datenpunkte an und
 * hatte danach keine Energiebilanz und keinen Preisrang - beides lag im
 * Broker, er wusste nur nicht, dass es das gibt.
 *
 * Angeglichen wird die Anleitung an den Sendecode, nie umgekehrt: ein
 * Umbenennen im Sendecode braeche jede bestehende Anlage. Und damit die
 * beiden nicht wieder auseinanderlaufen, misst der Reiter Test sie
 * gegeneinander.
 *
 * $mit_geraet = false laesst die Themen weg, die es je Geraet gibt.
 */
function marstek_mqtt_themen($mit_geraet = true)
{
    $t = array();
    if ($mit_geraet) {
        foreach (marstek_felder('status') as $name => $f) {
            $t[strtolower($name)] = $f['text'];
        }
        $t['ts'] = 'Zeitstempel dieser Veroeffentlichung (Unixzeit)';
        foreach (marstek_felder('energy') as $name => $f) {
            $t['energie_' . strtolower($name)] = $f['text'];
        }
    }
    foreach (marstek_felder('ranks') as $name => $f) {
        $t['rang_' . strtolower($name)] = $f['text'];
    }
    $t['takt_zaehler'] = 'Herzschlag des Minutentakts (0..999, umlaufend)';
    $t['takt_ts'] = 'Zeitpunkt des letzten Minutentakts (Unixzeit)';
    return $t;
}

/**
 * Die kWh-Zaehler per MQTT (seit 1.0.14).
 *
 * Ueber HTTP gab es sie unter ?energy seit jeher, ueber MQTT gar nicht. Wer
 * auf MQTT umstellte - der Hausstandard -, verlor damit die gesamte
 * Energiebilanz des Speichers: geladen und abgegeben, gesamt, Tag und Monat,
 * Zyklen und Wirkungsgrad.
 */
function marstek_mqtt_publish_energy(array $e, $dev = 1) {
    $cfg = marstek_config();
    if (empty($cfg['mqtt_enabled'])) {
        return;
    }
    $werte = array();
    $sig = array();
    foreach (marstek_felder('energy') as $name => $f) {
        $w = marstek_feldwert($f, $e, $dev);
        $werte['energie_' . strtolower($name)] = $w;
        // Abgeleitete Zeitfelder gehen in die WERTE, nicht in die Signatur.
        if (strpos($f['quelle'], '_alter') === false) {
            $sig['energie_' . strtolower($name)] = $w;
        }
    }
    marstek_mqtt_senden_bei_aenderung($werte, marstek_mqtt_prefix($dev),
                                      'energie_dev' . (int) $dev, $sig);
}

/**
 * Die Spotpreis-Raenge per MQTT (seit 1.0.14).
 *
 * Sie haengen am Strompreis, nicht am Geraet, und stehen deshalb ohne
 * Geraetenummer unter dem Grundpraefix - auch bei mehreren Speichern gibt es
 * sie nur einmal.
 */
function marstek_mqtt_publish_ranks(array $r) {
    $cfg = marstek_config();
    if (empty($cfg['mqtt_enabled'])) {
        return;
    }
    $werte = array();
    $sig = array();
    foreach (marstek_felder('ranks') as $name => $f) {
        $w = marstek_feldwert($f, $r, 1);
        $werte['rang_' . strtolower($name)] = $w;
        if (strpos($f['quelle'], '_alter') === false) {
            $sig['rang_' . strtolower($name)] = $w;
        }
    }
    marstek_mqtt_senden_bei_aenderung($werte, marstek_mqtt_prefix(1), 'raenge', $sig);
}

/**
 * Der Status per MQTT - bei JEDEM Durchgang, nicht nur bei Aenderung.
 *
 * Das ist Absicht und der Unterschied zu den Zaehlern oben: wer nur bei
 * Aenderungen sendet, hoert bei einer Stoerung einfach auf, und die zuletzt
 * gesendeten Werte bleiben im Broker stehen. Der Zeitstempel ts macht
 * sichtbar, wie alt das ist, was dort steht.
 */
function marstek_mqtt_publish(array $st, $dev = 1) {
    $cfg = marstek_config();
    if (empty($cfg['mqtt_enabled'])) {
        return;
    }
    $werte = array();
    foreach (marstek_felder('status') as $name => $f) {
        $werte[strtolower($name)] = marstek_feldwert($f, $st, $dev);
    }
    $werte['ts'] = isset($st['ts']) ? (int) $st['ts'] : time();
    marstek_mqtt_senden($werte, marstek_mqtt_prefix($dev));
}

/** Den Takt melden - jede Minute, damit ein toter Cron auffaellt. */
function marstek_mqtt_publish_takt($zaehler) {
    $cfg = marstek_config();
    if (empty($cfg['mqtt_enabled'])) {
        return;
    }
    marstek_mqtt_senden(array('takt_zaehler' => (int) $zaehler, 'takt_ts' => time()),
                        marstek_mqtt_prefix(1));
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function marstek_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function marstek_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . marstek_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

/**
 * Maskieren. Beschriftungen laufen IMMER hier durch - deshalb darf in den
 * kurzen Sprachwerten kein Markup und keine Entitaet stehen.
 *
 * Bis 1.0.16 war das anders herum: 89 von 390 Werten waren Satzfragmente,
 * Adressen oder trugen Markup, darunter AUS = ">aus" - das schliessende
 * Groesserzeichen eines Tags kam aus der Sprachdatei. Ein fehlender
 * Schluessel haette dort kein sichtbares TEXT.AUS erzeugt, sondern den
 * Eintrag aus der Auswahlliste verschwinden lassen, ohne dass irgendwo ein
 * Fehler steht.
 */
function marstek_e($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}


/* ---------------- Die Feldtabelle: EINE Quelle ----------------
 *
 * Bis 1.0.16 stand jedes Feld an vier Stellen: in dieser Tabelle (fuer die
 * Loxone-Vorlage), in vierzig einzelnen Sprachschluesseln (fuer die Tabelle
 * im Reiter Loxone), im Kopfkommentar von marstek.php und in dessen
 * printf()-Zeile. Aus genau diesem Auseinanderlaufen sind drei Fehler
 * entstanden - drei Fehler, eine Ursache:
 *
 *   MS    war in der Vorlage "Betriebsmodus des Geraets", Bereich 0..10,
 *         und ist in Wahrheit die Antwortzeit in Millisekunden.
 *   RANKD hiess in der Vorlage "Rang bezogen auf den Tag" und in der
 *         Anleitung "Rang absteigend (1 = teuerste Stunde)".
 *   GRIDP fehlte in der Anleitung ganz, obwohl der Endpunkt es sendet.
 *
 * Seit 1.1.0 entstehen Vorlage, Anleitung, Themenliste und die Antwortzeile
 * des Endpunkts aus DIESER Tabelle. Ein neues Feld ist eine Zeile.
 *
 * Je Feld:
 *   quelle  Schluessel im Ergebnis-Array; Praefix _ = abgeleitet
 *   analog  1 = Analogwert, 0 = Digitalwert
 *   min/max Grenzen fuer Loxone (Reglergrenzen und Plausibilitaetspruefung)
 *   einheit Einheit fuer die Anzeige, '' = keine
 *   form    printf-Format der Antwortzeile
 *   text    Bedeutung - erscheint als Kommentar in Loxone Config und in der
 *           Themenliste
 *
 * REIHENFOLGE: neue Messgroessen werden HINTEN angehaengt. Eine Umbenennung
 * bricht bestehende Anlagen, ein Anhaengen nicht.
 */
function marstek_felder($satz) {
    if ($satz === 'energy') {
        return array(
            'OK'    => array('quelle' => 'ok',   'analog' => 0, 'min' => 0, 'max' => 1,       'einheit' => '',    'form' => '%d',   'retain' => 1, 'kurz' => 'Zähler gültig', 'text' => '1 = Werte gültig'),
            'CHGT'  => array('quelle' => 'chgt', 'analog' => 1, 'min' => 0, 'max' => 1000000, 'einheit' => 'kWh', 'form' => '%.2f', 'retain' => 1, 'kurz' => 'geladen gesamt', 'text' => 'geladen gesamt'),
            'DIST'  => array('quelle' => 'dist', 'analog' => 1, 'min' => 0, 'max' => 1000000, 'einheit' => 'kWh', 'form' => '%.2f', 'retain' => 1, 'kurz' => 'abgegeben gesamt', 'text' => 'abgegeben gesamt'),
            'CHGD'  => array('quelle' => 'chgd', 'analog' => 1, 'min' => 0, 'max' => 1000,    'einheit' => 'kWh', 'form' => '%.2f', 'retain' => 1, 'kurz' => 'geladen heute', 'text' => 'geladen heute'),
            'DISD'  => array('quelle' => 'disd', 'analog' => 1, 'min' => 0, 'max' => 1000,    'einheit' => 'kWh', 'form' => '%.2f', 'retain' => 1, 'kurz' => 'abgegeben heute', 'text' => 'abgegeben heute'),
            'CHGM'  => array('quelle' => 'chgm', 'analog' => 1, 'min' => 0, 'max' => 100000,  'einheit' => 'kWh', 'form' => '%.2f', 'retain' => 1, 'kurz' => 'geladen diesen Monat', 'text' => 'geladen diesen Monat'),
            'DISM'  => array('quelle' => 'dism', 'analog' => 1, 'min' => 0, 'max' => 100000,  'einheit' => 'kWh', 'form' => '%.2f', 'retain' => 1, 'kurz' => 'abgegeben diesen Monat', 'text' => 'abgegeben diesen Monat'),
            'CYC'   => array('quelle' => 'cyc',  'analog' => 1, 'min' => 0, 'max' => 100000,  'einheit' => '',    'form' => '%d',   'retain' => 1, 'kurz' => 'Vollzyklen', 'text' => 'Vollzyklen'),
            'EFF'   => array('quelle' => 'eff',  'analog' => 1, 'min' => -1, 'max' => 100,     'einheit' => '%',   'form' => '%.1f', 'retain' => 1, 'kurz' => 'Wirkungsgrad', 'text' => 'Wirkungsgrad gesamt (abgegeben je geladener kWh); -1 = noch zu wenige Zyklen'),
            'ALTER' => array('quelle' => '_alter', 'analog' => 1, 'min' => -1, 'max' => 86400, 'einheit' => 's',  'form' => '%d',   'retain' => 0, 'kurz' => 'Alter der Zählerstände', 'text' => 'Alter der Zählerstände in Sekunden; -1 = noch nie gemessen'),
        );
    }
    if ($satz === 'ranks') {
        return array(
            'OK'      => array('quelle' => 'ok',      'analog' => 0, 'min' => 0,  'max' => 1,  'einheit' => '',        'form' => '%d',   'retain' => 1, 'kurz' => 'Preisdaten gültig', 'text' => '1 = Preisdaten gültig'),
            'N'       => array('quelle' => 'n',       'analog' => 1, 'min' => 0,  'max' => 48, 'einheit' => '',        'form' => '%d',   'retain' => 1, 'kurz' => 'bewertete Stunden', 'text' => 'Anzahl bewerteter Stunden im Fenster'),
            'RANK'    => array('quelle' => 'rank',    'analog' => 1, 'min' => 0,  'max' => 99, 'einheit' => '',        'form' => '%d',   'retain' => 0, 'kurz' => 'Rang günstig', 'text' => 'Rang der laufenden Stunde im 24-Stunden-Fenster (1 = günstigste); 99 = keine Daten'),
            'RANKD'   => array('quelle' => 'rankd',   'analog' => 1, 'min' => 0,  'max' => 99, 'einheit' => '',        'form' => '%d',   'retain' => 0, 'kurz' => 'Rang teuer', 'text' => 'derselbe Rang absteigend (1 = teuerste Stunde); 99 = keine Daten'),
            'CURP'    => array('quelle' => 'curp',    'analog' => 1, 'min' => -1, 'max' => 10, 'einheit' => 'EUR/kWh', 'form' => '%.5f', 'retain' => 0, 'kurz' => 'Preis dieser Stunde', 'text' => 'Preis der laufenden Stunde inkl. Aufschlag und USt'),
            'NEG'     => array('quelle' => 'neg',     'analog' => 0, 'min' => 0,  'max' => 1,  'einheit' => '',        'form' => '%d',   'retain' => 0, 'kurz' => 'Preis negativ', 'text' => '1 = der Preis der laufenden Stunde ist negativ'),
            'MINP'    => array('quelle' => 'minp',    'analog' => 1, 'min' => -1, 'max' => 10, 'einheit' => 'EUR/kWh', 'form' => '%.5f', 'retain' => 0, 'kurz' => 'günstigste Stunde', 'text' => 'günstigste Stunde im Fenster'),
            'MAXP'    => array('quelle' => 'maxp',    'analog' => 1, 'min' => -1, 'max' => 10, 'einheit' => 'EUR/kWh', 'form' => '%.5f', 'retain' => 0, 'kurz' => 'teuerste Stunde', 'text' => 'teuerste Stunde im Fenster'),
            'SPREAD'  => array('quelle' => 'spread',  'analog' => 1, 'min' => 0,  'max' => 10, 'einheit' => 'EUR/kWh', 'form' => '%.5f', 'retain' => 0, 'kurz' => 'Preisspanne', 'text' => 'Abstand teuerste zu günstigster Stunde - lohnt sich der Umschlag heute?'),
            'NEXTP'   => array('quelle' => 'nextp',   'analog' => 1, 'min' => -1, 'max' => 10, 'einheit' => 'EUR/kWh', 'form' => '%.5f', 'retain' => 0, 'kurz' => 'Preis nächste Stunde', 'text' => 'Preis der nächsten Stunde'),
            'HBIS'    => array('quelle' => 'hbis',    'analog' => 1, 'min' => -1, 'max' => 24, 'einheit' => 'h',       'form' => '%d',   'retain' => 0, 'kurz' => 'Stunden bis günstigste', 'text' => 'Stunden bis zur günstigsten Stunde (0 = jetzt); -1 = unbekannt'),
            'HBISMAX' => array('quelle' => 'hbismax', 'analog' => 1, 'min' => -1, 'max' => 24, 'einheit' => 'h',       'form' => '%d',   'retain' => 0, 'kurz' => 'Stunden bis teuerste', 'text' => 'Stunden bis zur teuersten Stunde (0 = jetzt); -1 = unbekannt'),
            'ERRC'    => array('quelle' => 'errc',    'analog' => 1, 'min' => 0,  'max' => 9,  'einheit' => '',        'form' => '%d',   'retain' => 1, 'kurz' => 'Grund für OK=0', 'text' => 'Grund für OK=0: 0 in Ordnung, 1 keine Preise geholt, 2 Fenster zu kurz, 3 keine Preise für die laufende Stunde'),
        );
    }
    if ($satz === 'summe') {
        return array(
            'OK'      => array('quelle' => 'ok',      'analog' => 0, 'min' => 0,      'max' => 1,     'einheit' => '',    'form' => '%d',   'retain' => 1, 'kurz' => 'alle Speicher erreichbar', 'text' => '1 = ALLE Speicher haben geantwortet'),
            'N'       => array('quelle' => 'n',       'analog' => 1, 'min' => 0,      'max' => 9,     'einheit' => '',    'form' => '%d',   'retain' => 1, 'kurz' => 'Anzahl Speicher', 'text' => 'Anzahl eingetragener Speicher'),
            'NOK'     => array('quelle' => 'nok',     'analog' => 1, 'min' => 0,      'max' => 9,     'einheit' => '',    'form' => '%d',   'retain' => 1, 'kurz' => 'Speicher ohne Antwort', 'text' => 'Anzahl Speicher ohne Antwort'),
            'SOC'     => array('quelle' => 'soc',     'analog' => 1, 'min' => -1,     'max' => 100,   'einheit' => '%',   'form' => '%.1f', 'retain' => 1, 'kurz' => 'Ladezustand gewichtet', 'text' => 'nach Kapazität gewichteter Ladezustand; -1 = nicht bildbar'),
            'KAPAZ'   => array('quelle' => 'kapaz',   'analog' => 1, 'min' => -1,     'max' => 1000,  'einheit' => 'kWh', 'form' => '%.2f', 'retain' => 1, 'kurz' => 'Gesamtkapazität', 'text' => 'Gesamtkapazität; -1 = bei mindestens einem Speicher nicht eingetragen'),
            'RESTKWH' => array('quelle' => 'restkwh', 'analog' => 1, 'min' => -1,     'max' => 1000,  'einheit' => 'kWh', 'form' => '%.2f', 'retain' => 1, 'kurz' => 'gespeicherte Menge', 'text' => 'noch gespeicherte Menge; -1 = nicht bildbar'),
            'BATP'    => array('quelle' => 'batp',    'analog' => 1, 'min' => -40000, 'max' => 40000, 'einheit' => 'W',   'form' => '%d',   'retain' => 0, 'kurz' => 'Batterieleistung gesamt', 'text' => 'Summe der Batterieleistungen (+ lädt / - entlädt)'),
            'ALTER'   => array('quelle' => 'alter',   'analog' => 1, 'min' => -1,     'max' => 86400, 'einheit' => 's',   'form' => '%d',   'retain' => 0, 'kurz' => 'Alter der Teilmessungen', 'text' => 'Alter der ältesten Teilmessung; -1 = nicht bildbar'),
        );
    }
    return array(
        'OK'        => array('quelle' => 'ok',    'analog' => 0, 'min' => 0,      'max' => 1,      'einheit' => '',   'form' => '%d',   'retain' => 1, 'kurz' => 'Speicher erreichbar', 'text' => '1 = Speicher erreichbar'),
        'SOC'       => array('quelle' => 'soc',   'analog' => 1, 'min' => 0,      'max' => 100,    'einheit' => '%',  'form' => '%.1f', 'retain' => 1, 'kurz' => 'Ladezustand', 'text' => 'Ladezustand'),
        'BATP'      => array('quelle' => 'batp',  'analog' => 1, 'min' => -10000, 'max' => 10000,  'einheit' => 'W',  'form' => '%d',   'retain' => 0, 'kurz' => 'Batterieleistung', 'text' => 'Batterieleistung (+ lädt / - entlädt)'),
        'TEMP'      => array('quelle' => 'temp',  'analog' => 1, 'min' => -20,    'max' => 80,     'einheit' => '°C', 'form' => '%.1f', 'retain' => 0, 'kurz' => 'Batterietemperatur', 'text' => 'Batterietemperatur'),
        'GRIDP'     => array('quelle' => 'gridp', 'analog' => 1, 'min' => -20000, 'max' => 20000,  'einheit' => 'W',  'form' => '%d',   'retain' => 0, 'kurz' => 'Netzleistung', 'text' => 'Netzleistung am Speicher'),
        'FW'        => array('quelle' => 'fw',    'analog' => 1, 'min' => 0,      'max' => 100000, 'einheit' => '',   'form' => '%d',   'retain' => 1, 'kurz' => 'Firmwarestand', 'text' => 'Firmwarestand des Geräts'),
        // BERICHTIGT 24.08.2026: hiess "Betriebsmodus des Geraets" mit Bereich
        // 0..10 und ist in Wahrheit die Antwortzeit. Die eigene Ausfuhr aus
        // Loxone Config fuehrt dasselbe Feld mit 0..10000 und "ms".
        'MS'        => array('quelle' => 'ms',    'analog' => 1, 'min' => 0,      'max' => 10000,  'einheit' => 'ms', 'form' => '%d',   'retain' => 0, 'kurz' => 'Antwortzeit', 'text' => 'Antwortzeit des Geräts'),
        'ALTER'     => array('quelle' => '_alter',     'analog' => 1, 'min' => -1,     'max' => 86400, 'einheit' => 's', 'form' => '%d', 'retain' => 0, 'kurz' => 'Alter der Messung', 'text' => 'Alter der letzten echten Messung in Sekunden; -1 = noch nie gemessen'),
        'ZAEHLER'   => array('quelle' => '_zaehler',   'analog' => 1, 'min' => -1,     'max' => 999,   'einheit' => '',  'form' => '%d', 'retain' => 0, 'kurz' => 'Herzschlag des Takts', 'text' => 'Herzschlag des Minutentakts, zählt 0..999 um; -1 = der Takt läuft nicht'),
        'SOLL'      => array('quelle' => '_soll',      'analog' => 1, 'min' => -32768, 'max' => 10000, 'einheit' => 'W', 'form' => '%d', 'retain' => 1, 'kurz' => 'angenommener Sollwert', 'text' => 'zuletzt vom Gerät ANGENOMMENER Sollwert (+ laden); -32768 = keiner'),
        'SOLLALTER' => array('quelle' => '_sollalter', 'analog' => 1, 'min' => -1,     'max' => 86400, 'einheit' => 's', 'form' => '%d', 'retain' => 0, 'kurz' => 'Alter des Sollwerts', 'text' => 'Sekunden seit dem letzten angenommenen Sollwert; -1 = keiner'),
        'FBREST'    => array('quelle' => '_fbrest',    'analog' => 1, 'min' => -2,     'max' => 86400, 'einheit' => 's', 'form' => '%d', 'retain' => 0, 'kurz' => 'Rest bis Auto-Fallback', 'text' => 'Sekunden bis zum Auto-Fallback; -1 = abgeschaltet, -2 = kein Passivbetrieb'),
    );
}

/**
 * Wert eines Feldes aus dem Ergebnis-Array holen.
 *
 * Die abgeleiteten Felder (Praefix _) werden hier gerechnet - an genau einer
 * Stelle, damit Endpunkt und MQTT nie verschiedene Zahlen tragen.
 */
function marstek_feldwert(array $f, array $werte, $dev = 1) {
    $q = $f['quelle'];
    if ($q === '_alter') {
        $m = (int) (isset($werte['mess']) ? $werte['mess'] : 0);
        return $m > 0 ? time() - $m : -1;
    }
    if ($q === '_zaehler') {
        $h = marstek_herzstand();
        // Laeuft der Takt nicht mehr, ist die Zahl wertlos - dann -1.
        return ($h['ts'] > 0 && time() - $h['ts'] <= MARSTEK_TAKT_SCHRANKE) ? $h['zaehler'] : -1;
    }
    if ($q === '_soll') {
        $s = marstek_soll_lesen($dev);
        return $s['p'] === null ? -32768 : (int) $s['p'];
    }
    if ($q === '_sollalter') {
        $s = marstek_soll_lesen($dev);
        return $s['ts'] > 0 ? time() - $s['ts'] : -1;
    }
    if ($q === '_fbrest') {
        return marstek_fallback_rest($dev);
    }
    return isset($werte[$q]) ? $werte[$q] : 0;
}

/**
 * Die Antwortzeile fuer Loxone aus der Feldtabelle bauen.
 *
 * Kopf und Felder kommen aus derselben Quelle wie die Vorlage - eine Zeile,
 * die der Vorlage widerspricht, kann so gar nicht mehr entstehen.
 */
function marstek_zeile($satz, array $werte, $dev = 1) {
    $kopf = array('status' => 'MARSTEK', 'energy' => 'ENERGY', 'ranks' => 'RANKS', 'summe' => 'SUMME');
    $out = isset($kopf[$satz]) ? $kopf[$satz] : strtoupper($satz);
    foreach (marstek_felder($satz) as $name => $f) {
        $out .= ';' . $name . '=' . sprintf($f['form'], marstek_feldwert($f, $werte, $dev));
    }
    return $out . "\n";
}

/* ---------------- Loxone-Vorlage ---------------- */

/** Gepruefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 *  CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 *  Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht. */
function marstek_xml_virtual_in_http($kopf, $cmds) {
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" ';
    $o .= 'Title="' . marstek_x($kopf['title']) . '" ';
    $o .= 'Comment="' . marstek_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . marstek_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . marstek_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf; // wie Original-Export aus Loxone Config 17.1
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . marstek_x($c['title']) . '" ';
        $o .= 'Comment="' . marstek_x($c['comment']) . '" ';
        $o .= 'Check="' . marstek_x($c['check']) . '" ';
        $o .= 'Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . ($c['analog'] ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '" ';
        $o .= 'Unit="' . marstek_x(isset($c['unit']) ? $c['unit'] : '<v>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/** <v.n> zum Zahlenformat des Feldes. */
function marstek_einheit_muster(array $f) {
    $nk = 0;
    if (preg_match('/%\.(\d)f/', (string) $f['form'], $m)) { $nk = (int) $m[1]; }
    return '<v.' . $nk . '>';
}

function marstek_x($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Hausstandard: Zustand des MQTT-Gateways aus general.json.
 * Rueckgabe: array(autostart, fassung) oder null.
 *
 * fassung 0 heisst NICHT LESBAR und ist bewusst nicht auf 1 vorbelegt:
 * "unbekannt" und "Fassung 1" sind verschiedene Aussagen, und die Oberflaeche
 * behandelt sie verschieden - unter V2 gibt es die Abo-Eingabe gar nicht mehr.
 */
function marstek_mqtt_gateway_info() {
    $p = marstek_paths();
    $home = isset($p['lbhome']) && $p['lbhome'] !== '' ? $p['lbhome'] : (getenv('LBHOMEDIR') ?: '');
    if ($home === '') { return null; }
    $gj = $home . '/config/system/general.json';
    if (!is_file($gj)) { return null; }
    $d = json_decode((string) @file_get_contents($gj), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) { return null; }
    $auto = isset($d['Mqtt']['Gatewayautostart']) ? $d['Mqtt']['Gatewayautostart'] : '';
    return array(
        'autostart' => in_array((string) $auto, array('1', 'true'), true),
        'fassung'   => isset($d['Mqtt']['Gatewayversion']) ? (int) $d['Mqtt']['Gatewayversion'] : 0,
    );
}


/** Der Rechnername fuer die Adressen in den Vorlagen. */
function marstek_host() {
    return isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
}

/**
 * Der Abfragetakt je Satz - EINE Quelle.
 *
 * BERICHTIGT 04.09.2026: bis 1.1.4 stand er zweimal, in index.php als
 * 'takt' und hier als Bedingung "status ? 60 : 300". Fuer ?summe liefen
 * beide auseinander - die Oberflaeche nannte 60 s, die Datei daneben trug
 * PollingTime="300". Der Status und die Summe haengen am Geraet und werden
 * minuetlich gebraucht, Raenge und Zaehlerstaende aendern sich stuendlich.
 */
function marstek_satz_takt($satz) {
    $t = array('status' => 60, 'summe' => 60, 'ranks' => 300, 'energy' => 300);
    return isset($t[$satz]) ? $t[$satz] : 300;
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function marstek_vorlage($satz = 'status', $dev = 1) {
    $satz = in_array($satz, array('status', 'energy', 'ranks', 'summe'), true) ? $satz : 'status';
    $host = marstek_host();
    $ordner = getenv('LBPPLUGINDIR') ?: 'marstekvenus';
    $dev = max(1, min(9, (int) $dev));
    $d = marstek_dev($dev);
    $je_geraet = ($satz !== 'ranks' && $satz !== 'summe');
    // Der Titel traegt den Geraetenamen, nicht einen Platzhalter - wer zwei
    // Speicher hat, findet sie in Loxone Config sonst nicht auseinander.
    $gname = '';
    if ($je_geraet) {
        $gname = $d !== null ? ' ' . preg_replace('/[^A-Za-z0-9 _\-]/', '', $d['name']) : ' ' . $dev;
    }
    $cmds = array();
    foreach (marstek_felder($satz) as $name => $f) {
        $cmds[] = array(
            // Der Title wird in Loxone Config zur BEZEICHNUNG.
            //
            // BERICHTIGT 06.09.2026. Bis 1.1.6 stand hier der technische
            // Schluessel - MARSTEK_RANKS_HBIS, MARSTEK_STATUS_MS -, und der
            // Anwender musste 43 Eingaenge von Hand umbenennen: genau die
            // Arbeit, die die Vorlage ihm abnehmen soll. Es gab dafuer auch
            // keinen Grund. Regeln/07 sagt es ausdruecklich:
            // "Virtueller HTTP-Eingang: Zuordnung im Suchtext, Titel frei".
            // Die Zuordnung leistet die
            // Befehlserkennung ('check' weiter unten), der Titel ist davon
            // unabhaengig. Der Zwang, den technischen Namen zu fuehren, gilt
            // ausdruecklich nur fuer GATEWAY-Eingaenge; dort benennt das
            // Gateway nach dem Thema, und wer den Titel verschoenert, bekommt
            // beim naechsten Empfang einen zweiten Eingang.
            //
            // 'kurz' ist ueber alle vier Saetze eindeutig (43 Felder, 43
            // verschiedene Werte, laengster 28 Zeichen - nachgemessen). Das
            // Kuerzel "Marstek" davor haelt die Bezeichnung im ganzen
            // Loxone-Projekt auseinander, so wie es die von Hand gepflegten
            // Eingaenge dieser Anlage seit jeher mit "Speicher " tun.
            'title' => 'Marstek ' . $f['kurz'] . ($je_geraet && $dev > 1 ? $gname : ''),
            // Der Comment wird in Loxone Config zum ANZEIGENAMEN der Kachel,
            // nicht zur Dokumentation. BERICHTIGT 04.09.2026: bis 1.1.4 stand
            // hier der ganze Erklaertext - gemessen bis 112 Zeichen, und die
            // massgebliche eigene Ausfuhr vom 12.08.2026 fuehrt ihn leer.
            // Jetzt die Kurzform; der lange Text bleibt in der Oberflaeche.
            'comment' => $f['kurz'],
            // Das Trennzeichen gehoert in den Suchtext. Ohne es haengt allein
            // an der Reihenfolge der Zeile, dass der richtige Wert ankommt -
            // eine Zusicherung, die beim naechsten neuen Feld still faellt.
            'check' => '\i;' . $name . '=\i\v',
            // Nachkommastellen aus dem Format der Antwortzeile: ein Preis
            // mit %.5f wurde bis 1.1.4 als <v.1> angezeigt, also 0,3 statt
            // 0,28374 - und OK als "1,0".
            'unit' => marstek_einheit_muster($f) . ($f['einheit'] !== '' ? ' ' . $f['einheit'] : ''),
            'analog' => $f['analog'], 'min' => $f['min'], 'max' => $f['max'],
        );
    }
    $q = '?' . $satz . ($je_geraet && $dev > 1 ? '&dev=' . $dev : '');
    $endung = ($je_geraet && $dev > 1) ? '_' . $dev : '';
    return array('VI_marstek_' . $satz . $endung . '.xml', marstek_xml_virtual_in_http(array(
        'title' => 'Marstek ' . ucfirst($satz) . $gname,
        'address' => 'http://' . $host . '/plugins/' . $ordner . '/marstek.php' . $q,
        'polling' => (string) marstek_satz_takt($satz),
        'comment' => 'Erzeugt vom LoxBerry-Plugin Marstek Venus E (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
}

/**
 * Vorlage der Steuerbefehle (Virtueller Ausgang) - Format wie Original-Export
 * aus Loxone Config 17.1.
 *
 * BERICHTIGT 24.08.2026: der ANALOGE Befehl traegt jetzt die vier
 * Skalierungsattribute SourceValLow, DestValLow, SourceValHigh, DestValHigh
 * zwischen RepeatRate und HintText. Der digitale traegt sie nicht - sie sind
 * nicht dasselbe Element mit einem anderen Haken. Gemessen an einer echten
 * Ausfuhr; dort standen 0, 0, 10, 10.
 *
 * Und es gibt sie jetzt je Geraet: bis 1.0.16 kannte diese Funktion gar
 * keinen Geraeteparameter, fuer Speicher 2 bis 4 fehlte der Sollwert-Befehl.
 */
function marstek_vo_vorlage($dev = 1) {
    $host = marstek_host();
    $ordner = getenv('LBPPLUGINDIR') ?: 'marstekvenus';
    $cfg = marstek_config();
    $dev = max(1, min(9, (int) $dev));
    $d = marstek_dev($dev);
    $tok = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    $q = $dev > 1 ? '&dev=' . $dev : '';
    $gname = $d !== null ? ' ' . preg_replace('/[^A-Za-z0-9 _\-]/', '', $d['name'])
                         : ($dev > 1 ? ' ' . $dev : '');
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    // Die Befehle tragen Schluessel, keine blanken Listenwerte: nur so sieht
    // umschrift_pruefen.py ihren Text. Bis 1.1.6 standen hier vier
    // Umschriften ("ueber", "enthaelt", "laedt", "entlaedt"), die in Loxone
    // Config als Anzeige- und Befehlsname auf dem Bildschirm standen - und
    // kein Werkzeug hat sie gefunden.
    $o .= '<VirtualOut HintText="" Title="' . marstek_x('Marstek steuern' . $gname) . '" Comment="'
        . marstek_x('Steuerbefehle über das Plugin ' . $ordner . ' — enthält das Aktionstoken.')
        . '" Address="http://' . marstek_x($host) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach (array(
        array('title' => 'Sollwert setzen (W, + lädt / - entlädt)',
              'adresse' => '/marstek.php?p=<v>&t=240' . $q, 'analog' => true),
        array('title' => 'Handbetrieb: Modus Auto',
              'adresse' => '/marstek.php?mode=auto' . $q, 'analog' => false),
        array('title' => 'Handbetrieb: Modus AI',
              'adresse' => '/marstek.php?mode=ai' . $q, 'analog' => false),
    ) as $c) {
        $o .= "\t" . '<VirtualOutCmd Title="' . marstek_x($c['title']) . '" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . marstek_x('/plugins/' . $ordner . $c['adresse'] . '&token=' . $tok) . '" ';
        $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" Repeat="0" RepeatRate="0" ';
        if (!empty($c['analog'])) {
            $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="10" DestValHigh="10" ';
        }
        $o .= 'HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('VQ_marstek_steuern' . ($dev > 1 ? '_' . $dev : '') . '.xml', $o);
}

/** Alle Vorlagen, die zu dieser Anlage gehoeren. Rueckgabe: array(name => inhalt). */
function marstek_vorlagen_alle() {
    $out = array();
    $devs = marstek_devices();
    $nummern = $devs ? array_keys($devs) : array(1);
    foreach ($nummern as $n) {
        foreach (array('status', 'energy') as $satz) {
            list($name, $inhalt) = marstek_vorlage($satz, $n);
            $out[$name] = $inhalt;
        }
        list($name, $inhalt) = marstek_vo_vorlage($n);
        $out[$name] = $inhalt;
    }
    list($name, $inhalt) = marstek_vorlage('ranks', 1);
    $out[$name] = $inhalt;
    if (count($nummern) > 1) {
        list($name, $inhalt) = marstek_vorlage('summe', 1);
        $out[$name] = $inhalt;
    }
    return $out;
}

/**
 * Alle Vorlagen in EINEM Archiv. Rueckgabe: array(name, inhalt) oder null,
 * wenn ZipArchive fehlt.
 *
 * Bei vier Speichern sind es sonst dreizehn Klicks. ZipArchive steht NICHT in
 * dpkg/apt und ist damit nicht zugesichert - deshalb die Wache; die
 * Einzelknoepfe bleiben daneben stehen.
 */
function marstek_vorlagen_paket() {
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $tmp = marstek_tmpdir() . '/vorlagen_' . getmypid() . '.zip';
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
        return null;
    }
    foreach (marstek_vorlagen_alle() as $name => $inhalt) {
        $zip->addFromString($name, $inhalt);
    }
    $zip->addFromString('LIESMICH.txt',
        "Loxone-Vorlagen des LoxBerry-Plugins Marstek Venus E\r\n"
      . 'Erzeugt am ' . date('d.m.Y H:i') . "\r\n\r\n"
      . "ACHTUNG: die Dateien VQ_* enthalten das Aktionstoken im Klartext.\r\n"
      . "Sie gehoeren nicht weitergegeben und nicht in eine Wolke.\r\n\r\n"
      . "Loxone Config legt beim Import NEU AN und ueberschreibt nichts.\r\n"
      . "Zweimal eingelesen ergibt doppelte Bausteine.\r\n\r\n"
      . "Die Adresse im Kopf jeder Datei ist ein VORSCHLAG - bitte pruefen,\r\n"
      . "ob der Miniserver den LoxBerry unter diesem Namen erreicht.\r\n");
    $zip->close();
    $inhalt = (string) @file_get_contents($tmp);
    @unlink($tmp);
    return array('marstek_vorlagen_' . date('Ymd') . '.zip', $inhalt);
}
/* ==================== Rechenkern-Selbsttest ==========================
 *
 * Aufruf:  php bin/cron.php --selbsttest
 *
 * Jeder Fall hat eine vorher feststehende Erwartung, und jede Gruppe hat
 * einen Fall, der DURCHGEHEN muss, und einen, der ABGEWIESEN werden muss -
 * sonst misst der Lauf sein eigenes Schweigen.
 *
 * Was er ausdruecklich NICHT misst: das Geraet, den Broker, den Miniserver
 * und die Dateirechte. Dafuer ist der Reiter Test da und das, was nur am
 * Geraet zu messen ist.
 */
function marstek_selbsttest()
{
    $faelle = 0;
    $fehl = array();
    $pruefe = function ($name, $ist, $soll) use (&$faelle, &$fehl) {
        $faelle++;
        if ($ist !== $soll) {
            $fehl[] = sprintf('%s: erwartet %s, gemessen %s', $name,
                var_export($soll, true), var_export($ist, true));
        }
    };

    /* --- 1. Die Feldtabelle: Grenzen, Kurzform, Zahlenformat --- */
    foreach (array('status', 'energy', 'ranks', 'summe') as $satz) {
        foreach (marstek_felder($satz) as $name => $f) {
            $pruefe($satz . '.' . $name . ' min<=max', $f['min'] <= $f['max'], true);
            $pruefe($satz . '.' . $name . ' Kurzform vorhanden',
                isset($f['kurz']) && $f['kurz'] !== '', true);
            // Der Comment wird in Loxone Config zum Kachelnamen - ueber
            // vierzig Zeichen ist es ein Satz, kein Name.
            $pruefe($satz . '.' . $name . ' Kurzform hoechstens 40 Zeichen',
                strlen((string) $f['kurz']) <= 40, true);
            // NEU 06.09.2026: ohne diese Zeile koennte ein neues Feld
            // stillschweigend als "nicht retained" hinausgehen, weil
            // marstek_mqtt_retain() im Zweifel false liefert. Wer ein Feld
            // anlegt, soll sich entscheiden muessen.
            $pruefe($satz . '.' . $name . ' Retain eingeteilt',
                isset($f['retain']) && ($f['retain'] === 0 || $f['retain'] === 1), true);
            // Traegt MinVal jeden Fehlwert, den der Text nennt?
            if (preg_match_all('/(-\d+) = /', $f['text'], $m)) {
                foreach ($m[1] as $fehlwert) {
                    $pruefe($satz . '.' . $name . ' MinVal traegt ' . $fehlwert,
                        (int) $fehlwert >= (int) $f['min'], true);
                }
            }
        }
    }

    /* --- 2. Die Suchtexte an der ERZEUGTEN Antwortzeile --- */
    foreach (array('status', 'energy', 'ranks', 'summe') as $satz) {
        $zeile = marstek_zeile($satz, array(), 1);
        foreach (array_keys(marstek_felder($satz)) as $name) {
            $pruefe($satz . ': Suchtext ;' . $name . '= genau einmal',
                substr_count($zeile, ';' . $name . '='), 1);
        }
    }

    /* --- 3. Themenliste gegen den Sendecode --- */
    list($nur_liste, $nur_code) = marstek_themen_abgleich();
    $pruefe('Themen: in der Liste, aber nicht im Sendecode', $nur_liste, array());
    $pruefe('Themen: im Sendecode, aber nicht in der Liste', $nur_code, array());

    /* --- Retain: die Einteilung des Hausstandards, in beide Richtungen ---
     *
     * Regeln/07, Abschnitt 3: Zustaende retained, Messwerte mit Zeitbezug
     * nicht, das Lebenszeichen nie. Die Liste steht hier ein zweites Mal,
     * getrennt von der Feldtabelle - eine Zeile, die dort versehentlich
     * umspringt, faellt hier auf. */
    foreach (array('ok', 'soc', 'fw', 'soll', 'energie_chgt', 'energie_cyc',
                   'rang_ok', 'rang_n') as $t) {
        $pruefe('Retain JA: ' . $t, marstek_mqtt_retain($t), true);
    }
    foreach (array('batp', 'temp', 'gridp', 'ms', 'alter', 'zaehler', 'sollalter',
                   'fbrest', 'rang_curp', 'rang_rank', 'energie_alter',
                   'ts', 'takt_zaehler', 'takt_ts', 'gibtsnicht') as $t) {
        $pruefe('Retain NEIN: ' . $t, marstek_mqtt_retain($t), false);
    }

    /* --- Die Bezeichnung der Vorlagen ist lesbar und eindeutig ---
     *
     * Bis 1.1.6 trug sie den technischen Schluessel. Beides wird geprueft:
     * kein Grossbuchstaben-Schluessel mehr, und ueber alle vier Saetze
     * keine Doppelung - sonst stehen in Loxone Config zwei Eingaenge unter
     * demselben Namen. */
    $bez = array();
    foreach (array('status', 'energy', 'ranks', 'summe') as $satz) {
        foreach (marstek_felder($satz) as $name => $f) {
            $pruefe($satz . '.' . $name . ' Bezeichnung nicht der Schluessel',
                (bool) preg_match('/^MARSTEK_[A-Z]+_/', (string) $f['kurz']), false);
            $bez[] = $f['kurz'];
        }
    }
    $pruefe('Bezeichnungen ueber alle Saetze eindeutig',
        count(array_unique($bez)), count($bez));

    /* --- 4. Der Abfragetakt: eine Quelle --- */
    foreach (array('status' => 60, 'summe' => 60, 'ranks' => 300, 'energy' => 300) as $s => $t) {
        $pruefe('Takt ' . $s, marstek_satz_takt($s), $t);
        list(, $xml) = marstek_vorlage($s, 1);
        $pruefe('Vorlage ' . $s . ' PollingTime',
            preg_match('/PollingTime="' . $t . '"/', $xml) === 1, true);
    }

    /* --- 5. Das Zahlenformat der Vorlage --- */
    $pruefe('Einheit %d  -> <v.0>', marstek_einheit_muster(array('form' => '%d')), '<v.0>');
    $pruefe('Einheit %.1f -> <v.1>', marstek_einheit_muster(array('form' => '%.1f')), '<v.1>');
    $pruefe('Einheit %.5f -> <v.5>', marstek_einheit_muster(array('form' => '%.5f')), '<v.5>');

    /* --- 6. Die Vorlagen sind wohlgeformt --- */
    if (function_exists('simplexml_load_string')) {
        $alt = libxml_use_internal_errors(true);
        foreach (marstek_vorlagen_alle() as $name => $inhalt) {
            $pruefe('Vorlage wohlgeformt: ' . $name,
                simplexml_load_string($inhalt) !== false, true);
            libxml_clear_errors();
        }
        libxml_use_internal_errors($alt);
    }

    /* --- 7. Adressen: jede Stelle einzeln --- */
    $pruefe('IP 192.0.2.7 gueltig', marstek_ip_gueltig('192.0.2.7'), true);
    $pruefe('IP 999.999.999.999 abgewiesen', marstek_ip_gueltig('999.999.999.999'), false);
    $pruefe('IP 192.0.2 abgewiesen', marstek_ip_gueltig('192.0.2'), false);
    $pruefe('Rundrufadresse', marstek_broadcast_zu('192.0.2.77'), '192.0.2.255');

    /* --- 8. Das Themen-Praefix: Einschleusung geht nicht durch --- */
    $pruefe('Thema sauber bleibt', marstek_mqtt_thema_saeubern('marstek/keller'),
        'marstek/keller');
    $pruefe('Thema mit Zeilenumbruch',
        marstek_mqtt_thema_saeubern("marstek\npublish fremd/x 1"), 'marstekpublishfremd/x1');
    $pruefe('Thema leer', marstek_mqtt_thema_saeubern('   '), 'marstek');

    /* --- 9. Die Wertpruefung, in beide Richtungen --- */
    $gut = array(
        array('cache_sec', 40), array('vat', 1.19), array('awattar', 'de'),
        array('mqtt_topic', 'marstek'), array('aktionstoken', 'abc-123_XY.z'),
        array('aktionstoken', ''), array('soc_max', 98), array('temp_max', 45),
        array('devices', array(array('name' => 'Venus E', 'ip' => '192.0.2.7',
                                     'port' => 30000, 'pmax_charge' => 2500,
                                     'pmax_discharge' => 2500, 'modbus' => 1, 'kwh' => 5.12))),
    );
    foreach ($gut as $g) {
        $pruefe('Wert zulaessig: ' . $g[0], marstek_wert_pruefen($g[0], $g[1]), '');
    }
    $schlecht = array(
        array('aktionstoken', array('a', 'b')),
        array('cache_sec', 'abc'),
        array('mqtt_topic', "marstek\npublish fremd/x 1"),
        array('devices', 'keine Liste'),
        array('temp_max', 9999),
        array('soc_min', -5),
        array('awattar', 'ch'),
        array('devices', array(array('ip' => '999.999.999.999'))),
    );
    foreach ($schlecht as $s) {
        $pruefe('Wert abgewiesen: ' . $s[0],
            marstek_wert_pruefen($s[0], $s[1]) !== '', true);
    }

    /* --- 10. Die Sicherung: eigene Ausfuhr, eigene Einfuhr --- */
    $ausfuhr = marstek_sicherung_schreiben();
    $pruefe('Sicherung traegt einen lesbaren Kopf',
        isset($ausfuhr['_hinweis']) && isset($ausfuhr['_stand']), true);
    $fremd = array_diff(array_keys($ausfuhr), array_keys(marstek_vorgaben()));
    $fremd = array_values(array_filter($fremd, function ($k) { return $k === '' || $k[0] !== '_'; }));
    $pruefe('Sicherung enthaelt nichts, was die Einfuhr nicht kennt', $fremd, array());

    /* --- 11. Die Schranke fuer den Takt steht an einer Stelle --- */
    $pruefe('Taktschranke definiert', defined('MARSTEK_TAKT_SCHRANKE'), true);

    printf("Rechenkern Marstek Venus E: %d Faelle geprueft, %d Fehlschlaege.\n",
           $faelle, count($fehl));
    foreach ($fehl as $z) {
        printf("  FEHL %s\n", $z);
    }
    return count($fehl) === 0 ? 0 : 1;
}

/**
 * Die Themenliste gegen den SENDECODE halten.
 *
 * BERICHTIGT 04.09.2026. Die Zeile im Reiter Test hat bis 1.1.4 die Zahl aus
 * marstek_mqtt_themen() gegen eine aus DERSELBEN Feldtabelle gerechnete Zahl
 * gehalten - eine Tautologie. Geeicht: dem Sendecode wurde ein Thema
 * hinzugefuegt, es ging hinaus, und die Zeile blieb gruen.
 *
 * Jetzt werden die vier veroeffentlichenden Funktionen wirklich gelesen und
 * ihre Themen gebildet. Rueckgabe: array(nur in der Liste, nur im Sendecode).
 */
function marstek_themen_abgleich()
{
    $liste = array_keys(marstek_mqtt_themen(true));

    // Was der Sendecode bildet - dieselben Schleifen wie dort, aber aus
    // dieser Funktion gelesen, damit ein neues Thema hier auffaellt.
    $code = array();
    foreach (array_keys(marstek_felder('status')) as $n) { $code[] = strtolower($n); }
    $code[] = 'ts';
    foreach (array_keys(marstek_felder('energy')) as $n) { $code[] = 'energie_' . strtolower($n); }
    foreach (array_keys(marstek_felder('ranks')) as $n) { $code[] = 'rang_' . strtolower($n); }
    $code[] = 'takt_zaehler';
    $code[] = 'takt_ts';

    // Und was in den publish-Funktionen WIRKLICH steht: jede Zuweisung an
    // $werte[...] in den vier marstek_mqtt_publish*-Funktionen.
    $quelle = @file_get_contents(__FILE__);
    $zusatz = array();
    if (is_string($quelle) && $quelle !== '') {
        foreach (array('marstek_mqtt_publish', 'marstek_mqtt_publish_energy',
                       'marstek_mqtt_publish_ranks', 'marstek_mqtt_publish_takt') as $fn) {
            $i = strpos($quelle, 'function ' . $fn . '(');
            if ($i === false) { continue; }
            $j = strpos($quelle, "\n}\n", $i);
            $rumpf = substr($quelle, $i, ($j === false ? 2000 : $j - $i));
            if (preg_match_all("/'([a-z0-9_]+)'\s*=>/", $rumpf, $m)) {
                foreach ($m[1] as $t) { $zusatz[] = $t; }
            }
            if (preg_match_all("/\\\$werte\['([a-z0-9_]+)'\]/", $rumpf, $m)) {
                foreach ($m[1] as $t) { $zusatz[] = $t; }
            }
        }
    }
    $code = array_values(array_unique(array_merge($code, $zusatz)));

    return array(array_values(array_diff($liste, $code)),
                 array_values(array_diff($code, $liste)));
}
