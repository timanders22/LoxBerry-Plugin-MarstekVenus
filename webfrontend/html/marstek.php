<?php
/**
 * Marstek Venus E - Miniserver-Endpunkt
 *
 * Die Antwortzeilen entstehen seit 1.1.0 aus marstek_felder() - derselben
 * Quelle, aus der auch die Loxone-Vorlage und die MQTT-Themenliste gebaut
 * werden. Eine Zeile, die der Vorlage widerspricht, kann so nicht mehr
 * entstehen; welche Felder ein Satz traegt, steht dort und nur dort.
 *
 * Aufrufe (abwaertskompatibel; &dev=N waehlt bei mehreren Speichern das Geraet, Standard 1):
 *   ?status[&dev=N]      -> MARSTEK;OK=..;SOC=..;BATP=..;TEMP=..;GRIDP=..;FW=..;MS=..;
 *                           ALTER=..;ZAEHLER=..;SOLL=..;SOLLALTER=..;FBREST=..
 *   ?ranks               -> RANKS;OK=..;N=..;RANK=..;RANKD=..;CURP=..;NEG=..;MINP=..;
 *                           MAXP=..;SPREAD=..;NEXTP=..;HBIS=..;HBISMAX=..;ERRC=..
 *   ?energy[&dev=N]      -> ENERGY;OK=..;CHGT=..;...;EFF=..;ALTER=..
 *                           kWh-Zaehler direkt vom Geraet via Modbus TCP (nur lesend;
 *                           muss beim Geraet aktiviert sein)
 *   ?summe               -> SUMME;OK=..;N=..;NOK=..;SOC=..;KAPAZ=..;RESTKWH=..;BATP=..;ALTER=..
 *                           Alle Speicher zusammen, Ladezustand nach Kapazitaet
 *                           gewichtet. Fehlt bei einem die Kapazitaet oder
 *                           antwortet einer nicht, kommt -1 statt einer Teilsumme.
 *   ?p=WATT&t=SEK&token=T[&dev=N|&dev=alle][&dry=1]
 *                        -> Passiv-Modus: p>0 = LADEN, p<0 = ENTLADEN, p=0 = Leerlauf
 *                           (Loxone-Konvention; API-intern wird das Vorzeichen gedreht)
 *                           Schaltender Aufruf - erfordert das Token aus dem Reiter
 *                           "Einbindung in Loxone" (?token=...), sonst HTTP 403.
 *                           &dry=1 rechnet alles und sendet NICHTS.
 *   ?mode=auto|ai&token=T[&dev=N][&dry=1]
 *                        -> Betriebsmodus an das Geraet zurueckgeben (Handbetrieb)
 *   ?selftest=1&token=T  -> prueft NUR das Token und antwortet; ruehrt den
 *                           Speicher nicht an (kein Schalten, keine Verbindung)
 *   ?diag=1&token=T      -> Diagnose. SEIT 1.1.0 TOKENPFLICHTIG: ein Durchgang
 *                           dauert bei einem stummen Geraet gemessene 24 Sekunden
 *                           und schickt Rundrufe ins Netz. Ein Endpunkt, den eine
 *                           fremde Webseite lahmlegen kann, gehoert nicht an den
 *                           Miniserver - dieselbe Erwaegung, aus der cron.php
 *                           2026 aus dem HTML-Verzeichnis umgezogen ist.
 *   ?debug=1             -> Rohdaten anzeigen
 *
 * ABWEISEN STATT ZURECHTBIEGEN (seit 1.1.0): p und mode werden geprueft,
 * bevor irgendetwas an das Geraet geht. Bis 1.0.16 wurde "?p=abc" zu 0 W und
 * ging als Leerlauf hinaus, "?mode=quatsch" zu Auto - ein Tippfehler in einer
 * Loxone-Adresse gab damit die Regie an den Speicher ab.
 */

require_once __DIR__ . '/marstek_lib.php';
header('Content-Type: text/plain; charset=utf-8');

/** Einen Parameter als Zeichenkette holen - oder null.
 *  Erst is_string, dann alles andere: ein ?p[]=1 ist ein Feld, und (int) darauf
 *  ergibt 1 samt Warnung. */
function mv_par($name) {
    if (!isset($_GET[$name]) || !is_string($_GET[$name])) {
        return null;
    }
    return trim($_GET[$name]);
}

$debug = isset($_GET['debug']);
$mv_devpar = mv_par('dev');
$mv_alle = ($mv_devpar !== null && strtolower($mv_devpar) === 'alle');
$dev = 1;
if ($mv_devpar !== null && !$mv_alle) {
    if (!preg_match('/^[1-9]$/', $mv_devpar)) {
        http_response_code(400);
        echo "FEHLER;OK=0;ERR=DEV\n";
        exit;
    }
    $dev = (int) $mv_devpar;
}
$mv_trocken = (mv_par('dry') === '1');

