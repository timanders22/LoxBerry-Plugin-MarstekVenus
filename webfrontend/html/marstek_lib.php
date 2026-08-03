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
 * Sollwert kam), MQTT-Publish ueber das LoxBerry MQTT Gateway.
 *
 * Keine persoenlichen Daten im Code - alles kommt aus der Plugin-Konfiguration
 * ($LBHOMEDIR/config/plugins/<plugin>/marstek.json).
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

$GLOBALS['marstek_last_ms'] = 0; // Antwortzeit des letzten RPC in Millisekunden

function marstek_paths() {
    $lbhomedir = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
    $plugindir = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    if ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $plugindir) === false) {
        $plugindir = 'marstekvenus';
    }
    if ($lbhomedir) {
        return array(
            'config' => $lbhomedir . '/config/plugins/' . $plugindir . '/marstek.json',
            'backup' => $lbhomedir . '/config/plugins/' . $plugindir . '.backup.json',
            'log' => $lbhomedir . '/log/plugins/' . $plugindir . '/marstek.log',
            'data' => $lbhomedir . '/data/plugins/' . $plugindir,
            'tmp' => '/tmp/marstekvenus',
            'lbhome' => $lbhomedir,
        );
    }
    return array(
        'config' => dirname(dirname(__DIR__)) . '/config/marstek.json',
        'backup' => dirname(dirname(__DIR__)) . '/config/marstek.backup.json',
        'log' => sys_get_temp_dir() . '/marstekvenus/marstek.log',
        'data' => sys_get_temp_dir() . '/marstekvenus/data',
        'tmp' => sys_get_temp_dir() . '/marstekvenus',
        'lbhome' => '',
    );
}

function marstek_config() {
    $p = marstek_paths();
    // Selbstheilung: fehlende/leere Konfiguration aus Sicherung wiederherstellen
    if ((!is_file($p['config']) || trim((string) @file_get_contents($p['config'])) === '' || trim((string) @file_get_contents($p['config'])) === '{}') && is_file($p['backup'])) {
        @mkdir(dirname($p['config']), 0775, true);
        @copy($p['backup'], $p['config']);
    }
    $cfg = is_file($p['config']) ? (json_decode((string) file_get_contents($p['config']), true) ?: array()) : array();
    $cfg += array(
        'devices' => array(),
        'cache_sec' => 40,
        'vat' => 1.19,
        'awattar' => 'de',            // de oder at
        'mqtt_enabled' => 0,
        'mqtt_topic' => 'marstek',
        'fallback_min' => 30,         // Standard 30 min; 0 = Auto-Fallback aus
        'aktionstoken' => '',         // schuetzt ?p= und ?mode= (unangemeldeter Endpunkt)
    );
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

function marstek_tmpdir() {
    $p = marstek_paths();
    if (!is_dir($p['tmp'])) {
        @mkdir($p['tmp'], 0775, true);
    }
    return $p['tmp'];
}

function marstek_datadir() {
    $p = marstek_paths();
    if (!is_dir($p['data'])) {
        @mkdir($p['data'], 0775, true);
    }
    return $p['data'];
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
    if (is_file($f) && filesize($f) > 512000) { // Rotation: letzte 200 Zeilen behalten
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($f, implode("\n", $tail) . "\n");
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function marstek_log_if_changed($key, $line) {
    $f = marstek_tmpdir() . '/last_' . $key . '.txt';
    $sig = preg_replace('/-?\d+(\.\d+)?/', '#', $line); // Zahlen ausblenden, nur Strukturwechsel loggen
    $prev = is_file($f) ? (string) file_get_contents($f) : '';
    if ($sig !== $prev) {
        marstek_log($key . ': ' . $line);
        @file_put_contents($f, $sig);
    }
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
    $targets = $mode === 'bc' ? array($bc, $d['ip']) : array($d['ip'], $bc);
    for ($a = 0; $a < $tries; $a++) {
        foreach ($targets as $target) {
            $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (!$s) {
                return array('_error' => 'Socket-Fehler');
            }
            @socket_set_option($s, SOL_SOCKET, SO_BROADCAST, 1);
            socket_set_option($s, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $tmo, 'usec' => 0));
            $tsend = microtime(true);
            @socket_sendto($s, $payload, strlen($payload), 0, $target, $d['port']);
            $t0 = microtime(true);
            while (microtime(true) - $t0 < $tmo) {
                $buf = ''; $from = ''; $rport = 0;
                $r = @socket_recvfrom($s, $buf, 4096, 0, $from, $rport);
                if ($r === false) {
                    break;
                }
                $j = @json_decode($buf, true);
                // id-Treffer ODER Antwort vom richtigen Geraet (id-Spiegelung fehlt bei FW 148)
                if (is_array($j) && ((isset($j['id']) && $j['id'] == $id)
                        || ($from === $d['ip'] && (isset($j['result']) || isset($j['error']))))) {
                    $GLOBALS['marstek_last_ms'] = (int) round((microtime(true) - $tsend) * 1000);
                    socket_close($s);
                    @file_put_contents($modef, $target === $bc ? 'bc' : 'uni');
                    if (isset($j['error'])) {
                        return array('_error' => json_encode($j['error']));
                    }
                    return isset($j['result']) ? $j['result'] : null;
                }
            }
            socket_close($s);
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
        file_put_contents($cache, json_encode($out));
    } elseif (is_file($cache)) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c)) {
            return $c;
        }
    }
    return $out;
}

