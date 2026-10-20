<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Marstek Venus E (Gruppe 1, 1.1.25;
 * Sollwert frei seit 1.1.26).
 *
 * Die Befehlsbruecke (bin/befehlsbruecke.php, byte-gleich aus der Stammfassung Werkzeuge/gemeinsam/) abonniert
 * <praefix>/befehl/# und gibt jeden gueltigen Befehl an tun() dieser Tabelle. tun() ruft DIESELBE Funktion wie der
 * HTTP-Endpunkt marstek.php - marstek_befehl_ausfuehren(), mit Quelle 'mqtt' und Kennung von=mqtt fuer die
 * Schreiber-Wache (Entwurf mv E3, E4) - und gibt array(ok, grund) fuer die Quittung zurueck
 * (marstek_befehl_quittung()). Ein Token gibt es auf diesem Weg nicht: das Plugin ist selbst der Empfaenger.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * Feste Namen vor Mustern; kein Name endet auf "quittung"; zwei Eintraege treffen nie dasselbe Thema
 * (befehlsbruecke.php --pruefen).
 *
 * Die Befehle (Entwurf mv 3.2, E5):
 *   sollwert     ganze Zahl in W (-10000 bis 10000; + laedt, - entlaedt), t = 240 s. FREI SEIT 1.1.26 nach der
 *                V2-Messung (Hausherr 20.10.2026: Nachtmessung 19./20.10. mit Gateway V2, 0 Verluste an UDP 11884,
 *                209/209 Werte). In 1.1.25 war er noch gesperrt. Rueckweg: $mv_sollwert_frei unten auf false
 *                setzen. Sperrminute 50 s statt 60 (Begruendung am Eintrag).
 *   modus_auto   Puls (nur 1): Regie an den Auto-Modus des Speichers zurueck.
 *   modus_ai     Puls (nur 1): Regie an den AI-Modus des Speichers.
 *   Geraet 2 bis 9: dieselben Befehle unter <praefix>/befehl/<nr>/... (die Bruecke abonniert genau ein Praefix;
 *   die Themen der Werte tragen die Nummer dagegen im Praefix, <praefix>/<nr>/...).
 * Alle drei greifen ein: zweiter Haken ist der vorhandene Hauptschalter "Steuerung aktiv" (steuerung_ein, ab Werk
 * EIN - der eigentliche Riegel ist der Haken "Befehle ueber MQTT annehmen", ab Werk aus; Entwurf mv E19).
 * "angenommen" heisst: die Funktion des Plugins meldet OK=1 (auch UNVERAENDERT=1 von der Befehlsbremse).
 */
if (!function_exists('marstek_config')) {
    $mv_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $mv_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/marstek_lib.php';
    }
    $mv_kand[] = dirname(__DIR__) . '/webfrontend/html/marstek_lib.php';
    foreach ($mv_kand as $mv_k) {
        if (is_file($mv_k)) {
            require_once $mv_k;
            break;
        }
    }
}
// Schalter in der Tabelle (Bauvorschrift Gruppe 1, Nachtrag 19.10.2026): frei seit 1.1.26 nach der V2-Messung
// (MV26_BAUVORSCHRIFT.md, Hausherr 20.10.2026). false sperrt den Sollwert ueber MQTT wieder (Rueckweg).
$mv_sollwert_frei = true;
$mv_sperre_grund = 'Loxone wechselt den Sollwert im 30-s-Takt zwischen S und S+1 (Dither); derselbe Wert kommt alle 60 s '
    . 'und muss durch, denn der Watchdog des Speichers ist 240 s. Die Befehlsbremse des Plugins (58 s, an t gebunden) '
    . 'bleibt die eine Pruefung.';
