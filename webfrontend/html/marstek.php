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
 *   ?debug=1             -> Rohdaten anzeigen. SEIT 1.1.5 TOKENPFLICHTIG, aus
 *                           derselben Erwaegung wie ?diag: der Schalter umgeht
 *                           den Zwischenspeicher und erzwingt einen frischen
 *                           Abruf. Gemessen an 1.1.4 gegen ein stummes Geraet,
 *                           drei Aufrufe hintereinander: 31 s, 30 s, 30 s -
 *                           jedes Mal, waehrend ?status warm 0 s brauchte.
 *                           ?diag kostete zum Vergleich 18 s.
 *
 * ABWEISEN STATT ZURECHTBIEGEN (seit 1.1.0): p und mode werden geprueft,
 * bevor irgendetwas an das Geraet geht. Bis 1.0.16 wurde "?p=abc" zu 0 W und
 * ging als Leerlauf hinaus, "?mode=quatsch" zu Auto - ein Tippfehler in einer
 * Loxone-Adresse gab damit die Regie an den Speicher ab.
 *
 * NEU 30.09.2026 (Durchgang):
 *   GRENZEN (C7): t gilt von 30 bis 3600 s, p bis zur Leistungsgrenze des
 *     Geraets. Was ausserhalb liegt, wird weiter begrenzt - Loxone schickt
 *     laufend, eine Abweisung liesse den Speicher stehen -, aber die Antwort
 *     traegt dann BEGRENZT=1, und das Protokoll bekommt eine gebremste Zeile.
 *   ANRUFER (C8): jede Abweisung und jeder Schaltbefehl kommt gebremst mit
 *     REMOTE_ADDR ins Protokoll (eine Zeile je Anrufer, Grund und Minute).
 *   KEINE DATEN (C6): ?status und ?summe antworten mit HTTP 503 und
 *     GRUND=KEIN_SPEICHER bzw. GRUND=NIE_GEMESSEN (Regeln/07).
 *   OK NACH ALTER (C5): OK=0, sobald ALTER groesser ist als das Dreifache des
 *     Abfragetakts (Entscheidung 4); ALTER bleibt daneben.
 *   ZWISCHENSPEICHER (C9): laeuft der Minutentakt, beantwortet ?status aus
 *     dessen Zwischenspeicher, solange der hoechstens drei Takte alt ist - ein
 *     schweigender Speicher blockierte den Endpunkt bis 1.1.17 24 s lang.
 *
 * GRUPPE 1 (1.1.25, Nachtrag 19.10.2026): die Verarbeitung von ?p= und ?mode= steht in
 *   marstek_befehl_ausfuehren() (marstek_lib.php); die Befehlsbruecke ruft sie fuer marstek/befehl/...
 *   Antwortzeilen, HTTP-Codes und Protokoll des Endpunkts sind unveraendert.
 *
 * BEFEHLSBREMSE (a1 / X-7, Verbesserungsbau 30.09.2026): ?p= und ?mode= je
 *   Geraet durch marstek_gebremst(). Derselbe Befehl innerhalb von 60 s:
 *   HTTP 200 mit UNVERAENDERT=1, nichts gesendet (ein Sollwert, der nur um
 *   1 W abweicht, gilt als derselbe - der Dither der Baustein-Liste). Ein
 *   anderer innerhalb von 10 s: HTTP 429, ERR=BREMSE;WARTEN_S=n - nur mit der
 *   Einstellung bremse_abstand_ein (ab Werk aus, Entscheidung Nr. 14). Merker nicht
 *   zu oeffnen: HTTP 503, ERR=BREMSE_MERKER. Der Trockenlauf geht an der
 *   Bremse vorbei (er sendet nichts).
 *
 * SCHREIBER-WACHE (Energie-1 C1, Entscheidung Nr. 25): ?p= und ?mode= tragen
 *   optional &von=<kennung> (die Vorlage setzt von=loxone); gemerkt wird
 *   Kennung@Absender. Mehr als ein Schreiber im Fenster (ab Werk 15 min):
 *   Protokoll, Reiter Test, Antwort ;SCHREIBER=n - abgewiesen wird nichts.
 *   Nur mit "Fremde Schreiber abweisen" (ab Werk aus) bekommt ein Sollwert
 *   eines nicht erlaubten Schreibers HTTP 409 GRUND=FREMDSCHREIBER und wird
 *   nicht gesendet; ?mode= nie. Merker nicht nutzbar: der Befehl geht
 *   trotzdem, die Antwort traegt ;WACHE=MERKER. Eine ungueltige Kennung:
 *   HTTP 400 ERR=VON. &dry=1 geht an der Wache vorbei.
 */

require_once __DIR__ . '/marstek_lib.php';
header('Content-Type: text/plain; charset=utf-8');

/* Dieser Baum ist der UNANGEMELDETE. Er legt nichts an - weder die
 * Konfiguration noch die Zweitschrift, und er heilt auch nichts. Der Merker
 * gilt fuer den ganzen Prozess, weil marstek_status(), marstek_dev() und
 * marstek_devices() die Lesefunktion selbst rufen; ein Schalter an einer
 * einzelnen Aufrufstelle waere die naechste Wette darauf, dass jemand daran
 * denkt. Gemessen an 1.1.4: ein einziges ?status ohne Token stellte die
 * Konfiguration samt Aktionstoken aus der Zweitschrift wieder her. */
