<?php
/**
 * Marstek Venus E - Admin-Oberflaeche (v1.0.0)
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
        if (@file_put_contents($mv_config_file, $json) !== false) {
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
    'mqtt_enabled' => 0, 'mqtt_topic' => 'marstek', 'fallback_min' => 30);
if (!is_array($mv_cfg['devices'])) { $mv_cfg['devices'] = json_decode(json_encode($mv_cfg['devices']), true) ?: array(); }
$mv_devices = function_exists('marstek_devices') ? marstek_devices() : array();

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
.mv-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.mv-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.mv-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.mv-wrap input[type=text], .mv-wrap input[type=number], .mv-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.mv-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.mv-row { display: flex; gap: 12px; }
.mv-row > div { flex: 1; }
.mv-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.mv-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.mv-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.mv-err { background: #ffebee; border: 1px solid #ef9a9a; }
.mv-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.mv-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.mv-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.mv-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.mv-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important; }
.mv-tab.mv-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.mv-pane { display: none; padding-top: 4px; }
.mv-pane.mv-active { display: block; }
.mv-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.mv-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.mv-tbl { border-collapse: collapse; margin: 8px 0; }
.mv-tbl th, .mv-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.mv-tbl th { background: #f0f0f0; }
.mv-devtbl { width: 100%; }
.mv-devtbl input { min-width: 60px; }
.mv-wrap .mv-btn, .mv-wrap a.mv-btn, .mv-wrap button { text-shadow: none !important; box-shadow: none !important; }
.mv-wrap a.mv-btn, .mv-wrap a.mv-btn:visited, .mv-wrap a.mv-btn:hover { color: #fff !important; text-decoration: none; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Standard aller Plugins) --- */
.mv-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.mv-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.mv-knopfreihe form { margin: 0; display: flex; }
.mv-knopfreihe .mv-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.mv-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.mv-legende span { display: inline-flex; align-items: center; gap: 6px; }
.mv-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.mv-btn.mv-b-lesen   { background: #6dac20; }
.mv-btn.mv-b-technik { background: #546e7a; }
.mv-btn.mv-b-aktion  { background: #e0620d; }
.mv-punkt.mv-b-lesen   { background: #6dac20; }
.mv-punkt.mv-b-technik { background: #546e7a; }
.mv-punkt.mv-b-aktion  { background: #e0620d; }
</style>
<div class="mv-wrap">

<?php if ($mv_saved) { ?><div class="mv-alert mv-ok"><b>Konfiguration gespeichert</b> (inkl. Sicherungskopie f&uuml;r Updates).</div><?php } ?>
<?php if ($mv_save_error !== '') { ?><div class="mv-alert mv-err"><b>Fehler:</b> <?= $mv_save_error ?></div><?php } ?>

<?php foreach ($mv_statuses as $n => $st) { ?>
<div class="mv-alert mv-info"><b><?= e($mv_devices[$n]['name']) ?></b> (<?= e(date('d.m.Y H:i:s', isset($st['ts']) ? (int) $st['ts'] : time())) ?>):
Ladezustand <?= e($st['soc']) ?> % &middot; Batterieleistung <?= e($st['batp']) ?> W (+ = l&auml;dt) &middot;
Temperatur <?= e($st['temp']) ?> &deg;C &middot; Verbindung <?= !empty($st['ok']) ? 'OK' : '<b>GEST&Ouml;RT</b>' ?>
<?php if (!empty($st['ok'])) { ?> &middot; <?= e(isset($st['model']) && $st['model'] !== '' ? $st['model'] : 'Venus') ?>,
Firmware <?= (int) (isset($st['fw']) ? $st['fw'] : 0) ?> &middot; Antwortzeit <?= (int) (isset($st['ms']) ? $st['ms'] : 0) ?> ms<?php } ?>
<?php if (!empty($mv_devices[$n]['modbus'])) {
    $en = @json_decode((string) @file_get_contents('/tmp/marstekvenus/energy_dev' . $n . '.json'), true);
    if (is_array($en) && !empty($en['ok'])) { ?>
<br>Energie heute: <b><?= e($en['chgd']) ?> kWh</b> geladen / <b><?= e($en['disd']) ?> kWh</b> abgegeben &middot;
Monat: <?= e($en['chgm']) ?> / <?= e($en['dism']) ?> kWh &middot; Zyklen: <?= (int) $en['cyc'] ?> &middot;
Wirkungsgrad gesamt: <?= e($en['eff']) ?> %
<?php } } ?>
<?php $hist = function_exists('marstek_history_read') ? marstek_history_read($n) : array(); ?>
<div style="margin-top:8px;"><?= mv_soc_svg($hist) ?></div>
<div class="mv-small">SOC-Verlauf heute (%). Die Messpunkte sammelt das Plugin automatisch im Hintergrund (ca. alle 4 Minuten).</div>
</div>
<?php } ?>

<div class="mv-tabs">
    <div class="mv-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="mv-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="mv-tab" data-pane="tab-test">Test</div>
    <div class="mv-tab" data-pane="tab-log">Logdateien</div>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="mv-pane" id="tab-settings">
<form method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Batteriespeicher (bis zu 4 Ger&auml;te)</h2>
<div class="mv-small">Ger&auml;t 1 ist das Standardger&auml;t (alle Aufrufe ohne <span class="mv-mono">&amp;dev=</span>).
Weitere Speicher (z.&nbsp;B. ein zweiter/dritter Venus E oder ein <b>Venus E Mini</b>) einfach in die n&auml;chste Zeile eintragen
und in Loxone mit <span class="mv-mono">&amp;dev=2</span> usw. ansprechen. Die lokale API muss an JEDEM Ger&auml;t einmalig
aktiviert werden (Marstek-App bzw. <a href="https://rweijnen.github.io/marstek-venus-monitor/latest/" target="_blank">Venus-Monitor-Tool</a> per Bluetooth), Standard-Port 30000.</div>
<table class="mv-tbl mv-devtbl">
<tr><th style="width:36px;">Nr.</th><th>Name (frei)</th><th>Ger&auml;te-IP</th><th style="width:90px;">UDP-Port</th><th style="width:110px;">Max. Laden (W)</th><th style="width:110px;">Max. Entladen (W)</th><th style="width:120px;">kWh-Z&auml;hler (Modbus)</th></tr>
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
<td><select data-role="none" name="dev_mb[]"><option value="0"<?= empty($d['modbus']) ? ' selected' : '' ?>>aus</option><option value="1"<?= !empty($d['modbus']) ? ' selected' : '' ?>>ein</option></select></td>
</tr>
<?php } ?>
</table>
<div class="mv-small">Leistungsgrenzen (Sollwerte werden hart darauf begrenzt): <b>Venus E Gen 3.0</b>: 2500/2500 W &middot;
<b>Venus E Mini</b> (2 kWh): 1500 W laden, 800 W entladen (Werkszustand; 1500 W mit Premium-Freischaltung).<br>
<b>kWh-Z&auml;hler (Modbus)</b>: Venus E Gen 3.0 kann ab Firmware 144 zus&auml;tzlich per <b>Modbus TCP</b> direkt
&uuml;ber das LAN-Kabel abgefragt werden (Port 502, kein Adapter n&ouml;tig). Das Plugin liest dar&uuml;ber NUR die
Energiez&auml;hler des Ger&auml;ts (geladen/abgegeben gesamt/Tag/Monat), Zyklen und Wirkungsgrad &mdash;
gesteuert wird weiterhin sicher &uuml;ber die UDP-API. Empfehlung: <b>einschalten</b>, wenn das Ger&auml;t per
LAN angeschlossen ist.</div>

<div class="mv-row">
    <div>
        <label>Status-Cache (Sekunden)</label>
        <input data-role="none" type="number" name="cache_sec" value="<?= (int) $mv_cfg['cache_sec'] ?>" min="5" max="300">
        <div class="mv-small">Schutz der Ger&auml;te: h&auml;ufigere Abfragen werden aus dem Cache beantwortet (Empfehlung 40; die Ger&auml;te m&ouml;gen keine Abfragen unter 60 s, der Cache f&auml;ngt das ab).</div>
    </div>
    <div>
        <label>Auto-Fallback (Minuten, 0 = aus)</label>
        <input data-role="none" type="number" name="fallback_min" value="<?= (int) $mv_cfg['fallback_min'] ?>" min="0" max="1440">
        <div class="mv-small">Kommt so lange KEIN Sollwert mehr an (z.&nbsp;B. Miniserver ausgefallen), gibt das Plugin die Regie zur&uuml;ck an den Speicher (<b>Auto-Modus</b>), statt ihn im Leerlauf stehen zu lassen. Empfehlung: 30. Der Watchdog im Sollwert (t=240) stoppt nur &mdash; der Fallback &uuml;bergibt.</div>
    </div>
</div>

<h2>Spotpreise (aWATTar)</h2>
<div class="mv-row">
    <div>
        <label>Markt</label>
        <select data-role="none" name="awattar">
            <option value="de"<?= $mv_cfg['awattar'] === 'de' ? ' selected' : '' ?>>Deutschland (api.awattar.de)</option>
            <option value="at"<?= $mv_cfg['awattar'] === 'at' ? ' selected' : '' ?>>&Ouml;sterreich (api.awattar.at)</option>
        </select>
    </div>
    <div>
        <label>USt-Faktor</label>
        <input data-role="none" type="text" name="vat" value="<?= e($mv_cfg['vat']) ?>" placeholder="1.19">
        <div class="mv-small">B&ouml;rsenpreis (netto) wird damit multipliziert; DE: 1.19, AT: 1.20, ohne USt: 1.0.</div>
    </div>
</div>

<h2>MQTT (optional)</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($mv_cfg['mqtt_enabled']) ? 'checked' : '' ?>> Status zus&auml;tzlich per MQTT ver&ouml;ffentlichen
</label>
<div class="mv-row" style="margin-top:6px;">
    <div>
        <label>Topic-Pr&auml;fix</label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= e($mv_cfg['mqtt_topic']) ?>" placeholder="marstek">
        <div class="mv-small">Nutzt das <b>LoxBerry MQTT Gateway</b> (muss eingerichtet sein). Ver&ouml;ffentlicht bei jeder Status-Abfrage:
        <span class="mv-mono"><?= e($mv_cfg['mqtt_topic']) ?>/soc</span>, <span class="mv-mono">/batp</span>, <span class="mv-mono">/temp</span>,
        <span class="mv-mono">/gridp</span>, <span class="mv-mono">/ok</span>, <span class="mv-mono">/fw</span>, <span class="mv-mono">/ms</span>
        (Ger&auml;t 2+: <span class="mv-mono"><?= e($mv_cfg['mqtt_topic']) ?>/2/soc</span> usw.).</div>
    </div>
</div>

<button data-role="none" class="mv-btn" type="submit">Speichern</button>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="mv-pane" id="tab-loxone">
<h2>Einbindung in Loxone &mdash; Schritt f&uuml;r Schritt</h2>
<p>Loxone ist der Energiemanager: Der Miniserver liest Status und Spot-Ranking vom Plugin, entscheidet
(PV-&Uuml;berschuss, g&uuml;nstige/teure Stunden) und schickt dem Speicher alle 60 Sekunden einen Leistungs-Sollwert.
F&auml;llt Loxone aus, l&auml;uft der Befehl nach wenigen Minuten ab und der Speicher geht sicher in den Leerlauf
(mit aktiviertem <b>Auto-Fallback</b> danach zur&uuml;ck in den Auto-Modus).</p>

<div class="mv-step"><b>Schritt 1: Virtueller HTTP-Eingang &bdquo;Speicher-Status&ldquo;</b> (Abfrage alle 60 s)
<table class="mv-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="mv-mono">http://<?= $mv_host ?>/plugins/<?= e($mv_plugindir) ?>/marstek.php?status</span></td></tr>
<tr><td>Abfragezyklus</td><td>60 Sekunden</td></tr>
</table>
Befehle (je ein &bdquo;Virtueller HTTP-Eingang Befehl&ldquo;; <span class="mv-mono">\i...\i</span> = Suchtext, <span class="mv-mono">\v</span> = Zahl dahinter):
<table class="mv-tbl">
<tr><th>Befehlserkennung</th><th>Bedeutung</th></tr>
<tr><td><span class="mv-mono">\iSOC=\i\v</span></td><td>Ladezustand in Prozent</td></tr>
<tr><td><span class="mv-mono">\iBATP=\i\v</span></td><td>Batterieleistung in Watt (<b>+ = l&auml;dt</b>, &minus; = entl&auml;dt)</td></tr>
<tr><td><span class="mv-mono">\iTEMP=\i\v</span></td><td>Batterietemperatur in &deg;C</td></tr>
<tr><td><span class="mv-mono">\iOK=\i\v</span></td><td>1 = Ger&auml;t antwortet, 0 = St&ouml;rung (f&uuml;r einen St&ouml;rungs-Push nach z. B. 15 min)</td></tr>
<tr><td><span class="mv-mono">\iFW=\i\v</span></td><td>Firmware-Version des Ger&auml;ts (optional; z. B. f&uuml;r eine Info-Kachel)</td></tr>
<tr><td><span class="mv-mono">\iMS=\i\v</span></td><td>Antwortzeit des Ger&auml;ts in ms (optional; Netzwerk-Diagnose)</td></tr>
</table>
<b>Mehrere Speicher?</b> F&uuml;r Ger&auml;t 2 einen zweiten virtuellen Eingang mit
<span class="mv-mono">...marstek.php?status&amp;dev=2</span> anlegen (Ger&auml;t 3: <span class="mv-mono">&amp;dev=3</span> usw.).
</div>

<div class="mv-step"><b>Schritt 2: Virtueller HTTP-Eingang &bdquo;Spot-Ranking&ldquo;</b> (Abfrage alle 300 s; gilt f&uuml;r alle Ger&auml;te gemeinsam)
<table class="mv-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="mv-mono">http://<?= $mv_host ?>/plugins/<?= e($mv_plugindir) ?>/marstek.php?ranks</span></td></tr>
<tr><td>Abfragezyklus</td><td>300 Sekunden</td></tr>
</table>
<table class="mv-tbl">
<tr><th>Befehlserkennung</th><th>Bedeutung</th></tr>
<tr><td><span class="mv-mono">\iRANK=\i\v</span></td><td>Rang der aktuellen Stunde unter den n&auml;chsten 24 h (1 = g&uuml;nstigste)</td></tr>
<tr><td><span class="mv-mono">\iRANKD=\i\v</span></td><td>Rang absteigend (1 = teuerste Stunde)</td></tr>
<tr><td><span class="mv-mono">\iNEG=\i\v</span></td><td>1 = aktueller Spotpreis ist negativ</td></tr>
<tr><td><span class="mv-mono">\iCURP=\i\v</span></td><td>aktueller Spotpreis in EUR/kWh (inkl. USt-Faktor)</td></tr>
<tr><td><span class="mv-mono">\iOK=\i\v</span></td><td>1 = Ranking-Daten g&uuml;ltig</td></tr>
</table>
Damit baut man z. B.: <i>&bdquo;Lade in den X g&uuml;nstigsten Stunden&ldquo;</i> = Auswahltasten-Baustein (X) und Vergleich
<span class="mv-mono">RANK &le; X</span>; <i>&bdquo;Entlade in den Y teuersten Stunden&ldquo;</i> = Vergleich <span class="mv-mono">RANKD &le; Y</span>.
</div>

<div class="mv-step"><b>Schritt 3: Virtueller Ausgang &bdquo;Sollwert senden&ldquo;</b>
<table class="mv-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>Adresse (Virtueller Ausgang)</td><td><span class="mv-mono">http://<?= $mv_host ?></span></td></tr>
<tr><td>Befehl bei EIN (analog)</td><td><span class="mv-mono">/plugins/<?= e($mv_plugindir) ?>/marstek.php?p=&lt;v&gt;&amp;t=240</span></td></tr>
</table>
<span class="mv-mono">&lt;v&gt;</span> = gew&uuml;nschte Leistung in Watt (<b>+ = laden</b>, &minus; = entladen, 0 = Leerlauf).
<span class="mv-mono">t=240</span> = Watchdog: ohne neuen Befehl stoppt der Speicher nach 240 s von selbst.
F&uuml;r Ger&auml;t 2 einen eigenen Befehl mit <span class="mv-mono">...&amp;t=240&amp;dev=2</span> anlegen.
Den Sollwert alle 60 s neu senden &mdash; Trick aus der Praxis: Der virtuelle Ausgang sendet nur bei WERT&Auml;NDERUNG;
deshalb einen &bdquo;Dither&ldquo;-Takt (Impulsgeber 60/60 s, Ausgang 0/1) per Formel <span class="mv-mono">I1+I2</span>
auf den Sollwert addieren &mdash; das 1-Watt-Zappeln erzwingt die Neusendung und ist v&ouml;llig unsch&auml;dlich.
</div>

<div class="mv-step"><b>Schritt 4: Empfohlene Loxone-Logik</b> (so ist es im Referenzprojekt gebaut)<br><br>
&bull; <b>PV-&Uuml;berschussladen</b> (immer aktiv): Ladeleistung = Netz-Export &minus; 100 W Reserve, erst ab 150 W Export,
begrenzt auf die maximale Ladeleistung. Wallbox und W&auml;rmepumpe haben automatisch Vorrang, weil der Speicher nur
nimmt, was NACH ihnen noch exportiert wird.<br>
&bull; <b>Spot-Laden</b> (Schalter): bei negativem Preis (<span class="mv-mono">NEG=1</span>) oder
<span class="mv-mono">RANK &le; X</span> mit voller Leistung laden &mdash; nur wenn Netzladen erlaubt ist.<br>
&bull; <b>Entladen</b> (Schalter): Entladeleistung = Netz-Bezug &minus; 50 W; optional nur in den
<span class="mv-mono">RANKD &le; Y</span> teuersten Stunden. Sperren: SOC unter Reserve, negativer Preis
(nie ins fallende Messer entladen), Wallbox l&auml;dt (sonst l&auml;dt der Speicher verlustreich das Auto).<br>
&bull; <b>Schutz</b>: Laden stoppt bei SOC 97 %, Entladen bei SOC 12 %; St&ouml;rungs-Push wenn
<span class="mv-mono">OK=0</span> l&auml;nger als 15 Minuten.<br>
&bull; <b>Mehrere Speicher</b>: entweder je Ger&auml;t eine eigene kleine Logik (empfohlen, wenn die Ger&auml;te
verschieden gro&szlig; sind, z. B. Venus E + Venus E Mini) oder eine gemeinsame Logik, die den Gesamt-Sollwert
im Verh&auml;ltnis der maximalen Leistungen auf die Ger&auml;te aufteilt.
</div>

<div class="mv-step"><b>Schritt 5 (optional): Virtueller HTTP-Eingang &bdquo;Speicher-Energie&ldquo;</b> (Abfrage alle 300 s)<br>
Voraussetzung: In den Einstellungen ist beim Ger&auml;t <b>&bdquo;kWh-Z&auml;hler (Modbus)&ldquo; = ein</b>
(Venus E Gen 3.0 ab Firmware 144, per LAN-Kabel angeschlossen). Damit bekommt Loxone die
<b>amtlichen Z&auml;hlerst&auml;nde des Ger&auml;ts</b> statt selbst hochgerechneter Werte &mdash; ideal f&uuml;r
Tages-/Monatsberichte und die Ersparnis-Rechnung.
<table class="mv-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="mv-mono">http://<?= $mv_host ?>/plugins/<?= e($mv_plugindir) ?>/marstek.php?energy</span></td></tr>
<tr><td>Abfragezyklus</td><td>300 Sekunden</td></tr>
</table>
<table class="mv-tbl">
<tr><th>Befehlserkennung</th><th>Bedeutung</th></tr>
<tr><td><span class="mv-mono">\iCHGD=\i\v</span></td><td>heute geladen (kWh)</td></tr>
<tr><td><span class="mv-mono">\iDISD=\i\v</span></td><td>heute abgegeben (kWh)</td></tr>
<tr><td><span class="mv-mono">\iCHGM=\i\v</span></td><td>diesen Monat geladen (kWh)</td></tr>
<tr><td><span class="mv-mono">\iDISM=\i\v</span></td><td>diesen Monat abgegeben (kWh)</td></tr>
<tr><td><span class="mv-mono">\iCHGT=\i\v</span> / <span class="mv-mono">\iDIST=\i\v</span></td><td>gesamt geladen / abgegeben (kWh, seit Werk)</td></tr>
<tr><td><span class="mv-mono">\iCYC=\i\v</span></td><td>Ladezyklen des Ger&auml;ts</td></tr>
<tr><td><span class="mv-mono">\iEFF=\i\v</span></td><td>Wirkungsgrad gesamt in % (abgegeben/geladen)</td></tr>
<tr><td><span class="mv-mono">\iOK=\i\v</span></td><td>1 = Modbus-Daten g&uuml;ltig</td></tr>
</table>
<b>Warum nicht gleich alles &uuml;ber Modbus?</b> Bewusste Entscheidung: Gesteuert wird &uuml;ber die UDP-API,
weil nur deren Passiv-Modus einen <b>Watchdog</b> hat (Speicher stoppt von selbst, wenn Loxone ausf&auml;llt).
Der Modbus-Steuermodus (&bdquo;Force Charge/Discharge&ldquo;) liefe ungebremst weiter und blockiert zudem die App.
Modbus wird deshalb hier NUR LESEND f&uuml;r die Z&auml;hler genutzt &mdash; die beste Kombination aus beiden Welten.
</div>

<div class="mv-step"><b>Schritt 6: Komplette Baustein-Liste zum 1:1-Nachbauen</b><br>
So ist die Logik im Referenzprojekt verdrahtet. Annahme: Der eigene Stromz&auml;hler liefert die
<b>Netzleistung in Watt</b> mit <b>+ = Bezug</b> und <b>&minus; = Einspeisung</b>. Alle Bausteine stehen in
Loxone Config unter den angegebenen Kategorien; &bdquo;&larr;&ldquo; = Eingang kommt von.
<br><br><b>6a) Bedienelemente</b> (Visualisierung freigeben, alle remanent)
<table class="mv-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung / Zweck</th></tr>
<tr><td>Taster EIN/AUS</td><td>Speicher: Netzladen erlaubt</td><td>Standard AUS &mdash; Hauptschalter f&uuml;rs Laden aus dem Netz</td></tr>
<tr><td>Taster EIN/AUS</td><td>Speicher: Spotpreis-Laden aktiv</td><td>Standard AUS &mdash; Laden bei Negativpreis / g&uuml;nstigen Stunden</td></tr>
<tr><td>Taster EIN/AUS</td><td>Speicher: Entladung ins Hausnetz erlaubt</td><td>Standard AUS</td></tr>
<tr><td>Taster EIN/AUS</td><td>Speicher: Entladung nur in teuersten Stunden</td><td>AUS = bei jedem Netzbezug entladen</td></tr>
<tr><td>Virtueller Eingang (Zahl 0&ndash;24)</td><td>Anzahl g&uuml;nstigste Stunden (Laden)</td><td>X f&uuml;r &bdquo;Lade in den X g&uuml;nstigsten Stunden&ldquo;; 0 = aus</td></tr>
<tr><td>Virtueller Eingang (Zahl 0&ndash;24)</td><td>Anzahl teuerste Stunden (Entladen)</td><td>Y f&uuml;r &bdquo;Entlade in den Y teuersten Stunden&ldquo;; 0 = aus</td></tr>
<tr><td>Virtueller Eingang (Zahl 0&ndash;100)</td><td>Mindest-SOC Reserve (%)</td><td>Notstromreserve, z. B. 12; im Winter h&ouml;her stellen</td></tr>
</table>
<b>6b) PV-&Uuml;berschussladen</b> (immer aktiv)
<table class="mv-tbl">
<tr><th>Baustein</th><th>Name</th><th>Formel / Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Formel F1</td><td>Export-Leistung (W)</td><td><span class="mv-mono">MAX(0;-I1)</span></td><td>I1 &larr; Netzleistung Z&auml;hler</td></tr>
<tr><td>Formel F2</td><td>Bezug-Leistung (W)</td><td><span class="mv-mono">MAX(0;I1)</span></td><td>I1 &larr; Netzleistung Z&auml;hler</td></tr>
<tr><td>Schwellwertschalter S1</td><td>Export &uuml;ber 150 W</td><td>Ein 150 / Aus 120</td><td>&larr; F1</td></tr>
<tr><td>Formel F3</td><td>PV-Ladeleistung (W)</td><td><span class="mv-mono">MIN(I1-100;2500)*I2</span> (100 W Reserve; 2500 = max. Laden)</td><td>I1 &larr; F1, I2 &larr; S1</td></tr>
</table>
<b>6c) Spot-Laden</b> (Netz-/Negativpreis-Laden)
<table class="mv-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Vergleicher V1</td><td>Rang &le; Ladestunden</td><td>Q=1 wenn I1 &ge; I2</td><td>I1 &larr; VE &bdquo;Anzahl g&uuml;nstigste Stunden&ldquo;, I2 &larr; RANK (Schritt 2)</td></tr>
<tr><td>Schwellwertschalter S2</td><td>Ladestunden eingestellt</td><td>Ein 0,5</td><td>&larr; VE &bdquo;Anzahl g&uuml;nstigste Stunden&ldquo;</td></tr>
<tr><td>UND U1</td><td>Spot-Ladefenster</td><td></td><td>V1 &amp; S2</td></tr>
<tr><td>UND U2</td><td>Ranking g&uuml;ltig</td><td></td><td>U1 &amp; OK (Schritt 2)</td></tr>
<tr><td>ODER O1</td><td>Spotladung n&ouml;tig</td><td></td><td>NEG (Schritt 2) | U2</td></tr>
<tr><td>UND U3</td><td>Spotpreis-Laden aktiv</td><td></td><td>O1 &amp; Taster &bdquo;Spotpreis-Laden aktiv&ldquo;</td></tr>
<tr><td>UND U4</td><td>Netzladen erlaubt</td><td></td><td>U3 &amp; Taster &bdquo;Netzladen erlaubt&ldquo;</td></tr>
<tr><td>Formel F4</td><td>Spot-Ladeleistung (W)</td><td><span class="mv-mono">I1*2500</span></td><td>I1 &larr; U4</td></tr>
<tr><td>Formel F5</td><td>Ladewunsch (W)</td><td><span class="mv-mono">MAX(I1;I2)</span></td><td>I1 &larr; F3, I2 &larr; F4</td></tr>
</table>
<b>6d) Entladen</b> (mit allen Sperren)
<table class="mv-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S3</td><td>SOC &uuml;ber 12 %</td><td>Ein 12 / Aus 11</td><td>&larr; SOC (Schritt 1)</td></tr>
<tr><td>Vergleicher V2</td><td>SOC &uuml;ber Reserve</td><td>Q=1 wenn I1 &ge; I2</td><td>I1 &larr; SOC, I2 &larr; VE &bdquo;Mindest-SOC Reserve&ldquo;</td></tr>
<tr><td>UND U5</td><td>SOC &uuml;ber 12 % und Reserve</td><td></td><td>S3 &amp; V2</td></tr>
<tr><td>Schwellwertschalter S4</td><td>Bezug &uuml;ber 100 W</td><td>Ein 100 / Aus 80</td><td>&larr; F2</td></tr>
<tr><td>Vergleicher V3</td><td>Rang &le; Entladestunden</td><td>Q=1 wenn I1 &ge; I2</td><td>I1 &larr; VE &bdquo;Anzahl teuerste Stunden&ldquo;, I2 &larr; RANKD</td></tr>
<tr><td>Schwellwertschalter S5</td><td>Entladestunden eingestellt</td><td>Ein 0,5</td><td>&larr; VE &bdquo;Anzahl teuerste Stunden&ldquo;</td></tr>
<tr><td>UND U6 / UND U7</td><td>Spot-Entladefenster / Ranking g&uuml;ltig</td><td></td><td>U6: V3 &amp; S5; U7: U6 &amp; OK</td></tr>
<tr><td>NICHT N1</td><td>Nur-teuerste-Modus aus</td><td></td><td>&larr; Taster &bdquo;nur in teuersten Stunden&ldquo;</td></tr>
<tr><td>ODER O2</td><td>Entladefenster erf&uuml;llt</td><td></td><td>U7 | N1</td></tr>
<tr><td>UND U8</td><td>Entladung erlaubt</td><td></td><td>O2 &amp; Taster &bdquo;Entladung erlaubt&ldquo;</td></tr>
<tr><td>NICHT N2 + UND U9</td><td>kein Negativpreis</td><td>nie ins fallende Messer entladen</td><td>U9: U8 &amp; NICHT(NEG)</td></tr>
<tr><td>Schwellwertschalter S6 + NICHT N3 + UND U10</td><td>Wallbox l&auml;dt nicht</td><td>S6: Wallbox-Leistung &uuml;ber 0,5 kW</td><td>U10: U9 &amp; N3</td></tr>
<tr><td>Formel F6</td><td>Entladewunsch (W)</td><td><span class="mv-mono">MIN(I1-50;2500)*I2*I3*I4</span></td><td>I1 &larr; F2, I2 &larr; U10, I3 &larr; S4, I4 &larr; U5</td></tr>
</table>
<b>6e) Sollwert bilden und senden</b>
<table class="mv-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S7</td><td>SOC &uuml;ber 97 %</td><td>Ein 97 / Aus 96 (Ladestopp voll)</td><td>&larr; SOC</td></tr>
<tr><td>Formel F7</td><td>Speicher-Sollleistung (W)</td><td><span class="mv-mono">I1*(1-I3)-I2</span></td><td>I1 &larr; F5, I2 &larr; F6, I3 &larr; S7</td></tr>
<tr><td>Impulsgeber T1</td><td>Sende-Takt 60 s</td><td>Impuls/Pause 60/60 s</td><td></td></tr>
<tr><td>Analogspeicher M1</td><td>Sample Sollleistung</td><td>speichert bei Trigger</td><td>Eingang &larr; F7, Trigger &larr; T1</td></tr>
<tr><td>Impulsgeber T2</td><td>Dither-Takt</td><td>60/60 s (erzwingt Neusendung)</td><td></td></tr>
<tr><td>Formel F8</td><td>Sollwert plus Dither</td><td><span class="mv-mono">I1+I2</span></td><td>I1 &larr; M1, I2 &larr; T2 &rarr; Ausgang an den Virtuellen Ausgang (Schritt 3)</td></tr>
</table>
<b>6f) St&ouml;rungs-&Uuml;berwachung</b>
<table class="mv-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>NICHT N4</td><td>Speicher nicht erreichbar</td><td></td><td>&larr; OK (Schritt 1)</td></tr>
<tr><td>Einschaltverz&ouml;gerung E1</td><td>15 Minuten St&ouml;rung</td><td>900 s</td><td>&larr; N4 &rarr; Benachrichtigungs-Baustein (Push)</td></tr>
</table>
<b>6g) Optional: Berichte aus den Ger&auml;tez&auml;hlern</b> (Schritt 5 n&ouml;tig) &mdash;
Monatsbilanz: Analogspeicher &bdquo;Stand Monatsanfang&ldquo; f&uuml;r CHGT und DIST (Trigger: Impuls am
Monatsersten), Formeln <span class="mv-mono">CHGT-Schnappschuss</span> bzw. <span class="mv-mono">DIST-Schnappschuss</span>,
Statusbaustein-Push mit geladen/entladen/Zyklen/Wirkungsgrad. Wochenbericht analog mit Trigger Montagmorgen.
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="mv-pane" id="tab-test">
<h2>Test</h2>
<div class="mv-legende">
<span><i class="mv-punkt mv-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="mv-punkt mv-b-technik"></i> Technische Auskunft &mdash; f&uuml;r die Fehlersuche</span>
<span><i class="mv-punkt mv-b-aktion"></i> L&ouml;st etwas aus &mdash; sendet oder ver&auml;ndert</span>
</div>

