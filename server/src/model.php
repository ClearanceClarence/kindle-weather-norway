<?php

declare(strict_types=1);

/*
 * Turns MET Norway's raw JSON into the "model": a plain array holding
 * everything the screens show, already worded and formatted in Norwegian.
 *
 * Screens only read the model. To show something new, add it here first.
 *
 * The model (see build_model):
 *
 *   place      [first line, second line] of the header
 *   date       "Fredag 13. nov."
 *   temp       "3°"                    current temperature
 *   feels      "Føles som −1°"
 *   sym        ['icon', 'label', 'short']   current weather (see parse_symbol)
 *   range      "Maks 4° · Min 2°"      rest of today
 *   wind       "6 m/s SV"
 *   windDir    225.0                   degrees the wind comes from
 *   gust       11 or null              m/s
 *   uv         5 or null               today's UV index, when 3 or higher
 *   moon       ['fraction', 'name']    see moon_phase
 *   rain       "3,2 mm"                rest of today
 *   sun        "08:07–16:04"; sunrise and sunset hold the two times alone
 *   hourly     six 3-hour steps: hour, sym, temp, mm, pop
 *   aurora     null, or the aurora notice: icon, text, when (added by render_request)
 *   hours      the next 24 hours: hour, day, sym, temp, feels, mm, mmRaw, pop, wind, gust, dir
 *   graph      hour-by-hour values for the next 24 hours (raw numbers)
 *   today      summary of today, same shape as a 'daily' entry
 *   daily      the next seven days: day, dayFull, date, sym, temps, mm, mmRaw
 *   hint       one line of advice for today, or ''
 *   tomorrow   "I morgen: Sludd, 3° / −4°", or ''
 *   updated    "13:07"                 when this picture was made
 *   stale      "10:11" when the forecast is over an hour old, otherwise ''
 *
 * render_request() in app.php adds two more:
 *
 *   alert      ['level', 'text', 'when'] or null   see build_alert
 *   nowcast    ['rates', 'text'] or null           see build_nowcast
 */

/**
 * One entry per forecast time, with only the fields the display uses.
 * Sorted by time. 't' is a Unix timestamp.
 */
function flatten_timeseries(array $forecast): array
{
    $series = [];
    foreach ($forecast['properties']['timeseries'] as $entry) {
        $data = $entry['data'] ?? [];
        $inst = $data['instant']['details'] ?? [];
        if (!isset($inst['air_temperature'])) {
            continue;
        }
        $series[] = [
            't'     => (int) strtotime($entry['time']),
            'temp'  => (float) $inst['air_temperature'],
            'wind'  => (float) ($inst['wind_speed'] ?? 0),
            'dir'   => (float) ($inst['wind_from_direction'] ?? 0),
            'gust'  => $inst['wind_speed_of_gust'] ?? null,
            'uv'    => $inst['ultraviolet_index_clear_sky'] ?? null,
            'cloud' => $inst['cloud_area_fraction'] ?? null,
            // The next hour (only present for the first two days or so).
            'sym1'  => $data['next_1_hours']['summary']['symbol_code'] ?? null,
            'p1'    => $data['next_1_hours']['details']['precipitation_amount'] ?? null,
            'pop1'  => $data['next_1_hours']['details']['probability_of_precipitation'] ?? null,
            // The next six hours.
            'sym6'  => $data['next_6_hours']['summary']['symbol_code'] ?? null,
            'p6'    => $data['next_6_hours']['details']['precipitation_amount'] ?? null,
            'max6'  => $data['next_6_hours']['details']['air_temperature_max'] ?? null,
            'min6'  => $data['next_6_hours']['details']['air_temperature_min'] ?? null,
            'sym12' => $data['next_12_hours']['summary']['symbol_code'] ?? null,
        ];
    }
    usort($series, fn($a, $b) => $a['t'] <=> $b['t']);
    return $series;
}

/** High, low, precipitation and UV for what is left of today. */
function summarize_rest_of_today(array $series, int $currentIndex, int $now): array
{
    $today = date('Y-m-d', $now);
    $hi = $lo = $series[$currentIndex]['temp'];
    $rain = 0.0;
    $uvMax = null;
    foreach ($series as $i => $entry) {
        if ($i < $currentIndex || date('Y-m-d', $entry['t']) !== $today) {
            continue;
        }
        $hi = max($hi, $entry['temp']);
        $lo = min($lo, $entry['temp']);
        $rain += (float) ($entry['p1'] ?? 0);
        if ($entry['uv'] !== null) {
            $uvMax = max($uvMax ?? 0, (float) $entry['uv']);
        }
    }
    return ['hi' => $hi, 'lo' => $lo, 'rain' => $rain, 'uvMax' => $uvMax];
}