/** Status (ES.GetStatus + Bat.GetStatus) mit Cache. Rueckgabe: assoziatives Array. */
function marstek_status($dev = 1, $force = false) {
    $cfg = marstek_config();
    $dev = max(1, (int) $dev);
    $cache = marstek_tmpdir() . '/status_dev' . $dev . '.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < max(5, (int) $cfg['cache_sec'])) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c)) {
            return $c;
        }
    }
    $es = marstek_rpc('ES.GetStatus', null, $dev);
    $ms = (int) $GLOBALS['marstek_last_ms'];
    $bat = marstek_rpc('Bat.GetStatus', null, $dev);
    $ok = (is_array($es) && !isset($es['_error'])) || (is_array($bat) && !isset($bat['_error'])) ? 1 : 0;
    $soc = 0; $batp = 0; $temp = 0; $gridp = 0;
    if (is_array($bat) && isset($bat['soc'])) {
        $soc = $bat['soc'];
    } elseif (is_array($es) && isset($es['bat_soc'])) {
        $soc = $es['bat_soc'];
    }
    if (is_array($es) && isset($es['bat_power'])) {
        $batp = $es['bat_power']; // + = laedt
    } elseif ($ok && is_array($d = marstek_dev($dev)) && !empty($d['modbus'])) {
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
    if (is_array($bat) && isset($bat['bat_temp'])) {
        $temp = $bat['bat_temp'];
        if ($temp > 100) {
            $temp = $temp / 10; // alte BMS-Firmware liefert 10x
        }
    }
    if (is_array($es) && isset($es['ongrid_power'])) {
        $gridp = $es['ongrid_power'];
    }
    $info = $ok ? marstek_devinfo($dev) : array('model' => '', 'fw' => 0);
    $out = array('ok' => $ok, 'soc' => round((float) $soc, 1), 'batp' => round((float) $batp),
                 'temp' => round((float) $temp, 1), 'gridp' => round((float) $gridp),
                 'fw' => (int) $info['fw'], 'model' => (string) $info['model'],
                 'ms' => $ok ? $ms : 0, 'ts' => time());
    file_put_contents($cache, json_encode($out));
    marstek_log_if_changed('status_dev' . $dev, "OK={$out['ok']} SOC={$out['soc']} BATP={$out['batp']} FW={$out['fw']} MS={$out['ms']}");
    if ($ok) {
        marstek_history_add($dev, $out['soc'], $out['batp']);
    }
    marstek_mqtt_publish($out, $dev);
    return $out;
}

