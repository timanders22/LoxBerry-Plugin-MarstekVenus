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

/* Bibliothek finden: installiert im html-Zweig, im Archiv daneben.
 *
 * BERICHTIGT 25.09.2026. Bis 1.1.15 stand hier eine eigene Wurzelsuche (ohne
 * general.json) und eine Kandidatenliste, deren erster Eintrag im
 * ausgepackten Archiv ein Pfad ab der Laufwerkswurzel war - eine
 * marstek_lib.php unter /html/plugins/htmlauth/ wurde VOR der eigenen geladen
 * (in WSL gemessen, Pruefung-MarstekVenus-1.1.16, Fall C4). Welcher Fall
 * vorliegt, sagt jetzt der Ablageort selbst; Wurzel, Ordner und Pfade kommen
 * aus marstek_paths(), EINER Stelle fuer die Wurzelregel.
 */
$mv_ordner_hier = basename(__DIR__);
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'htmlauth') {
    $libcand = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $mv_ordner_hier . '/marstek_lib.php';
} else {
    $libcand = dirname(__DIR__) . '/html/marstek_lib.php';
}
if (is_file($libcand)) {
    require_once $libcand;
}
if (!function_exists('marstek_config')) {
    echo '<p style="font-family:sans-serif;color:#b00">marstek_lib.php wurde nicht gefunden - '
       . 'das Plugin ist unvollstaendig installiert.</p>';
    exit;
}
$mv_pfade = marstek_paths();
$mv_lbhomedir = $mv_pfade['lbhome'];
$mv_plugindir = $mv_pfade['plugin'];
if ($mv_lbhomedir !== '') {
    $sdk_system = $mv_lbhomedir . '/libs/phplib/loxberry_system.php';
    $sdk_web = $mv_lbhomedir . '/libs/phplib/loxberry_web.php';
    if (file_exists($sdk_system)) {
        require_once $sdk_system;
        require_once $sdk_web;
    }
}
$mv_log_file = $mv_pfade['log'];
$mv_err_file = dirname($mv_pfade['log']) . '/cron.err';

// Die Selbstpruefung des Reiters Test liegt in einer eigenen Datei. Zwei
// Dateien, ein Prozess: keine gleichnamigen Funktionen.
$mv_testdatei = __DIR__ . '/mv_test.php';
if (is_file($mv_testdatei)) {
    require_once $mv_testdatei;
}

$mv_saved = false;
$mv_save_error = '';       // Fehler: es wurde nichts getan
$mv_beanstandung = array();// O4: warum das Formular abgewiesen wurde (es wurde NICHTS gespeichert)
$mv_warnungen = array();   // C11: gespeichert, aber mit Hinweis
$mv_meldung = '';
$mv_form_rueck = null;     // O4: die abgewiesene Eingabe - niemand soll alles neu tippen
$mv_mqtt_rueck = null;     // X-2: dasselbe fuer das MQTT-Formular
$mv_falsch = array();      // X-2: die beanstandeten Felder (Feldkennung => true)

/** X-2: das Merkmal fuer ein beanstandetes Feld (roter Rahmen, fuer Vorleser
 *  "ungueltig"). Die Kennung ist der Feldname, bei Geraetezeilen
 *  dev_<schluessel>_<nr>. */
function mv_fa($id, $setzen = null)
{
    // Die Liste liegt hier, nicht in $GLOBALS: rendern.py und der Router
    // binden diese Seite auch aus einer Funktion heraus ein.
    static $falsch = array();
    if (is_array($setzen)) {
        $falsch = $setzen;
        return '';
    }
    return isset($falsch[$id]) ? ' aria-invalid="true"' : '';
}

/** X-2: ein Wert fuer das value-Attribut - so, wie er gespeichert oder
 *  eingetippt ist (bis 1.1.18 mit (int): "5.5" kam als 5 zurueck). */
function mv_wert($v)
{
    return is_scalar($v) ? marstek_e((string) $v) : '';
}
$mv_suchergebnis = null;
$mv_suchmeldung = '';
$mv_post = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST');

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

/* ---------- O1 (Durchgang 30.09.2026): JEDER verandernde POST endet mit einer Umleitung ----------
 *
 * Bis 1.1.17 stand im ganzen Paket kein header('Location'. Gemessen: F5 nach
 * "Neues Token erzeugen" wuerfelte ein weiteres Token - das eben in Loxone
 * eingetragene lief danach auf HTTP 403 -, F5 nach "Log leeren" loeschte eine
 * inzwischen geschriebene Zeile, und Rueckspielen, Geraetesuche und Speichern
 * wiederholten ihre Wirkung. Das Ergebnis reist als Einmalmeldung ueber die
 * Umleitung (Bauform Docker NG 1.3.9). 303 heisst ausdruecklich "mit GET
 * abholen". Nur die Downloads (Vorlagen, Sicherung, CSV) antworten direkt -
 * sie veraendern nichts.
 */
function mv_weiter($reiter, array $daten = array())
{
    if (!preg_match('/^tab-(settings|mqtt|loxone|test|log)$/', (string) $reiter)) {
        $reiter = 'tab-settings';
    }
    if ($daten) {
        marstek_einmalmeldung_schreiben($daten);
    }
    header('Location: index.php?tab=' . substr($reiter, 4), true, 303);
    exit;
}

/* ---------- EIN Wachposten fuer alle Formulare ----------
 *
 * Vor jedem Handler, nicht in jedem Handler. Eine Abfrage je Knopf haette
 * man beim naechsten Knopf vergessen. Auch die Abweisung endet mit einer
 * Umleitung - sonst wiederholt ein Neuladen den abgewiesenen POST.
 */
if ($mv_post && !marstek_formtoken_ok()) {
    // Die Wache schaltet $mv_post ab, BEVOR sie umleitet: jeder Handler haengt
    // an $mv_post, und so bleibt das auch fuer wachposten_pruefen.py lesbar.
    $mv_post = false;
    mv_weiter($mv_active_tab, array('fehler' => marstek_t('MELD.FREMDES_FORMULAR')));
}

/* ---------- Das Ergebnis der vorigen Anfrage (NUR beim GET) ---------- */
if (!$mv_post) {
    $mv_flash = marstek_einmalmeldung_lesen();
    if (!empty($mv_flash['saved'])) { $mv_saved = true; }
    if (isset($mv_flash['meldung']) && is_string($mv_flash['meldung'])) { $mv_meldung = $mv_flash['meldung']; }
    if (isset($mv_flash['fehler']) && is_string($mv_flash['fehler'])) { $mv_save_error = $mv_flash['fehler']; }
    foreach (array('beanstandung', 'warnungen') as $mv_k) {
        if (isset($mv_flash[$mv_k]) && is_array($mv_flash[$mv_k])) {
            foreach ($mv_flash[$mv_k] as $mv_z) {
                if (is_string($mv_z)) {
                    if ($mv_k === 'beanstandung') { $mv_beanstandung[] = $mv_z; } else { $mv_warnungen[] = $mv_z; }
                }
            }
        }
    }
    /* X-2 (Regeln/04): die abgewiesenen Eingaben reisen unter 'eingaben', mit
     * dem Formular, zu dem sie gehoeren, und den beanstandeten Feldern. */
    if (isset($mv_flash['eingaben']) && is_array($mv_flash['eingaben'])) {
        $mv_fname = (isset($mv_flash['formular']) && is_string($mv_flash['formular'])) ? $mv_flash['formular'] : '';
        if ($mv_fname === 'einstellungen') { $mv_form_rueck = $mv_flash['eingaben']; }
        if ($mv_fname === 'mqtt') { $mv_mqtt_rueck = $mv_flash['eingaben']; }
        if (isset($mv_flash['falsch']) && is_array($mv_flash['falsch'])) {
            foreach ($mv_flash['falsch'] as $mv_z) {
                if (is_string($mv_z)) { $mv_falsch[$mv_z] = true; }
            }
        }
        mv_fa('', $mv_falsch);
    }
    if (!empty($mv_flash['suche'])) {
        $mv_suchergebnis = marstek_suchergebnis_lesen();
        if ($mv_suchergebnis === null) { $mv_suchergebnis = array(); }
        $mv_suchmeldung = (isset($mv_flash['suchmeldung']) && is_string($mv_flash['suchmeldung'])) ? $mv_flash['suchmeldung'] : '';
    }
}