/** The next 24 hours, one entry per hour, starting with the coming full hour. */
function hour_rows(array $byTime, array $current): array
{
    $rows = [];
    for ($step = 1; $step <= 24; $step++) {
        $time = $current['t'] + $step * 3600;
        // Hourly data runs out after about two days; stop at the first gap.
        if (!isset($byTime[$time]) || ($byTime[$time]['sym1'] ?? null) === null) {
            break;
        }
        $entry = $byTime[$time];
        $rows[] = [
            'hour'  => date('H', $time),
            'day'   => t(WEEKDAYS_SHORT[(int) date('w', $time)]),
            'sym'   => parse_symbol($entry['sym1']),
            'temp'  => fmt_temp($entry['temp']),
            'feels' => fmt_temp(feels_like($entry['temp'], $entry['wind'])),
            'mm'    => fmt_mm((float) ($entry['p1'] ?? 0)),
            'mmRaw' => (float) ($entry['p1'] ?? 0),
            'pop'   => $entry['pop1'] === null ? '' : (int) (round((float) $entry['pop1'] / 5) * 5) . ' %',
            'wind'  => (int) round($entry['wind']),
            'gust'  => $entry['gust'] === null ? null : (int) round((float) $entry['gust']),
            'dir'   => $entry['dir'],
        ];
    }
    return $rows;
}

/** Six steps, three hours apart, starting at the next hour divisible by 3. */
function next_hours(array $byTime, array $current): array
{
    $steps = [];
    $time = $current['t'] + 3600;
    while ((int) date('G', $time) % 3 !== 0) {
        $time += 3600;
    }
    for ($step = 0; $step < 6; $step++, $time += 3 * 3600) {
        if (!isset($byTime[$time])) {
            break;
        }
        $entry = $byTime[$time];
        // Amount and highest chance of precipitation over the three hours.
        $mm = 0.0;
        $pop = null;
        for ($hourOffset = 0; $hourOffset < 3; $hourOffset++) {
            $mm += (float) ($byTime[$time + $hourOffset * 3600]['p1'] ?? 0);
            $chance = $byTime[$time + $hourOffset * 3600]['pop1'] ?? null;
            if ($chance !== null) {
                $pop = max($pop ?? 0, (float) $chance);
            }
        }
        $steps[] = [
            'hour' => date('H', $time),
            'sym'  => parse_symbol($entry['sym1'] ?? $entry['sym6']),
            'temp' => fmt_temp($entry['temp']),
            'mm'   => fmt_mm($mm),
            'pop'  => $pop === null ? '' : (int) (round($pop / 5) * 5) . ' %',
        ];
    }
    return $steps;
}

/** Raw hour-by-hour values for the next 24 hours, for the meteogram. */
function graph_points(array $byTime, array $current): array
{
    $points = [];
    for ($step = 0; $step <= 24; $step++) {
        $time = $current['t'] + $step * 3600;
        if (!isset($byTime[$time])) {
            break;
        }
        $entry = $byTime[$time];
        $points[] = [
            't'     => $time,
            'hour'  => date('H', $time),
            'temp'  => $entry['temp'],
            'feels' => feels_like($entry['temp'], $entry['wind']),
            'mm'    => (float) ($entry['p1'] ?? 0),
            'pop'   => $entry['pop1'],
            'icon'  => parse_symbol($entry['sym1'] ?? $entry['sym6'])['icon'],
            'wind'  => $entry['wind'],
            'dir'   => $entry['dir'],
            'gust'  => $entry['gust'],
        ];
    }
    return $points;
}

/**
 * Summary of one calendar day, $offset days from today, or null when the
 * forecast has nothing for that day.
 */
