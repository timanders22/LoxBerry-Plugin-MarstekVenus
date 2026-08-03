<?php
/**
 * Marstek Venus E - Miniserver-Endpunkt
 *
 * Aufrufe (abwaertskompatibel; &dev=N waehlt bei mehreren Speichern das Geraet, Standard 1):
 *   ?status[&dev=N]      -> MARSTEK;OK=..;SOC=..;BATP=..;TEMP=..;GRIDP=..;FW=..;MS=..
 *                           BATP: + = laedt, - = entlaedt (Watt); FW = Firmware-Version;
 *                           MS = Antwortzeit des Geraets in Millisekunden
 *   ?ranks               -> RANKS;OK=..;N=..;RANK=..;RANKD=..;CURP=..;NEG=..
 *                           Rang der AKTUELLEN Stunde unter den naechsten 24 h
 *                           (RANK 1 = guenstigste, RANKD 1 = teuerste; aWATTar inkl. USt)
 *   ?p=WATT&t=SEK&token=T[&dev=N]-> Passiv-Modus: p>0 = LADEN, p<0 = ENTLADEN, p=0 = Leerlauf
 *                           (Loxone-Konvention; API-intern wird das Vorzeichen gedreht)
 *                           Schaltender Aufruf - erfordert das Token aus dem Reiter
 *                           "Einbindung in Loxone" (?token=...), sonst HTTP 403.
 *   ?mode=auto|ai&token=T[&dev=N]-> Betriebsmodus an das Geraet zurueckgeben (Handbetrieb)
 *                           Ebenfalls token-pflichtig.
 *   ?energy[&dev=N]      -> ENERGY;OK=..;CHGT=..;DIST=..;CHGD=..;DISD=..;CHGM=..;DISM=..;CYC=..;EFF=..
 *                           kWh-Zaehler direkt vom Geraet via Modbus TCP (nur lesend;
 *                           muss beim Geraet aktiviert sein): gesamt/Tag/Monat geladen
 *                           + abgegeben, Zyklen, Wirkungsgrad gesamt in %
 *   ?debug=1             -> Rohdaten anzeigen
 */

require_once __DIR__ . '/marstek_lib.php';
header('Content-Type: text/plain; charset=utf-8');
$debug = isset($_GET['debug']);
$dev = isset($_GET['dev']) ? max(1, min(9, (int) $_GET['dev'])) : 1;

/* ---------- Token-Pruefung fuer schaltende Befehle (p, mode) ---------- */
if (isset($_GET['p']) || isset($_GET['mode'])) {
    $mv_cfg_tok = marstek_config();
    $mv_soll = isset($mv_cfg_tok['aktionstoken']) ? (string) $mv_cfg_tok['aktionstoken'] : '';
    $mv_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if ($mv_soll === '' || !hash_equals($mv_soll, $mv_ist)) {
        http_response_code(403);
        echo "SET;OK=0;ERR=TOKEN\n";
        exit;
    }
}

/* ---------- Passiv-Sollwert ---------- */
if (isset($_GET['p'])) {
    list($ok, $p, $t) = marstek_set_passive($_GET['p'], isset($_GET['t']) ? $_GET['t'] : 240, $dev);
    echo "SET;OK=$ok;P=$p;T=$t;DEV=$dev\n";
    exit;
}

/* ---------- Modus zurueckgeben ---------- */
if (isset($_GET['mode'])) {
    list($ok, $m) = marstek_set_mode($_GET['mode'], $dev);
    echo "MODE;OK=$ok;M=$m;DEV=$dev\n";
    exit;
}

