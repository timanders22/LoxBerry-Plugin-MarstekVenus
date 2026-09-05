<?php
/**
 * Marstek Venus E - Selbstpruefung fuer den Reiter Test
 *
 * Je Zeile eine Frage, die sich OHNE Loxone beantworten laesst. Ein Hinweis
 * ist fuer "geht mich nichts an" da, nicht fuer "ich weiss es nicht":
 * Unklarheit ist ein Kreuz.
 *
 * Drei Bedingungen, alle aus schon vorhandenen Hausregeln:
 *
 *  - Der Endpunktaufruf wird zwischengespeichert (300 s), sonst ruft sich der
 *    Webserver bei jedem Klick selbst auf. Und er hat DREI Ausgaenge:
 *    geantwortet und plausibel, geantwortet und falsch, NICHT FESTSTELLBAR.
 *    "Ich kann es nicht messen" darf nicht wie "in Ordnung" aussehen.
 *  - Jede Zeile, die ueber eine Menge urteilt, prueft zuerst, ob die Menge
 *    leer ist - und ueber einen Takt, der gar nicht laeuft, wird kein
 *    Geraeteurteil gefaellt.
 *  - Die Zeilen, die die eigene Datei lesen, melden die ZAHL DER ANGESEHENEN
 *    STELLEN. Eine Null ist dann kein "in Ordnung", sondern ein Hinweis, dass
 *    nichts gemessen wurde.
 *
 * Zwei Dateien, ein Prozess: hier steht keine Funktion, die es in
 * marstek_lib.php oder index.php schon gibt.
 */

/** Eine Zeile der Selbstpruefung. $ok: true = Haken, false = Kreuz, null = Hinweis. */
function mv_pruefzeile($frage, $ok, $bemerkung = '')
{
    if ($ok === true) {
        $zeichen = '<span class="sm-ja">&#10004;</span>';
    } elseif ($ok === false) {
        $zeichen = '<span class="sm-nein">&#10008;</span>';
    } else {
        $zeichen = '<span class="sm-hinw">&#8226;</span>';
    }
    echo '<tr><td style="width:28px;text-align:center;">' . $zeichen . '</td><td style="width:38%;">'
       . htmlspecialchars((string) $frage, ENT_QUOTES, 'UTF-8') . '</td><td>'
       . htmlspecialchars((string) $bemerkung, ENT_QUOTES, 'UTF-8') . '</td></tr>';
}

/**
 * Den eigenen Endpunkt WIRKLICH aufrufen - ueber 127.0.0.1, nicht durch
 * Nachdenken. Das ist die einzige Zeile, die die getrennten Baeume findet:
 * html/ und htmlauth/ liegen installiert in verschiedenen Verzeichnissen, und
 * eine Lesepruefung sieht das nicht.
 *
 * Rueckgabe: array(zustand, text). zustand: 'ok' | 'falsch' | 'unbekannt'.
 */
function mv_endpunkt_probe($plugindir, $token)
{
    $cache = marstek_tmpdir() . '/selbsttest_endpunkt.json';
    if (is_file($cache) && time() - filemtime($cache) < 300) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c) && isset($c['zustand'])) {
            return array($c['zustand'], $c['text'] . ' (' . (time() - (int) filemtime($cache)) . ' s alt)');
        }
    }
    $url = 'http://127.0.0.1/plugins/' . rawurlencode($plugindir) . '/marstek.php?selftest=1&token=' . rawurlencode($token);
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 3, 'ignore_errors' => true, 'user_agent' => 'LoxBerry Marstek Selbsttest')));
    $antwort = @file_get_contents($url, false, $ctx);
    if ($antwort === false) {
        $erg = array('zustand' => 'unbekannt',
            'text' => 'Der Aufruf über 127.0.0.1 war nicht möglich. Das heißt NICHT, '
                    . 'dass der Endpunkt kaputt ist - der Webserver kann sich selbst abweisen.');
    } elseif (strpos($antwort, 'SELFTEST;OK=1') !== false) {
        $erg = array('zustand' => 'ok', 'text' => 'Antwort: ' . trim(substr($antwort, 0, 60)));
    } else {
        $erg = array('zustand' => 'falsch', 'text' => 'Der Endpunkt antwortet, aber nicht wie erwartet: '
                    . trim(substr((string) $antwort, 0, 120)));
    }
    marstek_write_json($cache, $erg);
    return array($erg['zustand'], $erg['text']);
}