/* ---------- Loxone-Vorlagen herunterladen (Download, veraendert nichts) ---------- */
if ($mv_post && isset($_POST['vorlage_paket'])) {
    $paket = marstek_vorlagen_paket();
    if ($paket !== null) {
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="' . $paket[0] . '"');
        echo $paket[1];
        exit;
    }
    mv_weiter('tab-loxone', array('fehler' => marstek_t('MELD.KEIN_ZIP')));
}
if ($mv_post && isset($_POST['vorlage_vo'])) {
    list($mv_vname, $mv_vinhalt) = marstek_vo_vorlage(isset($_POST['vorlage_dev']) && is_string($_POST['vorlage_dev']) ? (int) $_POST['vorlage_dev'] : 1);
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $mv_vname . '"');
    echo $mv_vinhalt;
    exit;
}
if ($mv_post && isset($_POST['vorlage'])) {
    list($mv_vname, $mv_vinhalt) = marstek_vorlage(
        is_string($_POST['vorlage']) ? $_POST['vorlage'] : 'status',
        isset($_POST['vorlage_dev']) && is_string($_POST['vorlage_dev']) ? (int) $_POST['vorlage_dev'] : 1);
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
if ($mv_post && isset($_POST['mv_sichern'])) {
    // Mit lesbarem Kopf (_hinweis, _stand, _fassung).
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="marstekvenus_' . date('Ymd_His') . '.json"');
    echo json_encode(marstek_sicherung_schreiben(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($mv_post && isset($_POST['mv_zurueck'])) {
    // Abgewiesen wird, was nicht passt - nie zurechtgebogen.
    if (!isset($_FILES['konfigdatei']) || !is_uploaded_file($_FILES['konfigdatei']['tmp_name'])) {
        mv_weiter('tab-settings', array('fehler' => marstek_t('MELD.IMPORT_KEINE_DATEI')));
    }
    if ((int) $_FILES['konfigdatei']['size'] > 262144) {
        /* Eine Sicherung dieses Plugins ist wenige Kilobyte gross.
         * Alles darueber wird gar nicht erst gelesen. */
        mv_weiter('tab-settings', array('fehler' => marstek_t('MELD.IMPORT_ZU_GROSS')));
    }
    /* Gelesen wird in der BIBLIOTHEK, nicht hier (siehe marstek_sicherung_lesen()).
     * C10 (30.09.2026): die Meldung sagt, was mit dem Aktionstoken und mit
     * fehlenden Schluesseln geschah; C13: weggefallene Geraetenummern werden
     * abgemeldet wie beim Speichern des Formulars. */
    $mv_vorher = array_keys(marstek_devices());
    $roh = (string) @file_get_contents($_FILES['konfigdatei']['tmp_name']);
    $mv_imp = marstek_sicherung_lesen($roh);
    $mv_imp_cfg = $mv_imp[0];
    $mv_imp_meld = $mv_imp[1];
    if ($mv_imp_cfg === null) {
        $mv_erste = isset($mv_imp_meld[0]) ? (string) $mv_imp_meld[0] : '';
        if ($mv_erste === 'KEIN_JSON') {
            $mv_f = marstek_t('MELD.IMPORT_KEIN_JSON');
        } elseif ($mv_erste === 'FREMD') {
            $mv_f = marstek_t('MELD.IMPORT_FREMD');
        } elseif (strncmp($mv_erste, 'UNBEKANNT:', 10) === 0) {
            $mv_f = sprintf(marstek_t('MELD.IMPORT_UNBEKANNT'), substr($mv_erste, 10));
        } elseif ($mv_erste === 'SCHREIBEN') {
            $mv_f = marstek_t('MELD.SPEICHERN_FEHLGESCHLAGEN');
        } else {
            // Alle Beanstandungen, nicht die erste.
            $mv_liste = array();
            foreach ($mv_imp_meld as $mv_z) {
                $mv_liste[] = strncmp($mv_z, 'WERT:', 5) === 0 ? substr($mv_z, 5) : $mv_z;
            }
            $mv_f = sprintf(marstek_t('MELD.IMPORT_WERTE'), implode(' | ', $mv_liste));
        }
        mv_weiter('tab-settings', array('fehler' => $mv_f));
    }
    $mv_texte = array(marstek_t('MELD.IMPORT_OK'));
    foreach ((isset($mv_imp[3]) && is_array($mv_imp[3])) ? $mv_imp[3] : array() as $mv_h) {
        if ($mv_h === 'TOKEN_BEHALTEN') {
            $mv_texte[] = marstek_t('MELD.IMPORT_TOKEN_BEHALTEN');
        } elseif ($mv_h === 'TOKEN_NEU') {
            $mv_texte[] = marstek_t('MELD.IMPORT_TOKEN_NEU');
        } elseif (strncmp($mv_h, 'BEHALTEN:', 9) === 0) {
            $mv_texte[] = sprintf(marstek_t('MELD.IMPORT_BEHALTEN'), substr($mv_h, 9));
        }
    }
    foreach (array_diff($mv_vorher, array_keys(marstek_devices())) as $mv_n) {
        $mv_a = marstek_geraet_abmelden($mv_n);
        $mv_texte[] = sprintf(marstek_t('MELD.GERAET_AUSGETRAGEN'), $mv_n,
            $mv_a['mqtt'] >= 0 ? sprintf(marstek_t('MELD.AUSGETRAGEN_MQTT'), $mv_a['mqtt'])
                               : marstek_t('MELD.AUSGETRAGEN_OHNE_MQTT'), $mv_a['alt']);
    }
    mv_weiter('tab-settings', array('saved' => 1, 'meldung' => implode(' ', $mv_texte)));
}

/* ---------- Log leeren ----------
 * O7 (30.09.2026): die Meldung folgt dem Rueckgabewert. Bis 1.1.17 kam bei
 * schreibgeschuetztem Protokoll gar keine Meldung, und die Zeile blieb. */
if ($mv_post && isset($_POST['clearlog'])) {
    if (!is_dir(dirname($mv_log_file))) { @mkdir(dirname($mv_log_file), 0775, true); }
    $mv_z = '[' . date('Y-m-d H:i:s') . '] ' . marstek_t('LOG.GELEERT') . "\n";
    if (@file_put_contents($mv_log_file, $mv_z) === strlen($mv_z)) {
        mv_weiter('tab-log', array('meldung' => marstek_t('MELD.LOG_GELEERT')));
    }
    mv_weiter('tab-log', array('fehler' => sprintf(marstek_t('MELD.LOG_NICHT_GELEERT'), $mv_log_file)));
}

/* ---------- Neues Aktionstoken erzeugen ---------- */
if ($mv_post && isset($_POST['token_neu'])) {
    $cfg = marstek_config();
    $cfg['aktionstoken'] = marstek_token_erzeugen();
    if (marstek_cfg_schreiben($cfg)) {
        marstek_log('Neues Aktionstoken erzeugt (Oberflaeche).');
        mv_weiter('tab-loxone', array('meldung' => marstek_t('MELD.TOKEN_NEU')));
    }
    mv_weiter('tab-loxone', array('fehler' => marstek_t('MELD.SPEICHERN_FEHLGESCHLAGEN')));
}

/* ---------- Geraetesuche ----------
 * Das Ergebnis liegt zehn Minuten im Zwischenspeicher und wird nach der
 * Umleitung einmal angezeigt (O1). */
if ($mv_post && isset($_POST['suchen'])) {
    list($mv_erg, $mv_sm) = marstek_suche(3);
    marstek_suchergebnis_ablegen($mv_erg);
    mv_weiter('tab-test', array('suche' => 1, 'suchmeldung' => (string) $mv_sm));
}
if ($mv_post && isset($_POST['uebernehmen']) && is_string($_POST['uebernehmen'])) {
    $ip = trim($_POST['uebernehmen']);
    // C11 (30.09.2026): das Modell aus der Suche belegt die Leistungsgrenzen vor.
    $mv_modell = (isset($_POST['modell']) && is_string($_POST['modell']))
        ? substr(preg_replace('/[^A-Za-z0-9 _.\-]/', '', $_POST['modell']), 0, 40) : '';
    if (!marstek_ip_gueltig($ip)) {
        mv_weiter('tab-test', array('fehler' => sprintf(marstek_t('MELD.IP_UNGUELTIG_SUCHE'), $ip)));
    }
    $cfg = marstek_config();
    // C13: die vergebenen Nummern bleiben; ein Eintrag ohne Nummer bekommt
    // jetzt die, unter der er bisher lief - die neue Zeile verschiebt nichts.
    $mv_nrn = marstek_geraete_nummern(isset($cfg['devices']) ? $cfg['devices'] : array());
    foreach ($mv_nrn as $d) {
        if (isset($d['ip']) && is_scalar($d['ip']) && trim((string) $d['ip']) === $ip) {
            mv_weiter('tab-test', array('meldung' => sprintf(marstek_t('MELD.SCHON_EINGETRAGEN'), $ip)));
        }
    }
    if (count($mv_nrn) >= 4) {
        mv_weiter('tab-test', array('fehler' => marstek_t('MELD.VIER_SCHON_VOLL')));
    }
    $mv_frei = 1;
    while (isset($mv_nrn[$mv_frei])) { $mv_frei++; }
    $mv_gr = marstek_modell_grenzen($mv_modell);
    $mv_neu = array();
    foreach ($mv_nrn as $mv_n => $d) { $d['nr'] = $mv_n; $mv_neu[] = $d; }
    $mv_ueb = array('name' => 'Venus E', 'ip' => $ip, 'port' => 30000,
        'pmax_charge' => $mv_gr !== null ? $mv_gr[0] : 2500,
        'pmax_discharge' => $mv_gr !== null ? $mv_gr[1] : 2500,
        'modbus' => 1, 'kwh' => 0, 'nr' => $mv_frei);
    // b1: das Modell aus der Suche steht danach in der Auswahl.
    if (marstek_modell_erkennen($mv_modell) !== '') {
        $mv_ueb['modell'] = marstek_modell_erkennen($mv_modell);
    }
    $mv_neu[] = $mv_ueb;
    $cfg['devices'] = $mv_neu;
    if (!marstek_cfg_schreiben($cfg)) {
        mv_weiter('tab-test', array('fehler' => marstek_t('MELD.SPEICHERN_FEHLGESCHLAGEN')));
    }
    $mv_t = sprintf(marstek_t('MELD.UEBERNOMMEN'), $ip);
    if ($mv_gr !== null) {
        $mv_t .= ' ' . sprintf(marstek_t('MELD.UEBERNOMMEN_GRENZEN'), $mv_modell, $mv_gr[0], $mv_gr[1]);
    }
    mv_weiter('tab-test', array('saved' => 1, 'meldung' => $mv_t));
}

/* ---------- Mitschnitt ein- und ausschalten ----------
 *
 * Er schaltet sich selbst ab. O7 (30.09.2026): die Meldung folgt dem
 * Rueckgabewert - bis 1.1.17 hiess es "laeuft bis ..." auch dann, wenn sich
 * die Merkerdatei nicht schreiben liess.
 */
if ($mv_post && isset($_POST['mitschnitt'])) {
    $sek = is_string($_POST['mitschnitt']) ? (int) $_POST['mitschnitt'] : 0;
    $bis = marstek_mitschnitt_schalten($sek);
    if ($bis === false) {
        mv_weiter('tab-test', array('fehler' => marstek_t('MELD.MITSCHNITT_FEHLER')));
    }
    mv_weiter('tab-test', array('meldung' => $bis > 0
        ? sprintf(marstek_t('MELD.MITSCHNITT_AN'), date('H:i:s', $bis))
        : marstek_t('MELD.MITSCHNITT_AUS')));
}

/* ---------- Schalten aus dem Reiter Test (O6, 30.09.2026) ----------
 *
 * Bis 1.1.17 waren die vier Knoepfe Verweise auf den Endpunkt mit dem Token in
 * der Adresse: F5 im geoeffneten Ergebnisreiter schaltete den Speicher erneut
 * (gemessen: 2 x ES.SetMode), und das Token stand im Browserverlauf. Jetzt ein
 * POST mit Merkmal, derselbe Weg wie der Endpunkt, danach die Umleitung.
 */
if ($mv_post && isset($_POST['schalten']) && is_string($_POST['schalten'])) {
    $mv_n = (isset($_POST['schalt_dev']) && is_string($_POST['schalt_dev']) && preg_match('/^[1-9]$/', $_POST['schalt_dev']))
        ? (int) $_POST['schalt_dev'] : 0;
    $mv_dv = marstek_dev($mv_n);
    $mv_befehle = array('leerlauf' => array(0, 60), 'entladen' => array(-800, 120), 'laden' => array(800, 120));
    if ($mv_n < 1 || $mv_dv === null) {
        mv_weiter('tab-test', array('fehler' => sprintf(marstek_t('MELD.SCHALTEN_KEIN_GERAET'), $mv_n)));
    }
    if (isset($mv_befehle[$_POST['schalten']])) {
        list($mv_ok, $mv_p, $mv_tt, $mv_h, $mv_b) = marstek_set_passive($mv_befehle[$_POST['schalten']][0],
            $mv_befehle[$_POST['schalten']][1], $mv_n);
        $mv_zeile = 'SET;OK=' . $mv_ok . ';P=' . $mv_p . ';T=' . $mv_tt . ';DEV=' . $mv_n
            . ($mv_b ? ';BEGRENZT=1' : '') . ($mv_h !== '' ? ';HINWEIS=' . $mv_h : '');
    } elseif ($_POST['schalten'] === 'auto') {
        list($mv_ok, $mv_m, $mv_h) = marstek_set_mode('auto', $mv_n);
        $mv_zeile = 'MODE;OK=' . $mv_ok . ';M=' . $mv_m . ';DEV=' . $mv_n . ($mv_h !== '' ? ';HINWEIS=' . $mv_h : '');
    } else {
        mv_weiter('tab-test', array('fehler' => sprintf(marstek_t('MELD.SCHALTEN_KEIN_GERAET'), $mv_n)));
    }
    marstek_log('Oberflaeche: Schaltknopf ' . $_POST['schalten'] . ' (Geraet ' . $mv_n . '): ' . $mv_zeile);
    mv_weiter('tab-test', array($mv_ok ? 'meldung' : 'fehler' =>
        sprintf(marstek_t('MELD.SCHALTEN_ERGEBNIS'), $mv_dv['name'], $mv_zeile)));
}

/* ---------- Verlauf als CSV (Download) ----------
 * O14 (30.09.2026): ein unbekanntes Geraet oder ein ungueltiger Tag ergibt
 * HTTP 400. Bis 1.1.17 kam eine leere Datei "marstek_verlauf_7_.csv". */
if ($mv_post && isset($_POST['verlauf_csv'])) {
    $n = (isset($_POST['verlauf_dev']) && is_string($_POST['verlauf_dev']) && preg_match('/^[1-9]$/', $_POST['verlauf_dev']))
        ? (int) $_POST['verlauf_dev'] : 0;
    $tag = (isset($_POST['verlauf_tag']) && is_string($_POST['verlauf_tag'])) ? $_POST['verlauf_tag'] : '';
    $mv_devs_csv = marstek_devices();
    if (!isset($mv_devs_csv[$n]) || !preg_match('/^\d{8}$/', $tag)
            || (!in_array($tag, marstek_history_tage($n), true) && $tag !== date('Ymd'))) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo marstek_t('MELD.CSV_UNGUELTIG') . "\n";
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="marstek_verlauf_' . $n . '_' . $tag . '.csv"');
    echo "zeit;soc_prozent;batterieleistung_w\r\n";
    foreach (marstek_history_read($n, $tag) as $p) {
        echo date('Y-m-d H:i:s', $p[0]) . ';' . $p[1] . ';' . $p[2] . "\r\n";
    }
    exit;
}

/* ---------- MQTT speichern (eigener Reiter, Hausstandard) ----------
 *
 * M6 (30.09.2026): das Praefix wird mit DERSELBEN Pruefung wie die Sicherung
 * und in der Form, die der Sender ohne Aenderung nimmt, angenommen - oder
 * abgewiesen. Bis 1.1.17 wurde "haus/mär stek" still zu "haus/mrstek", und
 * "marstek/" stand als Abo "marstek//#" in der Anleitung.
 * M5: bei einem Wechsel des Praefixes gehen die Signaturmerker weg (alles
 * geht beim naechsten Takt vollstaendig hinaus), und das alte Praefix raeumt
 * seine retained Themen einmal ab.
 */
if ($mv_post && isset($_POST['mqtt_save'])) {
    $cfg = marstek_config();
    $mv_alt_praefix = marstek_mqtt_prefix(1);
    $mv_alt_ein = !empty($cfg['mqtt_enabled']);
    $topic = (isset($_POST['mqtt_topic']) && is_string($_POST['mqtt_topic'])) ? trim($_POST['mqtt_topic']) : '';
    $mv_g = marstek_wert_pruefen('mqtt_topic', $topic);
    if ($mv_g !== '') {
        // X-2: das eingetippte Praefix und der Schalter kommen zurueck ins Formular.
        mv_weiter('tab-mqtt', array('fehler' => sprintf(marstek_t('MELD.PRAEFIX_UNGUELTIG'), $topic, $mv_g),
            'formular' => 'mqtt', 'falsch' => array('mqtt_topic'),
            'eingaben' => array('mqtt_topic' => $topic, 'mqtt_enabled' => isset($_POST['mqtt_enabled']) ? 1 : 0)));
    }
    $cfg['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $cfg['mqtt_topic'] = $topic;
    if (!marstek_cfg_schreiben($cfg)) {
        mv_weiter('tab-mqtt', array('fehler' => marstek_t('MELD.SPEICHERN_FEHLGESCHLAGEN')));
    }
    $mv_texte = array();
    if ($topic !== $mv_alt_praefix) {
        marstek_mqtt_merker_loeschen();
        $mv_z = marstek_mqtt_praefix_abraeumen($mv_alt_praefix);
        $mv_texte[] = $mv_z >= 0 ? sprintf(marstek_t('MELD.PRAEFIX_GEWECHSELT'), $mv_alt_praefix, $mv_z)
                                 : sprintf(marstek_t('MELD.PRAEFIX_GEWECHSELT_OHNE'), $mv_alt_praefix);
    } elseif (!$mv_alt_ein && !empty($cfg['mqtt_enabled'])) {
        marstek_mqtt_merker_loeschen();
    }
    mv_weiter('tab-mqtt', array('saved' => 1, 'meldung' => implode(' ', $mv_texte)));
}

/* ---------- Einstellungen speichern ----------
 *
 * O2 und O4 (Durchgang 30.09.2026): ABWEISEN STATT VERBIEGEN, mit DERSELBEN
 * Pruefung wie die Sicherung (marstek_wert_pruefen(), marstek_geraete_maengel(),
 * marstek_cfg_kreuzmaengel()). Bis 1.1.17 wurde hier geklemmt und gecastet:
 * Port 70000 -> 65535, "abc" -> 100, 2500.9 -> 2500, Kapazitaet "5,126" -> 0,
 * soc_min 60 / soc_max 30 -> beide 50 (der Speicher klebte bei 50 %), Markt
 * "ch" -> "de" - gemeldet wurde "Gespeichert.". Und was das Formular annahm,
 * wies die eigene Sicherung danach ab.
 * Jetzt: ist EIN Feld ungueltig, wird NICHTS gespeichert, alle Gruende stehen
 * in der Meldung, und das Formular zeigt die abgewiesene Eingabe wieder.
 * C13: die Zeile ist die Geraetenummer; eine geleerte Zeile rueckt nichts
 * nach, und eine weggefallene Nummer wird abgemeldet (marstek_geraet_abmelden()).
 * C11: liegt eine Leistungsgrenze ueber der des erkannten Modells, wird
 * gespeichert und gelb gewarnt.
 */
if ($mv_post && isset($_POST['save'])) {
    $alt = marstek_config();
    $mv_vorher = marstek_devices();
    $mv_str = function ($feld, $i = null) {
        if (!isset($_POST[$feld])) { return ''; }
        $v = $_POST[$feld];
        if ($i !== null) {
            if (!is_array($v)) { return null; }
            if (!isset($v[$i])) { return ''; }
            $v = $v[$i];
        }
        return is_string($v) ? trim($v) : null;
    };
    $mv_maengel = array();
    $mv_falsch_neu = array();   // X-2: die beanstandeten Felder
    $mv_rueck = array('geraete' => array());
    $mv_geraete = array();
    $mv_pruef = array();
    for ($i = 0; $i < 4; $i++) {
        $mv_nr = $i + 1;
        $mv_roh = array();
        foreach (array('name' => 'dev_name', 'ip' => 'dev_ip', 'port' => 'dev_port', 'pmax_charge' => 'dev_pc',
                       'pmax_discharge' => 'dev_pd', 'modbus' => 'dev_mb', 'kwh' => 'dev_kwh',
                       'modell' => 'dev_modell') as $mv_k => $mv_feld) {
            $v = $mv_str($mv_feld, $i);
            if ($v === null) {
                $mv_maengel[] = sprintf(marstek_t('MELD.EINGABE_UNGUELTIG'), $mv_nr, $mv_k);
                $mv_falsch_neu[] = 'dev_' . $mv_k . '_' . $mv_nr;   // X-2
                $v = '';
            }
            $mv_roh[$mv_k] = $v;
        }
        $mv_rueck['geraete'][$mv_nr] = $mv_roh;
        if ($mv_roh['ip'] === '') {
            continue;   // leere Zeile: diese Nummer ist unbelegt
        }
        if (!marstek_ip_gueltig($mv_roh['ip'])) {
            $mv_maengel[] = sprintf(marstek_t('MELD.IP_UNGUELTIG'), $mv_nr, $mv_roh['ip']);
            $mv_falsch_neu[] = 'dev_ip_' . $mv_nr;
        }
        $mv_kwh = str_replace(',', '.', $mv_roh['kwh']);
        $mv_eintrag = array(
            'name' => $mv_roh['name'],
            'ip' => $mv_roh['ip'],
            'port' => $mv_roh['port'] === '' ? '30000' : $mv_roh['port'],
            'pmax_charge' => $mv_roh['pmax_charge'] === '' ? '2500' : $mv_roh['pmax_charge'],
            'pmax_discharge' => $mv_roh['pmax_discharge'] === '' ? '2500' : $mv_roh['pmax_discharge'],
            'modbus' => $mv_roh['modbus'] === '1' ? 1 : 0,
            'kwh' => $mv_kwh === '' ? '0' : $mv_kwh,
            'nr' => $mv_nr,
            'modell' => $mv_roh['modell'],   // b1: '' = Erkennung
        );
        $mv_geraete[] = $mv_eintrag;
        // Die gemeinsame Pruefung bekommt die Adresse nur, wenn sie gueltig
        // ist - sonst stuende derselbe Grund zweimal in der Meldung.
        $mv_pruef[] = array('ip' => marstek_ip_gueltig($mv_roh['ip']) ? $mv_roh['ip'] : '') + $mv_eintrag;
        // X-2: welches Feld der Zeile beanstandet ist - dieselbe Pruefung, je Feld allein.
        foreach (array('name', 'port', 'pmax_charge', 'pmax_discharge', 'kwh', 'modell') as $mv_k) {
            if (marstek_geraete_maengel(array(array($mv_k => $mv_eintrag[$mv_k])))) {
                $mv_falsch_neu[] = 'dev_' . $mv_k . '_' . $mv_nr;
            }
        }
    }
    foreach (marstek_geraete_maengel($mv_pruef) as $mv_g) {
        $mv_maengel[] = $mv_g;
    }
    $mv_felder = array('cache_sec' => 'EINST.L_CACHE', 'fallback_min' => 'EINST.L_FALLBACK',
                       'verlauf_tage' => 'EINST.L_VERLAUF_TAGE', 'melden_ab' => 'EINST.L_MELDEN_AB',
                       'temp_min' => 'EINST.L_TEMP_MIN', 'temp_max' => 'EINST.L_TEMP_MAX',
                       'soc_min' => 'EINST.L_SOC_MIN', 'soc_max' => 'EINST.L_SOC_MAX',
                       'vat' => 'EINST.L_UST', 'aufschlag_ct' => 'EINST.L_AUFSCHLAG', 'awattar' => 'EINST.L_MARKT');
    $mv_neu = array();
    foreach ($mv_felder as $mv_k => $mv_l) {
        $v = $mv_str($mv_k);
        if ($v === null) { $v = ''; }
        if ($mv_k === 'vat' || $mv_k === 'aufschlag_ct') { $v = str_replace(',', '.', $v); }
        $mv_rueck[$mv_k] = $v;
        $mv_g = marstek_wert_pruefen($mv_k, $v);
        if ($mv_g !== '') {
            $mv_falsch_neu[] = $mv_k;   // X-2
            if ($mv_k === 'vat') {
                $mv_maengel[] = sprintf(marstek_t('MELD.UST_UNGUELTIG'), $v);
            } elseif ($mv_k === 'aufschlag_ct') {
                $mv_maengel[] = sprintf(marstek_t('MELD.AUFSCHLAG_UNGUELTIG'), $v);
            } else {
                $mv_maengel[] = marstek_t($mv_l) . ': ' . $v . ' - ' . $mv_g;
            }
            continue;
        }
        if ($mv_k === 'vat') {
            $mv_neu[$mv_k] = (float) $v;
        } elseif ($mv_k === 'aufschlag_ct') {
            $mv_neu[$mv_k] = round((float) $v, 3);
        } elseif ($mv_k === 'awattar') {
            $mv_neu[$mv_k] = $v;
        } else {
            $mv_neu[$mv_k] = (int) $v;
        }
    }
    foreach (array('steuerung_ein', 'verteilen_ein', 'melden_ein', 'schutz_ein', 'bremse_abstand_ein') as $mv_k) {
        $mv_neu[$mv_k] = isset($_POST[$mv_k]) ? 1 : 0;
        $mv_rueck[$mv_k] = $mv_neu[$mv_k];
    }
    foreach (marstek_cfg_kreuzmaengel($mv_neu + $alt) as $mv_g) {
        $mv_maengel[] = $mv_g;
    }
    // X-2: bei einer Kreuzbeanstandung sind beide Felder des Paars markiert.
    foreach (array(array('soc_min', 'soc_max'), array('temp_min', 'temp_max')) as $mv_paar) {
        $mv_pw = array_intersect_key($mv_neu + $alt, array_flip($mv_paar));
        if (marstek_cfg_kreuzmaengel($mv_pw)) {
            $mv_falsch_neu[] = $mv_paar[0];
            $mv_falsch_neu[] = $mv_paar[1];
        }
    }
    if ($mv_maengel) {
        mv_weiter('tab-settings', array('beanstandung' => $mv_maengel, 'formular' => 'einstellungen',
            'eingaben' => $mv_rueck, 'falsch' => array_values(array_unique($mv_falsch_neu))));
    }
    // Ab hier ist alles geprueft; erst jetzt werden die Typen gesetzt.
    $mv_norm = array();
    $mv_warn = array();
    foreach ($mv_geraete as $g) {
        $e = array('name' => $g['name'], 'ip' => $g['ip'], 'port' => (int) $g['port'],
                   'pmax_charge' => (int) $g['pmax_charge'], 'pmax_discharge' => (int) $g['pmax_discharge'],
                   'modbus' => (int) $g['modbus'], 'kwh' => round((float) $g['kwh'], 2), 'nr' => (int) $g['nr']);
        if ($g['modell'] !== '') { $e['modell'] = $g['modell']; }   // b1
        $mv_norm[] = $e;
        /* C11, b1: die Grenzen des GEWAEHLTEN Modells. Ohne Wahl wie bis
         * 1.1.18 die Erkennung - nur, wenn unter dieser Nummer schon DIESER
         * Speicher lief, denn das Modell stammt aus seinem Zwischenspeicher.
         * "anderes" hat keine Grenzen und warnt nicht. */
        $mv_mk = $g['modell'];
        $mv_modell = '';
        if ($mv_mk === '' && isset($mv_vorher[$e['nr']]) && $mv_vorher[$e['nr']]['ip'] === $e['ip']) {
            $mv_modell = marstek_modell_von($e['nr']);
            $mv_mk = marstek_modell_erkennen($mv_modell);
        }
        $mv_mod = marstek_modelle();
        $mv_gr = ($mv_mk !== '' && isset($mv_mod[$mv_mk])) ? $mv_mod[$mv_mk] : null;
        if ($mv_modell === '' && $mv_mk !== '') {
            $mv_modell = marstek_t('EINST.MODELL_' . strtoupper($mv_mk));
        }
        if ($mv_gr !== null && ($e['pmax_charge'] > $mv_gr[0] || $e['pmax_discharge'] > $mv_gr[1])) {
            $mv_warn[] = sprintf(marstek_t('MELD.MODELL_GRENZE'), $e['nr'], $mv_modell, $mv_gr[0], $mv_gr[1],
                                 $e['pmax_charge'], $e['pmax_discharge']);
        }
    }
    $cfg = $alt;                       // Bestand uebernehmen, dann ueberschreiben.
    foreach ($mv_neu as $mv_k => $v) { $cfg[$mv_k] = $v; }
    $cfg['devices'] = $mv_norm;
    // MQTT und Aktionstoken kommen aus dem Bestand und werden hier nicht
    // angefasst. Bis 1.0.10 fehlte das fuer aktionstoken: jedes Speichern
    // warf es still weg, und alle Loxone-Adressen liefen danach auf 403.
    if (!marstek_cfg_schreiben($cfg)) {
        mv_weiter('tab-settings', array('fehler' => marstek_t('MELD.SPEICHERN_FEHLGESCHLAGEN'),
            'formular' => 'einstellungen', 'eingaben' => $mv_rueck, 'falsch' => array()));
    }
    $mv_texte = array();
    foreach (array_diff(array_keys($mv_vorher), array_keys(marstek_devices())) as $mv_n) {
        $mv_a = marstek_geraet_abmelden($mv_n);
        $mv_texte[] = sprintf(marstek_t('MELD.GERAET_AUSGETRAGEN'), $mv_n,
            $mv_a['mqtt'] >= 0 ? sprintf(marstek_t('MELD.AUSGETRAGEN_MQTT'), $mv_a['mqtt'])
                               : marstek_t('MELD.AUSGETRAGEN_OHNE_MQTT'), $mv_a['alt']);
    }
    mv_weiter('tab-settings', array('saved' => 1, 'warnungen' => $mv_warn, 'meldung' => implode(' ', $mv_texte)));
}

/* ---------- Laden ---------- */
$mv_cfg = marstek_config();
$mv_devices = marstek_devices();
$mv_fehlten = marstek_cfg_vervollstaendigen();

// Beim ersten Aufruf ein Token erzeugen, damit der Endpunkt fuer Loxone sofort
// benutzbar ist (schuetzt ?p= und ?mode= im unangemeldeten marstek.php).
//
// BERICHTIGT 18.09.2026 - der dritte Weg. Ein frisch gewuerfeltes Token ist ein
// gueltiger Wert und kaeme deshalb durch die Zweitschrift-Wache in
// marstek_cfg_schreiben() hindurch. Gemessen (Fall F "ui_dritter_weg", WSL):
// marstek.json abgeschnitten und schreibgeschuetzt, Zweitschrift heil - die
// Heilung konnte nicht schreiben, diese Zeile wuerfelte ein neues Token, und
// das Speichern legte es ueber die Zweitschrift:
//     M2 Zweitschrift traegt altes Token: NEIN (erwartet JA)
// Ein neues Token entsteht deshalb nur, wenn keine Zweitschrift mit Token
// danebenliegt (marstek_token_darf_entstehen()). Der Knopf "Neues Aktionstoken
// erzeugen" weiter oben bleibt davon unberuehrt - er ist eine ausdrueckliche
// Entscheidung des Bedieners.
if (empty($mv_cfg['aktionstoken'])) {
    if (marstek_token_darf_entstehen()) {
        $mv_cfg['aktionstoken'] = marstek_token_erzeugen();
        marstek_cfg_schreiben($mv_cfg);
    } else {
        marstek_log('Es wurde KEIN neues Aktionstoken erzeugt: die Konfiguration traegt '
            . 'keines, aber die Zweitschrift neben dem Plugin-Ordner tut es. Sonst waeren '
            . 'alle Loxone-Adressen still auf HTTP 403 gelaufen.');
    }
}

// O4: nach einer Abweisung zeigt das Formular die abgewiesene Eingabe wieder,
// nicht den gespeicherten Stand - sonst tippt der Anwender alles noch einmal.
// Nur die Felder des Einstellungsformulars; Token und MQTT bleiben unberuehrt.
if (is_array($mv_form_rueck)) {
    foreach (array('cache_sec', 'fallback_min', 'verlauf_tage', 'melden_ab', 'temp_min', 'temp_max',
                   'soc_min', 'soc_max', 'vat', 'aufschlag_ct', 'awattar',
                   'steuerung_ein', 'verteilen_ein', 'melden_ein', 'schutz_ein', 'bremse_abstand_ein') as $mv_k) {
        if (array_key_exists($mv_k, $mv_form_rueck) && is_scalar($mv_form_rueck[$mv_k])) {
            $mv_cfg[$mv_k] = $mv_form_rueck[$mv_k];
        }
    }
}

// X-2: dasselbe fuer das MQTT-Formular.
if (is_array($mv_mqtt_rueck)) {
    foreach (array('mqtt_topic', 'mqtt_enabled') as $mv_k) {
        if (array_key_exists($mv_k, $mv_mqtt_rueck) && is_scalar($mv_mqtt_rueck[$mv_k])) {
            $mv_cfg[$mv_k] = $mv_mqtt_rueck[$mv_k];
        }
    }
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
// Vom ENDE lesen, nicht die ganze Datei einlesen. file() zieht sie
// vollstaendig in den Speicher; cron.err hatte bis 1.1.4 ausserdem keine
// Kappung und konnte auf der Ramdisk beliebig wachsen.
$mv_ereignisse = array_reverse(marstek_ereignisse(200));
$mv_log_lines = array_reverse(marstek_log_ende($mv_log_file, 300));
$mv_err_lines = array_reverse(marstek_log_ende($mv_err_file, 50));

/* Die Kurzform e() ist in 1.1.5 entfallen. Sie stand als GLOBALE Funktion
 * ohne Plugin-Kuerzel hier, mit 196 Aufrufen, waehrend die Bibliothek
 * daneben marstek_e() mit demselben Rumpf fuehrte und sechsmal gerufen wurde.
 * index.php bindet vorher loxberry_system.php und loxberry_web.php ein; eine
 * gleichnamige Funktion dort waere ein sofortiger fataler Fehler gewesen.
 * Jetzt gibt es einen Maskierhelfer, und der liegt in der Bibliothek.
 */

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
    // O12 (30.09.2026): ein Tag hat 23, 24 oder 25 Stunden. Bis 1.1.17 wurde
    // mit 86400 s gerechnet - am 25.10.2026 standen 25 Stundenpunkte in der
    // Datei, gezeichnet wurden 24, und die Achse lag ab 03:00 eine Stunde daneben.
    $day1 = (int) strtotime('+1 day', $day0);
    $tlen = max(1, $day1 - $day0);
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
        $tsh = $hh === 24 ? $day1 : (int) strtotime(date('Y-m-d', $day0) . sprintf(' %02d:00', $hh));
        $x = $x0 + $pw * ($tsh - $day0) / $tlen;
        $svg .= '<line x1="' . $x . '" y1="' . $y0 . '" x2="' . $x . '" y2="' . ($y0 + $ph) . '" stroke="#eeeeee" stroke-width="1"/>';
        $svg .= '<text x="' . $x . '" y="' . ($h - 6) . '" font-size="9" fill="#999" text-anchor="middle">' . $hh . ':00</text>';
    }
    $poly = array(); $polyp = array();
    foreach ($points as $pt) {
        $frac = ($pt[0] - $day0) / $tlen;
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
              . marstek_e(marstek_t('EINST.KEINE_MESSPUNKTE')) . '</text>';
    }
    return $svg . '</svg>';
}

// WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg aus general.json als stdClass)
// und wuerde gleichnamige Plugin-Variablen ueberschreiben - daher hier ueberall mv_-Praefix.
$mv_use_frame = class_exists('LBWeb', false);
if ($mv_use_frame) {
    LBWeb::lbheader('Marstek Venus E', 'https://wiki.loxberry.de/', 'help.html');
}
$mv_host = marstek_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
$mv_ft = marstek_e(marstek_formtoken());
// Laesst sich das Merkmal nicht ablegen, weist die Seite JEDES Formular ab.
// Bis 1.1.4 stand nirgends, warum - die Meldung riet zum Neuladen, was nie
// half. Gemessen mit einem Verzeichnis an der Stelle der Datei.
$mv_ft_stoerung = marstek_formtoken_stoerung();
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
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
/* Diese zweite Zeile stand bis 1.1.4 nicht da. Nachgetragen aus
   VORLAGE_hausstandard.css.html - der Behaelter rollt zwar auch ohne sie
   (am Bildschirm gemessen: 580 px Inhalt in 380 px Behaelter, weil die
   Eingabefelder eine Mindestbreite erzwingen), aber die Vorlage ist die
   Vorlage. */
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Auswahlfelder zeichnen ihren Pfeil selbst. Die Rahmen-CSS von jQuery
   Mobile setzt appearance auf none und nimmt den Pfeil weg; data-role="none"
   haelt nur deren Umbauten fern, nicht deren Stilregeln. Ohne Pfeil sieht
   das Feld aus wie ein Textfeld - dieselbe Fehlerklasse hat zweimal ein
   Mensch am Geraet gefunden. Die Raute im SVG ist %23, eine rohe Raute
   beendete den CSS-Wert. */
.sm-wrap select, .sm-tbl select, .sm-auswahl {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%23546e7a' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px;
}
.sm-devtbl input, .sm-devtbl select { min-width: 60px; }
/* X-2: ein beanstandetes Feld (Ergaenzung zur Vorlage). */
.sm-wrap input[aria-invalid="true"], .sm-wrap select[aria-invalid="true"] {
    border: 2px solid #c62828 !important; background-color: #ffebee !important; }
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
<?php if ($mv_ft_stoerung !== '') { ?>
<div class="sm-warnung"><?= marstek_e(sprintf(marstek_t('MELD.FORMTOKEN_ABLAGE'), $mv_ft_stoerung)) ?></div>
<?php } ?>

<?php if ($mv_saved) { ?><div class="sm-alert sm-ok"><b><?= marstek_e(marstek_t('MELD.GESPEICHERT')) ?></b> <?= marstek_e(marstek_t('MELD.GESPEICHERT_ZUSATZ')) ?></div><?php } ?>
<?php if ($mv_meldung !== '') { ?><div class="sm-alert sm-ok"><?= marstek_e($mv_meldung) ?></div><?php } ?>
<?php if ($mv_save_error !== '') { ?><div class="sm-alert sm-err"><b><?= marstek_e(marstek_t('MELD.FEHLER')) ?></b> <?= marstek_e($mv_save_error) ?></div><?php } ?>
<?php if ($mv_beanstandung) { ?><div class="sm-alert sm-err"><b><?= marstek_e(marstek_t('MELD.BEANSTANDUNG')) ?></b><ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($mv_beanstandung as $b) { ?><li><?= marstek_e($b) ?></li><?php } ?>
</ul></div><?php } ?>
<?php if ($mv_warnungen) { ?><div class="sm-alert sm-warn"><b><?= marstek_e(marstek_t('MELD.WARNUNG_KOPF')) ?></b><ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($mv_warnungen as $b) { ?><li><?= marstek_e($b) ?></li><?php } ?>
</ul></div><?php } ?>
<?php if ($mv_fehlten) { ?><div class="sm-alert sm-info"><?= marstek_e(sprintf(marstek_t('MELD.KONFIG_ERGAENZT'), implode(', ', $mv_fehlten))) ?></div><?php } ?>

<?php foreach ($mv_statuses as $n => $st) {
    $alter = !empty($st['mess']) ? time() - (int) $st['mess'] : -1; ?>
<div class="sm-alert sm-info"><b><?= marstek_e($mv_devices[$n]['name']) ?></b>
 &middot; <?= marstek_e(marstek_t('EINST.LADEZUSTAND')) ?>: <?= marstek_e($st['soc']) ?> %
 &middot; <?= marstek_e(marstek_t('EINST.BATTERIELEISTUNG')) ?>: <?= marstek_e($st['batp']) ?> W
 &middot; <?= marstek_e(marstek_t('EINST.TEMPERATUR')) ?>: <?= marstek_e($st['temp']) ?> &deg;C
 &middot; <?= marstek_e(marstek_t('EINST.VERBINDUNG')) ?>: <?php if (!empty($st['ok'])) { ?><span class="sm-ja">OK</span><?php } else { ?><span class="sm-nein"><?= marstek_e(marstek_t('EINST.GESTOERT')) ?></span><?php } ?>
<?php if (!empty($st['ok'])) { ?> &middot; <?= marstek_e(isset($st['model']) && $st['model'] !== '' ? $st['model'] : 'Venus') ?>
 &middot; <?= marstek_e(marstek_t('EINST.FIRMWARE')) ?> <?= (int) (isset($st['fw']) ? $st['fw'] : 0) ?>
 &middot; <?= marstek_e(marstek_t('EINST.ANTWORTZEIT')) ?> <?= (int) (isset($st['ms']) ? $st['ms'] : 0) ?> ms<?php } ?>
<br><span class="sm-small"><?= marstek_e(sprintf(marstek_t('EINST.LETZTE_MESSUNG'),
        $alter >= 0 ? date('d.m.Y H:i:s', (int) $st['mess']) . ' (' . $alter . ' s)' : marstek_t('EINST.NIE'))) ?></span>
<?php if (!empty($mv_devices[$n]['modbus'])) {
    $mv_edat = marstek_tmpdir() . '/energy_dev' . $n . '.json';
    $en = is_file($mv_edat) ? json_decode((string) @file_get_contents($mv_edat), true) : null;
    if (is_array($en) && !empty($en['chgt'])) { ?>
<br><?= marstek_e(marstek_t('EINST.ENERGIE_HEUTE')) ?>: <b><?= marstek_e($en['chgd']) ?> kWh</b> <?= marstek_e(marstek_t('EINST.GELADEN')) ?>,
<b><?= marstek_e($en['disd']) ?> kWh</b> <?= marstek_e(marstek_t('EINST.ABGEGEBEN')) ?>
&middot; <?= marstek_e(marstek_t('EINST.MONAT')) ?>: <?= marstek_e($en['chgm']) ?> / <?= marstek_e($en['dism']) ?> kWh
&middot; <?= marstek_e(marstek_t('EINST.ZYKLEN')) ?>: <?= (int) $en['cyc'] ?>
&middot; <?= marstek_e(marstek_t('EINST.WIRKUNGSGRAD')) ?>: <?= marstek_e($en['eff']) ?> %
<?php } } ?>
<?php
    $vtag = ($mv_verlauf_dev === $n && $mv_verlauf_tag !== '') ? $mv_verlauf_tag : date('Ymd');
    $vtage = marstek_history_tage($n);
    $hist = marstek_history_read($n, $vtag);
    $kz = marstek_history_kennzahlen($n, $vtag); ?>
<div style="margin-top:8px;"><?= mv_soc_svg($hist, $vtag) ?></div>
<div class="sm-small"><?= marstek_e(marstek_t('EINST.VERLAUF_ERKLAERUNG')) ?>
<?php if (!empty($kz['ok'])) { ?> &middot; <?= marstek_e(sprintf(marstek_t('EINST.VERLAUF_KENNZAHLEN'), $kz['socmin'], $kz['socmax'], $kz['hub'], $kz['n'])) ?><?php } ?>
</div>
<?php if (count($vtage) > 1) { ?>
<div class="sm-small" style="margin-top:4px;"><?= marstek_e(marstek_t('EINST.TAG_WAEHLEN')) ?>:
<?php foreach (array_slice($vtage, 0, 14) as $t) {
    $bez = substr($t, 6, 2) . '.' . substr($t, 4, 2) . '.'; ?>
<a href="index.php?tab=settings&amp;vdev=<?= $n ?>&amp;vtag=<?= marstek_e($t) ?>"<?= $t === $vtag ? ' style="font-weight:700;"' : '' ?>><?= marstek_e($bez) ?></a>
<?php } ?>
</div>
<?php } ?>
<form action="index.php" method="post" style="margin-top:6px;">
  <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <input data-role="none" type="hidden" name="verlauf_dev" value="<?= $n ?>">
  <input data-role="none" type="hidden" name="verlauf_tag" value="<?= marstek_e($vtag) ?>">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="verlauf_csv" value="1" style="min-width:200px;"><?= marstek_e(marstek_t('EINST.K_CSV')) ?></button>
</form>
</div>
<?php } ?>

<!-- Reiterleiste: echte Verweise, ausgeschrieben. Eine erzeugte Leiste macht
     hausstandard_pruefen.py blind, und ein Strich sammelt sich beim
     Ueberfliegen wie ein Haken ein. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $mv_active_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?tab=settings"><?= marstek_e(marstek_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $mv_active_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?tab=mqtt">MQTT</a>
	<a class="sm-tab<?= $mv_active_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?tab=loxone"><?= marstek_e(marstek_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $mv_active_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?tab=test"><?= marstek_e(marstek_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $mv_active_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?tab=log"><?= marstek_e(marstek_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $mv_active_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= marstek_e(marstek_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= marstek_e(marstek_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= marstek_e(marstek_t('EINST.H_GERAETE')) ?></h2>
<div class="sm-hilfe"><?= marstek_t('EINST.GERAETE_ERKLAERUNG') ?></div>
<div class="sm-breit">
<table class="sm-tbl sm-devtbl">
<tr><th style="width:34px;">Nr.</th><th><?= marstek_e(marstek_t('EINST.SP_NAME')) ?></th><th><?= marstek_e(marstek_t('EINST.SP_IP')) ?></th>
<th style="width:130px;"><?= marstek_e(marstek_t('EINST.SP_MODELL')) ?></th>
<th style="width:88px;"><?= marstek_e(marstek_t('EINST.SP_PORT')) ?></th><th style="width:104px;"><?= marstek_e(marstek_t('EINST.SP_PMAX_LADEN')) ?></th>
<th style="width:104px;"><?= marstek_e(marstek_t('EINST.SP_PMAX_ENTLADEN')) ?></th><th style="width:96px;"><?= marstek_e(marstek_t('EINST.SP_KWH')) ?></th>
<th style="width:110px;"><?= marstek_e(marstek_t('EINST.SP_MODBUS')) ?></th></tr>
<?php
// C13 (30.09.2026): Zeile N ist Geraet N - die Nummer haengt am Speicher,
// nicht an der Stelle in der Liste (marstek_geraete_nummern()).
$mv_zeilen = marstek_geraete_nummern(isset($mv_cfg['devices']) ? $mv_cfg['devices'] : array());
$mv_s = function ($v) { return is_scalar($v) ? (string) $v : ''; };
for ($i = 0; $i < 4; $i++) {
    if (is_array($mv_form_rueck) && isset($mv_form_rueck['geraete']) && is_array($mv_form_rueck['geraete'])) {
        $d = (isset($mv_form_rueck['geraete'][$i + 1]) && is_array($mv_form_rueck['geraete'][$i + 1]))
            ? $mv_form_rueck['geraete'][$i + 1] : array();
        if (isset($d['modbus'])) { $d['modbus'] = ($d['modbus'] === '1' || $d['modbus'] === 1) ? 1 : 0; }
    } else {
        $d = (isset($mv_zeilen[$i + 1]) && is_array($mv_zeilen[$i + 1])) ? $mv_zeilen[$i + 1] : array();
    }
    $d += array('name' => '', 'ip' => '', 'port' => 30000, 'pmax_charge' => 2500, 'pmax_discharge' => 2500, 'modbus' => 1, 'kwh' => 0,
                'modell' => '');
    /* b1: das Modell - gewaehlt, sonst (Eintrag ohne Modell, wie bis 1.1.18)
     * das Ergebnis der Erkennung aus der letzten Geraetemeldung. */
    $mv_mod_wahl = $mv_s($d['modell']);
    $mv_mod_erkannt = '';
    if ($mv_mod_wahl === '' && !is_array($mv_form_rueck) && isset($mv_zeilen[$i + 1])) {
        $mv_mod_erkannt = marstek_modell_von($i + 1);
        $mv_mod_wahl = marstek_modell_erkennen($mv_mod_erkannt);
    }
    $mv_kwh_anz = $mv_s($d['kwh']);
    if (is_numeric($mv_kwh_anz) && (float) $mv_kwh_anz <= 0) { $mv_kwh_anz = ''; } ?>
<tr>
<td><?= $i + 1 ?></td>
<td><input<?= mv_fa('dev_name_' . ($i + 1)) ?> data-role="none" type="text" name="dev_name[]" value="<?= marstek_e($mv_s($d['name'])) ?>" placeholder="<?= marstek_e($i === 0 ? marstek_t('EINST.PH_NAME') : marstek_t('EINST.PH_LEER')) ?>"></td>
<td><input<?= mv_fa('dev_ip_' . ($i + 1)) ?> data-role="none" type="text" name="dev_ip[]" value="<?= marstek_e($mv_s($d['ip'])) ?>" placeholder="<?= marstek_e($i === 0 ? '192.168.1.25' : '') ?>"></td>
<td><select<?= mv_fa('dev_modell_' . ($i + 1)) ?> data-role="none" class="sm-auswahl mv-modell" name="dev_modell[]">
<option value=""<?= $mv_mod_wahl === '' ? ' selected' : '' ?>><?= marstek_e(marstek_t('EINST.MODELL_UNBEKANNT')) ?></option>
<?php foreach (array_keys(marstek_modelle()) as $mv_mk) { ?>
<option value="<?= marstek_e($mv_mk) ?>"<?= $mv_mod_wahl === $mv_mk ? ' selected' : '' ?>><?= marstek_e(marstek_t('EINST.MODELL_' . strtoupper($mv_mk))) ?></option>
<?php } ?>
</select><?php if ($mv_mod_erkannt !== '') { ?><div class="sm-hilfe"><?= marstek_e(sprintf(marstek_t('EINST.MODELL_ERKANNT'), $mv_mod_erkannt)) ?></div><?php } ?></td>
<td><input<?= mv_fa('dev_port_' . ($i + 1)) ?> data-role="none" type="number" name="dev_port[]" value="<?= marstek_e($mv_s($d['port'])) ?>" min="1" max="65535"></td>
<td><input<?= mv_fa('dev_pmax_charge_' . ($i + 1)) ?> data-role="none" type="number" name="dev_pc[]" value="<?= marstek_e($mv_s($d['pmax_charge'])) ?>" min="100" max="3600"></td>
<td><input<?= mv_fa('dev_pmax_discharge_' . ($i + 1)) ?> data-role="none" type="number" name="dev_pd[]" value="<?= marstek_e($mv_s($d['pmax_discharge'])) ?>" min="100" max="3600"></td>
<td><input<?= mv_fa('dev_kwh_' . ($i + 1)) ?> data-role="none" type="text" name="dev_kwh[]" value="<?= marstek_e($mv_kwh_anz) ?>" placeholder="5.12"></td>
<td><select data-role="none" class="sm-auswahl" name="dev_mb[]">
<option value="0"<?= empty($d['modbus']) ? ' selected' : '' ?>><?= marstek_e(marstek_t('EINST.AUS')) ?></option>
<option value="1"<?= !empty($d['modbus']) ? ' selected' : '' ?>><?= marstek_e(marstek_t('EINST.EIN')) ?></option>
</select></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= marstek_t('EINST.GRENZEN_ERKLAERUNG') ?></div>

<h2><?= marstek_e(marstek_t('EINST.H_BETRIEB')) ?></h2>
<div class="sm-row">
    <div>
        <label><?= marstek_e(marstek_t('EINST.L_CACHE')) ?></label>
        <input<?= mv_fa('cache_sec') ?> data-role="none" type="number" name="cache_sec" value="<?= mv_wert($mv_cfg['cache_sec']) ?>" min="5" max="300">
        <div class="sm-hilfe"><?= marstek_e(marstek_t('EINST.H_CACHE')) ?></div>
    </div>
    <div>
        <label><?= marstek_e(marstek_t('EINST.L_FALLBACK')) ?></label>
        <input<?= mv_fa('fallback_min') ?> data-role="none" type="number" name="fallback_min" value="<?= mv_wert($mv_cfg['fallback_min']) ?>" min="0" max="1440">
        <div class="sm-hilfe"><?= marstek_e(marstek_t('EINST.H_FALLBACK')) ?></div>
    </div>
    <div>
        <label><?= marstek_e(marstek_t('EINST.L_VERLAUF_TAGE')) ?></label>
        <input<?= mv_fa('verlauf_tage') ?> data-role="none" type="number" name="verlauf_tage" value="<?= mv_wert($mv_cfg['verlauf_tage']) ?>" min="1" max="365">
        <div class="sm-hilfe"><?= marstek_e(marstek_t('EINST.H_VERLAUF_TAGE')) ?></div>
    </div>
</div>
<label style="display:inline-flex;align-items:center;gap:6px;margin-top:14px;">
    <input data-role="none" type="checkbox" name="steuerung_ein" <?= !empty($mv_cfg['steuerung_ein']) ? 'checked' : '' ?>>
    <?= marstek_e(marstek_t('EINST.L_STEUERUNG')) ?>
</label>
<div class="sm-hilfe"><?= marstek_e(marstek_t('EINST.H_STEUERUNG')) ?></div>
<label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;">
    <input data-role="none" type="checkbox" name="verteilen_ein" <?= !empty($mv_cfg['verteilen_ein']) ? 'checked' : '' ?>>
    <?= marstek_e(marstek_t('EINST.L_VERTEILEN')) ?>
</label>
<div class="sm-hilfe"><?= marstek_e(marstek_t('EINST.H_VERTEILEN')) ?></div>
<label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;">
    <input data-role="none" type="checkbox" name="bremse_abstand_ein" <?= !empty($mv_cfg['bremse_abstand_ein']) ? 'checked' : '' ?>>
    <?= marstek_e(marstek_t('EINST.L_BREMSE_ABSTAND')) ?>
</label>
<div class="sm-hilfe"><?= marstek_e(marstek_t('EINST.H_BREMSE_ABSTAND')) ?></div>

<h2><?= marstek_e(marstek_t('EINST.H_SCHUTZ')) ?></h2>
<div class="sm-hinweis"><?= marstek_t('EINST.SCHUTZ_ERKLAERUNG') ?></div>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="schutz_ein" <?= !empty($mv_cfg['schutz_ein']) ? 'checked' : '' ?>>
    <?= marstek_e(marstek_t('EINST.L_SCHUTZ')) ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div><label><?= marstek_e(marstek_t('EINST.L_TEMP_MIN')) ?></label>
        <input<?= mv_fa('temp_min') ?> data-role="none" type="number" name="temp_min" value="<?= mv_wert($mv_cfg['temp_min']) ?>" min="-20" max="20"></div>
    <div><label><?= marstek_e(marstek_t('EINST.L_TEMP_MAX')) ?></label>
        <input<?= mv_fa('temp_max') ?> data-role="none" type="number" name="temp_max" value="<?= mv_wert($mv_cfg['temp_max']) ?>" min="20" max="80"></div>
    <div><label><?= marstek_e(marstek_t('EINST.L_SOC_MIN')) ?></label>
        <input<?= mv_fa('soc_min') ?> data-role="none" type="number" name="soc_min" value="<?= mv_wert($mv_cfg['soc_min']) ?>" min="0" max="50"></div>
    <div><label><?= marstek_e(marstek_t('EINST.L_SOC_MAX')) ?></label>
        <input<?= mv_fa('soc_max') ?> data-role="none" type="number" name="soc_max" value="<?= mv_wert($mv_cfg['soc_max']) ?>" min="50" max="100"></div>
</div>

<h2><?= marstek_e(marstek_t('EINST.H_MELDEN')) ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="melden_ein" <?= !empty($mv_cfg['melden_ein']) ? 'checked' : '' ?>>
    <?= marstek_e(marstek_t('EINST.L_MELDEN')) ?>
</label>
<div class="sm-hilfe"><?= marstek_e(marstek_t('EINST.H_MELDEN')) ?></div>
<div class="sm-row" style="margin-top:6px;">
    <div style="max-width:240px;"><label><?= marstek_e(marstek_t('EINST.L_MELDEN_AB')) ?></label>
        <input<?= mv_fa('melden_ab') ?> data-role="none" type="number" name="melden_ab" value="<?= mv_wert($mv_cfg['melden_ab']) ?>" min="1" max="20"></div>
</div>

<h2><?= marstek_e(marstek_t('EINST.H_SPOT')) ?></h2>
<div class="sm-row">
    <div>
        <label><?= marstek_e(marstek_t('EINST.L_MARKT')) ?></label>
        <select<?= mv_fa('awattar') ?> data-role="none" class="sm-auswahl" name="awattar">
            <option value="de"<?= $mv_cfg['awattar'] === 'de' ? ' selected' : '' ?>><?= marstek_e(marstek_t('EINST.MARKT_DE')) ?></option>
            <option value="at"<?= $mv_cfg['awattar'] === 'at' ? ' selected' : '' ?>><?= marstek_e(marstek_t('EINST.MARKT_AT')) ?></option>
        </select>
    </div>
    <div>
        <label><?= marstek_e(marstek_t('EINST.L_UST')) ?></label>
        <input<?= mv_fa('vat') ?> data-role="none" type="text" name="vat" value="<?= mv_wert($mv_cfg['vat']) ?>" placeholder="1.19">
        <div class="sm-hilfe"><?= marstek_e(marstek_t('EINST.H_UST')) ?></div>
    </div>
    <div>
        <label><?= marstek_e(marstek_t('EINST.L_AUFSCHLAG')) ?></label>
        <input<?= mv_fa('aufschlag_ct') ?> data-role="none" type="text" name="aufschlag_ct" value="<?= mv_wert($mv_cfg['aufschlag_ct']) ?>" placeholder="0">
        <div class="sm-hilfe"><?= marstek_e(marstek_t('EINST.H_AUFSCHLAG')) ?></div>
    </div>
</div>

<button data-role="none" class="sm-btn sm-b-aktion" type="submit" style="margin-top:18px;"><?= marstek_e(marstek_t('EINST.K_SPEICHERN')) ?></button>
</form>

<h2><?= marstek_e(marstek_t('EINST.H_SICHERUNG')) ?></h2>
<div class="sm-hinweis"><?= marstek_t('EINST.SICHERUNG_ERKLAERUNG') ?></div>
<?php
/* X-3 (Verbesserungsbau 30.09.2026): bestuende die eigene Sicherung das
 * Zurueckspielen nicht, steht es hier - dieselbe Pruefung wie beim
 * Zurueckspielen (marstek_sicherung_pruefen()). Gesichert wird trotzdem. */
$mv_sich_gruende = array();
foreach (marstek_sicherung_eigene_maengel() as $mv_z) {
    if (strncmp($mv_z, 'UNBEKANNT:', 10) === 0) {
        $mv_sich_gruende[] = sprintf(marstek_t('EINST.SICHERUNG_UNBEKANNT'), substr($mv_z, 10));
    } else {
        $mv_sich_gruende[] = strncmp($mv_z, 'WERT:', 5) === 0 ? substr($mv_z, 5) : $mv_z;
    }
}
if ($mv_sich_gruende) { ?>
<div class="sm-alert sm-warn"><?= marstek_e(sprintf(marstek_t('EINST.SICHERUNG_WARNUNG'), implode(' | ', $mv_sich_gruende))) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mv_sichern" value="1"><?= marstek_e(marstek_t('EINST.K_EXPORT')) ?></button>
  </form>
</div>
<form action="index.php" method="post" enctype="multipart/form-data" style="margin-top:10px;">
  <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <label><?= marstek_e(marstek_t('EINST.L_IMPORT')) ?></label>
  <input data-role="none" type="file" name="konfigdatei" accept=".json,application/json" style="max-width:420px;">
  <div class="sm-knopfreihe" style="margin-top:8px;">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mv_zurueck" value="1"><?= marstek_e(marstek_t('EINST.K_IMPORT')) ?></button>
  </div>
</form>

</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $mv_active_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= marstek_e(marstek_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<h2><?= marstek_e(marstek_t('MQTT.H_MQTT')) ?></h2>
<?php if ($mv_gw !== null && !$mv_gw['autostart']) { ?><div class="sm-warnung"><b>MQTT:</b> <?= marstek_e(marstek_t('MQTT.W_AUTOSTART')) ?></div><?php } ?>
<?php if (marstek_mqtt_udpport() === 0) { ?><div class="sm-warnung"><?= marstek_e(marstek_t('MQTT.W_KEIN_GATEWAY')) ?></div><?php } ?>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($mv_cfg['mqtt_enabled']) ? 'checked' : '' ?>>
    <?= marstek_e(marstek_t('MQTT.L_EIN')) ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div style="max-width:420px;">
        <label><?= marstek_e(marstek_t('MQTT.L_PRAEFIX')) ?></label>
        <input<?= mv_fa('mqtt_topic') ?> data-role="none" type="text" name="mqtt_topic" value="<?= mv_wert($mv_cfg['mqtt_topic']) ?>" placeholder="marstek">
        <div class="sm-hilfe"><?= marstek_e(marstek_t('MQTT.H_PRAEFIX')) ?></div>
    </div>
</div>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" style="margin-top:18px;"><?= marstek_e(marstek_t('EINST.K_SPEICHERN')) ?></button>
</form>

<h2><?= marstek_e(marstek_t('MQTT.H_ABO')) ?></h2>
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
<div class="sm-hilfe"><?= marstek_t('MQTT.ABO_UNBEKANNT') ?></div>
<?php } ?>
<p><?= marstek_e(marstek_t('MQTT.ABO_EINTRAG')) ?> <span class="sm-mono"><?= marstek_e(marstek_mqtt_prefix(1)) ?>/#</span></p>

<h2><?= marstek_e(marstek_t('MQTT.H_THEMEN')) ?></h2>
<div class="sm-hinweis"><?= marstek_t('MQTT.THEMEN_ERKLAERUNG') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:34%;"><?= marstek_e(marstek_t('MQTT.SP_THEMA')) ?></th><th style="width:11%;"><?= marstek_e(marstek_t('MQTT.SP_RETAIN')) ?></th><th><?= marstek_e(marstek_t('MQTT.SP_BEDEUTUNG')) ?></th></tr>
<?php $mv_ret = 0; foreach (marstek_mqtt_themen(true) as $thema => $bedeutung) {
    $mv_r = marstek_mqtt_retain(preg_replace('/_\d+$/', '', $thema));
    if ($mv_r) { $mv_ret++; } ?>
<tr><td><span class="sm-mono"><?= marstek_e(marstek_mqtt_prefix(1)) ?>/<?= marstek_e($thema) ?></span></td><td><?= $mv_r ? marstek_e(marstek_t('MQTT.RETAIN_JA')) : marstek_e(marstek_t('MQTT.RETAIN_NEIN')) ?></td><td><?= marstek_e($bedeutung) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= marstek_e(sprintf(marstek_t('MQTT.THEMEN_ANZAHL'), count(marstek_mqtt_themen(true)))) ?>
 <?= marstek_e(sprintf(marstek_t('MQTT.RETAIN_ANZAHL'), $mv_ret, count(marstek_mqtt_themen(true)) - $mv_ret)) ?></div>
<div class="sm-hinweis"><?= marstek_t('MQTT.RETAIN_ERKLAERUNG') ?></div>
<?php if (count($mv_devices) > 1) { ?>
<div class="sm-hilfe"><?= marstek_e(sprintf(marstek_t('MQTT.MEHRERE_GERAETE'), marstek_mqtt_prefix(1), marstek_mqtt_prefix(1))) ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $mv_active_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= marstek_e(marstek_t('LOX.H_EINBINDUNG')) ?></h2>
<div class="sm-hinweis"><?= marstek_t('LOX.GRUNDGEDANKE') ?></div>

<!-- Eine gesammelte Legende OBEN im Reiter, nicht zwei Teillegenden
     mittendrin. Sie nennt genau die Farben, die hier vorkommen: grau fuer
     die Vorlagen, orange fuer "Neues Aktionstoken erzeugen". -->
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= marstek_e(marstek_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= marstek_e(marstek_t('LEGENDE.AKTION_ADRESSEN')) ?></span>
</div>

<?php
/* SIEBEN feste Schritte, in der Reihenfolge des Hausstandards. Bis 1.1.4
 * trug Kasten 1 keine Nummer, die Zaehlung begann bei "Schritt 2", das Abo
 * war in Kasten 1 hineingefaltet, und die Zahl der Kaesten haengte an der
 * Geraetezahl: acht bei einem Speicher, neun bei zweien. Eine Anleitung,
 * deren Nummern sich mit der Anlage verschieben, laesst sich nicht
 * zitieren - und die Baustein-Liste verweist auf feste Nummern. */
$mv_schritt = 0;
?>
<div class="sm-step"><b><?= marstek_e(sprintf(marstek_t('LOX.SCHRITT'), ++$mv_schritt)) ?>: <?= marstek_e(marstek_t('LOX.S1_TITEL')) ?></b><br>
<?= marstek_t('LOX.S1_TEXT') ?>
</div>

<div class="sm-step"><b><?= marstek_e(sprintf(marstek_t('LOX.SCHRITT'), ++$mv_schritt)) ?>: <?= marstek_e(marstek_t('LOX.S_ABO')) ?></b><br>
<?php if ($mv_gwf >= 2) { ?>
<div class="sm-hinweis"><?= marstek_t('MQTT.ABO_V2') ?></div>
<?php } elseif ($mv_gwf === 1) { ?>
<div class="sm-warnung"><?= marstek_t('MQTT.ABO_V1') ?></div>
<?php } else { ?>
<div class="sm-warnung"><?= marstek_t('MQTT.ABO_V1') ?></div>
<div class="sm-hinweis"><?= marstek_t('MQTT.ABO_V2') ?></div>
<div class="sm-hilfe"><?= marstek_t('MQTT.ABO_UNBEKANNT') ?></div>
<?php } ?>
</div>

<?php
/* Die Tabellen der virtuellen Eingaenge entstehen aus marstek_felder() -
 * derselben Quelle wie die Vorlage und die Antwortzeile des Endpunkts. Bis
 * 1.0.16 standen sie in vierzig einzelnen Sprachschluesseln daneben, und
 * genau daraus sind drei Fehler entstanden: MS falsch beschriftet, RANKD mit
 * zwei Bedeutungen, GRIDP gar nicht erwaehnt. */
// Der Takt kommt aus marstek_satz_takt() - dieselbe Quelle, aus der die
// erzeugte Vorlage ihr PollingTime nimmt. Bis 1.1.4 stand er hier zweimal,
// und fuer ?summe liefen beide auseinander: die Oberflaeche nannte 60 s,
// die Datei daneben trug PollingTime="300".
$mv_saetze = array(
    'status' => array('titel' => marstek_t('LOX.SATZ_STATUS'), 'q' => '?status', 'jedev' => true),
    'ranks'  => array('titel' => marstek_t('LOX.SATZ_RANKS'),  'q' => '?ranks',  'jedev' => false),
    'energy' => array('titel' => marstek_t('LOX.SATZ_ENERGY'), 'q' => '?energy', 'jedev' => true),
);
if (count($mv_devices) > 1) {
    $mv_saetze['summe'] = array('titel' => marstek_t('LOX.SATZ_SUMME'), 'q' => '?summe', 'jedev' => false);
}
foreach ($mv_saetze as $mv_s => $mv_unused) {
    $mv_saetze[$mv_s]['takt'] = marstek_satz_takt($mv_s);
}
// Schritt 3 ist EIN Kasten. Die drei bis vier Saetze stehen darin als
// Unterabschnitte, damit die Schrittnummern nicht an der Geraetezahl haengen.
?>
<div class="sm-step"><b><?= marstek_e(sprintf(marstek_t('LOX.SCHRITT'), ++$mv_schritt)) ?>: <?= marstek_e(marstek_t('LOX.S_EINGAENGE')) ?></b><br>
<?php foreach ($mv_saetze as $satz => $info) { ?>
<h3 class="sm-h3"><?= marstek_e($info['titel']) ?></h3>
<table class="sm-tbl">
<tr><th style="width:34%;"><?= marstek_e(marstek_t('LOX.SP_EIGENSCHAFT')) ?></th><th><?= marstek_e(marstek_t('LOX.SP_WERT')) ?></th></tr>
<tr><td><?= marstek_e(marstek_t('LOX.SP_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= $mv_host ?>/plugins/<?= marstek_e($mv_plugindir) ?>/marstek.php<?= marstek_e($info['q']) ?></span></td></tr>
<tr><td><?= marstek_e(marstek_t('LOX.SP_TAKT')) ?></td><td><?= (int) $info['takt'] ?> s</td></tr>
</table>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:22%;"><?= marstek_e(marstek_t('LOX.SP_SUCHTEXT')) ?></th><th style="width:10%;"><?= marstek_e(marstek_t('LOX.SP_EINHEIT')) ?></th><th><?= marstek_e(marstek_t('LOX.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (marstek_felder($satz) as $name => $f) { ?>
<tr><td><span class="sm-mono">\i;<?= marstek_e($name) ?>=\i\v</span></td><td><?= marstek_e($f['einheit']) ?></td><td><?= marstek_e(marstek_feldtext($satz, $name)) ?></td></tr>
<?php } ?>
</table>
</div>
<?php if ($info['jedev'] && count($mv_devices) > 1) { ?>
<div class="sm-hilfe"><?= marstek_e(sprintf(marstek_t('LOX.JE_GERAET'), $info['q'])) ?></div>
<?php } ?>
<?php } ?>
</div>

<div class="sm-step"><b><?= marstek_e(sprintf(marstek_t('LOX.SCHRITT'), ++$mv_schritt)) ?>: <?= marstek_e(marstek_t('LOX.S_AUSGANG')) ?></b><br>
<?= marstek_t('LOX.AUSGANG_TEXT') ?>
<table class="sm-tbl">
<tr><th style="width:34%;"><?= marstek_e(marstek_t('LOX.SP_EIGENSCHAFT')) ?></th><th><?= marstek_e(marstek_t('LOX.SP_WERT')) ?></th></tr>
<tr><td><?= marstek_e(marstek_t('LOX.SP_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= $mv_host ?></span></td></tr>
<tr><td><?= marstek_e(marstek_t('LOX.SP_BEFEHL_ANALOG')) ?></td><td><span class="sm-mono">/plugins/<?= marstek_e($mv_plugindir) ?>/marstek.php?p=&lt;v&gt;&amp;t=240&amp;token=<?= marstek_e($mv_cfg['aktionstoken']) ?></span></td></tr>
<tr><td><?= marstek_e(marstek_t('LOX.SP_BEFEHL_AUTO')) ?></td><td><span class="sm-mono">/plugins/<?= marstek_e($mv_plugindir) ?>/marstek.php?mode=auto&amp;token=<?= marstek_e($mv_cfg['aktionstoken']) ?></span></td></tr>
</table>
<div class="sm-warnung"><?= marstek_t('LOX.TOKEN_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= marstek_e(sprintf(marstek_t('LOX.SCHRITT'), ++$mv_schritt)) ?>: <?= marstek_e(marstek_t('LOX.S_AUSFALL')) ?></b><br>
<?= marstek_t('LOX.AUSFALL_TEXT') ?>
</div>

<div class="sm-step"><b><?= marstek_e(sprintf(marstek_t('LOX.SCHRITT'), ++$mv_schritt)) ?>: <?= marstek_e(marstek_t('LOX.S_BAUSTEINE')) ?></b><br>
<?= marstek_t('LOX.BAUSTEINE_VORTEXT') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:34px;">#</th><th style="width:20%;"><?= marstek_e(marstek_t('LOX.SP_BAUSTEIN')) ?></th><th style="width:24%;"><?= marstek_e(marstek_t('LOX.SP_NAME')) ?></th><th style="width:24%;"><?= marstek_e(marstek_t('LOX.SP_PARAMETER')) ?></th><th><?= marstek_e(marstek_t('LOX.SP_EINGAENGE')) ?></th></tr>
<?php
$mv_bausteine = array(
    array('Taster EIN/AUS', 'LOX.B_NETZLADEN', 'LOX.P_STANDARD_AUS', 'LOX.E_VISU'),
    array('Taster EIN/AUS', 'LOX.B_SPOTLADEN', 'LOX.P_STANDARD_AUS', 'LOX.E_VISU'),
    array('Taster EIN/AUS', 'LOX.B_ENTLADEN_ERLAUBT', 'LOX.P_STANDARD_AUS', 'LOX.E_VISU'),
    array('Taster EIN/AUS', 'LOX.B_NUR_TEUERSTE', 'LOX.P_STANDARD_AUS', 'LOX.E_VISU'),
    array('LOX.T_VE_ZAHL', 'LOX.B_ANZ_GUENSTIG', 'LOX.P_0_24', 'LOX.E_VISU'),
    array('LOX.T_VE_ZAHL', 'LOX.B_ANZ_TEUER', 'LOX.P_0_24', 'LOX.E_VISU'),
    array('LOX.T_VE_ZAHL', 'LOX.B_RESERVE', 'LOX.P_0_100', 'LOX.E_VISU'),
    // O10 (30.09.2026): Bezug positiv, Einspeisung negativ (Vortext) - also
    // ist der Export max(0;-I1) und der Bezug max(0;I1). Bis 1.1.17 standen
    // die beiden vertauscht da: wer die Liste nachbaute, lud bei Bezug aus dem
    // Netz und entlud bei Einspeisung. Die Anlage des Hausherrn rechnet
    // richtig ("Formel Export-Leistung (W)" = MAX(0;-I1)).
    array('LOX.T_FORMEL', 'LOX.B_EXPORT', 'max(0;-I1)', 'LOX.E_NETZ'),
    array('LOX.T_FORMEL', 'LOX.B_BEZUG', 'max(0;I1)', 'LOX.E_NETZ'),
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
    // #27/#28: BERICHTIGT 04.09.2026. Hier stand EIN UND mit drei Eingaengen
    // ("#25, #26, OK aus den Raengen"). Ein Logikbaustein hat zwei Eingaenge;
    // drei Bedingungen brauchen die Kaskade. Die Schwesterzeile #15 hat es
    // immer richtig gemacht. Alles ab hier ist um eine Nummer gerueckt.
    array('LOX.T_UND', 'LOX.B_ENTLADEFENSTER_ZEIT', '', 'LOX.E_NR25_NR26'),
    array('LOX.T_UND', 'LOX.B_ENTLADEFENSTER', '', 'LOX.E_NR27_OK'),
    array('LOX.T_NICHT', 'LOX.B_NICHT_NUR_TEUERSTE', '', 'LOX.E_NR4'),
    array('LOX.T_ODER', 'LOX.B_ENTLADEFENSTER_ERF', '', 'LOX.E_NR28_NR29'),
    array('LOX.T_UND', 'LOX.B_ENTLADUNG_ERLAUBT', '', 'LOX.E_NR30_NR3'),
    array('LOX.T_UND', 'LOX.B_KEIN_NEGPREIS', '', 'LOX.E_NR31_NEG'),
    array('LOX.T_FORMEL', 'LOX.B_ENTLADEWUNSCH', 'min(I1-50;2500)*I2*I3*I4', 'LOX.E_NR9_NR32_NR24_NR23'),
    array('LOX.T_SCHWELLE', 'LOX.B_SOC_UEBER97', 'LOX.P_EIN97', 'LOX.E_SOC'),
    array('LOX.T_FORMEL', 'LOX.B_SOLLLEISTUNG', 'I1*(1-I3)-I2', 'LOX.E_NR20_NR33_NR34'),
    array('LOX.T_IMPULS', 'LOX.B_SENDETAKT', 'LOX.P_60_60', ''),
    array('LOX.T_ANALOGSP', 'LOX.B_SAMPLE', 'LOX.P_TRIGGER', 'LOX.E_NR35_NR36'),
    array('LOX.T_FORMEL', 'LOX.B_DITHER', 'I1+I2', 'LOX.E_NR37_NR36_AUSGANG'),
    array('LOX.T_NICHT', 'LOX.B_NICHT_ERREICHBAR', '', 'LOX.E_OK'),
    array('LOX.T_EINVERZ', 'LOX.B_STOERUNG15', '900 s', 'LOX.E_NR39_PUSH'),
    array('LOX.T_AENDERUNG', 'LOX.B_TAKT_UEBERWACHUNG', 'LOX.P_180', 'LOX.E_ZAEHLER_PUSH'),
    // #42 bis #44: BERICHTIGT 04.09.2026. Der Vergleicher allein meldete im
    // Auto-Modus und nach jedem Auto-Fallback dauerhaft eine Stoerung:
    // MARSTEK_STATUS_SOLL traegt dann -32768 ("kein Sollwert"), und die
    // Abweichung zu BATP ist immer groesser als 200. Die Schwelle davor
    // blendet den Fehlwert aus.
    array('LOX.T_SCHWELLE', 'LOX.B_SOLL_GESETZT', 'LOX.P_EIN_SOLL', 'LOX.E_SOLL'),
    array('LOX.T_VERGLEICH', 'LOX.B_SOLL_ABWEICHUNG', 'LOX.P_Q1_ABWEICHUNG', 'LOX.E_SOLL_BATP'),
    array('LOX.T_UND', 'LOX.B_SOLL_IST', '', 'LOX.E_NR42_NR43'),
);
foreach ($mv_bausteine as $i => $b) {
    $typ = strpos($b[0], 'LOX.') === 0 ? marstek_t($b[0]) : $b[0];
    $par = strpos($b[2], 'LOX.') === 0 ? marstek_t($b[2]) : $b[2];
    $ein = $b[3] !== '' ? marstek_t($b[3]) : '';
    ?>
<tr><td><?= $i + 1 ?></td><td><?= marstek_e($typ) ?></td><td><?= marstek_e(marstek_t($b[1])) ?></td><td><?= marstek_e($par) ?></td><td><?= marstek_e($ein) ?></td></tr>
<?php } ?>
</table>
</div>
<?= marstek_t('LOX.BAUSTEINE_NACHTEXT') ?>
</div>

<div class="sm-step"><b><?= marstek_e(sprintf(marstek_t('LOX.SCHRITT'), ++$mv_schritt)) ?>: <?= marstek_e(marstek_t('LOX.S_GEGENPROBE')) ?></b><br>
<?= marstek_t('LOX.GEGENPROBE_TEXT') ?>
</div>

<h2><?= marstek_e(marstek_t('LOX.H_TOKEN')) ?></h2>
<table class="sm-tbl">
<tr><th style="width:34%;"><?= marstek_e(marstek_t('LOX.SP_EIGENSCHAFT')) ?></th><th><?= marstek_e(marstek_t('LOX.SP_WERT')) ?></th></tr>
<tr><td><?= marstek_e(marstek_t('LOX.AKTUELLES_TOKEN')) ?></td><td><span class="sm-mono"><?= marstek_e($mv_cfg['aktionstoken']) ?></span></td></tr>
</table>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= marstek_e(marstek_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= marstek_e(marstek_t('LOX.TOKEN_NEU_HINWEIS')) ?></div>

<h2><?= marstek_e(marstek_t('LOX.H_VORLAGEN')) ?></h2>
<div class="sm-hinweis"><?= marstek_t('LOX.VORLAGEN_ERKLAERUNG') ?></div>
<?php if (class_exists('ZipArchive')) { ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage_paket" value="1"><?= marstek_e(marstek_t('LOX.K_PAKET')) ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= marstek_e(sprintf(marstek_t('LOX.PAKET_INHALT'), count(marstek_vorlagen_alle()))) ?></div>
<?php } else { ?>
<div class="sm-hilfe"><?= marstek_e(marstek_t('LOX.KEIN_ZIP_HINWEIS')) ?></div>
<?php } ?>

<h3 class="sm-h3"><?= marstek_e(marstek_t('LOX.H_EINZELN')) ?></h3>
<?php $mv_vdevs = $mv_devices ? $mv_devices : array(1 => array('name' => 'Venus E'));
foreach ($mv_vdevs as $n => $d) { ?>
<div class="sm-small" style="margin-top:10px;"><b><?= marstek_e($d['name']) ?></b></div>
<div class="sm-knopfreihe">
<?php foreach (array('status' => marstek_t('LOX.SATZ_STATUS'), 'energy' => marstek_t('LOX.SATZ_ENERGY')) as $vs => $vn) { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_dev" value="<?= (int) $n ?>">
    <input data-role="none" type="hidden" name="vorlage" value="<?= marstek_e($vs) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" style="min-width:200px;"><?= marstek_e($vn) ?></button>
  </form>
<?php } ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage_dev" value="<?= (int) $n ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage_vo" value="1" style="min-width:200px;"><?= marstek_e(marstek_t('LOX.SATZ_STEUERN')) ?></button>
  </form>
</div>
<?php } ?>
<div class="sm-knopfreihe" style="margin-top:10px;">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage" value="ranks">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" style="min-width:200px;"><?= marstek_e(marstek_t('LOX.SATZ_RANKS')) ?></button>
  </form>
<?php if (count($mv_devices) > 1) { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage" value="summe">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" style="min-width:200px;"><?= marstek_e(marstek_t('LOX.SATZ_SUMME')) ?></button>
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
<h2><?= marstek_e(marstek_t('LOG.H_LOG')) ?></h2>
<?php if ($mv_use_frame && method_exists('LBWeb', 'loglist_html')) { ?>
<div style="margin-bottom:12px;"><?php echo LBWeb::loglist_html(); ?></div>
<?php } ?>
<h3 class="sm-h3"><?= marstek_e(marstek_t('LOG.H_EREIGNISSE')) ?></h3>
<div class="sm-hilfe"><?= marstek_t('LOG.EREIGNISSE_ERKLAERUNG') ?><br>
<?= marstek_e(marstek_t('LOG.DATEI')) ?> <span class="sm-mono"><?= marstek_e(marstek_ereignis_datei()) ?></span></div>
<?php if ($mv_ereignisse) { ?>
<div class="sm-log"><?= marstek_e(implode("\n", $mv_ereignisse)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-ok"><?= marstek_e(marstek_t('LOG.EREIGNISSE_LEER')) ?></div>
<?php } ?>

<h3 class="sm-h3"><?= marstek_e(marstek_t('LOG.H_LAUFEND')) ?></h3>
<div class="sm-hilfe" style="margin-bottom:8px;"><?= marstek_e(marstek_t('LOG.ERKLAERUNG')) ?><br>
<?= marstek_e(marstek_t('LOG.DATEI')) ?> <span class="sm-mono"><?= marstek_e($mv_log_file) ?></span></div>
<div class="sm-warnung"><?= marstek_e(marstek_t('LOG.RAMDISK')) ?></div>
<?php if ($mv_log_lines) { ?>
<div class="sm-log"><?= marstek_e(implode("\n", $mv_log_lines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?= marstek_e(marstek_t('LOG.LEER')) ?></div>
<?php } ?>

<h3 class="sm-h3"><?= marstek_e(marstek_t('LOG.H_CRONERR')) ?></h3>
<div class="sm-hilfe"><?= marstek_e(marstek_t('LOG.CRONERR_ERKLAERUNG')) ?><br>
<span class="sm-mono"><?= marstek_e($mv_err_file) ?></span></div>
<?php if ($mv_err_lines) { ?>
<div class="sm-log"><?= marstek_e(implode("\n", $mv_err_lines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-ok"><?= marstek_e(marstek_t('LOG.CRONERR_LEER')) ?></div>
<?php } ?>

<div class="sm-legende" style="margin-top:14px;">
<span><i class="sm-punkt sm-b-aktion"></i> <?= marstek_e(marstek_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= $mv_ft ?>">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= marstek_e(marstek_t('LOG.K_LEEREN')) ?></button>
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
    /* b1: die Auswahl des Modells belegt die Leistungsgrenzen der Zeile vor.
     * "anderes" und "nicht gewaehlt" lassen sie stehen. Gespeichert wird erst
     * mit dem Knopf; ueber der Modellgrenze warnt das Speichern gelb. */
    var mvGrenzen = <?= json_encode(array_filter(marstek_modelle())) ?>;
    document.querySelectorAll('select.mv-modell').forEach(function (s) {
        s.addEventListener('change', function () {
            var g = mvGrenzen[s.value];
            var tr = s.closest('tr');
            if (!g || !tr) { return; }
            var pc = tr.querySelector('input[name="dev_pc[]"]');
            var pd = tr.querySelector('input[name="dev_pd[]"]');
            if (pc) { pc.value = g[0]; }
            if (pd) { pd.value = g[1]; }
        });
    });
})();
</script>
<?php
if ($mv_use_frame) {
    LBWeb::lbfooter();
}