/* ---------- Selbsttest: Token pruefen, ohne etwas zu schalten ----------
 *
 * WOZU
 * Ob das in Loxone eingetragene Token noch stimmt, liess sich frueher nur
 * herausfinden, indem man wirklich schaltete - beim Speicher also den
 * Betriebsmodus umstellte. Wer nur nachsehen wollte, musste am Geraet etwas
 * veraendern. Das ist der falsche Preis fuer eine Auskunft.
 *
 * ?selftest=1&token=... antwortet daher genau wie die schaltenden Befehle,
 * ruehrt den Speicher aber nicht an: keine Verbindung zum Geraet, kein
 * Schreibzugriff, kein Protokolleintrag mit Wirkung.
 *
 * Antwort: SELFTEST;OK=1;TOKEN=OK bzw. HTTP 403 und SELFTEST;OK=0;ERR=TOKEN
 */
if (isset($_GET['selftest'])) {
    $mv_cfg_st = marstek_config();
    $mv_soll_st = isset($mv_cfg_st['aktionstoken']) ? (string) $mv_cfg_st['aktionstoken'] : '';
    $mv_ist_st = (string) mv_par('token');
    if ($mv_soll_st === '') {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    if (!hash_equals($mv_soll_st, $mv_ist_st)) {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    echo 'SELFTEST;OK=1;TOKEN=OK;DEV=' . $dev . "\n";
    exit;
}

/* ---------- Token-Pruefung fuer alles, was schaltet oder etwas kostet ----------
 *
 * p und mode schalten. diag schaltet nicht, kostet aber Zeit und schickt
 * Rundrufe - deshalb steht es seit 1.1.0 mit in dieser Liste. */
if (isset($_GET['p']) || isset($_GET['mode']) || isset($_GET['diag'])) {
    $mv_cfg_tok = marstek_config();
    $mv_soll = isset($mv_cfg_tok['aktionstoken']) ? (string) $mv_cfg_tok['aktionstoken'] : '';
    $mv_ist = (string) mv_par('token');
    if ($mv_soll === '' || !hash_equals($mv_soll, $mv_ist)) {
        http_response_code(403);
        echo "SET;OK=0;ERR=TOKEN\n";
        exit;
    }
}

/* ---------- Passiv-Sollwert ---------- */
if (isset($_GET['p'])) {
    $mv_p = mv_par('p');
    // Was nicht ins Muster passt, wird gemeldet - nicht zurechtgebogen.
    if ($mv_p === null || !preg_match('/^-?\d{1,6}$/', $mv_p)) {
        http_response_code(400);
        echo "SET;OK=0;ERR=P\n";
        exit;
    }
    $mv_t = mv_par('t');
    if ($mv_t !== null && !preg_match('/^\d{1,5}$/', $mv_t)) {
        http_response_code(400);
        echo "SET;OK=0;ERR=T\n";
        exit;
    }
    if ($mv_t === null) { $mv_t = '240'; }

    if ($mv_alle) {
        $mv_cfg_v = marstek_config();
        if (empty($mv_cfg_v['verteilen_ein'])) {
            http_response_code(400);
            echo "SET;OK=0;ERR=VERTEILEN_AUS\n";
            exit;
        }
        list($ok, $angenommen, $gesamt, $zeilen) = marstek_set_passive_alle($mv_p, $mv_t, $mv_trocken);
        $txt = '';
        foreach ($zeilen as $z) {
            $txt .= ';P' . $z['n'] . '=' . $z['p'] . ';OK' . $z['n'] . '=' . $z['ok'];
        }
        echo 'SET;OK=' . $ok . ';N=' . $angenommen . ';GES=' . $gesamt . ';DEV=alle'
           . ($mv_trocken ? ';DRY=1' : '') . $txt . "\n";
        exit;
    }
    list($ok, $p, $t, $hinweis) = marstek_set_passive($mv_p, $mv_t, $dev, $mv_trocken);
    echo 'SET;OK=' . $ok . ';P=' . $p . ';T=' . $t . ';DEV=' . $dev
       . ($hinweis !== '' ? ';HINWEIS=' . $hinweis : '') . "\n";
    exit;
}

/* ---------- Modus zurueckgeben ---------- */
if (isset($_GET['mode'])) {
    $mv_m = mv_par('mode');
    if ($mv_m === null) {
        http_response_code(400);
        echo "MODE;OK=0;ERR=MODE\n";
        exit;
    }
    list($ok, $m, $hinweis) = marstek_set_mode($mv_m, $dev, $mv_trocken);
    if ($hinweis === 'MODE') {
        http_response_code(400);
        echo "MODE;OK=0;ERR=MODE\n";
        exit;
    }
    echo 'MODE;OK=' . $ok . ';M=' . $m . ';DEV=' . $dev
       . ($hinweis !== '' ? ';HINWEIS=' . $hinweis : '') . "\n";
    exit;
}

/* ---------- Diagnose (tokenpflichtig, siehe oben) ---------- */
if (isset($_GET['diag'])) {
    echo "MARSTEK-DIAGNOSE\n================\n";
    foreach (marstek_diag($dev) as $line) {
        echo $line . "\n";
    }
    echo "\nHinweise:\n";
    echo "1. Antwortet der RUNDRUF, aber Unicast nicht (typisch fuer Venus E 3.0 mit FW 148):\n";
    echo "   Alles gut - das Plugin nutzt automatisch den Rundruf und merkt sich das.\n";
    echo "   (Nur relevant bei MEHREREN Venus-Geraeten im selben Netz: Rundruf-Befehle erreichen alle.)\n";
    echo "2. Antwortet GAR NICHTS auf UDP: Lokale API in der Marstek-App wirklich AKTIVIEREN\n";
    echo "   (Geraet -> Einstellungen -> Lokaler Modus / Open API -> Schalter einmal AUS und wieder EIN),\n";
    echo "   Firmware aktualisieren, oder die API per Bluetooth-Tool aktivieren:\n";
    echo "   https://rweijnen.github.io/marstek-venus-monitor/\n";
    echo "3. Zwei IP-Adressen? Haengt das Geraet an LAN UND WLAN, hat es zwei IPs (Router-Geraeteliste\n";
    echo "   pruefen) - die lokale API lauscht ggf. nur auf einer davon.\n";
    echo "4. Fehlt die PHP-Erweiterung sockets, faellt NUR der Rundruf aus. Unicast, Modbus und MQTT\n";
    echo "   laufen weiter; die Geraetesuche im Reiter Test steht dann nicht zur Verfuegung.\n";
    exit;
}

/* ---------- Energiezaehler (Modbus TCP, nur lesend) ---------- */
if (isset($_GET['energy'])) {
    $en = marstek_energy($dev, $debug);
    if ($debug) {
        $rawdat = marstek_tmpdir() . '/energy_raw_dev' . $dev . '.json';
        $raw = is_file($rawdat) ? json_decode((string) @file_get_contents($rawdat), true) : null;
        echo 'DEBUG Rohregister (vom letzten Lesevorgang, ' . (is_array($raw) && isset($raw['ts']) ? date('H:i:s', $raw['ts']) : '-') . "):\n";
        echo 'DEBUG Modbus 33000..33011: ' . json_encode(is_array($raw) ? $raw['regs_33000'] : null) . "\n";
        echo 'DEBUG Modbus 34002..34003: ' . json_encode(is_array($raw) ? $raw['regs_34002'] : null) . "\n";
        $b = marstek_bilanz_summe($dev, 'monat');
        echo 'DEBUG Tagesbilanz diesen Monat: ' . $b['tage'] . ' Tage, geladen ' . $b['chg']
           . ' kWh, abgegeben ' . $b['dis'] . " kWh\n\n";
    }
    echo marstek_zeile('energy', $en, $dev);
    exit;
}

/* ---------- Summe ueber alle Speicher ---------- */
if (isset($_GET['summe'])) {
    $s = marstek_summe();
    if ($debug) {
        foreach (marstek_devices() as $n => $d) {
            $sdat = marstek_tmpdir() . '/status_dev' . $n . '.json';
            $st = is_file($sdat) ? json_decode((string) @file_get_contents($sdat), true) : null;
            printf("DEBUG Geraet %d %s: ok=%s soc=%s kWh=%s\n", $n, $d['name'],
                is_array($st) ? (int) $st['ok'] : '-', is_array($st) ? $st['soc'] : '-',
                $d['kwh'] > 0 ? $d['kwh'] : 'nicht eingetragen');
        }
        echo "\n";
    }
    echo marstek_zeile('summe', $s, 1);
    exit;
}

/* ---------- Spot-Ranking ---------- */
if (isset($_GET['ranks'])) {
    $r = marstek_ranks($debug);
    if ($debug) {
        echo 'DEBUG Grund: ' . marstek_ranks_grund($r['errc']) . "\n";
        if ($r['list']) {
            foreach ($r['list'] as $ts => $pr) {
                printf("%s: %.4f EUR/kWh\n", date('d.m H:i', $ts), $pr);
            }
        }
        echo "\n";
    }
    echo marstek_zeile('ranks', $r, 1);
    exit;
}

/* ---------- Status (Default) ---------- */
$st = marstek_status($dev, $debug);
if ($debug) {
    echo 'DEBUG Geraet ' . $dev . ' (' . $st['model'] . ', FW ' . $st['fw'] . ', ' . $st['ms'] . " ms)\n";
    echo 'DEBUG letzte echte Messung: '
       . (!empty($st['mess']) ? date('d.m.Y H:i:s', $st['mess']) . ' (' . (time() - (int) $st['mess']) . ' s her)' : 'nie')
       . "\n";
    $h = marstek_herzstand();
    echo 'DEBUG Minutentakt: ' . ($h['ts'] > 0 ? date('d.m.Y H:i:s', $h['ts']) . ' (Zaehler ' . $h['zaehler'] . ')' : 'noch nie gelaufen') . "\n";
    echo 'DEBUG ES.GetStatus: ' . json_encode(marstek_rpc('ES.GetStatus', null, $dev)) . "\n";
    echo 'DEBUG Bat.GetStatus: ' . json_encode(marstek_rpc('Bat.GetStatus', null, $dev)) . "\n";
    echo 'DEBUG Marstek.GetDevice: ' . json_encode(marstek_devinfo($dev, true)) . "\n\n";
}
echo marstek_zeile('status', $st, $dev);