return array(
    'fassung' => 1,
    'linie'   => 'marstekvenus',
    'plugin'  => 'Marstek',
    'geladen' => function_exists('marstek_befehl_ausfuehren') && function_exists('marstek_befehl_quittung'),
    'zweiter_haken' => 'steuerung_ein',
    'einstellungen' => function () {
        // marstek_config(false): wie der unangemeldete Endpunkt legt die Bruecke nichts an und heilt nichts.
        $c = marstek_config(false);
        return array('praefix'     => marstek_mqtt_thema_saeubern($c['mqtt_topic']),
                     'annehmen'    => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_enabled']),
                     'eingreifend' => !empty($c['steuerung_ein']),
                     'mqtt_ein'    => !empty($c['mqtt_enabled']));
    },
    'protokoll' => function ($stufe, $text) {
        marstek_log(($stufe === 'INFO' ? '' : $stufe . ' ') . $text);
    },
    'teile' => array('nr' => '[2-9]'),
    'befehle' => array(
        'sollwert' => array('art' => 'ganz', 'min' => -10000, 'max' => 10000, 'eingreifend' => true,
            'sperre_s' => 50, 'begruendung' => $mv_sperre_grund,
            'titel' => 'Marstek Sollwert senden (MQTT)', 'kachel' => 'BEFEHL.K_SOLLWERT', 'quittung' => 'ausgefuehrt',
            'gesperrt' => !$mv_sollwert_frei,
            'tun' => function ($w, $t) use ($mv_sollwert_frei) {
                if (!$mv_sollwert_frei) {
                    return array(false, 'gesperrt');
                }
                $GLOBALS['marstek_nur_lesen'] = true;
                return marstek_befehl_quittung(marstek_befehl_ausfuehren('p', array('p' => (string) $w, 't' => '240',
                    'dev' => 1, 'alle' => false, 'trocken' => false, 'von_da' => true, 'von' => 'mqtt'), 'mqtt'));
            }),
        'modus_auto' => array('art' => 'puls', 'eingreifend' => true,
            'titel' => 'Marstek Handbetrieb: Modus Auto (MQTT)', 'kachel' => 'BEFEHL.K_AUTO', 'quittung' => 'ausgefuehrt',
            'tun' => function ($w, $t) {
                $GLOBALS['marstek_nur_lesen'] = true;
                return marstek_befehl_quittung(marstek_befehl_ausfuehren('mode', array('mode' => 'auto', 'dev' => 1,
                    'alle' => false, 'trocken' => false, 'von_da' => true, 'von' => 'mqtt'), 'mqtt'));
            }),
        'modus_ai' => array('art' => 'puls', 'eingreifend' => true,
            'titel' => 'Marstek Handbetrieb: Modus AI (MQTT)', 'kachel' => 'BEFEHL.K_AI', 'quittung' => 'ausgefuehrt',
            'tun' => function ($w, $t) {
                $GLOBALS['marstek_nur_lesen'] = true;
                return marstek_befehl_quittung(marstek_befehl_ausfuehren('mode', array('mode' => 'ai', 'dev' => 1,
                    'alle' => false, 'trocken' => false, 'von_da' => true, 'von' => 'mqtt'), 'mqtt'));
            }),
        '{nr}/sollwert' => array('art' => 'ganz', 'min' => -10000, 'max' => 10000, 'eingreifend' => true,
            'sperre_s' => 50, 'begruendung' => $mv_sperre_grund,
            'titel' => 'Marstek Sollwert senden Geraet N (MQTT)', 'kachel' => 'BEFEHL.K_SOLLWERT', 'quittung' => 'ausgefuehrt',
            'gesperrt' => !$mv_sollwert_frei,
            'tun' => function ($w, $t) use ($mv_sollwert_frei) {
                if (!$mv_sollwert_frei) {
                    return array(false, 'gesperrt');
                }
                if (marstek_dev((int) $t['nr']) === null) {
                    return array(false, 'DEV_NICHT_EINGETRAGEN');
                }
                $GLOBALS['marstek_nur_lesen'] = true;
                return marstek_befehl_quittung(marstek_befehl_ausfuehren('p', array('p' => (string) $w, 't' => '240',
                    'dev' => (int) $t['nr'], 'alle' => false, 'trocken' => false, 'von_da' => true, 'von' => 'mqtt'), 'mqtt'));
            }),
        '{nr}/modus_auto' => array('art' => 'puls', 'eingreifend' => true,
            'titel' => 'Marstek Handbetrieb: Modus Auto Geraet N (MQTT)', 'kachel' => 'BEFEHL.K_AUTO', 'quittung' => 'ausgefuehrt',
            'tun' => function ($w, $t) {
                if (marstek_dev((int) $t['nr']) === null) {
                    return array(false, 'DEV_NICHT_EINGETRAGEN');
                }
                $GLOBALS['marstek_nur_lesen'] = true;
                return marstek_befehl_quittung(marstek_befehl_ausfuehren('mode', array('mode' => 'auto',
                    'dev' => (int) $t['nr'], 'alle' => false, 'trocken' => false, 'von_da' => true, 'von' => 'mqtt'), 'mqtt'));
            }),
        '{nr}/modus_ai' => array('art' => 'puls', 'eingreifend' => true,
            'titel' => 'Marstek Handbetrieb: Modus AI Geraet N (MQTT)', 'kachel' => 'BEFEHL.K_AI', 'quittung' => 'ausgefuehrt',
            'tun' => function ($w, $t) {
                if (marstek_dev((int) $t['nr']) === null) {
                    return array(false, 'DEV_NICHT_EINGETRAGEN');
                }
                $GLOBALS['marstek_nur_lesen'] = true;
                return marstek_befehl_quittung(marstek_befehl_ausfuehren('mode', array('mode' => 'ai',
                    'dev' => (int) $t['nr'], 'alle' => false, 'trocken' => false, 'von_da' => true, 'von' => 'mqtt'), 'mqtt'));
            }),
    ),
);
