<?php

declare(strict_types=1);

/*
 * Sample weather data for development.
 *
 * Writes made-up MET Norway responses straight into the cache, marked as
 * fresh, so the server renders them without contacting api.met.no. Used by
 * tools/preview.php; handy whenever you work on a screen.
 */

/**
 * Fills $config['cache_dir'] with sample data built around the current time.
 *
 * $alert  true = include an active yellow wind alert
 */
function write_sample_data(array $config, bool $alert = false, bool $aurora = false): void
{
    if (!is_dir($config['cache_dir'])) {
        mkdir($config['cache_dir'], 0775, true);
    }
    $iso = fn(int $t) => gmdate('Y-m-d\TH:i:s\Z', $t);
    $save = function (string $name, array $data) use ($config): void {
        $path = cache_path($config, $name);
        file_put_contents($path . '.json', json_encode($data));
        file_put_contents($path . '.meta.json', json_encode([
            'fetched'   => time() - 60,
            'expires'   => time() + 3600,
            'attempted' => time() - 60,
        ]));
    };

    // --- Forecast: 60 hourly steps, then 6-hourly for a week ---------------
    $symbols = [
        'lightrain', 'lightrain', 'rain', 'cloudy', 'cloudy', 'partlycloudy_night', 'clearsky_night', 'lightsleet',
        'sleet', 'lightsnow', 'snow', 'fog', 'fair_day', 'clearsky_day', 'heavyrainshowersandthunder_day', 'partlycloudy_day',
    ];
    $start = (int) (floor((time() - 1800) / 3600) * 3600);
    $series = [];
    for ($i = 0; $i < 60; $i++) {
        $temp = 3 - $i * 0.15 + 2 * sin($i / 4);
        $symbol = $symbols[intdiv($i, 3) % count($symbols)];
        $wet = (bool) preg_match('/rain|sleet|snow/', $symbol);
        $series[] = ['time' => $iso($start + $i * 3600), 'data' => [
            'instant' => ['details' => [
                'air_temperature'             => round($temp, 1),
                'wind_speed'                  => round(6.2 + 3 * sin($i / 5), 1),
                'wind_from_direction'         => (225 + $i * 9) % 360,
                'wind_speed_of_gust'          => round(11.4 + 5 * sin($i / 5), 1),
                'ultraviolet_index_clear_sky' => 0.3,
                'cloud_area_fraction'         => $wet ? 95.0 : 30.0,
            ]],
            'next_1_hours' => ['summary' => ['symbol_code' => $symbol], 'details' => [
                'precipitation_amount'         => $wet ? round(0.3 + 0.5 * abs(sin($i / 2)), 1) : 0,
                'probability_of_precipitation' => $wet ? 72.3 : 8.1,
            ]],
            'next_6_hours' => ['summary' => ['symbol_code' => $symbol], 'details' => [
                'precipitation_amount' => 1.2,
                'air_temperature_max'  => round($temp + 1.5, 1),
                'air_temperature_min'  => round($temp - 2, 1),
            ]],
        ]];
    }
    $t = $start + 60 * 3600;
    $t += 21600 - $t % 21600;
    $laterSymbols = ['sleet', 'clearsky_day', 'cloudy', 'rain', 'snow', 'partlycloudy_day'];
    for ($i = 0; $i < 28; $i++, $t += 21600) {
        $temp = -2 + 4 * sin($i / 2);
        $series[] = ['time' => $iso($t), 'data' => [
            'instant' => ['details' => ['air_temperature' => round($temp, 1), 'wind_speed' => 4, 'wind_from_direction' => 90]],
            'next_6_hours' => ['summary' => ['symbol_code' => $laterSymbols[$i % 6]], 'details' => [
                'precipitation_amount' => 0.5,
                'air_temperature_max'  => round($temp + 2, 1),
                'air_temperature_min'  => round($temp - 3, 1),
            ]],
        ]];
    }
    $save('forecast', ['type' => 'Feature', 'properties' => ['timeseries' => $series]]);

    // --- Nowcast: dry for 25 minutes, then rain building up ----------------
    $steps = [];
    $t0 = time() - time() % 300;
    for ($i = 0; $i < 24; $i++) {
        $rate = $i < 5 ? 0 : min(4.5, ($i - 4) * 0.35);
        $steps[] = ['time' => $iso($t0 + $i * 300), 'data' => ['instant' => ['details' => ['precipitation_rate' => $rate]]]];
    }
    $save('nowcast', ['type' => 'Feature', 'properties' => ['timeseries' => $steps]]);

    // --- Alerts -------------------------------------------------------------
    $features = [];
    if ($alert) {
        $features[] = [
            'type' => 'Feature',
            'when' => ['interval' => [gmdate('c', time() - 7200), gmdate('c', time() + 20 * 3600)]],
            'properties' => [
                'area'               => 'Telemark',
                'awareness_level'    => '2; yellow; Moderate',
                'eventAwarenessName' => 'Kraftige vindkast',
                'riskMatrixColor'    => 'Yellow',
            ],
        ];
    }
    $save('alerts', ['type' => 'FeatureCollection', 'features' => $features]);
    $save('alerts_en', ['type' => 'FeatureCollection', 'features' => $features]);

    // --- Geomagnetic activity (NOAA): quiet, or a storm through the coming night ---
    $kp = [['time_tag', 'kp', 'observed', 'noaa_scale']];
    $step = (int) (floor(time() / 10800) * 10800);
    for ($i = -2; $i < 24; $i++) {
        $value = $aurora ? 6.33 : 2.0;
        $kp[] = [gmdate('Y-m-d H:i:s', $step + $i * 10800), number_format($value, 2), $i < 0 ? 'observed' : 'predicted', $value >= 5 ? 'G' . ((int) $value - 4) : null];
    }
    $save('kp', $kp);
}

