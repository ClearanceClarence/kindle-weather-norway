<?php

declare(strict_types=1);

/*
 * Small weather helpers: Norwegian names, number formatting, and a few
 * calculations (wind chill, compass direction, moon phase).
 */

const WEEKDAYS = ['Søndag', 'Mandag', 'Tirsdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lørdag'];
const WEEKDAYS_SHORT = ['søn.', 'man.', 'tir.', 'ons.', 'tor.', 'fre.', 'lør.'];
const MONTHS = ['jan.', 'feb.', 'mars', 'april', 'mai', 'juni', 'juli', 'aug.', 'sep.', 'okt.', 'nov.', 'des.'];

/**
 * Maps a MET symbol code (for example "lightrainshowers_day") to an icon
 * and labels in the language in use: ['icon' => ..., 'label' => "Lette regnbyger",
 * 'short' => "Regnbyger"].
 */
function parse_symbol(?string $code): array
{
    $code = $code ?: 'cloudy';
    $night = str_ends_with($code, '_night');
    $base = preg_replace('/_(day|night|polartwilight)$/', '', $code);
    $base = str_replace('lightss', 'lights', $base);        // MET has two misspelled codes
    $thunder = str_contains($base, 'andthunder');
    $base = str_replace('andthunder', '', $base);

    static $labels = [
        'clearsky' => 'Klarvær', 'fair' => 'Lettskyet', 'partlycloudy' => 'Delvis skyet',
        'cloudy' => 'Skyet', 'fog' => 'Tåke',
        'lightrain' => 'Lett regn', 'rain' => 'Regn', 'heavyrain' => 'Kraftig regn',
        'lightrainshowers' => 'Lette regnbyger', 'rainshowers' => 'Regnbyger',
        'heavyrainshowers' => 'Kraftige regnbyger',
        'lightsleet' => 'Lett sludd', 'sleet' => 'Sludd', 'heavysleet' => 'Kraftig sludd',
        'lightsleetshowers' => 'Lette sluddbyger', 'sleetshowers' => 'Sluddbyger',
        'heavysleetshowers' => 'Kraftige sluddbyger',
        'lightsnow' => 'Lett snø', 'snow' => 'Snø', 'heavysnow' => 'Kraftig snø',
        'lightsnowshowers' => 'Lette snøbyger', 'snowshowers' => 'Snøbyger',
        'heavysnowshowers' => 'Kraftige snøbyger',
    ];
    $label = $labels[$base] ?? t('Skyet');

    // Short label for the daily rows: drop the intensity word.
    $short = preg_replace('/^(Lette?|Kraftige?) /u', '', $label);
    $short = mb_strtoupper(mb_substr($short, 0, 1)) . mb_substr($short, 1);

    if ($thunder) {
        $icon = 'thunder';
        $label .= ' og torden';
        $short = 'Torden';
    } elseif (str_contains($base, 'sleet')) {
        $icon = 'sleet';
    } elseif (str_contains($base, 'snow')) {
        $icon = 'snow';
    } elseif (str_contains($base, 'rain')) {
        $icon = 'rain';
    } elseif ($base === 'fog') {
        $icon = 'fog';
    } elseif ($base === 'clearsky') {
        $icon = $night ? 'moon' : 'sun';
    } elseif ($base === 'fair' || $base === 'partlycloudy') {
        $icon = $night ? 'partly_night' : 'partly';
    } else {
        $icon = 'cloud';
    }
    // The icon carries both the kind and MET's code; see Canvas::icon().
    // The labels are put together in Norwegian above, then translated as whole phrases.
    $label = $thunder ? t($labels[$base] ?? 'Skyet') . t(' og torden') : t($label);
    return ['icon' => $icon . ':' . $code, 'label' => $label, 'short' => t($short)];
}

function fmt_temp(float $t): string
{
    $r = (int) round($t);
    return ($r < 0 ? "\u{2212}" . abs($r) : (string) $r) . '°';
}

function fmt_mm(float $mm): string
{
    return ($mm < 0.05 ? '0' : number_format($mm, 1, decimal_mark(), '')) . ' mm';
}

function compass(float $deg): string
{
    $names = ['N', t('NØ'), t('Ø'), t('SØ'), 'S', t('SV'), t('V'), t('NV')];
    return $names[(int) round($deg / 45) % 8];
}

/** Wind chill (the formula MET Norway and others use), valid for cold, windy weather. */
function feels_like(float $temp, float $windMs): float
{
    if ($temp > 10 || $windMs < 1.34) {
        return $temp;
    }
    $v = pow($windMs * 3.6, 0.16);
    return 13.12 + 0.6215 * $temp - 11.37 * $v + 0.3965 * $temp * $v;
}

/** Moon phase from the date alone: 0 = new moon, 0.5 = full moon. */
function moon_phase(int $now): array
{
    $synodic = 29.530588853;
    $age = fmod(($now - 947182440) / 86400, $synodic);     // days since the new moon of 6 Jan 2000
    if ($age < 0) {
        $age += $synodic;
    }
    $f = $age / $synodic;
    $name = match (true) {
        $f < 0.03 || $f >= 0.97 => t('Nymåne'),
        $f < 0.22 => t('Voksende sigd'),
        $f < 0.28 => t('Første kvarter'),
        $f < 0.47 => t('Voksende måne'),
        $f < 0.53 => t('Fullmåne'),
        $f < 0.72 => t('Minkende måne'),
        $f < 0.78 => t('Siste kvarter'),
        default   => t('Minkende sigd'),
    };
    return ['fraction' => $f, 'name' => $name];
}