/* ---------------- SOC-Tagesverlauf (History) ---------------- */

/** Messpunkt anhaengen (max. alle 240 s ein Punkt; Dateien aelter 8 Tage werden geloescht). */
function marstek_history_add($dev, $soc, $batp) {
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
        foreach (glob($dir . '/history_dev*_*.csv') ?: array() as $old) {
            if (time() - (int) filemtime($old) > 8 * 86400) {
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
    $f = marstek_datadir() . '/history_dev' . (int) $dev . '_' . $day . '.csv';
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

/* ---------------- Sollwert / Modus ---------------- */

/** Passiv-Sollwert setzen. $p: Loxone-Konvention + = LADEN (API-intern gedreht). */
function marstek_set_passive($p, $t, $dev = 1) {
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
    $res = marstek_rpc('ES.SetMode', array('id' => 0, 'config' => array(
        'mode' => 'Passive',
        'passive_cfg' => array('power' => -$p, 'cd_time' => $t),
    )), $dev);
    $ok = (is_array($res) && !empty($res['set_result'])) ? 1 : 0;
    if ($ok) { // Auto-Fallback: merken, wann zuletzt ein Sollwert kam
        @file_put_contents(marstek_tmpdir() . '/passive_dev' . (int) $dev, (string) time());
    }
    marstek_log_if_changed('set_dev' . (int) $dev, "p=$p t=$t ok=$ok");
    if (!$ok) {
        marstek_log("SET fehlgeschlagen (Geraet $dev): p=$p t=$t" . (is_array($res) && isset($res['_error']) ? ' (' . $res['_error'] . ')' : ''));
    }
    return array($ok, $p, $t);
}

/** Betriebsmodus an das Geraet zurueckgeben (auto|ai). */
function marstek_set_mode($m, $dev = 1) {
    $m = strtolower((string) $m) === 'ai' ? 'AI' : 'Auto';
    $cfgkey = $m === 'AI' ? 'ai_cfg' : 'auto_cfg';
    $res = marstek_rpc('ES.SetMode', array('id' => 0, 'config' => array('mode' => $m, $cfgkey => array('enable' => 1))), $dev);
    $ok = (is_array($res) && !empty($res['set_result'])) ? 1 : 0;
    @unlink(marstek_tmpdir() . '/passive_dev' . (int) $dev); // Fallback-Merker loeschen
    marstek_log("Modus $m gesetzt (Geraet " . (int) $dev . "): ok=$ok");
    return array($ok, $m);
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
        $f = marstek_tmpdir() . '/passive_dev' . $n;
        if (!is_file($f)) {
            continue; // kein Passiv-Betrieb aktiv
        }
        $last = (int) file_get_contents($f);
        if ($last > 0 && time() - $last > $min * 60) {
            list($ok, ) = marstek_set_mode('auto', $n);
            marstek_log('Auto-Fallback (Geraet ' . $n . '): ' . round((time() - $last) / 60) .
                ' min kein Sollwert -> Auto-Modus (ok=' . $ok . ')');
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

/** Energiezaehler per Modbus TCP (Cache 300 s). Nur wenn beim Geraet aktiviert. */
function marstek_energy($dev = 1, $force = false) {
    $d = marstek_dev($dev);
    $off = array('ok' => 0, 'chgt' => 0, 'dist' => 0, 'chgd' => 0, 'disd' => 0,
                 'chgm' => 0, 'dism' => 0, 'cyc' => 0, 'eff' => 0, 'ts' => time());
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
        marstek_log_if_changed('energy_dev' . (int) $dev, 'Modbus TCP keine Antwort (Port 502)');
        // WICHTIG: letzte bekannte Zaehlerstaende behalten (ok=0), NICHT auf 0 fallen -
        // sonst wuerden Monats-/Wochenbilanzen in Loxone bei einem Ausfall kippen.
        if (is_file($cache)) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c) && !empty($c['chgt'])) {
                $c['ok'] = 0;
                $c['ts'] = time();
                file_put_contents($cache, json_encode($c));
                return $c;
            }
        }
        file_put_contents($cache, json_encode($off));
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
    );
    $out['eff'] = $out['chgt'] > 0 ? round($out['dist'] / $out['chgt'] * 100, 1) : 0;
    file_put_contents($cache, json_encode($out));
    marstek_log_if_changed('energy_dev' . (int) $dev, "OK CHGD={$out['chgd']} DISD={$out['disd']} CYC={$out['cyc']}");
    return $out;
}

/* ---------------- Diagnose (Selbsttest fuer die Fehlersuche) ---------------- */

function marstek_diag($dev = 1) {
    $out = array();
    $d = marstek_dev($dev);
    if ($d === null) {
        return array('Geraet ' . (int) $dev . ' ist nicht konfiguriert (Plugin-Oberflaeche oeffnen).');
    }
    $out[] = 'Geraet ' . (int) $dev . ': ' . $d['name'] . ' - IP ' . $d['ip'] . ', UDP-Port ' . $d['port'] . ', kWh-Zaehler (Modbus): ' . ($d['modbus'] ? 'ein' : 'aus');
    $out[] = 'PHP sockets-Erweiterung: ' . (function_exists('socket_create') ? 'OK' : 'FEHLT!');
    // 1) UDP-Unicast mit langem Timeout
    $r = marstek_rpc('ES.GetStatus', null, $dev, 1, 5);
    if (is_array($r) && !isset($r['_error'])) {
        $out[] = 'UDP-Unicast ES.GetStatus: ANTWORT OK nach ' . (int) $GLOBALS['marstek_last_ms'] . ' ms -> lokale API funktioniert';
    } elseif (is_array($r)) {
        $out[] = 'UDP-Unicast ES.GetStatus: FEHLER: ' . $r['_error'];
    } else {
        $out[] = 'UDP-Unicast ES.GetStatus an ' . $d['ip'] . ':' . $d['port'] . ': KEINE ANTWORT (5 s Timeout)';
    }
    // 2) UDP-Broadcast (manche Firmwaren antworten nur darauf; findet auch falsche IPs)
    $bc = preg_replace('/\.\d+$/', '.255', $d['ip']);
    $got = '';
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($s) {
        @socket_set_option($s, SOL_SOCKET, SO_BROADCAST, 1);
        @socket_set_option($s, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 5, 'usec' => 0));
        $pl = json_encode(array('id' => 77777, 'method' => 'Marstek.GetDevice', 'params' => array('ble_mac' => '0')));
        @socket_sendto($s, $pl, strlen($pl), 0, $bc, $d['port']);
        $t0 = microtime(true);
        while (microtime(true) - $t0 < 5) {
            $buf = ''; $from = ''; $rp = 0;
            $r2 = @socket_recvfrom($s, $buf, 4096, 0, $from, $rp);
            if ($r2 === false) {
                break;
            }
            if (strpos((string) $buf, 'result') !== false || strpos((string) $buf, '77777') !== false) {
                $got = $from . ' -> ' . substr((string) $buf, 0, 160);
                break;
            }
        }
        socket_close($s);
    }
    $out[] = $got !== '' ? ('UDP-Broadcast an ' . $bc . ':' . $d['port'] . ': ANTWORT von ' . $got)
                         : ('UDP-Broadcast an ' . $bc . ':' . $d['port'] . ': keine Antwort');
    // 3) Modbus TCP: Verbindung + Geraetename-Register 31000
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
        $out[] = 'Modbus TCP Port 502: KEINE Verbindung (' . $errno . ')';
    }
    return $out;
}

