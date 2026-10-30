<?php
/**
 * Anbieterregel fuer die Haus-Themen haus/strompreis und haus/speicher (Regeln/07 Nr. 15)
 *
 * Stammfassung: Pruefung-Durchgang-2026-09-29/vb_g43_tb_bau_skripte/stuecke/haus_anbieter.php (Gruppe 43,
 * 29.10.2026; Vorschlag: nach Werkzeuge/gemeinsam/haus_anbieter.php und in gemeinsam_pruefen.py DATEIEN). In jeder
 * Anbieter-Linie BYTE-GLEICH unter webfrontend/html/haus_anbieter.php bzw. webfrontend/htmlauth/haus_anbieter.php
 * (neben der Bibliothek der Linie). Wer hier etwas aendert, aendert es in der Stammfassung, hebt HANB_FASSUNG an und
 * verteilt in alle Linien (Spotpreis Tibber, Zendure SolarFlow; danach aWATTar, Octopus, Marstek Venus E).
 *
 * NUR REINE FUNKTIONEN: kein Netz, kein Broker, keine Datei, keine Uhr (die Zeit kommt als Argument), keine
 * Sprachdatei. Die Linie liest die Haus-Themen selbst (abgekoppelter Leser oder Dauerverbindung), legt das Ergebnis
 * mit hanb_merken() ab, fragt hanb_lage() und sendet nur, wenn die Rolle "anbieter" ist.
 *
 * Die Regel (Entscheidung Gruppe 43 E4, Vorschlag 1 - "Der Anwender waehlt, sonst bleibt, wer schon sendet"):
 *   1. Ein Anbieter sendet in jedem Lauf sein <stamm>/anbieter (eigener Name, retained) und liest es zurueck.
 *   2. AUTOMATISCH beginnt eine Installation nur, wenn niemand Anspruch erhebt (<stamm>/anbieter leer oder nie
 *      gesetzt) UND kein Zaehler lebt (kein live gesehener Wechsel von <stamm>/zaehler binnen 120 s) UND ein Leser
 *      das binnen der letzten 5 Minuten mit vollem Fenster geprueft hat.
 *   3. Waehlt der Anwender den Knopf "Diese Installation soll Anbieter sein", schreibt sie ihren Namen (hanb_waehlen()).
 *      Der bisherige Anbieter sieht im naechsten Lauf einen FREMDEN Namen, hoert SELBST auf ("abgeloest") und raeumt
 *      NICHTS ab - die Themen gehoeren jetzt dem neuen.
 *   4. Schweigt der gewaehlte Anbieter (Zaehler > 120 s still), uebernimmt NIEMAND automatisch: der Wartende meldet
 *      "fremd_schweigt" (Knopf druecken zum Uebernehmen). Keine Mischung verschiedener Tarife oder Speicher.
 *   5. Lebt ein Zaehler, aber es gibt kein <stamm>/anbieter, ist ein Bestandsanbieter ohne diese Regel da
 *      (Marstek Venus E bis 1.1.28 fuer haus/speicher) - "bestand": nicht senden, ausser der Anwender waehlt.
 *   6. Abgeraeumt (leere Nutzlast mit Retain) wird nur, wenn diese Installation Anbieter IST oder WAR und nicht
 *      abgeloest wurde (hanb_darf_abraeumen()).
 *
 * Laeuft auf: PHP 7.4 bis 8.5. Schuetzt sich gegen doppeltes Laden; ein direkter Aufruf ueber den Webserver
 * antwortet mit 403.
 */

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
        && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}
if (defined('HANB_FASSUNG')) {
    return;
}
define('HANB_FASSUNG', '1.0.0');

/** Frist fuer einen lebenden Zaehler (Regeln/07 Nr. 15). */
define('HANB_FRIST', 120);

/** So lange gilt eine Pruefung des Lesers als Grundlage fuer einen automatischen Beginn. */
define('HANB_PRUEF_GILT', 300);