$GLOBALS['marstek_nur_lesen'] = true;

/** Einen Parameter als Zeichenkette holen - oder null.
 *  Erst is_string, dann alles andere: ein ?p[]=1 ist ein Feld, und (int) darauf
 *  ergibt 1 samt Warnung. */
function mv_par($name) {
    if (!isset($_GET[$name]) || !is_string($_GET[$name])) {
        return null;
    }
    return trim($_GET[$name]);
}

/** C8: antworten, gebremst mit dem Anrufer protokollieren, beenden. Das Token
 *  steht weder in der Zeile noch im Protokoll. */
function mv_abweisen($code, $zeile, $grund) {
    http_response_code($code);
    echo $zeile;
    marstek_anruf_log($grund, trim($zeile) . ' (HTTP ' . (int) $code . ')');
    exit;
}

/* Gruppe 1 (1.1.25): mv_von() und mv_wache() stehen jetzt in marstek_befehl_ausfuehren()
 * (marstek_lib.php) - dieselbe Pruefung fuer Endpunkt und Befehlsbruecke. */

// Auf den WERT sehen, nicht nur auf das Vorhandensein: ?debug=0 schaltete
// die Rohdatenansicht bis 1.1.4 ebenfalls ein.
$debug = (mv_par('debug') === '1');
$mv_devpar = mv_par('dev');
$mv_alle = ($mv_devpar !== null && strtolower($mv_devpar) === 'alle');
$dev = 1;
// ?dev=alle gilt nur fuer den Sollwert. Bis 1.1.4 lieferten ?status&dev=alle
// und ?energy&dev=alle kommentarlos Geraet 1, waehrend ?dev=7 mit ERR=DEV
// abgewiesen wurde - eine Adresse, die nicht tut, was sie sagt.
if ($mv_alle && !isset($_GET['p'])) {
    mv_abweisen(400, "FEHLER;OK=0;ERR=DEV_ALLE_NUR_BEI_P\n", 'abweisung');
}
if ($mv_devpar !== null && !$mv_alle) {
    if (!preg_match('/^[1-9]$/', $mv_devpar)) {
        mv_abweisen(400, "FEHLER;OK=0;ERR=DEV\n", 'abweisung');
    }
    $dev = (int) $mv_devpar;
    // Ein Geraet, das gar nicht eingetragen ist, ist ein Adressierungsfehler.
    // Bis 1.1.4 kam dafuer HTTP 200 mit OK=0 - dieselbe Antwort wie fuer ein
    // stummes Geraet, und in Loxone sah das aus wie ein defekter Speicher.
    if (marstek_dev($dev) === null) {
        mv_abweisen(400, 'FEHLER;OK=0;ERR=DEV_NICHT_EINGETRAGEN;DEV=' . $dev . "\n", 'abweisung');
    }
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
    // marstek_config(false): der unangemeldete Endpunkt legt NICHTS an.
    // Bis 1.1.4 stellte ein einziger Aufruf ohne Token die Konfiguration aus
    // der Zweitschrift wieder her - gemessen, mit Gegenprobe.
    $mv_cfg_st = marstek_config(false);
    $mv_soll_st = isset($mv_cfg_st['aktionstoken']) ? (string) $mv_cfg_st['aktionstoken'] : '';
    $mv_ist_st = (string) mv_par('token');
    if ($mv_soll_st === '') {
        mv_abweisen(403, "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n", 'token');
    }
    if (!hash_equals($mv_soll_st, $mv_ist_st)) {
        mv_abweisen(403, "SELFTEST;OK=0;ERR=TOKEN\n", 'token');
    }
    echo 'SELFTEST;OK=1;TOKEN=OK;DEV=' . $dev . "\n";
    exit;
}

/* ---------- Token-Pruefung fuer alles, was schaltet oder etwas kostet ----------
 *
 * p und mode schalten. diag schaltet nicht, kostet aber Zeit und schickt
 * Rundrufe - deshalb steht es seit 1.1.0 mit in dieser Liste. debug steht
 * seit 1.1.5 dabei: es umgeht den Zwischenspeicher und erzwingt einen
 * frischen Abruf, gemessen 30 s je Aufruf gegen ein stummes Geraet. */
if (isset($_GET['p']) || isset($_GET['mode']) || isset($_GET['diag'])
        || isset($_GET['debug'])) {
    $mv_cfg_tok = marstek_config(false);
    $mv_soll = isset($mv_cfg_tok['aktionstoken']) ? (string) $mv_cfg_tok['aktionstoken'] : '';
    $mv_ist = (string) mv_par('token');
    if ($mv_soll === '' || !hash_equals($mv_soll, $mv_ist)) {
        mv_abweisen(403, "SET;OK=0;ERR=TOKEN\n", 'token');
    }
}