<h3 class="mv-h3">Technische Auskunft</h3>
<div class="mv-knopfreihe">
<a class="mv-btn mv-b-technik"  href="/plugins/<?= e($mv_plugindir) ?>/marstek.php?status&amp;debug=1<?= $q ?>" target="_blank">Status abrufen (Debug)</a>
<a class="mv-btn mv-b-technik"  href="/plugins/<?= e($mv_plugindir) ?>/marstek.php?energy&amp;debug=1<?= $q ?>" target="_blank">kWh-Z&auml;hler (Modbus, Debug)</a>
<a class="mv-btn mv-b-technik"  href="/plugins/<?= e($mv_plugindir) ?>/marstek.php?diag=1<?= $q ?>" target="_blank">Diagnose (Selbsttest)</a>
<a class="mv-btn mv-b-technik"  href="/plugins/<?= e($mv_plugindir) ?>/marstek.php?ranks&amp;debug=1" target="_blank">Spot-Ranking (Debug)</a>
</div>

<h3 class="mv-h3">L&ouml;st etwas aus</h3>
<div class="mv-knopfreihe">
<a class="mv-btn mv-b-aktion"  href="/plugins/<?= e($mv_plugindir) ?>/marstek.php?p=0&amp;t=60<?= $q ?>" target="_blank">Leerlauf senden (p=0)</a>
<a class="mv-btn mv-b-aktion"  href="/plugins/<?= e($mv_plugindir) ?>/marstek.php?p=-800&amp;t=120<?= $q ?>" target="_blank">Entladen pr&uuml;fen (p=-800)</a>
<a class="mv-btn mv-b-aktion"  href="/plugins/<?= e($mv_plugindir) ?>/marstek.php?p=800&amp;t=120<?= $q ?>" target="_blank">Laden pr&uuml;fen (p=+800)</a>
<a class="mv-btn mv-b-aktion"  href="/plugins/<?= e($mv_plugindir) ?>/marstek.php?mode=auto<?= $q ?>" target="_blank">Modus Auto (Handbetrieb)</a>
</div>