/** So alt darf das Leseergebnis hoechstens sein, um eine Rolle zu WECHSELN (sonst bleibt die bisherige). */
define('HANB_LESEN_GILT', 300);

/** Die Gruende, die hanb_lage() liefern kann - fuer Proben und die Sprachschluessel der Linien (HAUS.A_<GRUND>). */
function hanb_gruende()
{
    return array('frei', 'eigen', 'gewaehlt', 'pruefe', 'bestand', 'fremd_lebt', 'fremd_schweigt', 'abgeloest',
                 'veraltet');
}

/** Ein Name, wie er in <stamm>/anbieter stehen darf: 1-32 Zeichen a-z, 0-9, _ und -. */
function hanb_name_taugt($name)
{
    return is_string($name) && preg_match('/^[a-z0-9_-]{1,32}\z/', $name) === 1;
}

/** Lebt die Quelle? Der letzte LIVE gesehene Zaehlerwechsel liegt 0...$frist s zurueck; eine Zeit in der Zukunft
 *  (Uhr zurueckgestellt) zaehlt nicht. */
function hanb_frisch($seit, $jetzt, $frist = HANB_FRIST)
{
    $seit = (int) $seit;
    $jetzt = (int) $jetzt;
    return $seit > 0 && $seit <= $jetzt && $jetzt - $seit <= (int) $frist;
}

/**
 * Ein Leseergebnis einarbeiten. $e = bisheriger Eintrag der Quelle (oder array()), $erg = Ergebnis einer Lesesitzung
 * array('lage' => ok|..., 'werte' => Thema => Wert, 'live' => Thema => true), $tz = Zaehlerthema, $wertthemen =
 * kurz => Thema (z. B. 'anbieter' => 'haus/strompreis/anbieter'), $gewartet = die Sitzung hat auf den Zaehler
 * gewartet (volles Fenster oder bis er live kam).
 * Eintrag: werte (kurz => Wert; '' = Abo bestaetigt, nichts zurueckbehalten), zaehler (zuletzt LIVE gesehen), seit
 * (Zeit des letzten live gesehenen WECHSELS - ein gleichbleibender Zaehler verlaengert nichts), beginn (erster
 * Versuch), gelesen (letzte Sitzung mit Lage ok), geprueft (letzte Sitzung mit Lage ok, die gewartet hat).
 */
function hanb_merken(array $e, array $erg, $tz, array $wertthemen, $jetzt, $gewartet)
{
    $jetzt = (int) $jetzt;
    $e += array('werte' => array(), 'zaehler' => null, 'seit' => 0, 'beginn' => $jetzt, 'gelesen' => 0,
                'geprueft' => 0);
    if (!is_array($e['werte'])) {
        $e['werte'] = array();
    }
    $werte = (isset($erg['werte']) && is_array($erg['werte'])) ? $erg['werte'] : array();
    $live = (isset($erg['live']) && is_array($erg['live'])) ? $erg['live'] : array();
    $ok = isset($erg['lage']) && $erg['lage'] === 'ok';
    if (array_key_exists($tz, $werte) && !empty($live[$tz]) && (string) $werte[$tz] !== (string) $e['zaehler']) {
        $e['zaehler'] = (string) $werte[$tz];
        $e['seit'] = $jetzt;
    }
    foreach ($wertthemen as $kurz => $thema) {
        if (array_key_exists($thema, $werte)) {
            $e['werte'][(string) $kurz] = (string) $werte[$thema];
        } elseif ($ok) {
            $e['werte'][(string) $kurz] = '';
        }
    }
    if ($ok) {
        $e['gelesen'] = $jetzt;
        if ($gewartet) {
            $e['geprueft'] = $jetzt;
        }
    }
    return $e;
}

/**
 * Auf den Zaehler warten? Ja - ausser die Quelle schweigt seit mehr als 10 Minuten: dann nur in jeder zehnten Minute
 * (Bremse wie Marstek E-E3 und aWATTar). Rueckgabe true/false.
 */