/* ---------------- Spot-Ranking (aWATTar) ---------------- */

function marstek_ranks($debug = false) {
    $cfg = marstek_config();
    $tld = $cfg['awattar'] === 'at' ? 'at' : 'de';
    $vat = (float) $cfg['vat'];
    if ($vat <= 0) { $vat = 1.0; }
    $rows = array();
    foreach (array(strtotime('today 00:00'), strtotime('tomorrow 00:00')) as $startTs) {
        $start = $startTs * 1000; $end = $start + 24 * 3600 * 1000;
        $url = "https://api.awattar.$tld/v1/marketdata?start=$start&end=$end";
        $cache = marstek_tmpdir() . '/spot_' . date('Ymd', $startTs) . '.cache';
        $js = false;
        if (is_file($cache) && time() - filemtime($cache) < 900) {
            $js = file_get_contents($cache);
        } else {
            $ctx = stream_context_create(array('http' => array('timeout' => 10, 'user_agent' => 'LoxBerry Marstek')));
            $neu = @file_get_contents($url, false, $ctx);
            if ($neu !== false && strpos($neu, 'marketprice') !== false) {
                file_put_contents($cache, $neu);
                $js = $neu;
            } elseif (is_file($cache)) {
                $js = file_get_contents($cache);
            }
        }
        $d = @json_decode((string) $js, true);
        if (isset($d['data'])) {
            $rows = array_merge($rows, $d['data']);
        }
    }
    $now = time(); $hstart = $now - ($now % 3600); $list = array(); $cur = null;
    foreach ($rows as $r) {
        $ts = (int) ($r['start_timestamp'] / 1000);
        if ($ts < $hstart || $ts >= $hstart + 24 * 3600) {
            continue;
        }
        $pr = round($r['marketprice'] / 1000 * $vat, 5); // EUR/MWh netto -> EUR/kWh inkl. USt
        $list[$ts] = $pr;
        if ($ts == $hstart) {
            $cur = $pr;
        }
    }
    if ($cur === null || count($list) < 6) {
        return array('ok' => 0, 'n' => 0, 'rank' => 99, 'rankd' => 99, 'curp' => 0, 'neg' => 0, 'list' => array());
    }
    $vals = array_values($list);
    sort($vals);
    $rank = 1;
    foreach ($vals as $v) {
        if ($v < $cur) {
            $rank++;
        }
    }
    return array('ok' => 1, 'n' => count($vals), 'rank' => $rank, 'rankd' => count($vals) + 1 - $rank,
                 'curp' => $cur, 'neg' => $cur < 0 ? 1 : 0, 'list' => $debug ? $list : array());
}