function summarize_day(array $series, int $now, int $offset): ?array
{
    $dayTs = strtotime("+$offset day", strtotime(date('Y-m-d', $now) . ' 12:00'));
    $date = date('Y-m-d', $dayTs);
    $max = $min = null;
    $symbol = null;
    $symbolDistance = 99;
    $mm = 0.0;
    foreach ($series as $entry) {
        if (date('Y-m-d', $entry['t']) !== $date) {
            continue;
        }
        foreach ([$entry['temp'], $entry['max6'], $entry['min6']] as $value) {
            if ($value === null) {
                continue;
            }
            $max = $max === null ? $value : max($max, $value);
            $min = $min === null ? $value : min($min, $value);
        }
        // Hourly entries carry 1-hour amounts; later entries only 6-hour amounts.
        $mm += (float) ($entry['p1'] ?? $entry['p6'] ?? 0);
        // The day's symbol is the one closest to noon.
        $sym = $entry['sym6'] ?? $entry['sym1'] ?? $entry['sym12'];
        $distance = abs((int) date('G', $entry['t']) - 12);
        if ($sym !== null && $distance < $symbolDistance) {
            $symbol = $sym;
            $symbolDistance = $distance;
        }
    }
    if ($max === null) {
        return null;
    }
    $weekday = t(WEEKDAYS[(int) date('w', $dayTs)]);
    return [
        'day'     => mb_substr($weekday, 0, 3),
        'dayFull' => $weekday,
        'date'    => date('j', $dayTs) . '.',
        'sym'     => parse_symbol($symbol),
        'temps'   => fmt_temp((float) $max) . ' / ' . fmt_temp((float) $min),
        'mm'      => fmt_mm($mm),
        'mmRaw'   => $mm,
    ];
}

/**
 * When the sun rises and sets during the hours the meteogram covers, so it
 * can shade the night.
 *
 * @return array{dark: bool, events: list<array{t: int, up: bool}>} whether
 *         the sun is down at the first hour, then each sunrise (up) and sunset
 */
function graph_sun(array $points, array $config): array
{
    $from = $points[0]['t'];
    $to = $points[count($points) - 1]['t'];
    $events = [];
    for ($day = $from - 86400; $day <= $to + 86400; $day += 86400) {
        $sun = date_sun_info($day, $config['lat'], $config['lon']);
        foreach (['sunrise' => true, 'sunset' => false] as $key => $up) {
            if (is_int($sun[$key]) && $sun[$key] > $from && $sun[$key] < $to) {
                $events[$sun[$key]] = $up;
            }
        }
    }
    ksort($events);

    if ($events !== []) {
        $dark = reset($events);                 // a sunrise comes first: it is dark until then
    } else {
        $sun = date_sun_info($from, $config['lat'], $config['lon']);
        $dark = is_int($sun['sunrise']) && is_int($sun['sunset'])
            ? ($from < $sun['sunrise'] || $from > $sun['sunset'])
            : $sun['sunrise'] === false;        // false = polar night, true = midnight sun
    }
    $list = [];
    foreach ($events as $time => $up) {
        $list[] = ['t' => $time, 'up' => $up];
    }
    return ['dark' => $dark, 'events' => $list];
}

/** Sunrise and sunset, calculated locally (no request to MET). */
function sun_times(int $now, array $config): array
{
    $sun = date_sun_info($now, $config['lat'], $config['lon']);
    $rises = is_int($sun['sunrise']);
    $sets = is_int($sun['sunset']);
    return [
        'text' => ($rises && $sets)
            ? date('H:i', $sun['sunrise']) . '–' . date('H:i', $sun['sunset'])
            : ($sun['sunrise'] === true ? t('Midnattssol') : t('Mørketid')),
        'sunrise' => $rises ? date('H:i', $sun['sunrise']) : '–',
        'sunset'  => $sets ? date('H:i', $sun['sunset']) : '–',
    ];
}

/**
 * Builds the model from the forecast. $fetched is when the forecast was
 * downloaded from MET.
 */