/* ---------- Diagnose (Selbsttest) ---------- */
if (isset($_GET['diag'])) {
    echo "MARSTEK-DIAGNOSE\n================\n";
    foreach (marstek_diag($dev) as $line) {
        echo $line . "\n";
    }
    echo "\nHinweise:\n";
    echo "1. Antwortet der BROADCAST-Test, aber Unicast nicht (typisch fuer Venus E 3.0 mit FW 148):\n";
    echo "   Alles gut - das Plugin nutzt automatisch den Broadcast-Weg und merkt sich das.\n";
    echo "   (Nur relevant bei MEHREREN Venus-Geraeten im selben Netz: Broadcast-Befehle erreichen alle Geraete.)\n";
    echo "2. Antwortet GAR NICHTS auf UDP: Lokale API in der Marstek-App wirklich AKTIVIEREN\n";
    echo "   (Geraet -> Einstellungen -> Lokaler Modus / Open API -> Schalter einmal AUS und wieder EIN),\n";
    echo "   Firmware aktualisieren, oder die API per Bluetooth-Tool aktivieren:\n";
    echo "   https://rweijnen.github.io/marstek-venus-monitor/\n";
    echo "3. Zwei IP-Adressen? Haengt das Geraet an LAN UND WLAN, hat es zwei IPs (Router-Geraeteliste\n";
    echo "   pruefen) - die lokale API lauscht ggf. nur auf einer davon.\n";
    exit;
}

/* ---------- Energiezaehler (Modbus TCP, nur lesend) ---------- */
if (isset($_GET['energy'])) {
    $en = marstek_energy($dev, $debug);
    if ($debug) {
        $raw = @json_decode((string) @file_get_contents(marstek_tmpdir() . '/energy_raw_dev' . $dev . '.json'), true);
        echo 'DEBUG Rohregister (vom letzten Lesevorgang, ' . (is_array($raw) && isset($raw['ts']) ? date('H:i:s', $raw['ts']) : '-') . "):\n";
        echo 'DEBUG Modbus 33000..33011: ' . json_encode(is_array($raw) ? $raw['regs_33000'] : null) . "\n";
        echo 'DEBUG Modbus 34002..34003: ' . json_encode(is_array($raw) ? $raw['regs_34002'] : null) . "\n\n";
    }
    printf("ENERGY;OK=%d;CHGT=%.2f;DIST=%.2f;CHGD=%.2f;DISD=%.2f;CHGM=%.2f;DISM=%.2f;CYC=%d;EFF=%.1f\n",
        $en['ok'], $en['chgt'], $en['dist'], $en['chgd'], $en['disd'], $en['chgm'], $en['dism'], $en['cyc'], $en['eff']);
    exit;
}

/* ---------- Spot-Ranking ---------- */
if (isset($_GET['ranks'])) {
    $r = marstek_ranks($debug);
    if ($debug && $r['list']) {
        foreach ($r['list'] as $ts => $pr) {
            printf("%s: %.4f EUR/kWh\n", date('d.m H:i', $ts), $pr);
        }
        echo "\n";
    }
    printf("RANKS;OK=%d;N=%d;RANK=%d;RANKD=%d;CURP=%.5f;NEG=%d\n",
        $r['ok'], $r['n'], $r['rank'], $r['rankd'], $r['curp'], $r['neg']);
    exit;
}

/* ---------- Status (Default) ---------- */
$st = marstek_status($dev, $debug);
if ($debug) {
    echo 'DEBUG Geraet ' . $dev . ' (' . $st['model'] . ', FW ' . $st['fw'] . ', ' . $st['ms'] . " ms)\n";
    echo 'DEBUG ES.GetStatus: ' . json_encode(marstek_rpc('ES.GetStatus', null, $dev)) . "\n";
    echo 'DEBUG Bat.GetStatus: ' . json_encode(marstek_rpc('Bat.GetStatus', null, $dev)) . "\n";
    echo 'DEBUG Marstek.GetDevice: ' . json_encode(marstek_devinfo($dev, true)) . "\n\n";
}
printf("MARSTEK;OK=%d;SOC=%.1f;BATP=%d;TEMP=%.1f;GRIDP=%d;FW=%d;MS=%d\n",
    $st['ok'], $st['soc'], $st['batp'], $st['temp'], $st['gridp'], $st['fw'], $st['ms']);