/**
 * Die eigene Oberflaeche auszaehlen: Reiterleiste, Bereiche und Positivliste.
 * Alle drei stehen ausgeschrieben und koennen deshalb auseinanderlaufen -
 * genau dafuer gibt es diese Zeile.
 *
 * Rueckgabe: array(leiste, bereiche, positivliste, formulare, mit_token)
 */
function mv_oberflaeche_zaehlen()
{
    // BEIDE Dateien. Bis zur ersten Fassung dieser Zeile las sie nur
    // index.php und meldete "12 von 12 Formularen" - die fuenf Formulare
    // dieses Reiters waren gar nicht gezaehlt. Eine Pruefung, die einen Teil
    // der Menge nicht ansieht, ist kein Nachweis, sondern ein blinder Fleck.
    $s = '';
    foreach (array(__DIR__ . '/index.php', __FILE__) as $datei) {
        if (is_file($datei)) {
            $s .= (string) @file_get_contents($datei);
        }
    }
    if ($s === '') {
        return array(0, 0, 0, 0, 0);
    }
    // Setzt der Server das sm-active? Ohne serverseitiges sm-active ist die
    // Seite ohne JavaScript leer - und hausstandard_pruefen.py ist an genau
    // dieser Stelle blind, weil die Klasse zusammengesetzt ist. Wer eine
    // Pruefung blind macht, ERSETZT sie (VORLAGE_hausstandard.css.html).
    $GLOBALS['mv_smactive'] = array(
        preg_match_all('/class="sm-tab<\?=[^>]*sm-active/', $s),
        preg_match_all('/class="sm-seite<\?=[^>]*sm-active/', $s),
    );
    preg_match_all('/data-ziel="(tab-[a-z]+)"/', $s, $a);
    preg_match_all('/id="(tab-[a-z]+)"/', $s, $b);
    preg_match('/\$mv_reiter_liste = array\((.*?)\);/s', $s, $c);
    $liste = array();
    if (isset($c[1])) {
        preg_match_all("/'(tab-[a-z]+)'/", $c[1], $d);
        $liste = $d[1];
    }
    $formulare = preg_match_all('/<form\b/', $s);
    $mit_token = preg_match_all('/name="formtoken"/', $s);
    return array(count(array_unique($a[1])), count(array_unique($b[1])), count(array_unique($liste)),
                 $formulare, $mit_token);
}

/**
 * Die Themenliste gegen den Sendecode halten.
 *
 * marstek_mqtt_themen() ist die Anleitung im Reiter MQTT, veroeffentlicht
 * wird an anderer Stelle. Nichts mass die beiden gegeneinander - und genau so
 * sind in 1.0.14 fuenfzehn Themen entstanden, die in der Anleitung fehlten.
 *
 * Gemessen wird gegen die Feldtabelle, aus der beide Seiten entstehen: kommt
 * je Feld genau ein Thema heraus, tragen Anleitung und Sendecode dieselbe
 * Liste.
 */
function mv_themen_pruefen()
{
    /* BERICHTIGT 04.09.2026. Bis 1.1.4 rechnete diese Zeile die erwartete
     * Zahl aus DERSELBEN Feldtabelle, aus der marstek_mqtt_themen() entsteht -
     * eine Tautologie. Geeicht: dem Sendecode wurde ein Thema hinzugefuegt,
     * es ging am UDP-Tor gemessen wirklich hinaus, und die Zeile blieb
     * gruen. Der Text im Reiter MQTT sagte dem Anwender derweil, hier
     * wuerden beide Seiten gegeneinander gemessen.
     *
     * marstek_themen_abgleich() liest die vier veroeffentlichenden
     * Funktionen und gibt zurueck, was nur in der Liste und was nur im
     * Sendecode steht. */
    list($nur_liste, $nur_code) = marstek_themen_abgleich();
    return array(count(marstek_mqtt_themen(true)), $nur_liste, $nur_code);
}

