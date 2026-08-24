<?php
/**
 * Marstek Venus E - Admin-Oberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * ZWEI BAUFEHLER AUS 1.0.16, DIE HIER BEHOBEN SIND:
 *
 * 1. lb_wurzel_ermitteln() wurde in Zeile 13 aufgerufen und erst in Zeile 236
 *    definiert - innerhalb eines if, und eine bedingt deklarierte Funktion
 *    zieht PHP nicht vor. Gemessen unter 7.4.33 und 8.4.24, jeweils ohne
 *    gesetztes LBHOMEDIR: "Fatal error: Call to undefined function". Der
 *    Rueckfall, der genau fuer diesen Fall geschrieben wurde, konnte nie
 *    greifen. Die Definition steht jetzt ganz oben.
 * 2. Kein Formular trug ein Merkmal gegen fremde Absender. Jetzt tragen alle
 *    eines, und EIN Wachposten prueft es, bevor irgendein Handler laeuft.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt.
 *
 * DIESE DEFINITION MUSS VOR DEM ERSTEN AUFRUF STEHEN. Siehe Kopf.
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

$mv_lbhomedir = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
$mv_plugindir = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
if ($mv_lbhomedir && is_dir($mv_lbhomedir . '/config/plugins/' . $mv_plugindir) === false) {
    $mv_plugindir = basename(dirname(__DIR__));
    if (is_dir($mv_lbhomedir . '/config/plugins/' . $mv_plugindir) === false) {
        $mv_plugindir = 'marstekvenus';
    }
}
if ($mv_lbhomedir) {
    $sdk_system = $mv_lbhomedir . '/libs/phplib/loxberry_system.php';
    $sdk_web = $mv_lbhomedir . '/libs/phplib/loxberry_web.php';
    if (file_exists($sdk_system)) {
        require_once $sdk_system;
        require_once $sdk_web;
    }
    $mv_log_file = $mv_lbhomedir . '/log/plugins/' . $mv_plugindir . '/marstek.log';
    $mv_err_file = $mv_lbhomedir . '/log/plugins/' . $mv_plugindir . '/cron.err';
} else {
    $mv_log_file = sys_get_temp_dir() . '/marstekvenus/marstek.log';
    $mv_err_file = sys_get_temp_dir() . '/marstekvenus/cron.err';
}

// Bibliothek einbinden (installiert unter .../html/plugins/<plugin>/, im Archiv unter ../html/).
// Kandidatenliste statt einer gerechneten Zahl ".." - das ist der Fehler, an
// dem Intercom und Heimkino gescheitert sind.
foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $mv_plugindir . '/marstek_lib.php',
    dirname(__DIR__) . '/html/marstek_lib.php',
) as $libcand) {
    if (is_file($libcand)) {
        require_once $libcand;
        break;
    }
}
if (!function_exists('marstek_config')) {
    echo '<p style="font-family:sans-serif;color:#b00">marstek_lib.php wurde nicht gefunden - '
       . 'das Plugin ist unvollstaendig installiert.</p>';
    exit;
}

// Die Selbstpruefung des Reiters Test liegt in einer eigenen Datei. Zwei
// Dateien, ein Prozess: keine gleichnamigen Funktionen.
$mv_testdatei = __DIR__ . '/mv_test.php';
if (is_file($mv_testdatei)) {
    require_once $mv_testdatei;
}

$mv_saved = false;
$mv_save_error = '';       // blockiert das Speichern
$mv_beanstandung = array();// wird gemeldet, blockiert aber NICHT
$mv_meldung = '';
$mv_post = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST');

/* ---------- EIN Wachposten fuer alle Formulare ----------
 *
 * Vor jedem Handler, nicht in jedem Handler. Eine Abfrage je Knopf haette
 * man beim naechsten Knopf vergessen.
 */
if ($mv_post && !marstek_formtoken_ok()) {
    $mv_post = false;
    $mv_save_error = marstek_t('MELD.FREMDES_FORMULAR');
}

/* ---------- Welcher Reiter ist offen ----------
 *
 * Die Positivliste steht AUSGESCHRIEBEN. hausstandard_pruefen.py sucht sie
 * als Literal; eine gerechnete Liste macht die Pruefung blind, und ein
 * Strich sammelt sich beim Ueberfliegen wie ein Haken ein.
 */
$mv_reiter_liste = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$mv_active_tab = 'tab-settings';
if (isset($_POST['activetab']) && is_string($_POST['activetab'])
    && in_array($_POST['activetab'], $mv_reiter_liste, true)) {
    $mv_active_tab = $_POST['activetab'];
} elseif (isset($_GET['tab']) && is_string($_GET['tab'])
          && in_array('tab-' . $_GET['tab'], $mv_reiter_liste, true)) {
    $mv_active_tab = 'tab-' . $_GET['tab'];
}

/* ---------- Loxone-Vorlagen herunterladen ---------- */
if ($mv_post && isset($_POST['vorlage_paket'])) {
    $paket = marstek_vorlagen_paket();
    if ($paket !== null) {
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="' . $paket[0] . '"');
        echo $paket[1];
        exit;
    }
    $mv_save_error = marstek_t('MELD.KEIN_ZIP');
    $mv_active_tab = 'tab-loxone';
}
if ($mv_post && isset($_POST['vorlage_vo'])) {
    list($mv_vname, $mv_vinhalt) = marstek_vo_vorlage(isset($_POST['vorlage_dev']) ? (int) $_POST['vorlage_dev'] : 1);
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $mv_vname . '"');
    echo $mv_vinhalt;
    exit;
}
if ($mv_post && isset($_POST['vorlage'])) {
    list($mv_vname, $mv_vinhalt) = marstek_vorlage(
        is_string($_POST['vorlage']) ? $_POST['vorlage'] : 'status',
        isset($_POST['vorlage_dev']) ? (int) $_POST['vorlage_dev'] : 1);
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $mv_vname . '"');
    echo $mv_vinhalt;
    exit;
}

/* ---------- Konfiguration sichern und zurueckspielen ----------
 *
 * Die Datei traegt das Aktionstoken. Das ist Absicht: eine Sicherung ohne
 * Token waere nach dem Zurueckspielen wertlos, weil alle Adressen im
 * Miniserver ungueltig wuerden.
 */