function build_model(array $forecast, int $fetched, array $config): array
{
    $now = time();
    $series = flatten_timeseries($forecast);
    $byTime = array_column($series, null, 't');

    // Current conditions: the latest entry that is not in the future.
    $currentIndex = 0;
    foreach ($series as $i => $entry) {
        if ($entry['t'] <= $now) {
            $currentIndex = $i;
        }
    }
    $current = $series[$currentIndex];
    $feels = feels_like($current['temp'], $current['wind']);
    $today = summarize_rest_of_today($series, $currentIndex, $now);

    $daily = [];
    for ($offset = 1; $offset <= 7; $offset++) {
        $day = summarize_day($series, $now, $offset);
        if ($day !== null) {
            $daily[] = $day;
        }
    }
    $sun = sun_times($now, $config);

    $graph = graph_points($byTime, $current);
    return [
        'place'    => [$config['place'], $config['area'] ?? ''],
        'date'     => date_text($now),
        'temp'     => fmt_temp($current['temp']),
        'feels'    => t('Føles som ') . fmt_temp($feels),
        'sym'      => parse_symbol($current['sym1'] ?? $current['sym6']),
        'range'    => t('Maks ') . fmt_temp($today['hi']) . t(' · Min ') . fmt_temp($today['lo']),
        'wind'     => (int) round($current['wind']) . ' m/s ' . compass($current['dir']),
        'windDir'  => $current['dir'],
        'gust'     => $current['gust'] === null ? null : (int) round((float) $current['gust']),
        'uv'       => ($today['uvMax'] !== null && $today['uvMax'] >= 2.5) ? (int) round($today['uvMax']) : null,
        'moon'     => moon_phase($now),
        'rain'     => fmt_mm($today['rain']),
        'sun'      => $sun['text'],
        'sunrise'  => $sun['sunrise'],
        'sunset'   => $sun['sunset'],
        'hourly'   => next_hours($byTime, $current),
        'hours'    => hour_rows($byTime, $current),
        'byTime'   => $byTime,            // for render_request(); removed before drawing
        'graph'    => $graph,
        'graphSun' => $graph === [] ? [] : graph_sun($graph, $config),
        'today'    => summarize_day($series, $now, 0),
        'daily'    => $daily,
        'hint'     => build_hint($current['temp'], $feels, $today['hi'], $today['lo'], $today['rain'], $current['gust'], $today['uvMax']),
        'tomorrow' => isset($daily[0])
            ? t('I morgen: ') . $daily[0]['sym']['short'] . ', ' . $daily[0]['temps']
            : '',
        // "Oppdatert" is when this picture was made, so it moves with every refresh.
        'updated'  => date('H:i', $now),
        'stale'    => $now - $fetched > 3600 ? date('H:i', $fetched) : '',
    ];
}

/** One line of practical advice for today, or '' when nothing stands out. */
function build_hint(float $temp, float $feels, float $hi, float $lo, float $rain, ?float $gust, ?float $uv): string
{
    $freezing = $lo <= 0.5;
    return match (true) {
        $rain >= 0.5 && $freezing      => t('Snø eller sludd i dag: kle deg godt'),
        $rain >= 5                     => t('Mye regn i dag: ta på regntøy'),
        $rain >= 0.5                   => t('Ta med paraply i dag'),
        $feels <= -10                  => t('Bitende kaldt: kle deg godt'),
        $gust !== null && $gust >= 15  => t('Kraftige vindkast i dag'),
        $lo <= 0.5 && $hi >= -0.5      => t('Rundt null grader: det kan bli glatt'),
        $uv !== null && $uv >= 5.5     => t('Sterk sol: husk solkrem'),
        $hi >= 20                      => t('Varmt og fint i dag'),
        default                        => '',
    };
}

/**
 * Sums up the radar nowcast: precipitation rate (mm/h) in 5-minute steps for
 * the next 90 minutes, and one line of text describing it.
 */
function build_nowcast(?array $nowcast): ?array
{
    $now = time();
    $rates = [];
    $times = [];
    foreach ($nowcast['properties']['timeseries'] ?? [] as $entry) {
        $time = (int) strtotime((string) ($entry['time'] ?? ''));
        $rate = $entry['data']['instant']['details']['precipitation_rate'] ?? null;
        if ($rate === null || $time < $now - 300 || count($rates) >= 18) {
            continue;
        }
        $rates[] = (float) $rate;
        $times[] = $time;
    }
    if (count($rates) < 6) {
        return null;
    }
    $wet = fn(float $r) => $r >= 0.1;
    $minutes = fn(int $i) => max(5, (int) (round(($times[$i] - $now) / 300) * 5));
    $text = t('Opphold');
    if ($wet($rates[0])) {
        $text = t('Nedbør hele perioden');
        foreach ($rates as $i => $r) {
            if (!$wet($r)) {
                $text = t('Nedbør nå, opphold om ') . $minutes($i) . ' min';
                break;
            }
        }
    } else {
        foreach ($rates as $i => $r) {
            if ($wet($r)) {
                $text = t('Nedbør om ') . $minutes($i) . ' min';
                break;
            }
        }
    }
    return ['rates' => $rates, 'text' => $text];
}