/**
 * Verweist jede Zeile der Baustein-Liste nur auf kleinere Nummern?
 *
 * Gemessen an der GERENDERTEN Tabelle, nicht am Quelltext: die Nummern
 * stehen in Sprachwerten ("#25, #26"), und zwischen Quelltext und Anzeige
 * liegen die Sprachdateien.
 *
 * Rueckgabe: array(Zahl der Zeilen, Fehlerliste).
 */
function mv_bausteinliste_pruefen()
{
    $s = @file_get_contents(__DIR__ . '/index.php');
    if (!is_string($s) || $s === '') {
        return array(0, array('index.php nicht lesbar - diese Zeile misst nichts'));
    }
    if (!preg_match('/\$mv_bausteine = array\((.*?)\n\);/s', $s, $m)) {
        return array(0, array('die Baustein-Liste wurde nicht gefunden'));
    }
    preg_match_all("/array\('[^']*', '([^']*)', '[^']*', '([^']*)'\)/", $m[1], $z);
    $fehler = array();
    $n = count($z[1]);
    foreach ($z[2] as $i => $eingang) {
        if ($eingang === '') { continue; }
        $text = strpos($eingang, 'LOX.') === 0 ? marstek_t($eingang) : $eingang;
        if (preg_match_all('/#(\d+)/', $text, $t)) {
            foreach ($t[1] as $verweis) {
                if ((int) $verweis >= $i + 1) {
                    $fehler[] = sprintf('#%d verweist auf #%s', $i + 1, $verweis);
                } elseif ((int) $verweis < 1 || (int) $verweis > $n) {
                    $fehler[] = sprintf('#%d verweist auf #%s, das es nicht gibt', $i + 1, $verweis);
                }
            }
        }
        // Ein UND oder ODER hat zwei Eingaenge - nicht drei.
        $typ = isset($z[1][$i]) ? $z[1][$i] : '';
        if (preg_match('/B_/', $typ) && substr_count($text, '#') > 2
                && strpos($s, "'LOX.T_UND', '" . $typ . "'") !== false) {
            $fehler[] = sprintf('#%d: ein UND mit mehr als zwei Eingaengen', $i + 1);
        }
    }
    return array($n, $fehler);
}

/** Jede erzeugbare Vorlage durch den Parser schicken. Rueckgabe: array(gut, gesamt, fehler). */
function mv_vorlagen_pruefen()
{
    $gut = 0; $gesamt = 0; $fehler = array();
    $alt = libxml_use_internal_errors(true);
    foreach (marstek_vorlagen_alle() as $name => $inhalt) {
        $gesamt++;
        if (simplexml_load_string($inhalt) !== false) {
            $gut++;
        } else {
            $fehler[] = $name;
        }
        libxml_clear_errors();
    }
    libxml_use_internal_errors($alt);
    return array($gut, $gesamt, $fehler);
}

/**
 * Der Reiter Test.
 *
 * $aktiv sagt, ob der Reiter gerade offen ist. Die teuren Zeilen - der
 * Endpunktaufruf - laufen NUR dann: index.php rendert alle fuenf Reiter in
 * das HTML, und eine Selbstpruefung, die etwas kostet, liefe sonst bei jedem
 * Seitenaufbau mit.
 */