if ($mv_post && isset($_POST['konfig_export'])) {
    $cfg = marstek_config();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="marstekvenus_' . date('Ymd_His') . '.json"');
    echo json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($mv_post && isset($_POST['konfig_import'])) {
    // Abgewiesen wird, was nicht passt - nie zurechtgebogen.
    if (!isset($_FILES['konfigdatei']) || !is_uploaded_file($_FILES['konfigdatei']['tmp_name'])) {
        $mv_save_error = marstek_t('MELD.IMPORT_KEINE_DATEI');
    } else {
        $roh = (string) @file_get_contents($_FILES['konfigdatei']['tmp_name']);
        $neu = json_decode($roh, true);
        if (!is_array($neu)) {
            $mv_save_error = marstek_t('MELD.IMPORT_KEIN_JSON');
        } elseif (!array_key_exists('devices', $neu)) {
            $mv_save_error = marstek_t('MELD.IMPORT_FREMD');
        } else {
            $vollstaendig = $neu + marstek_vorgaben();
            if (marstek_cfg_schreiben($vollstaendig)) {
                $mv_saved = true;
                $mv_meldung = marstek_t('MELD.IMPORT_OK');
                marstek_log('Konfiguration aus einer hochgeladenen Datei zurueckgespielt.');
            } else {
                $mv_save_error = marstek_t('MELD.SPEICHERN_FEHLGESCHLAGEN');
            }
        }
    }
    $mv_active_tab = 'tab-settings';
}

/* ---------- Log leeren ---------- */
if ($mv_post && isset($_POST['clearlog'])) {
    if (!is_dir(dirname($mv_log_file))) { @mkdir(dirname($mv_log_file), 0775, true); }
    @file_put_contents($mv_log_file, '[' . date('Y-m-d H:i:s') . '] '
        . marstek_t('LOG.GELEERT') . "\n");
    $mv_active_tab = 'tab-log';
}

/* ---------- Neues Aktionstoken erzeugen ---------- */
if ($mv_post && isset($_POST['token_neu'])) {
    $cfg = marstek_config();
    $cfg['aktionstoken'] = marstek_token_erzeugen();
    if (marstek_cfg_schreiben($cfg)) {
        $mv_meldung = marstek_t('MELD.TOKEN_NEU');
        marstek_log('Neues Aktionstoken erzeugt (Oberflaeche).');
    } else {
        $mv_save_error = marstek_t('MELD.SPEICHERN_FEHLGESCHLAGEN');
    }
    $mv_active_tab = 'tab-loxone';
}

/* ---------- Geraetesuche ---------- */
$mv_suchergebnis = null;
$mv_suchmeldung = '';
if ($mv_post && isset($_POST['suchen'])) {
    list($mv_suchergebnis, $mv_suchmeldung) = marstek_suche(3);
    $mv_active_tab = 'tab-test';
}
if ($mv_post && isset($_POST['uebernehmen']) && is_string($_POST['uebernehmen'])) {
    $ip = trim($_POST['uebernehmen']);
    if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)) {
        $cfg = marstek_config();
        $schon = false;
        foreach ((array) $cfg['devices'] as $d) {
            if (is_array($d) && isset($d['ip']) && $d['ip'] === $ip) { $schon = true; }
        }
        if ($schon) {
            $mv_meldung = sprintf(marstek_t('MELD.SCHON_EINGETRAGEN'), marstek_e($ip));
        } elseif (count((array) $cfg['devices']) >= 4) {
            $mv_save_error = marstek_t('MELD.VIER_SCHON_VOLL');
        } else {
            $cfg['devices'][] = array('name' => 'Venus E', 'ip' => $ip, 'port' => 30000,
                'pmax_charge' => 2500, 'pmax_discharge' => 2500, 'modbus' => 1, 'kwh' => 0);
            if (marstek_cfg_schreiben($cfg)) {
                $mv_saved = true;
                $mv_meldung = sprintf(marstek_t('MELD.UEBERNOMMEN'), marstek_e($ip));
            } else {
                $mv_save_error = marstek_t('MELD.SPEICHERN_FEHLGESCHLAGEN');
            }
        }
    }
    $mv_active_tab = 'tab-test';
}

/* ---------- Mitschnitt ein- und ausschalten ----------
 *
 * Er schaltet sich selbst ab. Ein Mitschnitt, der stehenbleibt, schreibt
 * unbemerkt weiter - deshalb eine Frist und eine Zeile im Reiter Test, die
 * daran erinnert.
 */
if ($mv_post && isset($_POST['mitschnitt'])) {
    $sek = is_string($_POST['mitschnitt']) ? (int) $_POST['mitschnitt'] : 0;
    $bis = marstek_mitschnitt_schalten($sek);
    $mv_meldung = $bis > 0
        ? sprintf(marstek_t('MELD.MITSCHNITT_AN'), date('H:i:s', $bis))
        : marstek_t('MELD.MITSCHNITT_AUS');
    $mv_active_tab = 'tab-test';
}

/* ---------- Verlauf als CSV ---------- */
if ($mv_post && isset($_POST['verlauf_csv'])) {
    $n = isset($_POST['verlauf_dev']) ? max(1, (int) $_POST['verlauf_dev']) : 1;
    $tag = isset($_POST['verlauf_tag']) && is_string($_POST['verlauf_tag'])
         ? preg_replace('/\D/', '', $_POST['verlauf_tag']) : date('Ymd');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="marstek_verlauf_' . $n . '_' . $tag . '.csv"');
    echo "zeit;soc_prozent;batterieleistung_w\r\n";
    foreach (marstek_history_read($n, $tag) as $p) {
        echo date('Y-m-d H:i:s', $p[0]) . ';' . $p[1] . ';' . $p[2] . "\r\n";
    }
    exit;
}