/* ---------------- MQTT (LoxBerry MQTT Gateway, UDP-Relay) ---------------- */

function marstek_mqtt_publish(array $st, $dev = 1) {
    $cfg = marstek_config();
    if (empty($cfg['mqtt_enabled'])) {
        return;
    }
    $p = marstek_paths();
    if ($p['lbhome'] === '') {
        return;
    }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    $udpport = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udpport = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udpport && isset($gen['mqtt']['udpinport'])) { $udpport = (int) $gen['mqtt']['udpinport']; }
    if (!$udpport) {
        return; // MQTT-Gateway nicht konfiguriert
    }
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'marstek';
    if ((int) $dev > 1) { // Geraet 1 behaelt die kurzen Topics (Abwaertskompatibilitaet)
        $prefix .= '/' . (int) $dev;
    }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        return;
    }
    foreach (array('soc' => $st['soc'], 'batp' => $st['batp'], 'temp' => $st['temp'],
                   'gridp' => $st['gridp'], 'ok' => $st['ok'],
                   'fw' => isset($st['fw']) ? $st['fw'] : 0,
                   'ms' => isset($st['ms']) ? $st['ms'] : 0) as $k => $v) {
        $msg = 'publish ' . $prefix . '/' . $k . ' ' . $v;
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udpport);
    }
    socket_close($s);
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
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
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
