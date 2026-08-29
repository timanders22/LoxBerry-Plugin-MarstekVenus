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
    if ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $plugindir) === false) {
        $plugindir = 'marstekvenus';
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

function marstek_config() {
    $p = marstek_paths();
    // Selbstheilung: fehlende/leere Konfiguration aus Sicherung wiederherstellen.
    // Entschieden wird nach INHALT, nicht nach Form - eine Datei mit "{}" ist
    // so leer wie keine.
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['backup'])) {
        if (!is_dir(dirname($p['config']))) { @mkdir(dirname($p['config']), 0775, true); }
        @copy($p['backup'], $p['config']);
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
    if (!marstek_write_atomic($p['config'], $json)) {
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
        @file_put_contents($f, $t);
        @chmod($f, 0600);
    }
    return $t;
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
function marstek_write_atomic($datei, $inhalt) {
    if ($inhalt === false || $inhalt === null) {
        return false;
    }
    $inhalt = (string) $inhalt;
    $tmp = $datei . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, $inhalt) !== strlen($inhalt)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0644);          // Rechte VOR dem Umbenennen setzen
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

function marstek_log($msg) {
    $f = marstek_logfile();
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 512000) { // Rotation: letzte 200 Zeilen behalten
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($f, implode("\n", $tail) . "\n");
    }
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
    $ende = microtime(true) + $sek;
    while (microtime(true) < $ende && count($out) < $max) {
        $von = '';
        $roh = @stream_socket_recvfrom($h, 8192, 0, $von);
        if ($roh === false || $roh === '') {
            usleep(20000);
            continue;
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
function marstek_rundruf_adressen() {
    $out = array();
    $nr = 0; $txt = '';
    $s = @stream_socket_client('udp://192.0.2.1:9', $nr, $txt, 1);
    if ($s) {
        $eigen = preg_replace('/:\d+$/', '', (string) stream_socket_get_name($s, false));
        fclose($s);
        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $eigen)) {
            $out[] = preg_replace('/\.\d+$/', '.255', $eigen);
        }
    }
    foreach (marstek_devices() as $d) {
        $bc = preg_replace('/\.\d+$/', '.255', $d['ip']);
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
    $bc = preg_replace('/\.\d+$/', '.255', $d['ip']);
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
                $antworten = marstek_udp_horchen($h, $tmo, 8);
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
                    $GLOBALS['marstek_last_ms'] = (int) round((microtime(true) - $tsend) * 1000);
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
    $ok = (is_array($es) && !isset($es['_error'])) || (is_array($bat) && !isset($bat['_error'])) ? 1 : 0;

    if (!$ok) {
        // Kein Wert ist besser als eine erfundene Null. Steht ein frueherer
        // Stand da, bleibt er stehen; nur ok und ts werden angefasst. 'mess'
        // ist der Zeitpunkt der letzten ECHTEN Messung und bleibt unberuehrt.
        $out = $alt !== null ? $alt : array('soc' => 0, 'batp' => 0, 'temp' => 0, 'gridp' => 0,
                                            'fw' => 0, 'model' => '', 'ms' => 0, 'mess' => 0);
        $out['ok'] = 0;
        $out['ts'] = time();
        if (!isset($out['mess'])) { $out['mess'] = 0; }
        marstek_write_json($cache, $out);
        marstek_ausfall_zaehlen($dev, true);
        marstek_log_if_changed('status_dev' . $dev,
            'OK=0 (keine Antwort) - letzte Messung '
            . ($out['mess'] ? date('d.m. H:i:s', $out['mess']) : 'nie'), 'ok=0');
        marstek_mqtt_publish($out, $dev);
        return $out;
    }

    $soc = 0; $batp = 0; $temp = 0; $gridp = 0;
    if (is_array($bat) && isset($bat['soc'])) {
        $soc = $bat['soc'];
    } elseif (is_array($es) && isset($es['bat_soc'])) {
        $soc = $es['bat_soc'];
    }
    if (is_array($es) && isset($es['bat_power'])) {
        $batp = $es['bat_power']; // + = laedt
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
            }
        }
    }
    if (is_array($bat) && isset($bat['bat_temp'])) {
        $temp = $bat['bat_temp'];
        if ($temp > 100) {
            $temp = $temp / 10; // alte BMS-Firmware liefert 10x
        }
    }
    if (is_array($es) && isset($es['ongrid_power'])) {
        $gridp = $es['ongrid_power'];
    }
    $info = marstek_devinfo($dev);
    $out = array('ok' => 1, 'soc' => round((float) $soc, 1), 'batp' => round((float) $batp),
                 'temp' => round((float) $temp, 1), 'gridp' => round((float) $gridp),
                 'fw' => (int) $info['fw'], 'model' => (string) $info['model'],
                 'ms' => $ms, 'ts' => time(), 'mess' => time());
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
        return array('schwere' => 3, 'text' => 'Die Konfigurationsdatei ist beschaedigt und '
            . 'konnte nicht gelesen werden. Die Zweitschrift liegt daneben.');
    }
    if ($zustand === 'fehlt' || $zustand === 'leer') {
        return array('schwere' => 4, 'text' => 'Das Plugin ist noch nicht eingerichtet - '
            . 'bitte die Plugin-Oberflaeche oeffnen und mindestens einen Speicher eintragen.');
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
    if (time() - $h['ts'] > 300) {
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
    if (time() - (int) $st['mess'] > 900) {
        return '';   // aelter als 15 Minuten: kein Urteil auf alten Zahlen
    }
    $temp = (float) $st['temp'];
    $soc = (float) $st['soc'];
    if ($temp >= (float) $cfg['temp_max']) {
        return 'TEMP_MAX';
    }
    if ($p > 0 && $temp <= (float) $cfg['temp_min']) {
        return 'TEMP_MIN';
    }
    if ($p > 0 && $soc >= (float) $cfg['soc_max']) {
        return 'SOC_MAX';
    }
    if ($p < 0 && $soc <= (float) $cfg['soc_min']) {
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
            list($ok, ) = marstek_set_mode('auto', $n);
            $minuten = (int) round((time() - $s['ts']) / 60);
            marstek_log('Auto-Fallback (Geraet ' . $n . '): ' . $minuten
                . ' min kein Sollwert -> Auto-Modus (ok=' . $ok . ')');
            marstek_melden(4, $d['name'] . ': seit ' . $minuten . ' Minuten kein Sollwert aus Loxone - '
                . 'der Speicher wurde in den Auto-Modus zurueckgegeben.');
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
    $out['eff'] = $out['chgt'] > 0 ? round($out['dist'] / $out['chgt'] * 100, 1) : 0;
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
    $bc = preg_replace('/\.\d+$/', '.255', $d['ip']);
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
        @fwrite($s, 'publish ' . $prefix . '/' . $k . ' ' . marstek_mqtt_wert_saeubern($v));
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
function marstek_mqtt_senden_bei_aenderung(array $werte, $prefix, $merkername)
{
    $sig = json_encode($werte);
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

/** Praefix aus der Konfiguration, bei Geraet 2..9 mit angehaengter Nummer. */
function marstek_mqtt_prefix($dev = 1)
{
    $cfg = marstek_config();
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'marstek';
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
    foreach (marstek_felder('energy') as $name => $f) {
        $werte['energie_' . strtolower($name)] = marstek_feldwert($f, $e, $dev);
    }
    marstek_mqtt_senden_bei_aenderung($werte, marstek_mqtt_prefix($dev), 'energie_dev' . (int) $dev);
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
    foreach (marstek_felder('ranks') as $name => $f) {
        $werte['rang_' . strtolower($name)] = marstek_feldwert($f, $r, 1);
    }
    marstek_mqtt_senden_bei_aenderung($werte, marstek_mqtt_prefix(1), 'raenge');
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
            'OK'    => array('quelle' => 'ok',   'analog' => 0, 'min' => 0, 'max' => 1,       'einheit' => '',    'form' => '%d',   'text' => '1 = Werte gueltig'),
            'CHGT'  => array('quelle' => 'chgt', 'analog' => 1, 'min' => 0, 'max' => 1000000, 'einheit' => 'kWh', 'form' => '%.2f', 'text' => 'geladen gesamt'),
            'DIST'  => array('quelle' => 'dist', 'analog' => 1, 'min' => 0, 'max' => 1000000, 'einheit' => 'kWh', 'form' => '%.2f', 'text' => 'abgegeben gesamt'),
            'CHGD'  => array('quelle' => 'chgd', 'analog' => 1, 'min' => 0, 'max' => 1000,    'einheit' => 'kWh', 'form' => '%.2f', 'text' => 'geladen heute'),
            'DISD'  => array('quelle' => 'disd', 'analog' => 1, 'min' => 0, 'max' => 1000,    'einheit' => 'kWh', 'form' => '%.2f', 'text' => 'abgegeben heute'),
            'CHGM'  => array('quelle' => 'chgm', 'analog' => 1, 'min' => 0, 'max' => 100000,  'einheit' => 'kWh', 'form' => '%.2f', 'text' => 'geladen diesen Monat'),
            'DISM'  => array('quelle' => 'dism', 'analog' => 1, 'min' => 0, 'max' => 100000,  'einheit' => 'kWh', 'form' => '%.2f', 'text' => 'abgegeben diesen Monat'),
            'CYC'   => array('quelle' => 'cyc',  'analog' => 1, 'min' => 0, 'max' => 100000,  'einheit' => '',    'form' => '%d',   'text' => 'Vollzyklen'),
            'EFF'   => array('quelle' => 'eff',  'analog' => 1, 'min' => 0, 'max' => 100,     'einheit' => '%',   'form' => '%.1f', 'text' => 'Wirkungsgrad gesamt'),
            'ALTER' => array('quelle' => '_alter', 'analog' => 1, 'min' => -1, 'max' => 86400, 'einheit' => 's',  'form' => '%d',   'text' => 'Alter der Zaehlerstaende in Sekunden; -1 = noch nie gemessen'),
        );
    }
    if ($satz === 'ranks') {
        return array(
            'OK'      => array('quelle' => 'ok',      'analog' => 0, 'min' => 0,  'max' => 1,  'einheit' => '',        'form' => '%d',   'text' => '1 = Preisdaten gueltig'),
            'N'       => array('quelle' => 'n',       'analog' => 1, 'min' => 0,  'max' => 48, 'einheit' => '',        'form' => '%d',   'text' => 'Anzahl bewerteter Stunden im Fenster'),
            'RANK'    => array('quelle' => 'rank',    'analog' => 1, 'min' => 0,  'max' => 99, 'einheit' => '',        'form' => '%d',   'text' => 'Rang der laufenden Stunde im 24-Stunden-Fenster (1 = guenstigste); 99 = keine Daten'),
            'RANKD'   => array('quelle' => 'rankd',   'analog' => 1, 'min' => 0,  'max' => 99, 'einheit' => '',        'form' => '%d',   'text' => 'derselbe Rang absteigend (1 = teuerste Stunde); 99 = keine Daten'),
            'CURP'    => array('quelle' => 'curp',    'analog' => 1, 'min' => -1, 'max' => 10, 'einheit' => 'EUR/kWh', 'form' => '%.5f', 'text' => 'Preis der laufenden Stunde inkl. Aufschlag und USt'),
            'NEG'     => array('quelle' => 'neg',     'analog' => 0, 'min' => 0,  'max' => 1,  'einheit' => '',        'form' => '%d',   'text' => '1 = der Preis der laufenden Stunde ist negativ'),
            'MINP'    => array('quelle' => 'minp',    'analog' => 1, 'min' => -1, 'max' => 10, 'einheit' => 'EUR/kWh', 'form' => '%.5f', 'text' => 'guenstigste Stunde im Fenster'),
            'MAXP'    => array('quelle' => 'maxp',    'analog' => 1, 'min' => -1, 'max' => 10, 'einheit' => 'EUR/kWh', 'form' => '%.5f', 'text' => 'teuerste Stunde im Fenster'),
            'SPREAD'  => array('quelle' => 'spread',  'analog' => 1, 'min' => 0,  'max' => 10, 'einheit' => 'EUR/kWh', 'form' => '%.5f', 'text' => 'Abstand teuerste zu guenstigster Stunde - lohnt sich der Umschlag heute?'),
            'NEXTP'   => array('quelle' => 'nextp',   'analog' => 1, 'min' => -1, 'max' => 10, 'einheit' => 'EUR/kWh', 'form' => '%.5f', 'text' => 'Preis der naechsten Stunde'),
            'HBIS'    => array('quelle' => 'hbis',    'analog' => 1, 'min' => -1, 'max' => 24, 'einheit' => 'h',       'form' => '%d',   'text' => 'Stunden bis zur guenstigsten Stunde (0 = jetzt); -1 = unbekannt'),
            'HBISMAX' => array('quelle' => 'hbismax', 'analog' => 1, 'min' => -1, 'max' => 24, 'einheit' => 'h',       'form' => '%d',   'text' => 'Stunden bis zur teuersten Stunde (0 = jetzt); -1 = unbekannt'),
            'ERRC'    => array('quelle' => 'errc',    'analog' => 1, 'min' => 0,  'max' => 9,  'einheit' => '',        'form' => '%d',   'text' => 'Grund fuer OK=0: 0 in Ordnung, 1 keine Preise geholt, 2 Fenster zu kurz, 3 keine Preise fuer die laufende Stunde'),
        );
    }
    if ($satz === 'summe') {
        return array(
            'OK'      => array('quelle' => 'ok',      'analog' => 0, 'min' => 0,      'max' => 1,     'einheit' => '',    'form' => '%d',   'text' => '1 = ALLE Speicher haben geantwortet'),
            'N'       => array('quelle' => 'n',       'analog' => 1, 'min' => 0,      'max' => 9,     'einheit' => '',    'form' => '%d',   'text' => 'Anzahl eingetragener Speicher'),
            'NOK'     => array('quelle' => 'nok',     'analog' => 1, 'min' => 0,      'max' => 9,     'einheit' => '',    'form' => '%d',   'text' => 'Anzahl Speicher ohne Antwort'),
            'SOC'     => array('quelle' => 'soc',     'analog' => 1, 'min' => -1,     'max' => 100,   'einheit' => '%',   'form' => '%.1f', 'text' => 'nach Kapazitaet gewichteter Ladezustand; -1 = nicht bildbar'),
            'KAPAZ'   => array('quelle' => 'kapaz',   'analog' => 1, 'min' => -1,     'max' => 1000,  'einheit' => 'kWh', 'form' => '%.2f', 'text' => 'Gesamtkapazitaet; -1 = bei mindestens einem Speicher nicht eingetragen'),
            'RESTKWH' => array('quelle' => 'restkwh', 'analog' => 1, 'min' => -1,     'max' => 1000,  'einheit' => 'kWh', 'form' => '%.2f', 'text' => 'noch gespeicherte Menge; -1 = nicht bildbar'),
            'BATP'    => array('quelle' => 'batp',    'analog' => 1, 'min' => -40000, 'max' => 40000, 'einheit' => 'W',   'form' => '%d',   'text' => 'Summe der Batterieleistungen (+ laedt / - entlaedt)'),
            'ALTER'   => array('quelle' => 'alter',   'analog' => 1, 'min' => -1,     'max' => 86400, 'einheit' => 's',   'form' => '%d',   'text' => 'Alter der aeltesten Teilmessung; -1 = nicht bildbar'),
        );
    }
    return array(
        'OK'        => array('quelle' => 'ok',    'analog' => 0, 'min' => 0,      'max' => 1,      'einheit' => '',   'form' => '%d',   'text' => '1 = Speicher erreichbar'),
        'SOC'       => array('quelle' => 'soc',   'analog' => 1, 'min' => 0,      'max' => 100,    'einheit' => '%',  'form' => '%.1f', 'text' => 'Ladezustand'),
        'BATP'      => array('quelle' => 'batp',  'analog' => 1, 'min' => -10000, 'max' => 10000,  'einheit' => 'W',  'form' => '%d',   'text' => 'Batterieleistung (+ laedt / - entlaedt)'),
        'TEMP'      => array('quelle' => 'temp',  'analog' => 1, 'min' => -20,    'max' => 80,     'einheit' => '°C', 'form' => '%.1f', 'text' => 'Batterietemperatur'),
        'GRIDP'     => array('quelle' => 'gridp', 'analog' => 1, 'min' => -20000, 'max' => 20000,  'einheit' => 'W',  'form' => '%d',   'text' => 'Netzleistung am Speicher'),
        'FW'        => array('quelle' => 'fw',    'analog' => 1, 'min' => 0,      'max' => 100000, 'einheit' => '',   'form' => '%d',   'text' => 'Firmwarestand des Geraets'),
        // BERICHTIGT 24.08.2026: hiess "Betriebsmodus des Geraets" mit Bereich
        // 0..10 und ist in Wahrheit die Antwortzeit. Die eigene Ausfuhr aus
        // Loxone Config fuehrt dasselbe Feld mit 0..10000 und "ms".
        'MS'        => array('quelle' => 'ms',    'analog' => 1, 'min' => 0,      'max' => 10000,  'einheit' => 'ms', 'form' => '%d',   'text' => 'Antwortzeit des Geraets'),
        'ALTER'     => array('quelle' => '_alter',     'analog' => 1, 'min' => -1,     'max' => 86400, 'einheit' => 's', 'form' => '%d', 'text' => 'Alter der letzten echten Messung in Sekunden; -1 = noch nie gemessen'),
        'ZAEHLER'   => array('quelle' => '_zaehler',   'analog' => 1, 'min' => -1,     'max' => 999,   'einheit' => '',  'form' => '%d', 'text' => 'Herzschlag des Minutentakts, zaehlt 0..999 um; -1 = der Takt laeuft nicht'),
        'SOLL'      => array('quelle' => '_soll',      'analog' => 1, 'min' => -32768, 'max' => 10000, 'einheit' => 'W', 'form' => '%d', 'text' => 'zuletzt vom Geraet ANGENOMMENER Sollwert (+ laden); -32768 = keiner'),
        'SOLLALTER' => array('quelle' => '_sollalter', 'analog' => 1, 'min' => -1,     'max' => 86400, 'einheit' => 's', 'form' => '%d', 'text' => 'Sekunden seit dem letzten angenommenen Sollwert; -1 = keiner'),
        'FBREST'    => array('quelle' => '_fbrest',    'analog' => 1, 'min' => -2,     'max' => 86400, 'einheit' => 's', 'form' => '%d', 'text' => 'Sekunden bis zum Auto-Fallback; -1 = abgeschaltet, -2 = kein Passivbetrieb'),
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
        return ($h['ts'] > 0 && time() - $h['ts'] <= 180) ? $h['zaehler'] : -1;
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
            'title' => 'MARSTEK_' . strtoupper($satz) . '_' . $name . ($je_geraet && $dev > 1 ? '_' . $dev : ''),
            'comment' => $f['text'] . ($f['einheit'] !== '' ? ' [' . $f['einheit'] . ']' : ''),
            // Das Trennzeichen gehoert in den Suchtext. Ohne es haengt allein
            // an der Reihenfolge der Zeile, dass der richtige Wert ankommt -
            // eine Zusicherung, die beim naechsten neuen Feld still faellt.
            'check' => '\i;' . $name . '=\i\v',
            'unit' => ($f['einheit'] !== '' ? '<v.1> ' . $f['einheit'] : '<v.1>'),
            'analog' => $f['analog'], 'min' => $f['min'], 'max' => $f['max'],
        );
    }
    $q = '?' . $satz . ($je_geraet && $dev > 1 ? '&dev=' . $dev : '');
    $endung = ($je_geraet && $dev > 1) ? '_' . $dev : '';
    return array('VI_marstek_' . $satz . $endung . '.xml', marstek_xml_virtual_in_http(array(
        'title' => 'Marstek ' . ucfirst($satz) . $gname,
        'address' => 'http://' . $host . '/plugins/' . $ordner . '/marstek.php' . $q,
        'polling' => $satz === 'status' ? '60' : '300',
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
    $o .= '<VirtualOut HintText="" Title="' . marstek_x('Marstek steuern' . $gname) . '" Comment="'
        . marstek_x('Steuerbefehle ueber das Plugin ' . $ordner . ' - enthaelt das Aktionstoken.')
        . '" Address="http://' . marstek_x($host) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach (array(
        array('Sollwert setzen (W, + laedt / - entlaedt)', '/marstek.php?p=<v>&t=240' . $q, true),
        array('Handbetrieb: Modus Auto', '/marstek.php?mode=auto' . $q, false),
        array('Handbetrieb: Modus AI', '/marstek.php?mode=ai' . $q, false),
    ) as $c) {
        $o .= "\t" . '<VirtualOutCmd Title="' . marstek_x($c[0]) . '" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . marstek_x('/plugins/' . $ordner . $c[1] . '&token=' . $tok) . '" ';
        $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
        $o .= 'Analog="' . (!empty($c[2]) ? 'true' : 'false') . '" Repeat="0" RepeatRate="0" ';
        if (!empty($c[2])) {
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