function mv_test_seite($ft, $plugindir, array $cfg, array $devices, $suchergebnis, $suchmeldung, $aktiv = true)
{
    // Der Maskierhelfer liegt in der Bibliothek. Bis 1.1.4 gab es ihn
    // dreimal: hier als Closure, in index.php als globale Funktion e() und
    // in marstek_lib.php als marstek_e().
    ?>
<h2><?= marstek_e(marstek_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<div class="sm-hinweis"><?= marstek_t('TEST.SELBSTPRUEFUNG_ERKLAERUNG') ?></div>
<?php if (!$aktiv) { ?>
<div class="sm-alert sm-info"><?= marstek_e(marstek_t('TEST.REITER_OEFFNEN')) ?></div>
<?php } else {
    $befund = marstek_befund();
    $zustand = marstek_config_zustand();
    $h = marstek_herzstand();
    ?>
<table class="sm-tbl">
<?php
    // --- Gesamtbefund (dieselbe Funktion, die Healthcheck und Meldung benutzen)
    mv_pruefzeile(marstek_t('TEST.Z_BEFUND'),
        (int) $befund['schwere'] >= 5 ? true : ((int) $befund['schwere'] === 4 ? null : false),
        $befund['text']);

    // --- Konfiguration
    $ztexte = array(
        'ok' => marstek_t('TEST.KONFIG_OK'),
        'leer' => marstek_t('TEST.KONFIG_LEER'),
        'fehlt' => marstek_t('TEST.KONFIG_FEHLT'),
        'zweitschrift' => marstek_t('TEST.KONFIG_ZWEITSCHRIFT'),
        'kaputt' => marstek_t('TEST.KONFIG_KAPUTT'),
    );
    mv_pruefzeile(marstek_t('TEST.Z_KONFIG'), $zustand === 'ok',
        isset($ztexte[$zustand]) ? $ztexte[$zustand] : $zustand);

    $vorgaben = marstek_vorgaben();
    $mv_cdat = marstek_paths()['config'];
    $roh = is_file($mv_cdat) ? json_decode((string) @file_get_contents($mv_cdat), true) : null;
    $vorhanden = 0;
    if (is_array($roh)) {
        foreach (array_keys($vorgaben) as $k) {
            if (array_key_exists($k, $roh)) { $vorhanden++; }
        }
    }
    mv_pruefzeile(marstek_t('TEST.Z_KONFIG_VOLL'), $vorhanden === count($vorgaben),
        sprintf(marstek_t('TEST.KONFIG_VOLL_TEXT'), $vorhanden, count($vorgaben)));

    // --- Minutentakt
    if ($h['ts'] <= 0) {
        mv_pruefzeile(marstek_t('TEST.Z_TAKT'), false, marstek_t('TEST.TAKT_NIE'));
    } else {
        $alter = time() - $h['ts'];
        mv_pruefzeile(marstek_t('TEST.Z_TAKT'), $alter <= 180,
            sprintf(marstek_t('TEST.TAKT_TEXT'), date('d.m.Y H:i:s', $h['ts']), $alter, $h['zaehler']));
    }

    // --- Geraete. Ueber eine leere Menge wird nicht geurteilt.
    if (!$devices) {
        mv_pruefzeile(marstek_t('TEST.Z_GERAETE'), false, marstek_t('TEST.KEIN_GERAET'));
    } else {
        mv_pruefzeile(marstek_t('TEST.Z_GERAETE'), true, sprintf(marstek_t('TEST.GERAETE_ANZAHL'), count($devices)));
        if ($h['ts'] <= 0) {
            mv_pruefzeile(marstek_t('TEST.Z_ANTWORTEN'), null, marstek_t('TEST.ANTWORTEN_OHNE_TAKT'));
        } else {
            foreach ($devices as $n => $d) {
                $c = marstek_tmpdir() . '/status_dev' . $n . '.json';
                $st = is_file($c) ? json_decode((string) @file_get_contents($c), true) : null;
                if (!is_array($st)) {
                    mv_pruefzeile($d['name'], null, marstek_t('TEST.NOCH_NICHT_ABGEFRAGT'));
                } else {
                    $mess = (int) (isset($st['mess']) ? $st['mess'] : 0);
                    mv_pruefzeile($d['name'], !empty($st['ok']),
                        (!empty($st['ok']) ? sprintf(marstek_t('TEST.GERAET_OK'), $st['soc'], (int) $st['ms'])
                                           : marstek_t('TEST.GERAET_STUMM'))
                        . ($mess > 0 ? ' - ' . sprintf(marstek_t('TEST.LETZTE_MESSUNG'), date('d.m. H:i:s', $mess)) : ''));
                }
            }
        }
        // Kapazitaet - nur ein Hinweis, kein Kreuz: sie ist fuer den
        // Regelbetrieb nicht noetig, nur fuer die Summe.
        if (count($devices) > 1) {
            $ohne = array();
            foreach ($devices as $d) {
                if ($d['kwh'] <= 0) { $ohne[] = $d['name']; }
            }
            mv_pruefzeile(marstek_t('TEST.Z_KAPAZITAET'), $ohne ? null : true,
                $ohne ? sprintf(marstek_t('TEST.KAPAZITAET_FEHLT'), implode(', ', $ohne))
                      : marstek_t('TEST.KAPAZITAET_OK'));
        }
    }

    // --- Ausfaelle. Die Zahl faellt aus dem Zaehler ab, der ohnehin die
    //     Grundlage der Meldung ist - sie kostet keine weitere Messung.
    if ($devices && $h['ts'] > 0) {
        $ausfaelle = 0;
        $letzter = 0;
        foreach (array_keys($devices) as $n) {
            $a = marstek_ausfall_stand($n);
            $ausfaelle += $a['heute'];
            $letzter = max($letzter, $a['letzter']);
        }
        mv_pruefzeile(marstek_t('TEST.Z_AUSFAELLE'), $ausfaelle === 0 ? true : null,
            $ausfaelle === 0 ? marstek_t('TEST.AUSFAELLE_KEINE')
                             : sprintf(marstek_t('TEST.AUSFAELLE_TEXT'), $ausfaelle,
                                       $letzter > 0 ? date('H:i:s', $letzter) : '-'));
    }

    // --- Token und Endpunkt
    mv_pruefzeile(marstek_t('TEST.Z_TOKEN'), !empty($cfg['aktionstoken']),
        !empty($cfg['aktionstoken']) ? marstek_t('TEST.TOKEN_OK') : marstek_t('TEST.TOKEN_FEHLT'));
    if (!empty($cfg['aktionstoken'])) {
        list($ez, $et) = mv_endpunkt_probe($plugindir, (string) $cfg['aktionstoken']);
        mv_pruefzeile(marstek_t('TEST.Z_ENDPUNKT'),
            $ez === 'ok' ? true : ($ez === 'unbekannt' ? null : false), $et);
    }

    // --- Rundruf
    mv_pruefzeile(marstek_t('TEST.Z_SOCKETS'), marstek_broadcast_moeglich() ? true : null,
        marstek_broadcast_moeglich() ? marstek_t('TEST.SOCKETS_DA') : marstek_t('TEST.SOCKETS_FEHLT'));

    // --- MQTT
    $gw = marstek_mqtt_gateway_info();
    $port = marstek_mqtt_udpport();
    if (empty($cfg['mqtt_enabled'])) {
        mv_pruefzeile(marstek_t('TEST.Z_MQTT'), null, marstek_t('TEST.MQTT_AUS'));
    } else {
        mv_pruefzeile(marstek_t('TEST.Z_MQTT'), $port > 0,
            $port > 0 ? sprintf(marstek_t('TEST.MQTT_PORT'), $port) : marstek_t('TEST.MQTT_KEIN_PORT'));
        mv_pruefzeile(marstek_t('TEST.Z_GATEWAY'),
            $gw === null ? null : ($gw['autostart'] ? true : false),
            $gw === null ? marstek_t('TEST.GATEWAY_UNBEKANNT')
                         : ($gw['autostart'] ? sprintf(marstek_t('TEST.GATEWAY_OK'), $gw['fassung'] > 0 ? $gw['fassung'] : '?')
                                             : marstek_t('TEST.GATEWAY_KEIN_AUTOSTART')));
    }
    list($n_themen, $n_nur_liste, $n_nur_code) = mv_themen_pruefen();
    mv_pruefzeile(marstek_t('TEST.Z_THEMEN'),
        $n_themen > 0 && !$n_nur_liste && !$n_nur_code,
        ($n_nur_liste || $n_nur_code)
            ? sprintf(marstek_t('TEST.THEMEN_ABWEICHUNG'),
                implode(', ', array_slice($n_nur_liste, 0, 6)) ?: '-',
                implode(', ', array_slice($n_nur_code, 0, 6)) ?: '-')
            : sprintf(marstek_t('TEST.THEMEN_TEXT'), $n_themen, $n_themen));

    // --- Vorlagen
    list($v_gut, $v_ges, $v_fehler) = mv_vorlagen_pruefen();
    mv_pruefzeile(marstek_t('TEST.Z_VORLAGEN'), $v_ges > 0 && $v_gut === $v_ges,
        $v_ges === 0 ? marstek_t('TEST.VORLAGEN_KEINE')
                     : sprintf(marstek_t('TEST.VORLAGEN_TEXT'), $v_gut, $v_ges)
                       . ($v_fehler ? ' - ' . implode(', ', $v_fehler) : ''));

    // --- Die eigene Oberflaeche
    list($leiste, $bereiche, $liste, $formulare, $mit_token) = mv_oberflaeche_zaehlen();
    mv_pruefzeile(marstek_t('TEST.Z_REITER'),
        $leiste > 0 && $leiste === $bereiche && $leiste === $liste,
        sprintf(marstek_t('TEST.REITER_TEXT'), $leiste, $bereiche, $liste));
    mv_pruefzeile(marstek_t('TEST.Z_FORMULARE'),
        $formulare > 0 && $formulare === $mit_token,
        sprintf(marstek_t('TEST.FORMULARE_TEXT'), $mit_token, $formulare));

    // Setzt der Server das sm-active - an der Leiste UND an den Bereichen?
    list($sa_leiste, $sa_bereiche) = isset($GLOBALS['mv_smactive'])
        ? $GLOBALS['mv_smactive'] : array(0, 0);
    mv_pruefzeile(marstek_t('TEST.Z_SMACTIVE'),
        $sa_leiste > 0 && $sa_leiste === $leiste && $sa_bereiche === $bereiche,
        sprintf(marstek_t('TEST.SMACTIVE_TEXT'), $sa_leiste, $leiste, $sa_bereiche, $bereiche));

    // Ist der eigene Cron-Eintrag da - und ist er eine DATEI? LoxBerry
    // fuehrt in diesen Ordnern nur Dateien aus; ein gleichnamiges
    // Verzeichnis aus einer aelteren Fassung blockiert dieselbe Stelle.
    $mv_home = marstek_paths()['lbhome'];
    if ($mv_home === '') {
        mv_pruefzeile(marstek_t('TEST.Z_CRON'), null, marstek_t('TEST.CRON_UNBEKANNT'));
    } else {
        $mv_cron = $mv_home . '/system/cron/cron.01min/' . $plugindir;
        $mv_andere = array();
        foreach (array('cron.03min', 'cron.05min', 'cron.10min', 'cron.15min',
                       'cron.30min', 'cron.hourly') as $mv_o) {
            if (file_exists($mv_home . '/system/cron/' . $mv_o . '/' . $plugindir)) {
                $mv_andere[] = $mv_o;
            }
        }
        mv_pruefzeile(marstek_t('TEST.Z_CRON'), is_file($mv_cron),
            (is_file($mv_cron) ? marstek_t('TEST.CRON_DA')
                : (is_dir($mv_cron) ? marstek_t('TEST.CRON_VERZEICHNIS')
                                    : marstek_t('TEST.CRON_FEHLT')))
            . ($mv_andere ? ' ' . sprintf(marstek_t('TEST.CRON_RESTE'),
                                          implode(', ', $mv_andere)) : ''));
    }

    // Verweist jede Zeile der Baustein-Liste nur auf KLEINERE Nummern?
    // Die Kaskade in 1.1.5 hat alles ab #27 verschoben; ohne diese Zeile
    // faellt ein vergessener Verweis erst dem Anwender auf.
    list($bl_zeilen, $bl_fehler) = mv_bausteinliste_pruefen();
    mv_pruefzeile(marstek_t('TEST.Z_BAUSTEINLISTE'),
        $bl_zeilen > 0 && !$bl_fehler,
        $bl_fehler ? implode('; ', array_slice($bl_fehler, 0, 4))
                   : sprintf(marstek_t('TEST.BAUSTEINLISTE_TEXT'), $bl_zeilen));

    // --- Mitschnitt. Er soll nicht aus Versehen stehenbleiben.
    $mbis = marstek_mitschnitt_bis();
    mv_pruefzeile(marstek_t('TEST.Z_MITSCHNITT'), $mbis ? null : true,
        $mbis ? sprintf(marstek_t('TEST.MITSCHNITT_LAEUFT'), date('H:i:s', $mbis))
              : marstek_t('TEST.MITSCHNITT_AUS'));

    // --- Spotpreise
    $r = marstek_ranks_ermitteln(false);
    mv_pruefzeile(marstek_t('TEST.Z_SPOT'), !empty($r['ok']),
        !empty($r['ok']) ? sprintf(marstek_t('TEST.SPOT_OK'), $r['n'], $r['rank'], $r['curp'])
                         : marstek_ranks_grund($r['errc']));
    ?>
</table>
<?php } ?>

<h2><?= marstek_e(marstek_t('TEST.H_KNOEPFE')) ?></h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= marstek_e(marstek_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= marstek_e(marstek_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= marstek_e(marstek_t('LEGENDE.AKTION')) ?></span>
</div>

<h3 class="sm-h3"><?= marstek_e(marstek_t('TEST.H_SUCHE')) ?></h3>
<div class="sm-hilfe"><?= marstek_e(marstek_t('TEST.SUCHE_ERKLAERUNG')) ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= marstek_e($ft) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="suchen" value="1"><?= marstek_e(marstek_t('TEST.K_SUCHEN')) ?></button>
  </form>
</div>
<?php if ($suchmeldung !== '') { ?><div class="sm-alert sm-warn"><?= marstek_e($suchmeldung) ?></div><?php } ?>
<?php if (is_array($suchergebnis)) {
    if (!$suchergebnis) { ?>
<div class="sm-alert sm-info"><?= marstek_e(marstek_t('TEST.SUCHE_LEER')) ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?= marstek_e(marstek_t('TEST.SP_IP')) ?></th><th><?= marstek_e(marstek_t('TEST.SP_MODELL')) ?></th><th><?= marstek_e(marstek_t('TEST.SP_FW')) ?></th><th style="width:200px;"></th></tr>
<?php foreach ($suchergebnis as $g) { ?>
<tr><td><span class="sm-mono"><?= marstek_e($g['ip']) ?></span></td><td><?= marstek_e($g['model']) ?></td><td><?= (int) $g['fw'] ?></td>
<td><form action="index.php" method="post" style="margin:0;">
<input data-role="none" type="hidden" name="formtoken" value="<?= marstek_e($ft) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="uebernehmen" value="<?= marstek_e($g['ip']) ?>" style="min-width:180px;"><?= marstek_e(marstek_t('TEST.K_UEBERNEHMEN')) ?></button>
</form></td></tr>
<?php } ?>
</table>
<?php } } ?>

<h3 class="sm-h3"><?= marstek_e(marstek_t('TEST.H_TECHNIK')) ?></h3>
<?php $mv_tok = rawurlencode((string) $cfg['aktionstoken']);
$mv_testdevs = $devices ? $devices : array(1 => array('name' => 'Venus E', 'modbus' => 1));
foreach ($mv_testdevs as $n => $d) {
    $q = $n > 1 ? '&amp;dev=' . (int) $n : ''; ?>
<div class="sm-small" style="margin-top:10px;"><b><?= marstek_e($d['name']) ?></b><?= $n > 1 ? ' <span class="sm-mono">&amp;dev=' . (int) $n . '</span>' : '' ?></div>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik" href="/plugins/<?= marstek_e($plugindir) ?>/marstek.php?status&amp;debug=1<?= $q ?>&amp;token=<?= $mv_tok ?>" target="_blank"><?= marstek_e(marstek_t('TEST.K_STATUS')) ?></a>
<?php if (!empty($d['modbus'])) { ?>
<a class="sm-btn sm-b-technik" href="/plugins/<?= marstek_e($plugindir) ?>/marstek.php?energy&amp;debug=1<?= $q ?>&amp;token=<?= $mv_tok ?>" target="_blank"><?= marstek_e(marstek_t('TEST.K_ENERGY')) ?></a>
<?php } ?>
<a class="sm-btn sm-b-technik" href="/plugins/<?= marstek_e($plugindir) ?>/marstek.php?diag=1<?= $q ?>&amp;token=<?= $mv_tok ?>" target="_blank"><?= marstek_e(marstek_t('TEST.K_DIAG')) ?></a>
</div>
<?php } ?>
<div class="sm-knopfreihe" style="margin-top:10px;">
<a class="sm-btn sm-b-technik" href="/plugins/<?= marstek_e($plugindir) ?>/marstek.php?ranks&amp;debug=1&amp;token=<?= $mv_tok ?>" target="_blank"><?= marstek_e(marstek_t('TEST.K_RANKS')) ?></a>
<?php if (count($devices) > 1) { ?>
<a class="sm-btn sm-b-technik" href="/plugins/<?= marstek_e($plugindir) ?>/marstek.php?summe&amp;debug=1&amp;token=<?= $mv_tok ?>" target="_blank"><?= marstek_e(marstek_t('TEST.K_SUMME')) ?></a>
<?php } ?>
</div>

<h3 class="sm-h3"><?= marstek_e(marstek_t('TEST.H_TROCKEN')) ?></h3>
<div class="sm-hilfe"><?= marstek_e(marstek_t('TEST.TROCKEN_ERKLAERUNG')) ?></div>
<div class="sm-knopfreihe">
<?php foreach ($mv_testdevs as $n => $d) { $q = $n > 1 ? '&amp;dev=' . (int) $n : ''; ?>
<a class="sm-btn sm-b-technik" href="/plugins/<?= marstek_e($plugindir) ?>/marstek.php?p=800&amp;t=120&amp;dry=1<?= $q ?>&amp;token=<?= $mv_tok ?>" target="_blank"><?= marstek_e(sprintf(marstek_t('TEST.K_TROCKEN_LADEN'), $d['name'])) ?></a>
<?php } ?>
</div>

<h3 class="sm-h3"><?= marstek_e(marstek_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= marstek_e(marstek_t('TEST.SCHALTEN_WARNUNG')) ?></div>
<?php foreach ($mv_testdevs as $n => $d) { $q = $n > 1 ? '&amp;dev=' . (int) $n : ''; ?>
<div class="sm-small" style="margin-top:10px;"><b><?= marstek_e($d['name']) ?></b></div>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion" href="/plugins/<?= marstek_e($plugindir) ?>/marstek.php?p=0&amp;t=60<?= $q ?>&amp;token=<?= $mv_tok ?>" target="_blank"><?= marstek_e(marstek_t('TEST.K_LEERLAUF')) ?></a>
<a class="sm-btn sm-b-aktion" href="/plugins/<?= marstek_e($plugindir) ?>/marstek.php?p=-800&amp;t=120<?= $q ?>&amp;token=<?= $mv_tok ?>" target="_blank"><?= marstek_e(marstek_t('TEST.K_ENTLADEN')) ?></a>
<a class="sm-btn sm-b-aktion" href="/plugins/<?= marstek_e($plugindir) ?>/marstek.php?p=800&amp;t=120<?= $q ?>&amp;token=<?= $mv_tok ?>" target="_blank"><?= marstek_e(marstek_t('TEST.K_LADEN')) ?></a>
<a class="sm-btn sm-b-aktion" href="/plugins/<?= marstek_e($plugindir) ?>/marstek.php?mode=auto<?= $q ?>&amp;token=<?= $mv_tok ?>" target="_blank"><?= marstek_e(marstek_t('TEST.K_AUTO')) ?></a>
</div>
<?php } ?>

<h3 class="sm-h3"><?= marstek_e(marstek_t('TEST.H_MITSCHNITT')) ?></h3>
<div class="sm-hilfe"><?= marstek_e(marstek_t('TEST.MITSCHNITT_ERKLAERUNG')) ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= marstek_e($ft) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="600"><?= marstek_e(marstek_t('TEST.K_MITSCHNITT_AN')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="formtoken" value="<?= marstek_e($ft) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="mitschnitt" value="0"><?= marstek_e(marstek_t('TEST.K_MITSCHNITT_AUS')) ?></button>
  </form>
</div>
<?php $mv_mit = marstek_mitschnitt_lesen(200);
if ($mv_mit) { ?>
<div class="sm-log" style="max-height:300px;"><?= marstek_e(implode("
", $mv_mit)) ?></div>
<?php } ?>

<div class="sm-hilfe" style="margin-top:14px;"><?= marstek_t('TEST.KNOEPFE_ERKLAERUNG') ?></div>
<?php
}