/**
 * Writes made-up aircraft (and one route) into the cache, in the shape the
 * ADS-B feeds and the route lookup use. $empty writes an empty sky.
 */
function write_sample_flights(array $config, bool $empty = false): void
{
    if (!is_dir($config['cache_dir'])) {
        mkdir($config['cache_dir'], 0775, true);
    }
    // call sign, registration, type code, category, altitude ft, speed kn, km away, bearing, track, climb ft/min
    $flights = $empty ? [] : [
        ['SAS4035', 'LN-RGI', 'B738', 'A3', 34100, 427, 12, 232, 236, 0],
        ['',        'LN-OOB', 'EC45', 'A7',  1480, 113, 15, 335, 150, 0],
        ['LNDAH',   'LN-DAH', 'C172', 'A1',  2950,  97, 24, 258,  95, 0],
        ['NOZ612',  'LN-FGB', 'B38M', 'A3', 20000, 346, 27,  70, 203, -1800],
        ['WIF2AK',  'LN-WDK', 'DH8D', 'A3', 17100, 302, 36, 165, 301, 1400],
        ['KLM1146', 'PH-EZS', 'E190', 'A3', 37100, 448, 40, 112, 192, 0],
        ['CLX784',  'LX-VCF', 'B748', 'A5', 36000, 492, 46,  64, 186, 0],
        ['NJE414P', 'CS-LTA', 'C68A', 'A2', 27000, 410, 38, 205,  20, 2200],
    ];
    $ac = [];
    foreach ($flights as $i => [$call, $reg, $type, $category, $feet, $knots, $km, $bearing, $track, $rate]) {
        $lat = $config['lat'] + $km * cos(deg2rad($bearing)) / 111.2;
        $lon = $config['lon'] + $km * sin(deg2rad($bearing)) / (111.2 * cos(deg2rad($config['lat'])));
        $ac[] = [
            'hex' => sprintf('4780%02x', $i), 'flight' => str_pad($call, 8), 'r' => $reg, 't' => $type, 'category' => $category,
            'alt_baro' => $feet, 'gs' => $knots, 'track' => $track, 'baro_rate' => $rate, 'lat' => $lat, 'lon' => $lon, 'seen_pos' => 1.2,
        ];
    }
    $name = sprintf('aircraft_%.4F_%.4F_%d', $config['lat'], $config['lon'], $config['flight_radius']);
    file_put_contents($config['cache_dir'] . '/' . $name . '.json', json_encode(['ac' => $ac, 'total' => count($ac)]));
    file_put_contents($config['cache_dir'] . '/route_sas4035.json', json_encode(['response' => ['flightroute' => [
        'callsign'    => 'SAS4035',
        'airline'     => ['name' => 'SAS', 'icao' => 'SAS'],
        'origin'      => ['iata_code' => 'OSL', 'municipality' => 'Oslo', 'name' => 'Oslo Gardermoen', 'latitude' => 60.1939, 'longitude' => 11.1004],
        'destination' => ['iata_code' => 'SVG', 'municipality' => 'Stavanger', 'name' => 'Stavanger Sola', 'latitude' => 58.8767, 'longitude' => 5.6378],
    ]]]));
    file_put_contents($config['cache_dir'] . '/route_klm1146.json', json_encode(['response' => ['flightroute' => [
        'callsign'    => 'KLM1146',
        'airline'     => ['name' => 'KLM', 'icao' => 'KLM'],
        'origin'      => ['iata_code' => 'OSL', 'municipality' => 'Oslo', 'name' => 'Oslo Gardermoen', 'latitude' => 60.1939, 'longitude' => 11.1004],
        'destination' => ['iata_code' => 'AMS', 'municipality' => 'Amsterdam', 'name' => 'Amsterdam Schiphol', 'latitude' => 52.3086, 'longitude' => 4.7639],
    ]]]));
    // The first route source has no answer for these flights; the second one (above) does.
    foreach (['sas4035', 'klm1146', 'noz612', 'wif2ak', 'clx784', 'nje414p'] as $call) {
        file_put_contents($config['cache_dir'] . '/route_lol_' . $call . '.json', '[]');
        file_put_contents($config['cache_dir'] . '/route_vrs_' . $call . '.json', '[]');
    }
    remove_file($config['cache_dir'] . '/flight_state.json');
}