/* ---------- Passiv-Sollwert und Modus ----------
 *
 * Gruppe 1 (1.1.25, Nachtrag 19.10.2026): die Verarbeitung von ?p= und ?mode= steht in marstek_befehl_ausfuehren()
 * (marstek_lib.php) - bis 1.1.24 stand sie hier. Dieselbe Funktion ruft die Befehlsbruecke fuer marstek/befehl/...
 * (Quelle mqtt, von=mqtt). Der Endpunkt prueft vorher wie bisher Geraet und Token (oben) und gibt danach
 * Antwortzeile, HTTP-Code und Protokollzeilen unveraendert aus; die Wirkungstests des Baus belegen Quelle = Bau. */
if (isset($_GET['p']) || isset($_GET['mode'])) {
    $mv_e = marstek_befehl_ausfuehren(isset($_GET['p']) ? 'p' : 'mode', array(
        'p' => mv_par('p'), 't' => mv_par('t'), 'mode' => mv_par('mode'), 'dev' => $dev, 'alle' => $mv_alle,
        'trocken' => $mv_trocken, 'von_da' => isset($_GET['von']), 'von' => isset($_GET['von']) ? $_GET['von'] : null), 'http');
    if ($mv_e['http'] !== 200) {
        mv_abweisen($mv_e['http'], $mv_e['zeile'], $mv_e['grund']);
    }
    echo $mv_e['zeile'];
    foreach ($mv_e['logs'] as $mv_l) {
        marstek_anruf_log($mv_l[0], $mv_l[1]);
    }
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
    echo "   Bei EINEM eingetragenen Speicher nimmt das Plugin den Rundruf als Rueckfall - jeder Abruf\n";
    echo "   versucht zuerst Unicast, der Weg wird nicht gemerkt, und eine Antwort zaehlt nur von der\n";
    echo "   eingetragenen Adresse. Bei MEHREREN Speichern gehen Schaltbefehle nie per Rundruf.\n";
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
    // C6 (Nachtrag 30.09.2026): wie ?status und ?summe - ohne Speicher oder
    // ohne je eine Messung HTTP 503 mit dem Grund (Regeln/07:484). Bis 1.1.17
    // kam HTTP 200 mit OK=0 und lauter Nullen.
    if (!marstek_devices()) {
        http_response_code(503);
        echo "ENERGY;OK=0;GRUND=KEIN_SPEICHER\n";
        exit;
    }
    $en = marstek_energy($dev, $debug);
    if (!$debug && empty($en['mess'])) {
        http_response_code(503);
        echo 'ENERGY;OK=0;GRUND=NIE_GEMESSEN;DEV=' . $dev . "\n";
        exit;
    }
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
    // C6 (30.09.2026): ohne Speicher oder ohne je eine Messung HTTP 503 mit
    // dem Grund in der Zeile (Regeln/07). Bis 1.1.17 kam HTTP 200 mit N=0.
    if ((int) $s['n'] === 0) {
        http_response_code(503);
        echo "SUMME;OK=0;GRUND=KEIN_SPEICHER\n";
        exit;
    }
    if ((int) $s['nie'] >= (int) $s['n']) {
        http_response_code(503);
        echo "SUMME;OK=0;GRUND=NIE_GEMESSEN\n";
        exit;
    }
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
// C6: ohne eingetragenen Speicher gibt es nichts zu melden - HTTP 503.
if (!marstek_devices()) {
    http_response_code(503);
    echo "MARSTEK;OK=0;GRUND=KEIN_SPEICHER\n";
    exit;
}
/* C9 (30.09.2026): laeuft der Minutentakt, kommt ?status aus seinem
 * Zwischenspeicher, solange der letzte Abrufversuch hoechstens drei Takte alt
 * ist. Bis 1.1.17 fragte der Endpunkt den Speicher selbst, sobald cache_sec
 * vorbei war, und blockierte bei einem schweigenden Speicher 24 s lang einen
 * HTTP-Eingang des Miniservers. OK und ALTER sagen, wie frisch der Stand ist
 * (C5). ?debug=1 fragt weiterhin selbst. */
$st = null;
if (!$debug && marstek_takt_laeuft()) {
    $mv_sc = marstek_tmpdir() . '/status_dev' . $dev . '.json';
    $mv_sw = is_file($mv_sc) ? json_decode((string) @file_get_contents($mv_sc), true) : null;
    if (is_array($mv_sw) && isset($mv_sw['ts']) && time() - (int) $mv_sw['ts'] <= 3 * marstek_satz_takt('status')) {
        $st = $mv_sw;
    }
}
if ($st === null) {
    $st = marstek_status($dev, $debug);
}
// C6: nie gemessen - HTTP 503 mit Grund statt OK=0 und erfundener Nullen.
if (!$debug && empty($st['mess'])) {
    http_response_code(503);
    echo 'MARSTEK;OK=0;GRUND=NIE_GEMESSEN;DEV=' . $dev . "\n";
    exit;
}
if ($debug) {
    echo 'DEBUG Geraet ' . $dev . ' (' . (isset($st['model']) ? $st['model'] : '') . ', FW '
       . (isset($st['fw']) ? $st['fw'] : 0) . ', ' . (isset($st['ms']) ? $st['ms'] : 0) . " ms)\n";
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