function hanb_warten(array $e, $jetzt)
{
    $jetzt = (int) $jetzt;
    if (!$e) {
        return true;
    }
    $seit = isset($e['seit']) ? (int) $e['seit'] : 0;
    $beginn = isset($e['beginn']) ? (int) $e['beginn'] : $jetzt;
    $ab = ($seit > 0 && $seit <= $jetzt) ? $seit : $beginn;
    if ($jetzt - $ab <= 600) {
        return true;
    }
    return ((int) floor($jetzt / 60)) % 10 === 0;
}

/** Ein fremder Name fuer Zustand und Meldungen: gueltige Namen und '-' (aWATTar bis 1.2.40 ohne Preise) bleiben,
 *  alles andere wird '?' - ein Wert aus dem Broker erscheint nie ungeprueft in einer Oberflaeche oder einem Protokoll. */
function hanb_fremd_name($name)
{
    if (!is_string($name) || $name === '') {
        return '';
    }
    return (hanb_name_taugt($name) || $name === '-') ? $name : '?';
}

/** Der Zustand einer Installation in sauberer Form: rolle (aus|anbieter|wartet), grund, fremd, seit, gewaehlt. */
function hanb_zustand(array $z)
{
    $rolle = isset($z['rolle']) && in_array($z['rolle'], array('aus', 'anbieter', 'wartet'), true) ? $z['rolle'] : 'aus';
    $grund = isset($z['grund']) && in_array($z['grund'], hanb_gruende(), true) ? $z['grund'] : '';
    return array(
        'rolle'    => $rolle,
        'grund'    => $grund,
        'fremd'    => hanb_fremd_name(isset($z['fremd']) ? $z['fremd'] : ''),
        'seit'     => isset($z['seit']) ? (int) $z['seit'] : 0,
        'gewaehlt' => isset($z['gewaehlt']) ? (int) $z['gewaehlt'] : 0,
    );
}

/**
 * Die Rolle dieser Installation im Lauf $jetzt (Regel oben, Schritte 2-5).
 *   $eigen  eigener Name (hanb_name_taugt), z. B. 'tibber', 'zendure'
 *   $q      Eintrag der Quelle aus hanb_merken() - werte['anbieter'] und seit, gelesen, geprueft
 *   $alt    bisheriger Zustand (hanb_zustand())
 * Rueckgabe hanb_zustand() mit rolle anbieter|wartet. Die Rolle "aus" (Haken aus) setzt die Linie selbst.
 * Ist das Leseergebnis aelter als HANB_LESEN_GILT oder nicht juenger als die Wahl per Knopf, bleibt die bisherige
 * Rolle (Grund 'veraltet' bzw. 'gewaehlt'): ein altes Ergebnis darf weder abloesen noch einen Beginn ausloesen.
 */
