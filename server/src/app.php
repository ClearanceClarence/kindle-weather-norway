<?php

declare(strict_types=1);

/*
 * The request flow: read the query string, load the data the chosen screen
 * needs, build the model, and hand it to the screen.
 */

/**
 * Reads and validates the query string.
 *
 *   view      which screen (see src/views.php); "closing" needs no forecast,
 *             "icons" draws a sample strip of an icon set,
 *             and "fonts" a sample of a typeface
 *   batt      battery level 0-100, shown in the header
 *   x         1 = draw touch controls (close button and tab bar)
 *   interval  minutes between refreshes, mentioned on the offline screen
 *   wake      "06:00", when quiet hours end, mentioned on the night screen
 *   set       icon set for view=icons, typeface for view=fonts
 *
 * $default is the screen to show when the request names none.
 */
function parse_request(array $query, string $default): array
{
    $int = fn(string $key) => isset($query[$key]) && ctype_digit((string) $query[$key]) ? (int) $query[$key] : null;

    $view = (string) ($query['view'] ?? $default);
    if ($view === 'forecast') {
        $view = 'now';                   // name used by early versions of the Kindle script
    }
    if (!in_array($view, ['closing', 'icons', 'fonts'], true) && !isset(SCREENS[$view])) {
        $view = $default;
    }

    $batt = $int('batt');
    $wake = (string) ($query['wake'] ?? '');
    return [
        'view'     => $view,
        'batt'     => $batt === null ? null : max(0, min(100, $batt)),
        'touch'    => ($query['x'] ?? '') === '1',
        'interval' => max(1, min(1440, $int('interval') ?? 10)),
        'wake'     => preg_match('/^\d{1,2}:\d{2}$/', $wake) ? $wake : '',
        'page'     => max(0, min(9, $int('page') ?? 0)),
        'set'      => preg_match('/^[a-z0-9-]{1,70}$/', (string) ($query['set'] ?? '')) ? (string) $query['set'] : '',
    ];
}

/** Handles one request and returns the PNG to send back. */
function render_request(array $config, array $query): string
{
    $req = parse_request($query, $config['default_view']);
    active_tabs($config['tabs']);
    flight_button((bool) $config['flights']);
    Canvas::$iconSet = $config['icon_set'];
    Canvas::$iconDir = $config['icon_dir'];
    Canvas::$typeface = $config['fonts'][$config['font']] ?? null;

    if ($req['view'] === 'closing') {
        return render_closing($req, $config);
    }
    if ($req['view'] === 'fonts') {
        // The admin page asks for the sample in its own language, also before anything is saved.
        language(isset($_GET['lang']) && is_string($_GET['lang']) ? $_GET['lang'] : null);
        return render_font_sample($req, $config);
    }
    if ($req['view'] === 'icons') {
        return render_icon_sample($req, $config);
    }
    if ($config['lat'] === null || $config['lon'] === null) {
        return render_setup($req, $config);
    }

    $forecast = load_forecast($config);
    if ($forecast === null) {
        return render_error($req, $config);
    }

    $model = build_model($forecast['data'], $forecast['fetched'], $config);
    // The offline screen is stored on the Kindle and shown later, so it gets
    // no alert. Only "Nå" shows the radar strip.
    $model['alert'] = $req['view'] === 'offline' ? null : build_alert(load_alerts($config));
    $model['nowcast'] = $req['view'] === 'now' ? build_nowcast(load_nowcast($config)) : null;
    // The aurora notice is shown on "Nå". It is fetched last: it matters least if it fails.
    $model['aurora'] = ($req['view'] === 'now' && $config['aurora'])
        ? build_aurora(load_kp_forecast($config), $model['byTime'], $config)
        : null;
    unset($model['byTime']);

    return (SCREENS[$req['view']])($model, $req, $config);
}

/** Handles one request for the flight screen (flights.php) and returns the PNG. */
function render_flight_request(array $config, array $query): string
{
    $req = parse_request($query, $config['default_view']);
    Canvas::$iconSet = $config['icon_set'];
    Canvas::$iconDir = $config['icon_dir'];
    Canvas::$typeface = $config['fonts'][$config['font']] ?? null;
    if ($config['lat'] === null || $config['lon'] === null) {
        return render_setup($req, $config);
    }

    $tap = preg_match('/^(\d{1,4}),(\d{1,4})$/', (string) ($query['tap'] ?? ''), $m) ? [(int) $m[1], (int) $m[2]] : null;
    $aircraft = load_aircraft($config);
    $state = flight_state($config);
    [$index, $tapped] = $aircraft ? select_aircraft($aircraft, $state, $tap, ($query['open'] ?? '') === '1') : [0, false];
    $route = $aircraft ? load_route($config, $aircraft[$index]) : null;

    [$png, $drawn] = render_flights($aircraft, $index, $tapped, $route, $req, $config);
    flight_state($config, ['selected' => $tapped ? $aircraft[$index]['id'] : '', 'drawn' => $drawn]);
    return $png;
}

/**
 * A plain-text health report, shown at /?view=status: how long a picture
 * takes to draw and how the last contact with MET Norway went. It contains
 * no location or contact details, so it is safe to paste when asking for help.
 */
function status_text(array $config): string
{
    $lines = [
        'PHP ' . PHP_VERSION . ', FreeType: ' . (function_exists('imagettftext') ? 'ja' : 'NEI'),
        'Sted satt opp: ' . ($config['lat'] === null ? 'NEI' : 'ja'),
        'Skrift: ' . ($config['fonts'][$config['font']]['google'] ? 'fra Google Fonts' : $config['font']) . ', ikoner: ' . $config['icon_set'],
    ];
    if ($config['lat'] !== null) {
        $started = microtime(true);
        $png = render_request($config, ['view' => $config['default_view']]);
        $lines[] = sprintf('Å tegne «%s» tok %.1f s (%d kB), medregnet eventuell kontakt med MET', $config['default_view'], microtime(true) - $started, strlen($png) / 1000);
        $started = microtime(true);
        render_request($config, ['view' => $config['default_view']]);
        $lines[] = sprintf('Å tegne den en gang til tok %.1f s', microtime(true) - $started);

        $labels = ['forecast' => 'Varsel (MET)', 'nowcast' => 'Nedbørradar (MET)', 'alerts' => 'Farevarsler (MET)', 'kp' => 'Nordlys (NOAA)'];
        foreach (array_keys($labels) as $name) {
            $metaFile = cache_path($config, $name) . '.meta.json';
            $meta = is_file($metaFile) ? (json_decode((string) file_get_contents($metaFile), true) ?: []) : [];
            if ($meta === []) {
                $lines[] = $labels[$name] . ': aldri hentet';
                continue;
            }
            $lines[] = sprintf(
                '%s: siste forsøk for %d s siden, svar %s på %s s, data %s',
                $labels[$name],
                time() - (int) ($meta['attempted'] ?? 0),
                ($meta['status'] ?? null) === 0 ? 'INGEN (ingen kontakt)' : (string) ($meta['status'] ?? 'ukjent'),
                (string) ($meta['seconds'] ?? '?'),
                isset($meta['fetched']) ? round((time() - $meta['fetched']) / 60) . ' min gamle' : 'mangler'
            );
        }
    }
    return implode("\n", $lines) . "\n";
}
