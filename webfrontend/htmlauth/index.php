<?php
/**
 * Marstek Venus E - Admin-Oberflaeche
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Logdateien
 * Mehrgeraete-Betrieb (bis 4 Speicher, auch Venus E Mini), SOC-Tagesverlauf,
 * Firmware-/Antwortzeit-Anzeige, Auto-Fallback.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

$mv_lbhomedir = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
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
    $mv_config_dir = $mv_lbhomedir . '/config/plugins/' . $mv_plugindir;
    $mv_backup_file = $mv_lbhomedir . '/config/plugins/' . $mv_plugindir . '.backup.json';
    $mv_log_file = $mv_lbhomedir . '/log/plugins/' . $mv_plugindir . '/marstek.log';
} else {
    $mv_config_dir = dirname(dirname(__DIR__)) . '/config';
    $mv_backup_file = $mv_config_dir . '/marstek.backup.json';
    $mv_log_file = sys_get_temp_dir() . '/marstekvenus/marstek.log';
}
$mv_config_file = $mv_config_dir . '/marstek.json';

// Bibliothek einbinden (installiert unter .../html/plugins/<plugin>/, im Archiv unter ../html/)
foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $mv_plugindir . '/marstek_lib.php',
    dirname(__DIR__) . '/html/marstek_lib.php',
) as $libcand) {
    if (is_file($libcand)) {
        require_once $libcand;
        break;
    }
}

// Selbstheilung: fehlende/leere Konfiguration aus Sicherung wiederherstellen
if ((!is_file($mv_config_file) || trim((string) @file_get_contents($mv_config_file)) === '' || trim((string) @file_get_contents($mv_config_file)) === '{}') && is_file($mv_backup_file)) {
    @mkdir($mv_config_dir, 0775, true);
    @copy($mv_backup_file, $mv_config_file);
}

$mv_saved = false;
$mv_save_error = '';
$mv_active_tab = preg_match('/^tab-(settings|loxone|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : '')) ? $_POST['activetab'] : 'tab-settings';

// ---------- Log leeren ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($mv_log_file), 0775, true);
    @file_put_contents($mv_log_file, '[' . date('Y-m-d H:i:s') . "] Log geleert (Admin-Oberflaeche)\n");
    $mv_active_tab = 'tab-log';
}

// ---------- Neues Aktionstoken erzeugen ----------
$mv_token_meldung = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token_neu'])) {
    $mv_cfg_tok = function_exists('marstek_config') ? marstek_config() : array();
    if (!is_array($mv_cfg_tok)) { $mv_cfg_tok = array(); }
    $mv_cfg_tok['aktionstoken'] = function_exists('marstek_token_erzeugen') ? marstek_token_erzeugen() : bin2hex(random_bytes(12));
    if (!is_dir($mv_config_dir)) {
        @mkdir($mv_config_dir, 0775, true);
    }
    $mv_json_tok = json_encode($mv_cfg_tok, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if ($mv_json_tok !== false && @file_put_contents($mv_config_file, $mv_json_tok) !== false) {
        @copy($mv_config_file, $mv_backup_file);
        $mv_token_meldung = 'Neues Token erzeugt. <b>Die Adressen in Loxone m&uuml;ssen '
                          . 'angepasst werden</b> &ndash; die alten funktionieren nicht mehr.';
    }
    $mv_active_tab = 'tab-loxone';
}

// ---------- Speichern ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save']) && !isset($_POST['clearlog'])) {
    $mv_cfg = array();
    // Geraete (bis 4 Zeilen; nur Zeilen mit IP werden uebernommen)
    $mv_cfg['devices'] = array();
    $names = isset($_POST['dev_name']) ? (array) $_POST['dev_name'] : array();
    $ips = isset($_POST['dev_ip']) ? (array) $_POST['dev_ip'] : array();
    $ports = isset($_POST['dev_port']) ? (array) $_POST['dev_port'] : array();
    $pcs = isset($_POST['dev_pc']) ? (array) $_POST['dev_pc'] : array();
    $pds = isset($_POST['dev_pd']) ? (array) $_POST['dev_pd'] : array();
    for ($i = 0; $i < 4; $i++) {
        $ip = trim((string) (isset($ips[$i]) ? $ips[$i] : ''));
        if ($ip === '') {
            continue;
        }
        if (!preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)) {
            $mv_save_error = 'Ger&auml;t ' . ($i + 1) . ': bitte eine IPv4-Adresse eingeben (z. B. 192.168.1.25).';
            continue;
        }
        $mbs = isset($_POST['dev_mb']) ? (array) $_POST['dev_mb'] : array();
        $mv_cfg['devices'][] = array(
            'name' => trim((string) (isset($names[$i]) ? $names[$i] : '')),
            'ip' => $ip,
            'port' => max(1, min(65535, (int) (isset($ports[$i]) ? $ports[$i] : 30000))),
            'pmax_charge' => max(100, min(3600, (int) (isset($pcs[$i]) ? $pcs[$i] : 2500))),
            'pmax_discharge' => max(100, min(3600, (int) (isset($pds[$i]) ? $pds[$i] : 2500))),
            'modbus' => (isset($mbs[$i]) && $mbs[$i] === '1') ? 1 : 0,
        );
    }
    $mv_cfg['cache_sec'] = max(5, min(300, (int) (isset($_POST['cache_sec']) ? $_POST['cache_sec'] : 40)));
    $mv_cfg['awattar'] = (isset($_POST['awattar']) && $_POST['awattar'] === 'at') ? 'at' : 'de';
    $vat = str_replace(',', '.', (string) (isset($_POST['vat']) ? $_POST['vat'] : '1.19'));
    $mv_cfg['vat'] = (is_numeric($vat) && $vat > 0.5 && $vat < 2) ? (float) $vat : 1.19;
    $mv_cfg['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $mv_cfg['mqtt_topic'] = preg_replace('#[^\w/\-]#', '', (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'marstek')) ?: 'marstek';
    $mv_cfg['fallback_min'] = max(0, min(1440, (int) (isset($_POST['fallback_min']) ? $_POST['fallback_min'] : 0)));
    if ($mv_save_error === '') {
        if (!is_dir($mv_config_dir)) {
            @mkdir($mv_config_dir, 0775, true);
        }
        $json = json_encode($mv_cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
        // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
        if ($json !== false && @file_put_contents($mv_config_file, $json) !== false) {
            $mv_saved = true;
            @copy($mv_config_file, $mv_backup_file); // Sicherung ausserhalb des Plugin-Ordners
        } else {
            $mv_save_error = 'Konfiguration konnte nicht gespeichert werden: ' . $mv_config_file;
        }
    }
}

// ---------- Laden ----------
$mv_cfg = function_exists('marstek_config') ? marstek_config() : array();
if (!is_array($mv_cfg)) { $mv_cfg = json_decode(json_encode($mv_cfg), true) ?: array(); }
$mv_cfg += array('devices' => array(), 'cache_sec' => 40, 'vat' => 1.19, 'awattar' => 'de',
    'mqtt_enabled' => 0, 'mqtt_topic' => 'marstek', 'fallback_min' => 30, 'aktionstoken' => '');
if (!is_array($mv_cfg['devices'])) { $mv_cfg['devices'] = json_decode(json_encode($mv_cfg['devices']), true) ?: array(); }
$mv_devices = function_exists('marstek_devices') ? marstek_devices() : array();

// Beim ersten Aufruf ein Token erzeugen, damit der Endpunkt fuer Loxone sofort
// benutzbar ist (schuetzt ?p= und ?mode= im unangemeldeten marstek.php).
if (empty($mv_cfg['aktionstoken'])) {
    $mv_cfg['aktionstoken'] = function_exists('marstek_token_erzeugen') ? marstek_token_erzeugen() : bin2hex(random_bytes(12));
    if (!is_dir($mv_config_dir)) {
        @mkdir($mv_config_dir, 0775, true);
    }
    $mv_json_init = json_encode($mv_cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if ($mv_json_init !== false && @file_put_contents($mv_config_file, $mv_json_init) !== false) {
        @copy($mv_config_file, $mv_backup_file);
    }
}

// Letzter Status je Geraet (Cache von marstek.php - KEIN Live-Aufruf, damit die Seite schnell laedt)
$mv_statuses = array();
foreach ($mv_devices as $n => $d) {
    $st = @json_decode((string) @file_get_contents('/tmp/marstekvenus/status_dev' . $n . '.json'), true);
    if (is_array($st) && isset($st['soc'])) {
        $mv_statuses[$n] = $st;
    }
}
$mv_log_lines = array();
if (is_file($mv_log_file)) {
    $mv_log_lines = array_slice(array_reverse(file($mv_log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 300);
}

function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/** Mini-SVG: SOC-Tagesverlauf (heute, 0-24 h, 0-100 %). */
function mv_soc_svg($points) {
    $w = 720; $h = 120; $x0 = 34; $y0 = 8; $pw = $w - $x0 - 8; $ph = $h - $y0 - 20;
    $day0 = strtotime('today 00:00');
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;max-width:' . $w . 'px;height:auto;background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;" xmlns="http://www.w3.org/2000/svg">';
    foreach (array(0, 25, 50, 75, 100) as $pct) {
        $y = $y0 + $ph - $ph * $pct / 100;
        $svg .= '<line x1="' . $x0 . '" y1="' . $y . '" x2="' . ($x0 + $pw) . '" y2="' . $y . '" stroke="#e5e5e5" stroke-width="1"/>';
        $svg .= '<text x="' . ($x0 - 5) . '" y="' . ($y + 3) . '" font-size="9" fill="#999" text-anchor="end">' . $pct . '</text>';
    }
    foreach (array(0, 6, 12, 18, 24) as $hh) {
        $x = $x0 + $pw * $hh / 24;
        $svg .= '<line x1="' . $x . '" y1="' . $y0 . '" x2="' . $x . '" y2="' . ($y0 + $ph) . '" stroke="#eeeeee" stroke-width="1"/>';
        $svg .= '<text x="' . $x . '" y="' . ($h - 6) . '" font-size="9" fill="#999" text-anchor="middle">' . $hh . ':00</text>';
    }
    $poly = array();
    foreach ($points as $pt) {
        $frac = ($pt[0] - $day0) / 86400;
        if ($frac < 0 || $frac > 1) {
            continue;
        }
        $x = round($x0 + $pw * $frac, 1);
        $y = round($y0 + $ph - $ph * max(0, min(100, $pt[1])) / 100, 1);
        $poly[] = $x . ',' . $y;
    }
    if (count($poly) >= 2) {
        $first = explode(',', $poly[0]); $last = explode(',', $poly[count($poly) - 1]);
        $svg .= '<polygon points="' . $first[0] . ',' . ($y0 + $ph) . ' ' . implode(' ', $poly) . ' ' . $last[0] . ',' . ($y0 + $ph) . '" fill="#6dac20" opacity="0.15"/>';
        $svg .= '<polyline points="' . implode(' ', $poly) . '" fill="none" stroke="#6dac20" stroke-width="2"/>';
        $svg .= '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="3" fill="#6dac20"/>';
    } else {
        $svg .= '<text x="' . ($x0 + $pw / 2) . '" y="' . ($y0 + $ph / 2) . '" font-size="11" fill="#aaa" text-anchor="middle">Noch keine Messpunkte heute (kommen automatisch alle paar Minuten)</text>';
    }
    return $svg . '</svg>';
}

// WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg aus general.json als stdClass)
// und wuerde gleichnamige Plugin-Variablen ueberschreiben - daher hier ueberall mv_-Praefix.
$mv_use_frame = class_exists('LBWeb', false);
if ($mv_use_frame) {
    LBWeb::lbheader('Marstek Venus E', 'https://wiki.loxberry.de/', '');
}
$mv_host = e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
?>
<style>
.sm-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; }
.sm-row > div { flex: 1; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
.sm-devtbl { width: 100%; }
.sm-devtbl input { min-width: 60px; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { text-shadow: none !important; box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }

/* --- Einheitliches Kachel-Raster im Reiter <?php echo marstek_t('TEXT.TEST'); ?> (Standard aller Plugins) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($mv_saved) { ?><div class="sm-alert sm-ok"><b><?php echo marstek_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b> <?php echo marstek_t('TEXT.INKL_SICHERUNGSKOPIE_FR_UPDATES'); ?></div><?php } ?>
<?php if ($mv_save_error !== '') { ?><div class="sm-alert sm-err"><b><?php echo marstek_t('TEXT.FEHLER'); ?></b> <?= $mv_save_error ?></div><?php } ?>
<?php if ($mv_token_meldung !== '') { ?><div class="sm-alert sm-ok"><?= $mv_token_meldung ?></div><?php } ?>

<?php foreach ($mv_statuses as $n => $st) { ?>
<div class="sm-alert sm-info"><b><?= e($mv_devices[$n]['name']) ?></b> (<?= e(date('d.m.Y H:i:s', isset($st['ts']) ? (int) $st['ts'] : time())) ?><?php echo marstek_t('TEXT.LADEZUSTAND'); ?> <?= e($st['soc']) ?> <?php echo marstek_t('TEXT.BATTERIELEISTUNG'); ?> <?= e($st['batp']) ?> <?php echo marstek_t('TEXT.W_LDT_TEMPERATUR'); ?> <?= e($st['temp']) ?> <?php echo marstek_t('TEXT.C_VERBINDUNG'); ?> <?= !empty($st['ok']) ? 'OK' : '<b>GEST&Ouml;RT</b>' ?>
<?php if (!empty($st['ok'])) { ?> &middot; <?= e(isset($st['model']) && $st['model'] !== '' ? $st['model'] : 'Venus') ?><?php echo marstek_t('TEXT.FIRMWARE'); ?> <?= (int) (isset($st['fw']) ? $st['fw'] : 0) ?> <?php echo marstek_t('TEXT.ANTWORTZEIT'); ?> <?= (int) (isset($st['ms']) ? $st['ms'] : 0) ?> ms<?php } ?>
<?php if (!empty($mv_devices[$n]['modbus'])) {
    $en = @json_decode((string) @file_get_contents('/tmp/marstekvenus/energy_dev' . $n . '.json'), true);
    if (is_array($en) && !empty($en['ok'])) { ?>
<br><?php echo marstek_t('TEXT.ENERGIE_HEUTE'); ?> <b><?= e($en['chgd']) ?> kWh</b> <?php echo marstek_t('TEXT.GELADEN'); ?> <b><?= e($en['disd']) ?> kWh</b> <?php echo marstek_t('TEXT.ABGEGEBEN_MONAT'); ?> <?= e($en['chgm']) ?> / <?= e($en['dism']) ?> <?php echo marstek_t('TEXT.KWH_ZYKLEN'); ?> <?= (int) $en['cyc'] ?> <?php echo marstek_t('TEXT.WIRKUNGSGRAD_GESAMT'); ?> <?= e($en['eff']) ?> %
<?php } } ?>
<?php $hist = function_exists('marstek_history_read') ? marstek_history_read($n) : array(); ?>
<div style="margin-top:8px;"><?= mv_soc_svg($hist) ?></div>
<div class="sm-small"><?php echo marstek_t('TEXT.SOC_VERLAUF_HEUTE_DIE_MESSPUNKTE_S'); ?></div>
</div>
<?php } ?>

<div class="sm-tabs">
    <div class="sm-tab" data-pane="tab-settings"><?php echo marstek_t('REITER.EINSTELLUNGEN'); ?></div>
    <div class="sm-tab" data-pane="tab-loxone"><?php echo marstek_t('REITER.LOXONE'); ?></div>
    <div class="sm-tab" data-pane="tab-test"><?php echo marstek_t('REITER.TEST'); ?></div>
    <div class="sm-tab" data-pane="tab-log"><?php echo marstek_t('REITER.LOG'); ?></div>
</div>

<!-- ================= Reiter: <?php echo marstek_t('TEXT.EINSTELLUNG'); ?>en ================= -->
<div class="sm-pane" id="tab-settings">
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo marstek_t('TEXT.BATTERIESPEICHER_BIS_ZU_4_GERTE'); ?></h2>
<div class="sm-small"><?php echo marstek_t('TEXT.GERT_1_IST_DAS_STANDARDGERT_ALLE_A'); ?> <span class="sm-mono"><?php echo marstek_t('TEXT.DEV'); ?></span><?php echo marstek_t('TEXT.WEITERE_SPEICHER_Z_B_EIN_ZWEITER_D'); ?> <b><?php echo marstek_t('TEXT.VENUS_E_MINI'); ?></b><?php echo marstek_t('TEXT.EINFACH_IN_DIE_NCHSTE_ZEILE_EINTRA'); ?> <span class="sm-mono"><?php echo marstek_t('TEXT.DEV_2'); ?></span> <?php echo marstek_t('TEXT.USW_ANSPRECHEN_DIE_LOKALE_API_MUSS'); ?> <a href="https://rweijnen.github.io/marstek-venus-monitor/latest/" target="_blank"><?php echo marstek_t('TEXT.VENUS_MONITOR_TOOL'); ?></a> <?php echo marstek_t('TEXT.PER_BLUETOOTH_STANDARD_PORT_30000'); ?></div>
<table class="sm-tbl sm-devtbl">
<tr><th style="width:36px;">Nr.</th><th><?php echo marstek_t('TEXT.NAME_FREI'); ?></th><th><?php echo marstek_t('TEXT.GERTE_IP'); ?></th><th style="width:90px;"><?php echo marstek_t('TEXT.UDP_PORT'); ?></th><th style="width:110px;"><?php echo marstek_t('TEXT.MAX_LADEN_W'); ?></th><th style="width:110px;"><?php echo marstek_t('TEXT.MAX_ENTLADEN_W'); ?></th><th style="width:120px;"><?php echo marstek_t('TEXT.KWH_ZHLER_MODBUS'); ?></th></tr>
<?php for ($i = 0; $i < 4; $i++) {
    $d = isset($mv_cfg['devices'][$i]) ? (array) $mv_cfg['devices'][$i] : array();
    $d += array('name' => '', 'ip' => '', 'port' => 30000, 'pmax_charge' => 2500, 'pmax_discharge' => 2500, 'modbus' => 1); ?>
<tr>
<td><?= $i + 1 ?></td>
<td><input data-role="none" type="text" name="dev_name[]" value="<?= e($d['name']) ?>" placeholder="<?= $i === 0 ? 'z. B. Venus E Keller' : 'leer = ungenutzt' ?>"></td>
<td><input data-role="none" type="text" name="dev_ip[]" value="<?= e($d['ip']) ?>" placeholder="<?= $i === 0 ? 'z. B. 192.168.1.25' : '' ?>"></td>
<td><input data-role="none" type="number" name="dev_port[]" value="<?= (int) $d['port'] ?>" min="1" max="65535"></td>
<td><input data-role="none" type="number" name="dev_pc[]" value="<?= (int) $d['pmax_charge'] ?>" min="100" max="3600"></td>
<td><input data-role="none" type="number" name="dev_pd[]" value="<?= (int) $d['pmax_discharge'] ?>" min="100" max="3600"></td>
<td><select data-role="none" name="dev_mb[]"><option value="0"<?= empty($d['modbus']) ? ' selected' : '' ?><?php echo marstek_t('TEXT.AUS'); ?></option><option value="1"<?= !empty($d['modbus']) ? ' selected' : '' ?><?php echo marstek_t('TEXT.EIN'); ?></option></select></td>
</tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo marstek_t('TEXT.LEISTUNGSGRENZEN_SOLLWERTE_WERDEN_'); ?> <b><?php echo marstek_t('TEXT.VENUS_E_GEN_3_0'); ?></b><?php echo marstek_t('TEXT.2500_2500_W'); ?>
<b>Venus E Mini</b> <?php echo marstek_t('TEXT.2_KWH_1500_W_LADEN_800_W_ENTLADEN_'); ?><br>
<b>kWh-Z&auml;hler (Modbus)</b><?php echo marstek_t('TEXT.VENUS_E_GEN_3_0_KANN_AB_FIRMWARE_1'); ?> <b><?php echo marstek_t('TEXT.MODBUS_TCP'); ?></b> <?php echo marstek_t('TEXT.DIREKT_BER_DAS_LAN_KABEL_ABGEFRAGT'); ?> <b><?php echo marstek_t('TEXT.EINSCHALTEN'); ?></b><?php echo marstek_t('TEXT.WENN_DAS_GERT_PER_LAN_ANGESCHLOSSE'); ?></div>

<div class="sm-row">
    <div>
        <label><?php echo marstek_t('TEXT.STATUS_CACHE_SEKUNDEN'); ?></label>
        <input data-role="none" type="number" name="cache_sec" value="<?= (int) $mv_cfg['cache_sec'] ?>" min="5" max="300">
        <div class="sm-small"><?php echo marstek_t('TEXT.SCHUTZ_DER_GERTE_HUFIGERE_ABFRAGEN'); ?></div>
    </div>
    <div>
        <label><?php echo marstek_t('TEXT.AUTO_FALLBACK_MINUTEN_0_AUS'); ?></label>
        <input data-role="none" type="number" name="fallback_min" value="<?= (int) $mv_cfg['fallback_min'] ?>" min="0" max="1440">
        <div class="sm-small"><?php echo marstek_t('TEXT.KOMMT_SO_LANGE_KEIN_SOLLWERT_MEHR_'); ?><b><?php echo marstek_t('TEXT.AUTO_MODUS'); ?></b><?php echo marstek_t('TEXT.STATT_IHN_IM_LEERLAUF_STEHEN_ZU_LA'); ?></div>
    </div>
</div>

<h2><?php echo marstek_t('TEXT.SPOTPREISE_AWATTAR'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo marstek_t('TEXT.MARKT'); ?></label>
        <select data-role="none" name="awattar">
            <option value="de"<?= $mv_cfg['awattar'] === 'de' ? ' selected' : '' ?><?php echo marstek_t('TEXT.DEUTSCHLAND_API_AWATTAR_DE'); ?></option>
            <option value="at"<?= $mv_cfg['awattar'] === 'at' ? ' selected' : '' ?><?php echo marstek_t('TEXT.OUML_STERREICH_API_AWATTAR_AT'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo marstek_t('TEXT.UST_FAKTOR'); ?></label>
        <input data-role="none" type="text" name="vat" value="<?= e($mv_cfg['vat']) ?>" placeholder="1.19">
        <div class="sm-small"><?php echo marstek_t('TEXT.BRSENPREIS_NETTO_WIRD_DAMIT_MULTIP'); ?></div>
    </div>
</div>

<h2><?php echo marstek_t('TEXT.MQTT_OPTIONAL'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($mv_cfg['mqtt_enabled']) ? 'checked' : '' ?><?php echo marstek_t('TEXT.STATUS_ZUSTZLICH_PER_MQTT_VERFFENT'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo marstek_t('TEXT.TOPIC_PRFIX'); ?></label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= e($mv_cfg['mqtt_topic']) ?>" placeholder="marstek">
        <div class="sm-small"><?php echo marstek_t('TEXT.NUTZT_DAS'); ?> <b><?php echo marstek_t('TEXT.LOXBERRY_MQTT_GATEWAY'); ?></b> <?php echo marstek_t('TEXT.MUSS_EINGERICHTET_SEIN_VERFFENTLIC'); ?>
        <span class="sm-mono"><?= e($mv_cfg['mqtt_topic']) ?><?php echo marstek_t('TEXT.SOC'); ?></span>, <span class="sm-mono"><?php echo marstek_t('TEXT.BATP'); ?></span>, <span class="sm-mono"><?php echo marstek_t('TEXT.TEMP'); ?></span>,
        <span class="sm-mono"><?php echo marstek_t('TEXT.GRIDP'); ?></span>, <span class="sm-mono">/ok</span>, <span class="sm-mono">/fw</span>, <span class="sm-mono">/ms</span>
        <?php echo marstek_t('TEXT.GERT_2'); ?> <span class="sm-mono"><?= e($mv_cfg['mqtt_topic']) ?><?php echo marstek_t('TEXT.2_SOC'); ?></span> <?php echo marstek_t('TEXT.USW'); ?></div>
    </div>
</div>

<button data-role="none" class="sm-btn" type="submit"><?php echo marstek_t('TEXT.SPEICHERN'); ?></button>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane" id="tab-loxone">
<h2><?php echo marstek_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<p><?php echo marstek_t('TEXT.LOXONE_IST_DER_ENERGIEMANAGER_DER_'); ?> <b><?php echo marstek_t('TEXT.AUTO_FALLBACK'); ?></b> <?php echo marstek_t('TEXT.DANACH_ZURCK_IN_DEN_AUTO_MODUS'); ?></p>

<div class="sm-step"><b><?php echo marstek_t('TEXT.SCHRITT_1_VIRTUELLER_HTTP_EINGANG_'); ?></b> <?php echo marstek_t('TEXT.ABFRAGE_ALLE_60_S'); ?>
<table class="sm-tbl">
<tr><th><?php echo marstek_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo marstek_t('TEXT.WERT'); ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono"><?php echo marstek_t('TEXT.HTTP'); ?><?= $mv_host ?><?php echo marstek_t('TEXT.PLUGINS'); ?><?= e($mv_plugindir) ?><?php echo marstek_t('TEXT.MARSTEK_PHP_STATUS'); ?></span></td></tr>
<tr><td><?php echo marstek_t('TEXT.ABFRAGEZYKLUS'); ?></td><td><?php echo marstek_t('TEXT.60_SEKUNDEN'); ?></td></tr>
</table>
<?php echo marstek_t('TEXT.BEFEHLE_JE_EIN_VIRTUELLER_HTTP_EIN'); ?> <span class="sm-mono">\i...\i</span> <?php echo marstek_t('TEXT.SUCHTEXT'); ?> <span class="sm-mono">\v</span> <?php echo marstek_t('TEXT.ZAHL_DAHINTER'); ?>
<table class="sm-tbl">
<tr><th><?php echo marstek_t('TEXT.BEFEHLSERKENNUNG'); ?></th><th><?php echo marstek_t('TEXT.BEDEUTUNG'); ?></th></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.ISOC_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.LADEZUSTAND_IN_PROZENT'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.IBATP_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.BATTERIELEISTUNG_IN_WATT'); ?><b><?php echo marstek_t('TEXT.LDT'); ?></b><?php echo marstek_t('TEXT.ENTLDT'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.ITEMP_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.BATTERIETEMPERATUR_IN_C'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.IOK_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.1_GERT_ANTWORTET_0_STRUNG_FR_EINEN'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.IFW_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.FIRMWARE_VERSION_DES_GERTS_OPTIONA'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.IMS_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.ANTWORTZEIT_DES_GERTS_IN_MS_OPTION'); ?></td></tr>
</table>
<b><?php echo marstek_t('TEXT.MEHRERE_SPEICHER'); ?></b> <?php echo marstek_t('TEXT.FR_GERT_2_EINEN_ZWEITEN_VIRTUELLEN'); ?>
<span class="sm-mono"><?php echo marstek_t('TEXT.MARSTEK_PHP_STATUSDEV_2'); ?></span> <?php echo marstek_t('TEXT.ANLEGEN_GERT_3'); ?> <span class="sm-mono"><?php echo marstek_t('TEXT.DEV_3'); ?></span> usw.).
</div>

<div class="sm-step"><b><?php echo marstek_t('TEXT.SCHRITT_2_VIRTUELLER_HTTP_EINGANG_'); ?></b> <?php echo marstek_t('TEXT.ABFRAGE_ALLE_300_S_GILT_FR_ALLE_GE'); ?>
<table class="sm-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= $mv_host ?>/plugins/<?= e($mv_plugindir) ?><?php echo marstek_t('TEXT.MARSTEK_PHP_RANKS'); ?></span></td></tr>
<tr><td>Abfragezyklus</td><td><?php echo marstek_t('TEXT.300_SEKUNDEN'); ?></td></tr>
</table>
<table class="sm-tbl">
<tr><th>Befehlserkennung</th><th>Bedeutung</th></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.IRANK_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.RANG_DER_AKTUELLEN_STUNDE_UNTER_DE'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.IRANKD_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.RANG_ABSTEIGEND_1_TEUERSTE_STUNDE'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.INEG_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.1_AKTUELLER_SPOTPREIS_IST_NEGATIV'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.ICURP_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.AKTUELLER_SPOTPREIS_IN_EUR_KWH_INK'); ?></td></tr>
<tr><td><span class="sm-mono">\iOK=\i\v</span></td><td><?php echo marstek_t('TEXT.1_RANKING_DATEN_GLTIG'); ?></td></tr>
</table>
<?php echo marstek_t('TEXT.DAMIT_BAUT_MAN_Z_B'); ?> <i><?php echo marstek_t('TEXT.LADE_IN_DEN_X_GNSTIGSTEN_STUNDEN'); ?></i> <?php echo marstek_t('TEXT.AUSWAHLTASTEN_BAUSTEIN_X_UND_VERGL'); ?>
<span class="sm-mono"><?php echo marstek_t('TEXT.RANK_X'); ?></span>; <i><?php echo marstek_t('TEXT.ENTLADE_IN_DEN_Y_TEUERSTEN_STUNDEN'); ?></i> <?php echo marstek_t('TEXT.VERGLEICH'); ?> <span class="sm-mono"><?php echo marstek_t('TEXT.RANKD_Y'); ?></span>.
</div>

<div class="sm-step"><b><?php echo marstek_t('TEXT.SCHRITT_3_VIRTUELLER_AUSGANG_SOLLW'); ?></b>
<table class="sm-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td><?php echo marstek_t('TEXT.ADRESSE_VIRTUELLER_AUSGANG'); ?></td><td><span class="sm-mono">http://<?= $mv_host ?></span></td></tr>
<tr><td><?php echo marstek_t('TEXT.BEFEHL_BEI_EIN_ANALOG'); ?></td><td><span class="sm-mono">/plugins/<?= e($mv_plugindir) ?><?php echo marstek_t('TEXT.MARSTEK_PHP_P_VT_240'); ?></span></td></tr>
</table>
<span class="sm-mono">&lt;v&gt;</span> <?php echo marstek_t('TEXT.GEWNSCHTE_LEISTUNG_IN_WATT'); ?><b><?php echo marstek_t('TEXT.LADEN'); ?></b><?php echo marstek_t('TEXT.ENTLADEN_0_LEERLAUF'); ?>
<span class="sm-mono">t=240</span> <?php echo marstek_t('TEXT.WATCHDOG_OHNE_NEUEN_BEFEHL_STOPPT_'); ?> <span class="sm-mono"><?php echo marstek_t('TEXT.T_240DEV_2'); ?></span> <?php echo marstek_t('TEXT.ANLEGEN_DEN_SOLLWERT_ALLE_60_S_NEU'); ?> <span class="sm-mono">I1+I2</span>
<?php echo marstek_t('TEXT.AUF_DEN_SOLLWERT_ADDIEREN_DAS_1_WA'); ?>
<div class="sm-alert sm-warn"><b>Token n&ouml;tig:</b> Der Endpunkt liegt unangemeldet und ist deshalb mit einem Token abgesichert &ndash; ohne passendes <span class="sm-mono">&amp;token=...</span> antwortet er mit HTTP 403. Die vollst&auml;ndige Adresse mit Token steht im n&auml;chsten Abschnitt.</div>
</div>

<div class="sm-step"><b>Aktionstoken</b>
<table class="sm-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>Aktuelles Token</td><td><span class="sm-mono"><?= e($mv_cfg['aktionstoken']) ?></span></td></tr>
<tr><td>Adresse Sollwert (Beispiel)</td><td><span class="sm-mono">http://<?= $mv_host ?>/plugins/<?= e($mv_plugindir) ?>/marstek.php?p=&lt;v&gt;&amp;t=240&amp;token=<?= e($mv_cfg['aktionstoken']) ?></span></td></tr>
<tr><td>Adresse Modus (Beispiel)</td><td><span class="sm-mono">http://<?= $mv_host ?>/plugins/<?= e($mv_plugindir) ?>/marstek.php?mode=auto&amp;token=<?= e($mv_cfg['aktionstoken']) ?></span></td></tr>
</table>
<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" type="submit" name="token_neu" value="1">Neues Token erzeugen</button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> Aktion &ndash; &auml;ndert bestehende Loxone-Adressen</span>
</div>
</div>

<div class="sm-step"><b><?php echo marstek_t('TEXT.SCHRITT_4_EMPFOHLENE_LOXONE_LOGIK'); ?></b> <?php echo marstek_t('TEXT.SO_IST_ES_IM_REFERENZPROJEKT_GEBAU'); ?><br><br>
<?php echo marstek_t('TEXT.TEXT_2'); ?> <b><?php echo marstek_t('TEXT.PV_UUML_BERSCHUSSLADEN'); ?></b> <?php echo marstek_t('TEXT.IMMER_AKTIV_LADELEISTUNG_NETZ_EXPO'); ?><br>
&bull; <b><?php echo marstek_t('TEXT.SPOT_LADEN'); ?></b> <?php echo marstek_t('TEXT.SCHALTER_BEI_NEGATIVEM_PREIS'); ?><span class="sm-mono"><?php echo marstek_t('TEXT.NEG_1'); ?></span><?php echo marstek_t('TEXT.ODER'); ?>
<span class="sm-mono">RANK &le; X</span> <?php echo marstek_t('TEXT.MIT_VOLLER_LEISTUNG_LADEN_NUR_WENN'); ?><br>
&bull; <b><?php echo marstek_t('TEXT.ENTLADEN'); ?></b> <?php echo marstek_t('TEXT.SCHALTER_ENTLADELEISTUNG_NETZ_BEZU'); ?>
<span class="sm-mono">RANKD &le; Y</span> <?php echo marstek_t('TEXT.TEUERSTEN_STUNDEN_SPERREN_SOC_UNTE'); ?><br>
&bull; <b><?php echo marstek_t('TEXT.SCHUTZ'); ?></b><?php echo marstek_t('TEXT.LADEN_STOPPT_BEI_SOC_97_ENTLADEN_B'); ?>
<span class="sm-mono">OK=0</span> <?php echo marstek_t('TEXT.LNGER_ALS_15_MINUTEN'); ?><br>
&bull; <b><?php echo marstek_t('TEXT.MEHRERE_SPEICHER_2'); ?></b><?php echo marstek_t('TEXT.ENTWEDER_JE_GERT_EINE_EIGENE_KLEIN'); ?>
</div>

<div class="sm-step"><b><?php echo marstek_t('TEXT.SCHRITT_5_OPTIONAL_VIRTUELLER_HTTP'); ?></b> <?php echo marstek_t('TEXT.ABFRAGE_ALLE_300_S'); ?><br>
<?php echo marstek_t('TEXT.VORAUSSETZUNG_IN_DEN_EINSTELLUNGEN'); ?> <b><?php echo marstek_t('TEXT.KWH_ZHLER_MODBUS_EIN'); ?></b>
<?php echo marstek_t('TEXT.VENUS_E_GEN_3_0_AB_FIRMWARE_144_PE'); ?>
<b><?php echo marstek_t('TEXT.AMTLICHEN_ZHLERSTNDE_DES_GERTS'); ?></b> <?php echo marstek_t('TEXT.STATT_SELBST_HOCHGERECHNETER_WERTE'); ?>
<table class="sm-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= $mv_host ?>/plugins/<?= e($mv_plugindir) ?><?php echo marstek_t('TEXT.MARSTEK_PHP_ENERGY'); ?></span></td></tr>
<tr><td>Abfragezyklus</td><td>300 Sekunden</td></tr>
</table>
<table class="sm-tbl">
<tr><th>Befehlserkennung</th><th>Bedeutung</th></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.ICHGD_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.HEUTE_GELADEN_KWH'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.IDISD_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.HEUTE_ABGEGEBEN_KWH'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.ICHGM_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.DIESEN_MONAT_GELADEN_KWH'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.IDISM_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.DIESEN_MONAT_ABGEGEBEN_KWH'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.ICHGT_I_V'); ?></span> / <span class="sm-mono"><?php echo marstek_t('TEXT.IDIST_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.GESAMT_GELADEN_ABGEGEBEN_KWH_SEIT_'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.ICYC_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.LADEZYKLEN_DES_GERTS'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo marstek_t('TEXT.IEFF_I_V'); ?></span></td><td><?php echo marstek_t('TEXT.WIRKUNGSGRAD_GESAMT_IN_ABGEGEBEN_G'); ?></td></tr>
<tr><td><span class="sm-mono">\iOK=\i\v</span></td><td><?php echo marstek_t('TEXT.1_MODBUS_DATEN_GLTIG'); ?></td></tr>
</table>
<b><?php echo marstek_t('TEXT.WARUM_NICHT_GLEICH_ALLES_BER_MODBU'); ?></b> <?php echo marstek_t('TEXT.BEWUSSTE_ENTSCHEIDUNG_GESTEUERT_WI'); ?> <b><?php echo marstek_t('TEXT.WATCHDOG'); ?></b> <?php echo marstek_t('TEXT.HAT_SPEICHER_STOPPT_VON_SELBST_WEN'); ?>
</div>

<div class="sm-step"><b><?php echo marstek_t('TEXT.SCHRITT_6_KOMPLETTE_BAUSTEIN_LISTE'); ?></b><br>
<?php echo marstek_t('TEXT.SO_IST_DIE_LOGIK_IM_REFERENZPROJEK'); ?>
<b><?php echo marstek_t('TEXT.NETZLEISTUNG_IN_WATT'); ?></b> mit <b><?php echo marstek_t('TEXT.BEZUG'); ?></b> und <b><?php echo marstek_t('TEXT.EINSPEISUNG'); ?></b><?php echo marstek_t('TEXT.ALLE_BAUSTEINE_STEHEN_IN_LOXONE_CO'); ?>
<br><br><b><?php echo marstek_t('TEXT.6A_BEDIENELEMENTE'); ?></b> <?php echo marstek_t('TEXT.VISUALISIERUNG_FREIGEBEN_ALLE_REMA'); ?>
<table class="sm-tbl">
<tr><th><?php echo marstek_t('TEXT.BAUSTEIN'); ?></th><th><?php echo marstek_t('TEXT.NAME'); ?></th><th><?php echo marstek_t('TEXT.EINSTELLUNG_ZWECK'); ?></th></tr>
<tr><td><?php echo marstek_t('TEXT.TASTER_EIN_AUS'); ?></td><td><?php echo marstek_t('TEXT.SPEICHER_NETZLADEN_ERLAUBT'); ?></td><td><?php echo marstek_t('TEXT.STANDARD_AUS_HAUPTSCHALTER_FRS_LAD'); ?></td></tr>
<tr><td>Taster EIN/AUS</td><td><?php echo marstek_t('TEXT.SPEICHER_SPOTPREIS_LADEN_AKTIV'); ?></td><td><?php echo marstek_t('TEXT.STANDARD_AUS_LADEN_BEI_NEGATIVPREI'); ?></td></tr>
<tr><td>Taster EIN/AUS</td><td><?php echo marstek_t('TEXT.SPEICHER_ENTLADUNG_INS_HAUSNETZ_ER'); ?></td><td><?php echo marstek_t('TEXT.STANDARD_AUS'); ?></td></tr>
<tr><td>Taster EIN/AUS</td><td><?php echo marstek_t('TEXT.SPEICHER_ENTLADUNG_NUR_IN_TEUERSTE'); ?></td><td><?php echo marstek_t('TEXT.AUS_BEI_JEDEM_NETZBEZUG_ENTLADEN'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.VIRTUELLER_EINGANG_ZAHL_024'); ?></td><td><?php echo marstek_t('TEXT.ANZAHL_GNSTIGSTE_STUNDEN_LADEN'); ?></td><td><?php echo marstek_t('TEXT.X_FR_LADE_IN_DEN_X_GNSTIGSTEN_STUN'); ?></td></tr>
<tr><td>Virtueller Eingang (Zahl 0&ndash;24)</td><td><?php echo marstek_t('TEXT.ANZAHL_TEUERSTE_STUNDEN_ENTLADEN'); ?></td><td><?php echo marstek_t('TEXT.Y_FR_ENTLADE_IN_DEN_Y_TEUERSTEN_ST'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.VIRTUELLER_EINGANG_ZAHL_0100'); ?></td><td><?php echo marstek_t('TEXT.MINDEST_SOC_RESERVE'); ?></td><td><?php echo marstek_t('TEXT.NOTSTROMRESERVE_Z_B_12_IM_WINTER_H'); ?></td></tr>
</table>
<b><?php echo marstek_t('TEXT.6B_PV_UUML_BERSCHUSSLADEN'); ?></b> <?php echo marstek_t('TEXT.IMMER_AKTIV'); ?>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th><?php echo marstek_t('TEXT.FORMEL_EINSTELLUNG'); ?></th><th><?php echo marstek_t('TEXT.EINGNGE'); ?></th></tr>
<tr><td><?php echo marstek_t('TEXT.FORMEL_F1'); ?></td><td><?php echo marstek_t('TEXT.EXPORT_LEISTUNG_W'); ?></td><td><span class="sm-mono"><?php echo marstek_t('TEXT.MAX_0_I1'); ?></span></td><td><?php echo marstek_t('TEXT.I1_NETZLEISTUNG_ZHLER'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.FORMEL_F2'); ?></td><td><?php echo marstek_t('TEXT.BEZUG_LEISTUNG_W'); ?></td><td><span class="sm-mono"><?php echo marstek_t('TEXT.MAX_0_I1_2'); ?></span></td><td>I1 &larr; Netzleistung Z&auml;hler</td></tr>
<tr><td><?php echo marstek_t('TEXT.SCHWELLWERTSCHALTER_S1'); ?></td><td><?php echo marstek_t('TEXT.EXPORT_BER_150_W'); ?></td><td><?php echo marstek_t('TEXT.EIN_150_AUS_120'); ?></td><td><?php echo marstek_t('TEXT.F1'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.FORMEL_F3'); ?></td><td><?php echo marstek_t('TEXT.PV_LADELEISTUNG_W'); ?></td><td><span class="sm-mono"><?php echo marstek_t('TEXT.MIN_I1_100_2500_I2'); ?></span> <?php echo marstek_t('TEXT.100_W_RESERVE_2500_MAX_LADEN'); ?></td><td><?php echo marstek_t('TEXT.I1_F1_I2_S1'); ?></td></tr>
</table>
<b><?php echo marstek_t('TEXT.6C_SPOT_LADEN'); ?></b> <?php echo marstek_t('TEXT.NETZ_NEGATIVPREIS_LADEN'); ?>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo marstek_t('TEXT.VERGLEICHER_V1'); ?></td><td><?php echo marstek_t('TEXT.RANG_LADESTUNDEN'); ?></td><td><?php echo marstek_t('TEXT.Q_1_WENN_I1_I2'); ?></td><td><?php echo marstek_t('TEXT.I1_VE_ANZAHL_GNSTIGSTE_STUNDEN_I2_'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.SCHWELLWERTSCHALTER_S2'); ?></td><td><?php echo marstek_t('TEXT.LADESTUNDEN_EINGESTELLT'); ?></td><td><?php echo marstek_t('TEXT.EIN_0_5'); ?></td><td><?php echo marstek_t('TEXT.VE_ANZAHL_GNSTIGSTE_STUNDEN'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.UND_U1'); ?></td><td><?php echo marstek_t('TEXT.SPOT_LADEFENSTER'); ?></td><td></td><td><?php echo marstek_t('TEXT.V1_S2'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.UND_U2'); ?></td><td><?php echo marstek_t('TEXT.RANKING_GLTIG'); ?></td><td></td><td><?php echo marstek_t('TEXT.U1_OK_SCHRITT_2'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.ODER_O1'); ?></td><td><?php echo marstek_t('TEXT.SPOTLADUNG_NTIG'); ?></td><td></td><td><?php echo marstek_t('TEXT.NEG_SCHRITT_2_U2'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.UND_U3'); ?></td><td><?php echo marstek_t('TEXT.SPOTPREIS_LADEN_AKTIV'); ?></td><td></td><td><?php echo marstek_t('TEXT.O1_TASTER_SPOTPREIS_LADEN_AKTIV'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.UND_U4'); ?></td><td><?php echo marstek_t('TEXT.NETZLADEN_ERLAUBT'); ?></td><td></td><td><?php echo marstek_t('TEXT.U3_TASTER_NETZLADEN_ERLAUBT'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.FORMEL_F4'); ?></td><td><?php echo marstek_t('TEXT.SPOT_LADELEISTUNG_W'); ?></td><td><span class="sm-mono">I1*2500</span></td><td><?php echo marstek_t('TEXT.I1_U4'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.FORMEL_F5'); ?></td><td><?php echo marstek_t('TEXT.LADEWUNSCH_W'); ?></td><td><span class="sm-mono"><?php echo marstek_t('TEXT.MAX_I1_I2'); ?></span></td><td><?php echo marstek_t('TEXT.I1_F3_I2_F4'); ?></td></tr>
</table>
<b><?php echo marstek_t('TEXT.6D_ENTLADEN'); ?></b> <?php echo marstek_t('TEXT.MIT_ALLEN_SPERREN'); ?>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo marstek_t('TEXT.SCHWELLWERTSCHALTER_S3'); ?></td><td><?php echo marstek_t('TEXT.SOC_BER_12'); ?></td><td><?php echo marstek_t('TEXT.EIN_12_AUS_11'); ?></td><td><?php echo marstek_t('TEXT.SOC_SCHRITT_1'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.VERGLEICHER_V2'); ?></td><td><?php echo marstek_t('TEXT.SOC_BER_RESERVE'); ?></td><td>Q=1 wenn I1 &ge; I2</td><td><?php echo marstek_t('TEXT.I1_SOC_I2_VE_MINDEST_SOC_RESERVE'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.UND_U5'); ?></td><td><?php echo marstek_t('TEXT.SOC_BER_12_UND_RESERVE'); ?></td><td></td><td><?php echo marstek_t('TEXT.S3_V2'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.SCHWELLWERTSCHALTER_S4'); ?></td><td><?php echo marstek_t('TEXT.BEZUG_BER_100_W'); ?></td><td><?php echo marstek_t('TEXT.EIN_100_AUS_80'); ?></td><td><?php echo marstek_t('TEXT.F2'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.VERGLEICHER_V3'); ?></td><td><?php echo marstek_t('TEXT.RANG_ENTLADESTUNDEN'); ?></td><td>Q=1 wenn I1 &ge; I2</td><td><?php echo marstek_t('TEXT.I1_VE_ANZAHL_TEUERSTE_STUNDEN_I2_R'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.SCHWELLWERTSCHALTER_S5'); ?></td><td><?php echo marstek_t('TEXT.ENTLADESTUNDEN_EINGESTELLT'); ?></td><td>Ein 0,5</td><td><?php echo marstek_t('TEXT.VE_ANZAHL_TEUERSTE_STUNDEN'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.UND_U6_UND_U7'); ?></td><td><?php echo marstek_t('TEXT.SPOT_ENTLADEFENSTER_RANKING_GLTIG'); ?></td><td></td><td><?php echo marstek_t('TEXT.U6_V3_S5_U7_U6_OK'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.NICHT_N1'); ?></td><td><?php echo marstek_t('TEXT.NUR_TEUERSTE_MODUS_AUS'); ?></td><td></td><td><?php echo marstek_t('TEXT.TASTER_NUR_IN_TEUERSTEN_STUNDEN'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.ODER_O2'); ?></td><td><?php echo marstek_t('TEXT.ENTLADEFENSTER_ERFLLT'); ?></td><td></td><td>U7 | N1</td></tr>
<tr><td><?php echo marstek_t('TEXT.UND_U8'); ?></td><td><?php echo marstek_t('TEXT.ENTLADUNG_ERLAUBT'); ?></td><td></td><td><?php echo marstek_t('TEXT.O2_TASTER_ENTLADUNG_ERLAUBT'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.NICHT_N2_UND_U9'); ?></td><td><?php echo marstek_t('TEXT.KEIN_NEGATIVPREIS'); ?></td><td><?php echo marstek_t('TEXT.NIE_INS_FALLENDE_MESSER_ENTLADEN'); ?></td><td><?php echo marstek_t('TEXT.U9_U8_NICHT_NEG'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.SCHWELLWERTSCHALTER_S6_NICHT_N3_UN'); ?></td><td><?php echo marstek_t('TEXT.WALLBOX_LDT_NICHT'); ?></td><td><?php echo marstek_t('TEXT.S6_WALLBOX_LEISTUNG_BER_0_5_KW'); ?></td><td><?php echo marstek_t('TEXT.U10_U9_N3'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.FORMEL_F6'); ?></td><td><?php echo marstek_t('TEXT.ENTLADEWUNSCH_W'); ?></td><td><span class="sm-mono"><?php echo marstek_t('TEXT.MIN_I1_50_2500_I2_I3_I4'); ?></span></td><td><?php echo marstek_t('TEXT.I1_F2_I2_U10_I3_S4_I4_U5'); ?></td></tr>
</table>
<b><?php echo marstek_t('TEXT.6E_SOLLWERT_BILDEN_UND_SENDEN'); ?></b>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo marstek_t('TEXT.SCHWELLWERTSCHALTER_S7'); ?></td><td><?php echo marstek_t('TEXT.SOC_BER_97'); ?></td><td><?php echo marstek_t('TEXT.EIN_97_AUS_96_LADESTOPP_VOLL'); ?></td><td><?php echo marstek_t('TEXT.SOC_2'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.FORMEL_F7'); ?></td><td><?php echo marstek_t('TEXT.SPEICHER_SOLLLEISTUNG_W'); ?></td><td><span class="sm-mono">I1*(1-I3)-I2</span></td><td><?php echo marstek_t('TEXT.I1_F5_I2_F6_I3_S7'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.IMPULSGEBER_T1'); ?></td><td><?php echo marstek_t('TEXT.SENDE_TAKT_60_S'); ?></td><td><?php echo marstek_t('TEXT.IMPULS_PAUSE_60_60_S'); ?></td><td></td></tr>
<tr><td><?php echo marstek_t('TEXT.ANALOGSPEICHER_M1'); ?></td><td><?php echo marstek_t('TEXT.SAMPLE_SOLLLEISTUNG'); ?></td><td><?php echo marstek_t('TEXT.SPEICHERT_BEI_TRIGGER'); ?></td><td><?php echo marstek_t('TEXT.EINGANG_F7_TRIGGER_T1'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.IMPULSGEBER_T2'); ?></td><td><?php echo marstek_t('TEXT.DITHER_TAKT'); ?></td><td><?php echo marstek_t('TEXT.60_60_S_ERZWINGT_NEUSENDUNG'); ?></td><td></td></tr>
<tr><td><?php echo marstek_t('TEXT.FORMEL_F8'); ?></td><td><?php echo marstek_t('TEXT.SOLLWERT_PLUS_DITHER'); ?></td><td><span class="sm-mono">I1+I2</span></td><td><?php echo marstek_t('TEXT.I1_M1_I2_T2_AUSGANG_AN_DEN_VIRTUEL'); ?></td></tr>
</table>
<b><?php echo marstek_t('TEXT.6F_STRUNGS_UUML_BERWACHUNG'); ?></b>
<table class="sm-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td><?php echo marstek_t('TEXT.NICHT_N4'); ?></td><td><?php echo marstek_t('TEXT.SPEICHER_NICHT_ERREICHBAR'); ?></td><td></td><td><?php echo marstek_t('TEXT.OK_SCHRITT_1'); ?></td></tr>
<tr><td><?php echo marstek_t('TEXT.EINSCHALTVERZGERUNG_E1'); ?></td><td><?php echo marstek_t('TEXT.15_MINUTEN_STRUNG'); ?></td><td>900 s</td><td><?php echo marstek_t('TEXT.N4_BENACHRICHTIGUNGS_BAUSTEIN_PUSH'); ?></td></tr>
</table>
<b><?php echo marstek_t('TEXT.6G_OPTIONAL_BERICHTE_AUS_DEN_GERTE'); ?></b> <?php echo marstek_t('TEXT.SCHRITT_5_NTIG_MONATSBILANZ_ANALOG'); ?> <span class="sm-mono"><?php echo marstek_t('TEXT.CHGT_SCHNAPPSCHUSS'); ?></span> <?php echo marstek_t('TEXT.BZW'); ?> <span class="sm-mono"><?php echo marstek_t('TEXT.DIST_SCHNAPPSCHUSS'); ?></span><?php echo marstek_t('TEXT.STATUSBAUSTEIN_PUSH_MIT_GELADEN_EN'); ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane" id="tab-test">
<h2>Test</h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo marstek_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo marstek_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo marstek_t('LEGENDE.AKTION'); ?></span>
</div>

<h3 class="sm-h3"><?php echo marstek_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik"  href="/plugins/<?= e($mv_plugindir) ?><?php echo marstek_t('TEXT.MARSTEK_PHP_STATUSDEBUG_1'); ?><?= $q ?>" target="_blank"><?php echo marstek_t('TEXT.STATUS_ABRUFEN_DEBUG'); ?></a>
<a class="sm-btn sm-b-technik"  href="/plugins/<?= e($mv_plugindir) ?><?php echo marstek_t('TEXT.MARSTEK_PHP_ENERGYDEBUG_1'); ?><?= $q ?>" target="_blank"><?php echo marstek_t('TEXT.KWH_ZHLER_MODBUS_DEBUG'); ?></a>
<a class="sm-btn sm-b-technik"  href="/plugins/<?= e($mv_plugindir) ?><?php echo marstek_t('TEXT.MARSTEK_PHP_DIAG_1'); ?><?= $q ?>" target="_blank"><?php echo marstek_t('TEXT.DIAGNOSE_SELBSTTEST'); ?></a>
<a class="sm-btn sm-b-technik"  href="/plugins/<?= e($mv_plugindir) ?>/marstek.php?ranks&amp;debug=1" target="_blank"><?php echo marstek_t('TEXT.SPOT_RANKING_DEBUG'); ?></a>
</div>

<h3 class="sm-h3"><?php echo marstek_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= e($mv_plugindir) ?><?php echo marstek_t('TEXT.MARSTEK_PHP_P_0T_60'); ?><?= $q ?>&amp;token=<?= e($mv_cfg['aktionstoken']) ?>" target="_blank"><?php echo marstek_t('TEXT.LEERLAUF_SENDEN_P_0'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= e($mv_plugindir) ?><?php echo marstek_t('TEXT.MARSTEK_PHP_P_800T_120'); ?><?= $q ?>&amp;token=<?= e($mv_cfg['aktionstoken']) ?>" target="_blank"><?php echo marstek_t('TEXT.ENTLADEN_PRFEN_P_800'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= e($mv_plugindir) ?><?php echo marstek_t('TEXT.MARSTEK_PHP_P_800T_120_2'); ?><?= $q ?>&amp;token=<?= e($mv_cfg['aktionstoken']) ?>" target="_blank"><?php echo marstek_t('TEXT.LADEN_PRFEN_P_800'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= e($mv_plugindir) ?><?php echo marstek_t('TEXT.MARSTEK_PHP_MODE_AUTO'); ?><?= $q ?>&amp;token=<?= e($mv_cfg['aktionstoken']) ?>" target="_blank"><?php echo marstek_t('TEXT.MODUS_AUTO_HANDBETRIEB'); ?></a>
</div>

<?php $testdevs = $mv_devices ? $mv_devices : array(1 => array('name' => 'Ger&auml;t 1'));
foreach ($testdevs as $n => $d) { $q = $n > 1 ? '&amp;dev=' . $n : ''; ?>
<p><b><?= e($d['name']) ?></b><?= $n > 1 ? ' <span class="sm-small">(Aufrufe mit &amp;dev=' . $n . ')</span>' : '' ?><br>

<?php if (!empty($d['modbus'])) { ?><?php } ?>

</p>
<?php } ?>

<div class="sm-small">
&bull; <b><?php echo marstek_t('TEXT.STATUS_ABRUFEN'); ?></b> <?php echo marstek_t('TEXT.ZEIGT_DIE_ROHANTWORTEN_DES_GERTS_E'); ?><br>
&bull; <b><?php echo marstek_t('TEXT.SPOT_RANKING'); ?></b> <?php echo marstek_t('TEXT.LISTET_ALLE_STUNDENPREISE_DER_NCHS'); ?><br>
&bull; <b><?php echo marstek_t('TEXT.LEERLAUF_SENDEN'); ?></b> <?php echo marstek_t('TEXT.SETZT_DEN_PASSIV_SOLLWERT_AUF_0_W_'); ?><br>
&bull; <b><?php echo marstek_t('TEXT.ENTLADEN_PRFEN'); ?></b> / <b><?php echo marstek_t('TEXT.LADEN_PRFEN'); ?></b> <?php echo marstek_t('TEXT.GEBEN_VON_HAND_800_W_FR_120_S_VOR_'); ?><br>
&bull; <b><?php echo marstek_t('TEXT.MODUS_AUTO'); ?></b> <?php echo marstek_t('TEXT.GIBT_DIE_REGIE_AN_DEN_SPEICHER_ZUR'); ?>
</div>
</div>

<!-- ================= Reiter: <?php echo marstek_t('TEXT.LOGDATEI'); ?>en ================= -->
<div class="sm-pane" id="tab-log">
<h2>Logdatei</h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo marstek_t('TEXT.PROTOKOLLIERT_WERDEN_STATUS_SOLLWE'); ?><br><?php echo marstek_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= e($mv_log_file) ?></span></div>
<?php if ($mv_log_lines) { ?>
<div class="sm-log"><?= e(implode("\n", $mv_log_lines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo marstek_t('TEXT.NOCH_KEINE_LOG_EINTRGE_VORHANDEN'); ?></div>
<?php } ?>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn" type="submit" style="background:#c62828;"><?php echo marstek_t('TEXT.LOG_LEEREN'); ?></button>
</form>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { activate(t.dataset.pane); }); });
    activate(<?= json_encode($mv_active_tab) ?>);
})();
</script>
<?php
if ($mv_use_frame) {
    LBWeb::lbfooter();
}