/* ---------- MQTT speichern (eigener Reiter, Hausstandard) ---------- */
if ($mv_post && isset($_POST['mqtt_save'])) {
    $cfg = marstek_config();
    $cfg['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $topic = isset($_POST['mqtt_topic']) && is_string($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'marstek';
    $cfg['mqtt_topic'] = preg_replace('#[^\w/\-]#', '', $topic) ?: 'marstek';
    if (marstek_cfg_schreiben($cfg)) {
        $mv_saved = true;
    } else {
        $mv_save_error = marstek_t('MELD.SPEICHERN_FEHLGESCHLAGEN');
    }
    $mv_active_tab = 'tab-mqtt';
}

/* ---------- Einstellungen speichern ----------
 *
 * BERICHTIGT 24.08.2026: eine krumme Zeile verwirft nicht mehr das ganze
 * Formular. Gemessen an 1.0.16: eine unvollstaendige IP in Zeile 2 hat
 * Geraetename, Status-Cache, Auto-Fallback und Markt gleich mit verworfen -
 * der Anwender tippt dann alles noch einmal, wegen einer Zeile, die er
 * womoeglich gar nicht ausfuellen wollte.
 *
 * Jetzt: die betroffene Zeile wird uebergangen, alles Uebrige gespeichert,
 * und die Beanstandung erscheint daneben in einem eigenen Kasten.
 */
if ($mv_post && isset($_POST['save'])) {
    $alt = marstek_config();
    $cfg = $alt;                       // Bestand uebernehmen, dann ueberschreiben.
    $cfg['devices'] = array();
    $names = isset($_POST['dev_name']) ? (array) $_POST['dev_name'] : array();
    $ips   = isset($_POST['dev_ip'])   ? (array) $_POST['dev_ip']   : array();
    $ports = isset($_POST['dev_port']) ? (array) $_POST['dev_port'] : array();
    $pcs   = isset($_POST['dev_pc'])   ? (array) $_POST['dev_pc']   : array();
    $pds   = isset($_POST['dev_pd'])   ? (array) $_POST['dev_pd']   : array();
    $mbs   = isset($_POST['dev_mb'])   ? (array) $_POST['dev_mb']   : array();
    $kwhs  = isset($_POST['dev_kwh'])  ? (array) $_POST['dev_kwh']  : array();
    for ($i = 0; $i < 4; $i++) {
        $ip = isset($ips[$i]) && is_string($ips[$i]) ? trim($ips[$i]) : '';
        if ($ip === '') {
            continue;
        }
        // Jedes Feld einzeln pruefen - 999.999.999.999 passte bis 1.0.16
        // durch, weil nur die Form geprueft wurde, nicht der Wertebereich.
        $teile = explode('.', $ip);
        $gut = count($teile) === 4;
        foreach ($teile as $t) {
            if (!preg_match('/^\d{1,3}$/', $t) || (int) $t > 255) { $gut = false; }
        }
        if (!$gut) {
            $mv_beanstandung[] = sprintf(marstek_t('MELD.IP_UNGUELTIG'), $i + 1, marstek_e($ip));
            continue;
        }
        $kwh = isset($kwhs[$i]) && is_string($kwhs[$i]) ? str_replace(',', '.', trim($kwhs[$i])) : '';
        $cfg['devices'][] = array(
            'name' => isset($names[$i]) && is_string($names[$i]) ? trim($names[$i]) : '',
            'ip' => $ip,
            'port' => max(1, min(65535, (int) (isset($ports[$i]) ? $ports[$i] : 30000))),
            'pmax_charge' => max(100, min(3600, (int) (isset($pcs[$i]) ? $pcs[$i] : 2500))),
            'pmax_discharge' => max(100, min(3600, (int) (isset($pds[$i]) ? $pds[$i] : 2500))),
            'modbus' => (isset($mbs[$i]) && $mbs[$i] === '1') ? 1 : 0,
            // Leer bleibt leer. Ein Vorgabewert waere eine Annahme, und der
            // gewichtete Gesamt-Ladezustand haengt daran.
            'kwh' => (is_numeric($kwh) && (float) $kwh > 0) ? round((float) $kwh, 2) : 0,
        );
    }
    $cfg['cache_sec'] = max(5, min(300, (int) (isset($_POST['cache_sec']) ? $_POST['cache_sec'] : 40)));
    $cfg['awattar'] = (isset($_POST['awattar']) && $_POST['awattar'] === 'at') ? 'at' : 'de';
    $vat = isset($_POST['vat']) && is_string($_POST['vat']) ? str_replace(',', '.', trim($_POST['vat'])) : '1.19';
    if (is_numeric($vat) && $vat > 0.5 && $vat < 2) {
        $cfg['vat'] = (float) $vat;
    } else {
        $mv_beanstandung[] = sprintf(marstek_t('MELD.UST_UNGUELTIG'), marstek_e($vat));
    }
    $auf = isset($_POST['aufschlag_ct']) && is_string($_POST['aufschlag_ct'])
         ? str_replace(',', '.', trim($_POST['aufschlag_ct'])) : '0';
    if (is_numeric($auf) && $auf >= -50 && $auf <= 100) {
        $cfg['aufschlag_ct'] = round((float) $auf, 3);
    } else {
        $mv_beanstandung[] = sprintf(marstek_t('MELD.AUFSCHLAG_UNGUELTIG'), marstek_e($auf));
    }
    $cfg['fallback_min'] = max(0, min(1440, (int) (isset($_POST['fallback_min']) ? $_POST['fallback_min'] : 0)));
    $cfg['verlauf_tage'] = max(1, min(365, (int) (isset($_POST['verlauf_tage']) ? $_POST['verlauf_tage'] : 8)));
    $cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;
    $cfg['verteilen_ein'] = isset($_POST['verteilen_ein']) ? 1 : 0;
    $cfg['melden_ein'] = isset($_POST['melden_ein']) ? 1 : 0;
    $cfg['melden_ab'] = max(1, min(20, (int) (isset($_POST['melden_ab']) ? $_POST['melden_ab'] : 3)));
    $cfg['schutz_ein'] = isset($_POST['schutz_ein']) ? 1 : 0;
    $cfg['temp_min'] = max(-20, min(20, (int) (isset($_POST['temp_min']) ? $_POST['temp_min'] : 0)));
    $cfg['temp_max'] = max(20, min(80, (int) (isset($_POST['temp_max']) ? $_POST['temp_max'] : 45)));
    $cfg['soc_min'] = max(0, min(50, (int) (isset($_POST['soc_min']) ? $_POST['soc_min'] : 5)));
    $cfg['soc_max'] = max(50, min(100, (int) (isset($_POST['soc_max']) ? $_POST['soc_max'] : 98)));
    // MQTT und Aktionstoken kommen aus dem Bestand ($cfg = $alt oben) und
    // werden hier nicht angefasst. Bis 1.0.10 fehlte das fuer aktionstoken:
    // jedes Speichern warf es still weg, und alle Loxone-Adressen liefen
    // danach auf 403.
    if (marstek_cfg_schreiben($cfg)) {
        $mv_saved = true;
    } else {
        $mv_save_error = marstek_t('MELD.SPEICHERN_FEHLGESCHLAGEN');
    }
    $mv_active_tab = 'tab-settings';
}

/* ---------- Laden ---------- */
$mv_cfg = marstek_config();
$mv_devices = marstek_devices();
$mv_fehlten = marstek_cfg_vervollstaendigen();

// Beim ersten Aufruf ein Token erzeugen, damit der Endpunkt fuer Loxone sofort
// benutzbar ist (schuetzt ?p= und ?mode= im unangemeldeten marstek.php).
if (empty($mv_cfg['aktionstoken'])) {
    $mv_cfg['aktionstoken'] = marstek_token_erzeugen();
    marstek_cfg_schreiben($mv_cfg);
}

// Letzter Status je Geraet (Zwischenspeicher - KEIN Live-Aufruf, damit die
// Seite schnell laedt).
$mv_statuses = array();
foreach ($mv_devices as $n => $d) {
    $mv_sdat = marstek_tmpdir() . '/status_dev' . $n . '.json';
    $st = is_file($mv_sdat) ? json_decode((string) @file_get_contents($mv_sdat), true) : null;
    if (is_array($st) && isset($st['soc'])) {
        $mv_statuses[$n] = $st;
    }
}
$mv_log_lines = array();
if (is_file($mv_log_file)) {
    $mv_log_lines = array_slice(array_reverse(file($mv_log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 300);
}
$mv_err_lines = array();
if (is_file($mv_err_file) && filesize($mv_err_file) > 0) {
    $mv_err_lines = array_slice(array_reverse(file($mv_err_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 50);
}

/** Kurzform fuer maskierte Ausgabe. */
function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/**
 * Mini-SVG: SOC-Verlauf eines Tages, dazu die Batterieleistung als zweite
 * Kurve.
 *
 * $tag ist 'Ymd'. Der Tagesanfang richtet sich nach dem GEZEIGTEN Tag, nicht
 * nach heute - sonst faellt bei der Tagesauswahl jeder Punkt aus dem Bild.
 */
function mv_soc_svg($points, $tag = '') {
    $w = 720; $h = 150; $x0 = 34; $y0 = 8; $pw = $w - $x0 - 34; $ph = $h - $y0 - 20;
    $day0 = $tag !== '' ? (int) strtotime(substr($tag, 0, 4) . '-' . substr($tag, 4, 2) . '-' . substr($tag, 6, 2) . ' 00:00')
                        : (int) strtotime('today 00:00');
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;max-width:' . $w . 'px;height:auto;background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;" xmlns="http://www.w3.org/2000/svg">';
    // Grenzen der Leistungsachse aus den Daten - eine feste Achse waere bei
    // einem Venus E Mini (800 W) genauso falsch wie bei zwei Gen 3.0.
    $pmax = 100;
    foreach ($points as $pt) {
        if (abs($pt[2]) > $pmax) { $pmax = abs($pt[2]); }
    }
    $pmax = ceil($pmax / 500) * 500;
    foreach (array(0, 25, 50, 75, 100) as $pct) {
        $y = $y0 + $ph - $ph * $pct / 100;
        $svg .= '<line x1="' . $x0 . '" y1="' . $y . '" x2="' . ($x0 + $pw) . '" y2="' . $y . '" stroke="#e5e5e5" stroke-width="1"/>';
        $svg .= '<text x="' . ($x0 - 5) . '" y="' . ($y + 3) . '" font-size="9" fill="#999" text-anchor="end">' . $pct . '</text>';
    }
    // rechte Achse: Leistung
    foreach (array(-$pmax, 0, $pmax) as $wt) {
        $y = $y0 + $ph / 2 - ($ph / 2) * $wt / $pmax;
        $svg .= '<text x="' . ($x0 + $pw + 4) . '" y="' . ($y + 3) . '" font-size="9" fill="#c47b1a" text-anchor="start">' . (int) $wt . '</text>';
    }
    foreach (array(0, 6, 12, 18, 24) as $hh) {
        $x = $x0 + $pw * $hh / 24;
        $svg .= '<line x1="' . $x . '" y1="' . $y0 . '" x2="' . $x . '" y2="' . ($y0 + $ph) . '" stroke="#eeeeee" stroke-width="1"/>';
        $svg .= '<text x="' . $x . '" y="' . ($h - 6) . '" font-size="9" fill="#999" text-anchor="middle">' . $hh . ':00</text>';
    }
    $poly = array(); $polyp = array();
    foreach ($points as $pt) {
        $frac = ($pt[0] - $day0) / 86400;
        if ($frac < 0 || $frac > 1) {
            continue;
        }
        $x = round($x0 + $pw * $frac, 1);
        $poly[] = $x . ',' . round($y0 + $ph - $ph * max(0, min(100, $pt[1])) / 100, 1);
        $polyp[] = $x . ',' . round($y0 + $ph / 2 - ($ph / 2) * max(-$pmax, min($pmax, $pt[2])) / $pmax, 1);
    }
    if (count($poly) >= 2) {
        $first = explode(',', $poly[0]); $last = explode(',', $poly[count($poly) - 1]);
        $svg .= '<polygon points="' . $first[0] . ',' . ($y0 + $ph) . ' ' . implode(' ', $poly) . ' ' . $last[0] . ',' . ($y0 + $ph) . '" fill="#6dac20" opacity="0.15"/>';
        $svg .= '<polyline points="' . implode(' ', $polyp) . '" fill="none" stroke="#e0a020" stroke-width="1" opacity="0.85"/>';
        $svg .= '<polyline points="' . implode(' ', $poly) . '" fill="none" stroke="#6dac20" stroke-width="2"/>';
        $svg .= '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="3" fill="#6dac20"/>';
    } else {
        $svg .= '<text x="' . ($x0 + $pw / 2) . '" y="' . ($y0 + $ph / 2) . '" font-size="11" fill="#aaa" text-anchor="middle">'
              . e(marstek_t('EINST.KEINE_MESSPUNKTE')) . '</text>';
    }
    return $svg . '</svg>';
}

// WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg aus general.json als stdClass)
// und wuerde gleichnamige Plugin-Variablen ueberschreiben - daher hier ueberall mv_-Praefix.
$mv_use_frame = class_exists('LBWeb', false);
if ($mv_use_frame) {
    LBWeb::lbheader('Marstek Venus E', 'https://wiki.loxberry.de/', 'help.html');
}
$mv_host = e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
$mv_ft = e(marstek_formtoken());
$mv_gw = marstek_mqtt_gateway_info();
$mv_gwf = ($mv_gw === null) ? 0 : (int) $mv_gw['fassung'];
$mv_verlauf_dev = isset($_GET['vdev']) ? max(1, (int) $_GET['vdev']) : 0;
$mv_verlauf_tag = isset($_GET['vtag']) && is_string($_GET['vtag']) ? preg_replace('/\D/', '', $_GET['vtag']) : '';
?>
<style>
/* Hausstandard - Klassennamen sind fest sm-, in jedem Plugin. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3, .sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap input[type=file], .sm-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1 1 200px; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
.sm-mono { font-family: Consolas, 'Courier New', monospace; background: #f0f0f0; padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-small, .sm-hilfe { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px; padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px; padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important;
  text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, 'Courier New', monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa; border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 5px 8px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-breit { overflow-x: auto; }
.sm-devtbl input, .sm-devtbl select { min-width: 60px; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben sind kein Feinschliff, sondern Pflicht. */
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-ja  { color: #1a7f1a; font-weight: 700; }
.sm-nein { color: #b00000; font-weight: 700; }
.sm-hinw { color: #8a6d1a; font-weight: 700; }
</style>
<div class="sm-wrap">

<?php if ($mv_saved) { ?><div class="sm-alert sm-ok"><b><?= e(marstek_t('MELD.GESPEICHERT')) ?></b> <?= e(marstek_t('MELD.GESPEICHERT_ZUSATZ')) ?></div><?php } ?>
<?php if ($mv_meldung !== '') { ?><div class="sm-alert sm-ok"><?= e($mv_meldung) ?></div><?php } ?>
<?php if ($mv_save_error !== '') { ?><div class="sm-alert sm-err"><b><?= e(marstek_t('MELD.FEHLER')) ?></b> <?= e($mv_save_error) ?></div><?php } ?>
<?php if ($mv_beanstandung) { ?><div class="sm-alert sm-warn"><b><?= e(marstek_t('MELD.BEANSTANDUNG')) ?></b><ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($mv_beanstandung as $b) { ?><li><?= e($b) ?></li><?php } ?>
</ul></div><?php } ?>
<?php if ($mv_fehlten) { ?><div class="sm-alert sm-info"><?= e(sprintf(marstek_t('MELD.KONFIG_ERGAENZT'), implode(', ', $mv_fehlten))) ?></div><?php } ?>

<?php foreach ($mv_statuses as $n => $st) {
    $alter = !empty($st['mess']) ? time() - (int) $st['mess'] : -1; ?>
<div class="sm-alert sm-info"><b><?= e($mv_devices[$n]['name']) ?></b>
 &middot; <?= e(marstek_t('EINST.LADEZUSTAND')) ?>: <?= e($st['soc']) ?> %
 &middot; <?= e(marstek_t('EINST.BATTERIELEISTUNG')) ?>: <?= e($st['batp']) ?> W
 &middot; <?= e(marstek_t('EINST.TEMPERATUR')) ?>: <?= e($st['temp']) ?> &deg;C
 &middot; <?= e(marstek_t('EINST.VERBINDUNG')) ?>: <?php if (!empty($st['ok'])) { ?><span class="sm-ja">OK</span><?php } else { ?><span class="sm-nein"><?= e(marstek_t('EINST.GESTOERT')) ?></span><?php } ?>
<?php if (!empty($st['ok'])) { ?> &middot; <?= e(isset($st['model']) && $st['model'] !== '' ? $st['model'] : 'Venus') ?>
 &middot; <?= e(marstek_t('EINST.FIRMWARE')) ?> <?= (int) (isset($st['fw']) ? $st['fw'] : 0) ?>
 &middot; <?= e(marstek_t('EINST.ANTWORTZEIT')) ?> <?= (int) (isset($st['ms']) ? $st['ms'] : 0) ?> ms<?php } ?>
<br><span class="sm-small"><?= e(sprintf(marstek_t('EINST.LETZTE_MESSUNG'),
        $alter >= 0 ? date('d.m.Y H:i:s', (int) $st['mess']) . ' (' . $alter . ' s)' : marstek_t('EINST.NIE'))) ?></span>
<?php if (!empty($mv_devices[$n]['modbus'])) {
    $mv_edat = marstek_tmpdir() . '/energy_dev' . $n . '.json';
    $en = is_file($mv_edat) ? json_decode((string) @file_get_contents($mv_edat), true) : null;
    if (is_array($en) && !empty($en['chgt'])) { ?>
<br><?= e(marstek_t('EINST.ENERGIE_HEUTE')) ?>: <b><?= e($en['chgd']) ?> kWh</b> <?= e(marstek_t('EINST.GELADEN')) ?>,
<b><?= e($en['disd']) ?> kWh</b> <?= e(marstek_t('EINST.ABGEGEBEN')) ?>
&middot; <?= e(marstek_t('EINST.MONAT')) ?>: <?= e($en['chgm']) ?> / <?= e($en['dism']) ?> kWh
&middot; <?= e(marstek_t('EINST.ZYKLEN')) ?>: <?= (int) $en['cyc'] ?>
&middot; <?= e(marstek_t('EINST.WIRKUNGSGRAD')) ?>: <?= e($en['eff']) ?> %
<?php } } ?>
<?php
    $vtag = ($mv_verlauf_dev === $n && $mv_verlauf_tag !== '') ? $mv_verlauf_tag : date('Ymd');
    $vtage = marstek_history_tage($n);
    $hist = marstek_history_read($n, $vtag);
    $kz = marstek_history_kennzahlen($n, $vtag); ?>
<div style="margin-top:8px;"><?= mv_soc_svg($hist, $vtag) ?></div>
<div class="sm-small"><?= e(marstek_t('EINST.VERLAUF_ERKLAERUNG')) ?>
<?php if (!empty($kz['ok'])) { ?> &middot; <?= e(sprintf(marstek_t('EINST.VERLAUF_KENNZAHLEN'), $kz['socmin'], $kz['socmax'], $kz['hub'], $kz['n'])) ?><?php } ?>
</div>
<?php if (count($vtage) > 1) { ?>
<div class="sm-small" style="margin-top:4px;"><?= e(marstek_t('EINST.TAG_WAEHLEN')) ?>:
<?php foreach (array_slice($vtage, 0, 14) as $t) {
    $bez = substr($t, 6, 2) . '.' . substr($t, 4, 2) . '.'; ?>
<a href="index.php?tab=settings&amp;vdev=<?= $n ?>&amp;vtag=<?= e($t) ?>"<?= $t === $vtag ? ' style="font-weight:700;"' : '' ?>><?= e($bez) ?></a>
<?php } ?>
</div>
<?php } ?>
<form action="index.php" method="post" style="margin-top:6px;">
  <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <input data-role="none" type="hidden" name="verlauf_dev" value="<?= $n ?>">
  <input data-role="none" type="hidden" name="verlauf_tag" value="<?= e($vtag) ?>">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="verlauf_csv" value="1" style="min-width:200px;"><?= e(marstek_t('EINST.K_CSV')) ?></button>
</form>
</div>
<?php } ?>

<!-- Reiterleiste: echte Verweise, ausgeschrieben. Eine erzeugte Leiste macht
     hausstandard_pruefen.py blind, und ein Strich sammelt sich beim
     Ueberfliegen wie ein Haken ein. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $mv_active_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?tab=settings"><?= e(marstek_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $mv_active_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?tab=mqtt">MQTT</a>
	<a class="sm-tab<?= $mv_active_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?tab=loxone"><?= e(marstek_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $mv_active_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?tab=test"><?= e(marstek_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $mv_active_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?tab=log"><?= e(marstek_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $mv_active_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= e(marstek_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= e(marstek_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= e(marstek_t('EINST.H_GERAETE')) ?></h2>
<div class="sm-hilfe"><?= marstek_t('EINST.GERAETE_ERKLAERUNG') ?></div>
<div class="sm-breit">
<table class="sm-tbl sm-devtbl">
<tr><th style="width:34px;">Nr.</th><th><?= e(marstek_t('EINST.SP_NAME')) ?></th><th><?= e(marstek_t('EINST.SP_IP')) ?></th>
<th style="width:88px;"><?= e(marstek_t('EINST.SP_PORT')) ?></th><th style="width:104px;"><?= e(marstek_t('EINST.SP_PMAX_LADEN')) ?></th>
<th style="width:104px;"><?= e(marstek_t('EINST.SP_PMAX_ENTLADEN')) ?></th><th style="width:96px;"><?= e(marstek_t('EINST.SP_KWH')) ?></th>
<th style="width:110px;"><?= e(marstek_t('EINST.SP_MODBUS')) ?></th></tr>
<?php for ($i = 0; $i < 4; $i++) {
    $d = isset($mv_cfg['devices'][$i]) && is_array($mv_cfg['devices'][$i]) ? $mv_cfg['devices'][$i] : array();
    $d += array('name' => '', 'ip' => '', 'port' => 30000, 'pmax_charge' => 2500, 'pmax_discharge' => 2500, 'modbus' => 1, 'kwh' => 0); ?>
<tr>
<td><?= $i + 1 ?></td>
<td><input data-role="none" type="text" name="dev_name[]" value="<?= e($d['name']) ?>" placeholder="<?= e($i === 0 ? marstek_t('EINST.PH_NAME') : marstek_t('EINST.PH_LEER')) ?>"></td>
<td><input data-role="none" type="text" name="dev_ip[]" value="<?= e($d['ip']) ?>" placeholder="<?= e($i === 0 ? '192.168.1.25' : '') ?>"></td>
<td><input data-role="none" type="number" name="dev_port[]" value="<?= (int) $d['port'] ?>" min="1" max="65535"></td>
<td><input data-role="none" type="number" name="dev_pc[]" value="<?= (int) $d['pmax_charge'] ?>" min="100" max="3600"></td>
<td><input data-role="none" type="number" name="dev_pd[]" value="<?= (int) $d['pmax_discharge'] ?>" min="100" max="3600"></td>
<td><input data-role="none" type="text" name="dev_kwh[]" value="<?= $d['kwh'] > 0 ? e($d['kwh']) : '' ?>" placeholder="5.12"></td>
<td><select data-role="none" name="dev_mb[]">
<option value="0"<?= empty($d['modbus']) ? ' selected' : '' ?>><?= e(marstek_t('EINST.AUS')) ?></option>
<option value="1"<?= !empty($d['modbus']) ? ' selected' : '' ?>><?= e(marstek_t('EINST.EIN')) ?></option>
</select></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= marstek_t('EINST.GRENZEN_ERKLAERUNG') ?></div>

<h2><?= e(marstek_t('EINST.H_BETRIEB')) ?></h2>
<div class="sm-row">
    <div>
        <label><?= e(marstek_t('EINST.L_CACHE')) ?></label>
        <input data-role="none" type="number" name="cache_sec" value="<?= (int) $mv_cfg['cache_sec'] ?>" min="5" max="300">
        <div class="sm-hilfe"><?= e(marstek_t('EINST.H_CACHE')) ?></div>
    </div>
    <div>
        <label><?= e(marstek_t('EINST.L_FALLBACK')) ?></label>
        <input data-role="none" type="number" name="fallback_min" value="<?= (int) $mv_cfg['fallback_min'] ?>" min="0" max="1440">
        <div class="sm-hilfe"><?= e(marstek_t('EINST.H_FALLBACK')) ?></div>
    </div>
    <div>
        <label><?= e(marstek_t('EINST.L_VERLAUF_TAGE')) ?></label>
        <input data-role="none" type="number" name="verlauf_tage" value="<?= (int) $mv_cfg['verlauf_tage'] ?>" min="1" max="365">
        <div class="sm-hilfe"><?= e(marstek_t('EINST.H_VERLAUF_TAGE')) ?></div>
    </div>
</div>
<label style="display:inline-flex;align-items:center;gap:6px;margin-top:14px;">
    <input data-role="none" type="checkbox" name="steuerung_ein" <?= !empty($mv_cfg['steuerung_ein']) ? 'checked' : '' ?>>
    <?= e(marstek_t('EINST.L_STEUERUNG')) ?>
</label>
<div class="sm-hilfe"><?= e(marstek_t('EINST.H_STEUERUNG')) ?></div>
<label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;">
    <input data-role="none" type="checkbox" name="verteilen_ein" <?= !empty($mv_cfg['verteilen_ein']) ? 'checked' : '' ?>>
    <?= e(marstek_t('EINST.L_VERTEILEN')) ?>
</label>
<div class="sm-hilfe"><?= e(marstek_t('EINST.H_VERTEILEN')) ?></div>

<h2><?= e(marstek_t('EINST.H_SCHUTZ')) ?></h2>
<div class="sm-hinweis"><?= marstek_t('EINST.SCHUTZ_ERKLAERUNG') ?></div>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="schutz_ein" <?= !empty($mv_cfg['schutz_ein']) ? 'checked' : '' ?>>
    <?= e(marstek_t('EINST.L_SCHUTZ')) ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div><label><?= e(marstek_t('EINST.L_TEMP_MIN')) ?></label>
        <input data-role="none" type="number" name="temp_min" value="<?= (int) $mv_cfg['temp_min'] ?>" min="-20" max="20"></div>
    <div><label><?= e(marstek_t('EINST.L_TEMP_MAX')) ?></label>
        <input data-role="none" type="number" name="temp_max" value="<?= (int) $mv_cfg['temp_max'] ?>" min="20" max="80"></div>
    <div><label><?= e(marstek_t('EINST.L_SOC_MIN')) ?></label>
        <input data-role="none" type="number" name="soc_min" value="<?= (int) $mv_cfg['soc_min'] ?>" min="0" max="50"></div>
    <div><label><?= e(marstek_t('EINST.L_SOC_MAX')) ?></label>
        <input data-role="none" type="number" name="soc_max" value="<?= (int) $mv_cfg['soc_max'] ?>" min="50" max="100"></div>
</div>

<h2><?= e(marstek_t('EINST.H_MELDEN')) ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="melden_ein" <?= !empty($mv_cfg['melden_ein']) ? 'checked' : '' ?>>
    <?= e(marstek_t('EINST.L_MELDEN')) ?>
</label>
<div class="sm-hilfe"><?= e(marstek_t('EINST.H_MELDEN')) ?></div>
<div class="sm-row" style="margin-top:6px;">
    <div style="max-width:240px;"><label><?= e(marstek_t('EINST.L_MELDEN_AB')) ?></label>
        <input data-role="none" type="number" name="melden_ab" value="<?= (int) $mv_cfg['melden_ab'] ?>" min="1" max="20"></div>
</div>

<h2><?= e(marstek_t('EINST.H_SPOT')) ?></h2>
<div class="sm-row">
    <div>
        <label><?= e(marstek_t('EINST.L_MARKT')) ?></label>
        <select data-role="none" name="awattar">
            <option value="de"<?= $mv_cfg['awattar'] === 'de' ? ' selected' : '' ?>><?= e(marstek_t('EINST.MARKT_DE')) ?></option>
            <option value="at"<?= $mv_cfg['awattar'] === 'at' ? ' selected' : '' ?>><?= e(marstek_t('EINST.MARKT_AT')) ?></option>
        </select>
    </div>
    <div>
        <label><?= e(marstek_t('EINST.L_UST')) ?></label>
        <input data-role="none" type="text" name="vat" value="<?= e($mv_cfg['vat']) ?>" placeholder="1.19">
        <div class="sm-hilfe"><?= e(marstek_t('EINST.H_UST')) ?></div>
    </div>
    <div>
        <label><?= e(marstek_t('EINST.L_AUFSCHLAG')) ?></label>
        <input data-role="none" type="text" name="aufschlag_ct" value="<?= e($mv_cfg['aufschlag_ct']) ?>" placeholder="0">
        <div class="sm-hilfe"><?= e(marstek_t('EINST.H_AUFSCHLAG')) ?></div>
    </div>
</div>

<button data-role="none" class="sm-btn sm-b-aktion" type="submit" style="margin-top:18px;"><?= e(marstek_t('EINST.K_SPEICHERN')) ?></button>
</form>

<h2><?= e(marstek_t('EINST.H_SICHERUNG')) ?></h2>
<div class="sm-hinweis"><?= marstek_t('EINST.SICHERUNG_ERKLAERUNG') ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="konfig_export" value="1"><?= e(marstek_t('EINST.K_EXPORT')) ?></button>
  </form>
</div>
<form action="index.php" method="post" enctype="multipart/form-data" style="margin-top:10px;">
  <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <label><?= e(marstek_t('EINST.L_IMPORT')) ?></label>
  <input data-role="none" type="file" name="konfigdatei" accept=".json,application/json" style="max-width:420px;">
  <div class="sm-knopfreihe" style="margin-top:8px;">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="konfig_import" value="1"><?= e(marstek_t('EINST.K_IMPORT')) ?></button>
  </div>
</form>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $mv_active_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= e(marstek_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<h2><?= e(marstek_t('MQTT.H_MQTT')) ?></h2>
<?php if ($mv_gw !== null && !$mv_gw['autostart']) { ?><div class="sm-warnung"><b>MQTT:</b> <?= e(marstek_t('MQTT.W_AUTOSTART')) ?></div><?php } ?>
<?php if (marstek_mqtt_udpport() === 0) { ?><div class="sm-warnung"><?= e(marstek_t('MQTT.W_KEIN_GATEWAY')) ?></div><?php } ?>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($mv_cfg['mqtt_enabled']) ? 'checked' : '' ?>>
    <?= e(marstek_t('MQTT.L_EIN')) ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div style="max-width:420px;">
        <label><?= e(marstek_t('MQTT.L_PRAEFIX')) ?></label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= e($mv_cfg['mqtt_topic']) ?>" placeholder="marstek">
        <div class="sm-hilfe"><?= e(marstek_t('MQTT.H_PRAEFIX')) ?></div>
    </div>
</div>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" style="margin-top:18px;"><?= e(marstek_t('EINST.K_SPEICHERN')) ?></button>
</form>

<h2><?= e(marstek_t('MQTT.H_ABO')) ?></h2>
<?php
/* Der Satz "Ohne diesen Eintrag kommt am Miniserver nichts an" gilt NUR fuer
 * Gateway V1. Unter V2 schaltet der LoxBerry-Kern die Knoepfe auf der
 * Abonnement-Seite ab - der unbedingte Satz schickte jeden V2-Anwender zu
 * einem Eingabefeld, das es nicht mehr gibt. Ist die Fassung nicht lesbar,
 * stehen BEIDE Saetze da: einen von beiden zu behaupten waere fuer die
 * Haelfte der Anlagen falsch. */
if ($mv_gwf >= 2) { ?>
<div class="sm-hinweis"><?= marstek_t('MQTT.ABO_V2') ?></div>
<?php } elseif ($mv_gwf === 1) { ?>
<div class="sm-warnung"><?= marstek_t('MQTT.ABO_V1') ?></div>
<?php } else { ?>
<div class="sm-warnung"><?= marstek_t('MQTT.ABO_V1') ?></div>
<div class="sm-hinweis"><?= marstek_t('MQTT.ABO_V2') ?></div>
<?php } ?>
<p><?= e(marstek_t('MQTT.ABO_EINTRAG')) ?> <span class="sm-mono"><?= e($mv_cfg['mqtt_topic']) ?>/#</span></p>

<h2><?= e(marstek_t('MQTT.H_THEMEN')) ?></h2>
<div class="sm-hinweis"><?= marstek_t('MQTT.THEMEN_ERKLAERUNG') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:38%;"><?= e(marstek_t('MQTT.SP_THEMA')) ?></th><th><?= e(marstek_t('MQTT.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (marstek_mqtt_themen(true) as $thema => $bedeutung) { ?>
<tr><td><span class="sm-mono"><?= e($mv_cfg['mqtt_topic']) ?>/<?= e($thema) ?></span></td><td><?= e($bedeutung) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= e(sprintf(marstek_t('MQTT.THEMEN_ANZAHL'), count(marstek_mqtt_themen(true)))) ?></div>
<?php if (count($mv_devices) > 1) { ?>
<div class="sm-hilfe"><?= e(sprintf(marstek_t('MQTT.MEHRERE_GERAETE'), $mv_cfg['mqtt_topic'], $mv_cfg['mqtt_topic'])) ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $mv_active_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= e(marstek_t('LOX.H_EINBINDUNG')) ?></h2>
<div class="sm-hinweis"><?= marstek_t('LOX.GRUNDGEDANKE') ?></div>

<div class="sm-step"><b><?= e(marstek_t('LOX.S1_TITEL')) ?></b><br>
<?= marstek_t('LOX.S1_TEXT') ?>
<?php if ($mv_gwf >= 2) { ?>
<div class="sm-hinweis"><?= marstek_t('MQTT.ABO_V2') ?></div>
<?php } elseif ($mv_gwf === 1) { ?>
<div class="sm-warnung"><?= marstek_t('MQTT.ABO_V1') ?></div>
<?php } else { ?>
<div class="sm-warnung"><?= marstek_t('MQTT.ABO_V1') ?></div>
<div class="sm-hilfe"><?= marstek_t('MQTT.ABO_V2') ?></div>
<?php } ?>
</div>

<?php
/* Die Tabellen der virtuellen Eingaenge entstehen aus marstek_felder() -
 * derselben Quelle wie die Vorlage und die Antwortzeile des Endpunkts. Bis
 * 1.0.16 standen sie in vierzig einzelnen Sprachschluesseln daneben, und
 * genau daraus sind drei Fehler entstanden: MS falsch beschriftet, RANKD mit
 * zwei Bedeutungen, GRIDP gar nicht erwaehnt. */
$mv_saetze = array(
    'status' => array('titel' => marstek_t('LOX.SATZ_STATUS'), 'takt' => 60,  'q' => '?status', 'jedev' => true),
    'ranks'  => array('titel' => marstek_t('LOX.SATZ_RANKS'),  'takt' => 300, 'q' => '?ranks',  'jedev' => false),
    'energy' => array('titel' => marstek_t('LOX.SATZ_ENERGY'), 'takt' => 300, 'q' => '?energy', 'jedev' => true),
);
if (count($mv_devices) > 1) {
    $mv_saetze['summe'] = array('titel' => marstek_t('LOX.SATZ_SUMME'), 'takt' => 60, 'q' => '?summe', 'jedev' => false);
}
$mv_schritt = 1;
foreach ($mv_saetze as $satz => $info) { $mv_schritt++; ?>
<div class="sm-step"><b><?= e(sprintf(marstek_t('LOX.SCHRITT'), $mv_schritt)) ?>: <?= e($info['titel']) ?></b><br>
<table class="sm-tbl">
<tr><th style="width:34%;"><?= e(marstek_t('LOX.SP_EIGENSCHAFT')) ?></th><th><?= e(marstek_t('LOX.SP_WERT')) ?></th></tr>
<tr><td><?= e(marstek_t('LOX.SP_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= $mv_host ?>/plugins/<?= e($mv_plugindir) ?>/marstek.php<?= e($info['q']) ?></span></td></tr>
<tr><td><?= e(marstek_t('LOX.SP_TAKT')) ?></td><td><?= (int) $info['takt'] ?> s</td></tr>
</table>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:22%;"><?= e(marstek_t('LOX.SP_SUCHTEXT')) ?></th><th style="width:10%;"><?= e(marstek_t('LOX.SP_EINHEIT')) ?></th><th><?= e(marstek_t('LOX.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (marstek_felder($satz) as $name => $f) { ?>
<tr><td><span class="sm-mono">\i;<?= e($name) ?>=\i\v</span></td><td><?= e($f['einheit']) ?></td><td><?= e($f['text']) ?></td></tr>
<?php } ?>
</table>
</div>
<?php if ($info['jedev'] && count($mv_devices) > 1) { ?>
<div class="sm-hilfe"><?= e(sprintf(marstek_t('LOX.JE_GERAET'), $info['q'])) ?></div>
<?php } ?>
</div>
<?php } ?>

<div class="sm-step"><b><?= e(sprintf(marstek_t('LOX.SCHRITT'), ++$mv_schritt)) ?>: <?= e(marstek_t('LOX.S_AUSGANG')) ?></b><br>
<?= marstek_t('LOX.AUSGANG_TEXT') ?>
<table class="sm-tbl">
<tr><th style="width:34%;"><?= e(marstek_t('LOX.SP_EIGENSCHAFT')) ?></th><th><?= e(marstek_t('LOX.SP_WERT')) ?></th></tr>
<tr><td><?= e(marstek_t('LOX.SP_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= $mv_host ?></span></td></tr>
<tr><td><?= e(marstek_t('LOX.SP_BEFEHL_ANALOG')) ?></td><td><span class="sm-mono">/plugins/<?= e($mv_plugindir) ?>/marstek.php?p=&lt;v&gt;&amp;t=240&amp;token=<?= e($mv_cfg['aktionstoken']) ?></span></td></tr>
<tr><td><?= e(marstek_t('LOX.SP_BEFEHL_AUTO')) ?></td><td><span class="sm-mono">/plugins/<?= e($mv_plugindir) ?>/marstek.php?mode=auto&amp;token=<?= e($mv_cfg['aktionstoken']) ?></span></td></tr>
</table>
<div class="sm-warnung"><?= marstek_t('LOX.TOKEN_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= e(sprintf(marstek_t('LOX.SCHRITT'), ++$mv_schritt)) ?>: <?= e(marstek_t('LOX.S_AUSFALL')) ?></b><br>
<?= marstek_t('LOX.AUSFALL_TEXT') ?>
</div>

<div class="sm-step"><b><?= e(sprintf(marstek_t('LOX.SCHRITT'), ++$mv_schritt)) ?>: <?= e(marstek_t('LOX.S_BAUSTEINE')) ?></b><br>
<?= marstek_t('LOX.BAUSTEINE_VORTEXT') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:34px;">#</th><th style="width:20%;"><?= e(marstek_t('LOX.SP_BAUSTEIN')) ?></th><th style="width:24%;"><?= e(marstek_t('LOX.SP_NAME')) ?></th><th style="width:24%;"><?= e(marstek_t('LOX.SP_PARAMETER')) ?></th><th><?= e(marstek_t('LOX.SP_EINGAENGE')) ?></th></tr>
<?php
$mv_bausteine = array(
    array('Taster EIN/AUS', 'LOX.B_NETZLADEN', 'LOX.P_STANDARD_AUS', 'LOX.E_VISU'),
    array('Taster EIN/AUS', 'LOX.B_SPOTLADEN', 'LOX.P_STANDARD_AUS', 'LOX.E_VISU'),
    array('Taster EIN/AUS', 'LOX.B_ENTLADEN_ERLAUBT', 'LOX.P_STANDARD_AUS', 'LOX.E_VISU'),
    array('Taster EIN/AUS', 'LOX.B_NUR_TEUERSTE', 'LOX.P_STANDARD_AUS', 'LOX.E_VISU'),
    array('LOX.T_VE_ZAHL', 'LOX.B_ANZ_GUENSTIG', 'LOX.P_0_24', 'LOX.E_VISU'),
    array('LOX.T_VE_ZAHL', 'LOX.B_ANZ_TEUER', 'LOX.P_0_24', 'LOX.E_VISU'),
    array('LOX.T_VE_ZAHL', 'LOX.B_RESERVE', 'LOX.P_0_100', 'LOX.E_VISU'),
    array('LOX.T_FORMEL', 'LOX.B_EXPORT', 'max(0;I1)', 'LOX.E_NETZ'),
    array('LOX.T_FORMEL', 'LOX.B_BEZUG', 'max(0;-I1)', 'LOX.E_NETZ'),
    array('LOX.T_SCHWELLE', 'LOX.B_EXPORT_UEBER', 'LOX.P_EIN150', 'LOX.E_NR8'),
    array('LOX.T_FORMEL', 'LOX.B_PV_LADE', 'min(I1-100;2500)*I2', 'LOX.E_NR8_NR10'),
    array('LOX.T_VERGLEICH', 'LOX.B_RANG_LADE', 'LOX.P_Q1_KLEINER', 'LOX.E_NR5_RANK'),
    array('LOX.T_SCHWELLE', 'LOX.B_LADESTD_GESETZT', 'LOX.P_EIN05', 'LOX.E_NR5'),
    array('LOX.T_UND', 'LOX.B_SPOTFENSTER', '', 'LOX.E_NR12_NR13'),
    array('LOX.T_UND', 'LOX.B_RANKING_GUELTIG', '', 'LOX.E_NR14_OK'),
    array('LOX.T_ODER', 'LOX.B_SPOTLADUNG_NOETIG', '', 'LOX.E_NEG_NR15'),
    array('LOX.T_UND', 'LOX.B_SPOT_AKTIV', '', 'LOX.E_NR16_NR2'),
    array('LOX.T_UND', 'LOX.B_NETZLADEN_ERLAUBT', '', 'LOX.E_NR17_NR1'),
    array('LOX.T_FORMEL', 'LOX.B_SPOT_LADE', 'I1*2500', 'LOX.E_NR18'),
    array('LOX.T_FORMEL', 'LOX.B_LADEWUNSCH', 'max(I1;I2)', 'LOX.E_NR11_NR19'),
    array('LOX.T_SCHWELLE', 'LOX.B_SOC_UEBER12', 'LOX.P_EIN12', 'LOX.E_SOC'),
    array('LOX.T_VERGLEICH', 'LOX.B_SOC_UEBER_RESERVE', 'LOX.P_Q1_GROESSER', 'LOX.E_SOC_NR7'),
    array('LOX.T_UND', 'LOX.B_SOC_FREI', '', 'LOX.E_NR21_NR22'),
    array('LOX.T_SCHWELLE', 'LOX.B_BEZUG_UEBER100', 'LOX.P_EIN100', 'LOX.E_NR9'),
    array('LOX.T_VERGLEICH', 'LOX.B_RANG_ENTLADE', 'LOX.P_Q1_GROESSER', 'LOX.E_NR6_RANKD'),
    array('LOX.T_SCHWELLE', 'LOX.B_ENTLADESTD_GESETZT', 'LOX.P_EIN05', 'LOX.E_NR6'),
    array('LOX.T_UND', 'LOX.B_ENTLADEFENSTER', '', 'LOX.E_NR25_NR26_OK'),
    array('LOX.T_NICHT', 'LOX.B_NICHT_NUR_TEUERSTE', '', 'LOX.E_NR4'),
    array('LOX.T_ODER', 'LOX.B_ENTLADEFENSTER_ERF', '', 'LOX.E_NR27_NR28'),
    array('LOX.T_UND', 'LOX.B_ENTLADUNG_ERLAUBT', '', 'LOX.E_NR29_NR3'),
    array('LOX.T_UND', 'LOX.B_KEIN_NEGPREIS', '', 'LOX.E_NR30_NEG'),
    array('LOX.T_FORMEL', 'LOX.B_ENTLADEWUNSCH', 'min(I1-50;2500)*I2*I3*I4', 'LOX.E_NR9_NR31_NR24_NR23'),
    array('LOX.T_SCHWELLE', 'LOX.B_SOC_UEBER97', 'LOX.P_EIN97', 'LOX.E_SOC'),
    array('LOX.T_FORMEL', 'LOX.B_SOLLLEISTUNG', 'I1*(1-I3)-I2', 'LOX.E_NR20_NR32_NR33'),
    array('LOX.T_IMPULS', 'LOX.B_SENDETAKT', 'LOX.P_60_60', ''),
    array('LOX.T_ANALOGSP', 'LOX.B_SAMPLE', 'LOX.P_TRIGGER', 'LOX.E_NR34_NR35'),
    array('LOX.T_FORMEL', 'LOX.B_DITHER', 'I1+I2', 'LOX.E_NR36_NR35_AUSGANG'),
    array('LOX.T_NICHT', 'LOX.B_NICHT_ERREICHBAR', '', 'LOX.E_OK'),
    array('LOX.T_EINVERZ', 'LOX.B_STOERUNG15', '900 s', 'LOX.E_NR38_PUSH'),
    array('LOX.T_AENDERUNG', 'LOX.B_TAKT_UEBERWACHUNG', 'LOX.P_180', 'LOX.E_ZAEHLER_PUSH'),
    array('LOX.T_VERGLEICH', 'LOX.B_SOLL_IST', 'LOX.P_Q1_ABWEICHUNG', 'LOX.E_SOLL_BATP'),
);
foreach ($mv_bausteine as $i => $b) {
    $typ = strpos($b[0], 'LOX.') === 0 ? marstek_t($b[0]) : $b[0];
    $par = strpos($b[2], 'LOX.') === 0 ? marstek_t($b[2]) : $b[2];
    $ein = $b[3] !== '' ? marstek_t($b[3]) : '';
    ?>
<tr><td><?= $i + 1 ?></td><td><?= e($typ) ?></td><td><?= e(marstek_t($b[1])) ?></td><td><?= e($par) ?></td><td><?= e($ein) ?></td></tr>
<?php } ?>
</table>
</div>
<?= marstek_t('LOX.BAUSTEINE_NACHTEXT') ?>
</div>

<div class="sm-step"><b><?= e(sprintf(marstek_t('LOX.SCHRITT'), ++$mv_schritt)) ?>: <?= e(marstek_t('LOX.S_GEGENPROBE')) ?></b><br>
<?= marstek_t('LOX.GEGENPROBE_TEXT') ?>
</div>

<h2><?= e(marstek_t('LOX.H_TOKEN')) ?></h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= e(marstek_t('LEGENDE.AKTION_ADRESSEN')) ?></span>
</div>
<table class="sm-tbl">
<tr><th style="width:34%;"><?= e(marstek_t('LOX.SP_EIGENSCHAFT')) ?></th><th><?= e(marstek_t('LOX.SP_WERT')) ?></th></tr>
<tr><td><?= e(marstek_t('LOX.AKTUELLES_TOKEN')) ?></td><td><span class="sm-mono"><?= e($mv_cfg['aktionstoken']) ?></span></td></tr>
</table>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= e(marstek_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>

<h2><?= e(marstek_t('LOX.H_VORLAGEN')) ?></h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= e(marstek_t('LEGENDE.TECHNIK')) ?></span>
</div>
<div class="sm-hinweis"><?= marstek_t('LOX.VORLAGEN_ERKLAERUNG') ?></div>
<?php if (class_exists('ZipArchive')) { ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage_paket" value="1"><?= e(marstek_t('LOX.K_PAKET')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= e(sprintf(marstek_t('LOX.PAKET_INHALT'), count(marstek_vorlagen_alle()))) ?></div>
<?php } else { ?>
<div class="sm-hilfe"><?= e(marstek_t('LOX.KEIN_ZIP_HINWEIS')) ?></div>
<?php } ?>

<h3 class="sm-h3"><?= e(marstek_t('LOX.H_EINZELN')) ?></h3>
<?php $mv_vdevs = $mv_devices ? $mv_devices : array(1 => array('name' => 'Venus E'));
foreach ($mv_vdevs as $n => $d) { ?>
<div class="sm-small" style="margin-top:10px;"><b><?= e($d['name']) ?></b></div>
<div class="sm-knopfreihe">
<?php foreach (array('status' => marstek_t('LOX.SATZ_STATUS'), 'energy' => marstek_t('LOX.SATZ_ENERGY')) as $vs => $vn) { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_dev" value="<?= (int) $n ?>">
    <input data-role="none" type="hidden" name="vorlage" value="<?= e($vs) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" style="min-width:200px;"><?= e($vn) ?></button>
  </form>
<?php } ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_dev" value="<?= (int) $n ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage_vo" value="1" style="min-width:200px;"><?= e(marstek_t('LOX.SATZ_STEUERN')) ?></button>
  </form>
</div>
<?php } ?>
<div class="sm-knopfreihe" style="margin-top:10px;">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage" value="ranks">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" style="min-width:200px;"><?= e(marstek_t('LOX.SATZ_RANKS')) ?></button>
  </form>
<?php if (count($mv_devices) > 1) { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage" value="summe">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" style="min-width:200px;"><?= e(marstek_t('LOX.SATZ_SUMME')) ?></button>
  </form>
<?php } ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $mv_active_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<?php
if (function_exists('mv_test_seite')) {
    // Der letzte Parameter sagt, ob der Reiter offen ist: index.php rendert
    // alle fuenf Reiter in das HTML, und eine Selbstpruefung, die etwas
    // kostet (hier der Aufruf des eigenen Endpunkts), liefe sonst bei jedem
    // Seitenaufbau mit.
    mv_test_seite($mv_ft, $mv_plugindir, $mv_cfg, $mv_devices, $mv_suchergebnis, $mv_suchmeldung,
                  $mv_active_tab === 'tab-test');
} else {
    echo '<div class="sm-alert sm-err">mv_test.php wurde nicht gefunden.</div>';
}
?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $mv_active_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= e(marstek_t('LOG.H_LOG')) ?></h2>
<?php if ($mv_use_frame && method_exists('LBWeb', 'loglist_html')) { ?>
<div style="margin-bottom:12px;"><?php echo LBWeb::loglist_html(); ?></div>
<?php } ?>
<div class="sm-hilfe" style="margin-bottom:8px;"><?= e(marstek_t('LOG.ERKLAERUNG')) ?><br>
<?= e(marstek_t('LOG.DATEI')) ?> <span class="sm-mono"><?= e($mv_log_file) ?></span></div>
<div class="sm-warnung"><?= e(marstek_t('LOG.RAMDISK')) ?></div>
<?php if ($mv_log_lines) { ?>
<div class="sm-log"><?= e(implode("\n", $mv_log_lines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?= e(marstek_t('LOG.LEER')) ?></div>
<?php } ?>

<h3 class="sm-h3"><?= e(marstek_t('LOG.H_CRONERR')) ?></h3>
<div class="sm-hilfe"><?= e(marstek_t('LOG.CRONERR_ERKLAERUNG')) ?><br>
<span class="sm-mono"><?= e($mv_err_file) ?></span></div>
<?php if ($mv_err_lines) { ?>
<div class="sm-log"><?= e(implode("\n", $mv_err_lines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-ok"><?= e(marstek_t('LOG.CRONERR_LEER')) ?></div>
<?php } ?>

<div class="sm-legende" style="margin-top:14px;">
<span><i class="sm-punkt sm-b-aktion"></i> <?= e(marstek_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= e(marstek_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.ziel === id); });
        document.querySelectorAll('.sm-seite').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function (ev) { ev.preventDefault(); activate(t.dataset.ziel); }); });
    activate(<?= json_encode($mv_active_tab) ?>);
})();
</script>
<?php
if ($mv_use_frame) {
    LBWeb::lbfooter();
}