function hanb_lage($eigen, array $q, array $alt, $jetzt)
{
    $jetzt = (int) $jetzt;
    $alt = hanb_zustand($alt);
    $war = $alt['rolle'] === 'anbieter';
    $setze = function ($rolle, $grund, $fremd) use ($alt, $jetzt) {
        return array('rolle' => $rolle, 'grund' => $grund, 'fremd' => $fremd,
                     'seit' => ($alt['rolle'] === $rolle && $alt['seit'] > 0) ? $alt['seit'] : $jetzt,
                     'gewaehlt' => $rolle === 'anbieter' ? $alt['gewaehlt'] : 0);
    };
    $gelesen = isset($q['gelesen']) ? (int) $q['gelesen'] : 0;
    if ($war && $alt['gewaehlt'] > 0 && $gelesen <= $alt['gewaehlt']) {
        return $setze('anbieter', 'gewaehlt', '');
    }
    if ($gelesen <= 0 || $gelesen > $jetzt || $jetzt - $gelesen > HANB_LESEN_GILT) {
        if ($war) {
            return $setze('anbieter', $alt['grund'] !== '' ? $alt['grund'] : 'veraltet', '');
        }
        return $setze('wartet', $gelesen <= 0 ? 'pruefe' : 'veraltet', $alt['fremd']);
    }
    $a = (isset($q['werte']['anbieter']) && is_string($q['werte']['anbieter'])) ? trim($q['werte']['anbieter']) : '';
    $lebt = hanb_frisch(isset($q['seit']) ? $q['seit'] : 0, $jetzt);
    if ($a !== '' && $a !== (string) $eigen) {
        if ($war) {
            return $setze('wartet', 'abgeloest', hanb_fremd_name($a));
        }
        return $setze('wartet', $lebt ? 'fremd_lebt' : 'fremd_schweigt', hanb_fremd_name($a));
    }
    if ($a === (string) $eigen) {
        return $setze('anbieter', ($war && $alt['grund'] === 'gewaehlt') ? 'gewaehlt' : 'eigen', '');
    }
    if ($war) {
        return $setze('anbieter', $alt['grund'] !== '' && $alt['grund'] !== 'veraltet' ? $alt['grund'] : 'frei', '');
    }
    if ($lebt) {
        return $setze('wartet', 'bestand', '');
    }
    $geprueft = isset($q['geprueft']) ? (int) $q['geprueft'] : 0;
    if ($geprueft <= 0 || $geprueft > $jetzt || $jetzt - $geprueft > HANB_PRUEF_GILT) {
        return $setze('wartet', 'pruefe', '');
    }
    return $setze('anbieter', 'frei', '');
}

/**
 * Der Knopf "Diese Installation soll Anbieter sein" (Schritt 3). Rueckgabe array(Zustand, Hinweis): Zustand =
 * anbieter/gewaehlt mit Zeitpunkt; Hinweis '' oder 'fremd_lebt' (ein anderer Anbieter sendet noch - Plugins ohne
 * diese Regel muessen dort ausgeschaltet werden, sonst senden beide) bzw. 'bestand' (lebender Zaehler ohne Namen).
 * 'fremd' im Zustand nennt den bisherigen Anbieter (fuer die Meldung).
 */
function hanb_waehlen($eigen, array $q, $jetzt)
{
    $jetzt = (int) $jetzt;
    $a = (isset($q['werte']['anbieter']) && is_string($q['werte']['anbieter'])) ? trim($q['werte']['anbieter']) : '';
    $lebt = hanb_frisch(isset($q['seit']) ? $q['seit'] : 0, $jetzt);
    $fremd = ($a !== '' && $a !== (string) $eigen) ? hanb_fremd_name($a) : '';
    $hinweis = '';
    if ($lebt && $fremd !== '') {
        $hinweis = 'fremd_lebt';
    } elseif ($lebt && $a === '') {
        $hinweis = 'bestand';
    }
    return array(array('rolle' => 'anbieter', 'grund' => 'gewaehlt', 'fremd' => $fremd, 'seit' => $jetzt,
                       'gewaehlt' => $jetzt), $hinweis);
}

/** Darf diese Installation ihre retained Haus-Themen abraeumen (Schritt 6)? Nur als Anbieter oder mit dem Merker
 *  "war Anbieter" - nie, wenn sie abgeloest wurde oder nur wartet. */
function hanb_darf_abraeumen(array $z, $frueher)
{
    $z = hanb_zustand($z);
    if ($z['rolle'] === 'anbieter') {
        return true;
    }
    if ($z['grund'] === 'abgeloest') {
        return false;
    }
    return (bool) $frueher && $z['rolle'] === 'aus';
}

/** Ampel fuer die Testzeile: 1 gruen (sendet), 2 grau (wartet bewusst / aus), 0 rot (doppelt oder unklar). */
function hanb_ampel(array $z)
{
    $z = hanb_zustand($z);
    if ($z['rolle'] === 'anbieter') {
        return in_array($z['grund'], array('frei', 'eigen', 'gewaehlt'), true) ? 1 : 0;
    }
    return 2;
}