/** True when the sky is dark enough for aurora at $time: the sun at least 12 degrees down. */
function is_dark(int $time, array $config): bool
{
    $sun = date_sun_info($time, $config['lat'], $config['lon']);
    $dawn = $sun['nautical_twilight_begin'];
    $dusk = $sun['nautical_twilight_end'];
    if (!is_int($dawn) || !is_int($dusk)) {
        return $dawn === false;          // false = the sun stays that far down all day
    }
    return $time < $dawn || $time > $dusk;
}

/**
 * The aurora notice: the first stretch of dark hours in the coming 24 where
 * the forecast Kp reaches the configured level. Null when there is none.
 *
 * $kpSteps is [unix time => Kp] in 3-hour steps; $byTime is the forecast by
 * hour, used for cloud cover. Returns 'text' and 'when' like an alert.
 */
function build_aurora(?array $kpSteps, array $byTime, array $config): ?array
{
    if ($kpSteps === null || !$config['aurora']) {
        return null;
    }
    $now = time();
    $first = (int) (floor($now / 3600) * 3600);
    $run = [];
    for ($timestamp = $first; $timestamp < $first + 24 * 3600; $timestamp += 3600) {
        $kp = null;
        foreach ($kpSteps as $start => $value) {
            if ($start <= $timestamp && $timestamp < $start + 3 * 3600) {
                $kp = $value;
            }
        }
        if ($kp !== null && $kp >= $config['aurora_kp'] && is_dark($timestamp, $config)) {
            $run[] = ['t' => $timestamp, 'kp' => $kp, 'cloud' => $byTime[$timestamp]['cloud'] ?? null];
        } elseif ($run !== []) {
            break;
        }
    }
    if ($run === []) {
        return null;
    }

    $kp = max(array_column($run, 'kp'));
    $clouds = array_filter(array_column($run, 'cloud'), 'is_numeric');
    $cloudy = $clouds !== [] && array_sum($clouds) / count($clouds) >= 75;
    $start = $run[0]['t'];
    $end = end($run)['t'] + 3600;

    if ($start <= $now) {
        $time = t('nå');
    } else {
        $hour = (int) date('G', $start);
        $time = ($hour >= 15 ? t('i kveld') : t('i natt'));
    }
    $text = ($kp >= $config['aurora_kp'] + 2 ? t('Gode sjanser for nordlys ') : t('Nordlys mulig ')) . $time;
    if ($cloudy) {
        $text .= t(', men skyet');
    }
    return [
        'icon' => 'aurora',
        'text' => $text,
        'when' => date('H', $start) . '–' . date('H', $end) . ' · Kp ' . (int) round($kp),
    ];
}

/** Picks the most serious alert in force and words it for the banner. */
function build_alert(?array $alerts): ?array
{
    $now = time();
    $best = null;
    foreach ($alerts['features'] ?? [] as $feature) {
        $properties = $feature['properties'] ?? [];
        $color = strtolower((string) ($properties['riskMatrixColor'] ?? ''));
        if ($color === '' && preg_match('/(yellow|orange|red)/i', (string) ($properties['awareness_level'] ?? ''), $match)) {
            $color = strtolower($match[1]);
        }
        $level = ['yellow' => 1, 'orange' => 2, 'red' => 3][$color] ?? 0;
        $name = trim((string) ($properties['eventAwarenessName'] ?? $properties['event'] ?? ''));
        if ($level === 0 || $name === '') {
            continue;
        }
        $interval = $feature['when']['interval'] ?? $properties['when']['interval'] ?? [];
        $start = isset($interval[0]) ? (int) strtotime((string) $interval[0]) : 0;
        $end = isset($interval[1]) ? (int) strtotime((string) $interval[1]) : 0;
        if ($end > 0 && $end < $now) {
            continue;
        }
        $when = '';
        if ($start > $now) {
            $when = t('fra ') . t(WEEKDAYS_SHORT[(int) date('w', $start)]) . ' ' . date('H:i', $start);
        } elseif ($end > 0) {
            $when = t('til ') . t(WEEKDAYS_SHORT[(int) date('w', $end)]) . ' ' . date('H:i', $end);
        }
        if ($best === null || $level > $best['level']) {
            $best = [
                'level' => $level,
                'text'  => ['', t('Gult'), t('Oransje'), t('Rødt')][$level] . t(' farevarsel: ') . $name,
                'when'  => $when,
            ];
        }
    }
    return $best;
}