<?php $testdevs = $mv_devices ? $mv_devices : array(1 => array('name' => 'Ger&auml;t 1'));
foreach ($testdevs as $n => $d) { $q = $n > 1 ? '&amp;dev=' . $n : ''; ?>
<p><b><?= e($d['name']) ?></b><?= $n > 1 ? ' <span class="mv-small">(Aufrufe mit &amp;dev=' . $n . ')</span>' : '' ?><br>

<?php if (!empty($d['modbus'])) { ?><?php } ?>

</p>
<?php } ?>

<div class="mv-small">
&bull; <b>Status abrufen</b> zeigt die Rohantworten des Ger&auml;ts (ES.GetStatus / Bat.GetStatus / Marstek.GetDevice
mit Modell + Firmware) sowie Antwortzeit und die Loxone-Zeile.<br>
&bull; <b>Spot-Ranking</b> listet alle Stundenpreise der n&auml;chsten 24 h inkl. Rang der aktuellen Stunde.<br>
&bull; <b>Leerlauf senden</b> setzt den Passiv-Sollwert auf 0 W f&uuml;r 60 s &mdash; ungef&auml;hrlicher Verbindungstest.<br>
&bull; <b>Entladen pr&uuml;fen</b> / <b>Laden pr&uuml;fen</b> geben von Hand 800 W f&uuml;r 120 s vor. Damit l&auml;sst sich trennen,
ob der Speicher selbst nicht abgibt oder ob aus Loxone einfach kein negativer Sollwert kommt: Reagiert das Ger&auml;t hier,
liegt es an der Ansteuerung. Nach 120 s stoppt der Watchdog von selbst.<br>
&bull; <b>Modus Auto</b> gibt die Regie an den Speicher zur&uuml;ck (z. B. wenn Loxone l&auml;ngere Zeit ausf&auml;llt).
</div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="mv-pane" id="tab-log">
<h2>Logdatei</h2>
<div class="mv-small" style="margin-bottom:8px;">Protokolliert werden Status-/Sollwert-&Auml;nderungen (nur bei Strukturwechsel, kein Zahlenspam), Modus-Wechsel, Auto-Fallback und Fehler. Neueste Eintr&auml;ge oben (max. 300 angezeigt).<br>Datei: <span class="mv-mono"><?= e($mv_log_file) ?></span></div>
<?php if ($mv_log_lines) { ?>
<div class="mv-log"><?= e(implode("\n", $mv_log_lines)) ?></div>
<?php } else { ?>
<div class="mv-alert mv-info">Noch keine Log-Eintr&auml;ge vorhanden.</div>
<?php } ?>
<form method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="mv-btn" type="submit" style="background:#c62828;">Log leeren</button>
</form>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.mv-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('mv-active', t.dataset.pane === id); });
        document.querySelectorAll('.mv-pane').forEach(function (p) { p.classList.toggle('mv-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { activate(t.dataset.pane); }); });
    activate(<?= json_encode($mv_active_tab) ?>);
})();
</script>
<?php
if ($mv_use_frame) {
    LBWeb::lbfooter();
}
