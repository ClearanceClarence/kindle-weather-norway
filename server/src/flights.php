<?php

declare(strict_types=1);

/*
 * Flights overhead: the data behind the flight screen (flights.php).
 *
 *   load_aircraft()   aircraft within range right now, from an open ADS-B feed
 *   load_route()      where a flight is going, from two route databases, checked against its position
 *   flight_state()    which aircraft the user tapped, remembered between refreshes
 *
 * The map under the aircraft is in flightmap.php; the drawing is in
 * views/flights.php.
 *
 * Positions come from volunteer-run networks of ADS-B receivers (adsb.lol,
 * with adsb.fi as a fallback). Coverage depends on receivers nearby, so
 * low-flying aircraft can be missing. Neither service is told who you are:
 * the contact address is for MET Norway only.
 */

// Type codes as the feeds give them => a name worth showing. Unknown codes are shown as they are.
const AIRCRAFT_TYPES = [
    'A20N' => 'Airbus A320neo', 'A21N' => 'Airbus A321neo', 'A319' => 'Airbus A319', 'A320' => 'Airbus A320',
    'A321' => 'Airbus A321', 'A332' => 'Airbus A330-200', 'A333' => 'Airbus A330-300', 'A339' => 'Airbus A330neo',
    'A359' => 'Airbus A350-900', 'A35K' => 'Airbus A350-1000', 'A388' => 'Airbus A380', 'BCS1' => 'Airbus A220-100',
    'BCS3' => 'Airbus A220-300', 'B37M' => 'Boeing 737 MAX 7', 'B38M' => 'Boeing 737 MAX 8', 'B39M' => 'Boeing 737 MAX 9',
    'B737' => 'Boeing 737-700', 'B738' => 'Boeing 737-800', 'B739' => 'Boeing 737-900', 'B744' => 'Boeing 747-400',
    'B748' => 'Boeing 747-8', 'B752' => 'Boeing 757-200', 'B763' => 'Boeing 767-300', 'B772' => 'Boeing 777-200',
    'B77L' => 'Boeing 777-200LR', 'B77W' => 'Boeing 777-300ER', 'B788' => 'Boeing 787-8', 'B789' => 'Boeing 787-9',
    'B78X' => 'Boeing 787-10', 'E170' => 'Embraer 170', 'E75L' => 'Embraer 175', 'E190' => 'Embraer 190',
    'E195' => 'Embraer 195', 'E290' => 'Embraer 190-E2', 'E295' => 'Embraer 195-E2', 'CRJ9' => 'Bombardier CRJ900',
    'DH8A' => 'Dash 8-100', 'DH8B' => 'Dash 8-200', 'DH8C' => 'Dash 8-300', 'DH8D' => 'Dash 8-400',
    'AT72' => 'ATR 72', 'AT75' => 'ATR 72-500', 'AT76' => 'ATR 72-600', 'SF34' => 'Saab 340', 'SB20' => 'Saab 2000',
    'B350' => 'Beechcraft King Air 350', 'BE20' => 'Beechcraft King Air 200', 'PC12' => 'Pilatus PC-12', 'PC24' => 'Pilatus PC-24',
    'C172' => 'Cessna 172', 'C182' => 'Cessna 182', 'C208' => 'Cessna Caravan', 'C25A' => 'Cessna Citation CJ2',
    'C56X' => 'Cessna Citation Excel', 'C68A' => 'Cessna Citation Latitude', 'P28A' => 'Piper Cherokee', 'DA40' => 'Diamond DA40',
    'DA42' => 'Diamond DA42', 'SR22' => 'Cirrus SR22', 'GLF6' => 'Gulfstream G650', 'F900' => 'Dassault Falcon 900',
    'EC35' => 'Airbus H135', 'EC45' => 'Airbus H145', 'H145' => 'Airbus H145', 'EC55' => 'Airbus H155', 'EC75' => 'Airbus H175',
    'AS32' => 'Super Puma', 'AS50' => 'Airbus H125', 'A139' => 'Leonardo AW139', 'A169' => 'Leonardo AW169', 'A189' => 'Leonardo AW189',
    'AW10' => 'Leonardo AW101', 'S92' => 'Sikorsky S-92', 'R44' => 'Robinson R44', 'R66' => 'Robinson R66', 'B412' => 'Bell 412',
    'F16' => 'F-16', 'F35' => 'F-35', 'C130' => 'C-130 Hercules', 'C30J' => 'C-130J Hercules', 'P8' => 'P-8 Poseidon',
];

// Type codes => which silhouette to draw (see Canvas::plane). Types not
// listed are guessed from their size class, speed and altitude.
const AIRCRAFT_SHAPES = [
    'jet4'    => ['A388', 'A342', 'A343', 'A345', 'A346', 'B741', 'B742', 'B744', 'B748', 'IL76', 'C17', 'A124', 'B52', 'E3TF', 'K35R'],
    'tailjet' => ['CRJ2', 'CRJ7', 'CRJ9', 'CRJX', 'E135', 'E145', 'E35L', 'E50P', 'E55P', 'C25A', 'C25B', 'C25C', 'C510', 'C525', 'C56X',
                  'C680', 'C68A', 'C700', 'C750', 'CL30', 'CL35', 'CL60', 'GL5T', 'GLEX', 'GLF4', 'GLF5', 'GLF6', 'G280', 'F2TH', 'F900',
                  'FA7X', 'FA8X', 'LJ35', 'LJ45', 'LJ60', 'PC24', 'H25B', 'MD82', 'MD83', 'B712', 'F100', 'F70'],
    'prop2'   => ['DH8A', 'DH8B', 'DH8C', 'DH8D', 'AT43', 'AT45', 'AT46', 'AT72', 'AT75', 'AT76', 'SF34', 'SB20', 'B350', 'BE20', 'BE9L',
                  'DHC6', 'D228', 'JS32', 'JS41', 'F50', 'DA42', 'P68', 'PA34', 'BE58', 'C295', 'CN35'],
    'prop4'   => ['C130', 'C30J', 'A400', 'P3', 'DHC7'],
    'small'   => ['C150', 'C152', 'C172', 'C182', 'C206', 'C208', 'P28A', 'P28B', 'P28R', 'PA18', 'PA28', 'DA20', 'DA40', 'SR20', 'SR22',
                  'PC12', 'TBM7', 'TBM8', 'TBM9', 'M20P', 'AA5', 'DR40', 'RV7', 'RV8', 'GLID', 'ULAC'],
    'fighter' => ['F16', 'F35', 'F18', 'F15', 'EUFI', 'RFAL', 'GRIF', 'SB39', 'HAWK'],
    'heli'    => ['EC20', 'EC30', 'EC35', 'EC45', 'H145', 'EC55', 'EC75', 'EC25', 'AS32', 'AS50', 'AS55', 'AS65', 'A109', 'A139', 'A169',
                  'A189', 'AW10', 'S61', 'S76', 'S92', 'R22', 'R44', 'R66', 'B06', 'B407', 'B412', 'B429', 'NH90', 'H60', 'H47', 'LYNX'],
];

// How large each silhouette is drawn on the map, as half its length in pixels.
const AIRCRAFT_SIZES = ['jet4' => 31, 'jet2' => 26, 'tailjet' => 22, 'prop4' => 28, 'prop2' => 24, 'small' => 19, 'fighter' => 21, 'heli' => 24];

/**
 * Which silhouette suits an aircraft: from its type code when it is a known
 * one, otherwise from the size class it broadcasts (A1 = light ... A5 =
 * heavy, A7 = helicopter) together with how fast and high it flies.
 */
function aircraft_shape(string $code, string $category, float $kmh, float $meters): string
{
    foreach (AIRCRAFT_SHAPES as $shape => $codes) {
        if (in_array($code, $codes, true)) {
            return $shape;
        }
    }
    return match (true) {
        $category === 'A7'                => 'heli',
        $category === 'A6'                => 'fighter',
        in_array($category, ['A1', 'B1', 'B4'], true) => 'small',
        $category === 'A2'                => ($kmh > 550 || $meters > 8500) ? 'tailjet' : 'prop2',
        default                           => 'jet2',
    };
}

// The three letters that start an airline's call sign => the airline.
const AIRLINES = [
    'SAS' => 'SAS', 'SZS' => 'SAS', 'NOZ' => 'Norwegian', 'NSZ' => 'Norwegian', 'NAX' => 'Norwegian', 'IBK' => 'Norwegian',
    'WIF' => 'Widerøe', 'NWG' => 'Norse Atlantic', 'DAT' => 'DAT', 'FIN' => 'Finnair', 'ICE' => 'Icelandair',
    'KLM' => 'KLM', 'DLH' => 'Lufthansa', 'EWG' => 'Eurowings', 'BAW' => 'British Airways', 'AFR' => 'Air France',
    'SWR' => 'Swiss', 'AUA' => 'Austrian', 'BEL' => 'Brussels Airlines', 'LOT' => 'LOT', 'BTI' => 'airBaltic',
    'RYR' => 'Ryanair', 'WZZ' => 'Wizz Air', 'WMT' => 'Wizz Air Malta', 'WUK' => 'Wizz Air UK', 'EZY' => 'easyJet', 'EJU' => 'easyJet', 'VLG' => 'Vueling',
    'IBE' => 'Iberia', 'TAP' => 'TAP', 'THY' => 'Turkish Airlines', 'PGT' => 'Pegasus', 'SXS' => 'SunExpress',
    'QTR' => 'Qatar Airways', 'UAE' => 'Emirates', 'ETD' => 'Etihad', 'ETH' => 'Ethiopian', 'UAL' => 'United',
    'DAL' => 'Delta', 'AAL' => 'American', 'ACA' => 'Air Canada', 'TOM' => 'TUI', 'TFL' => 'TUI', 'BLX' => 'TUI',
    'JAF' => 'TUI', 'NVD' => 'Avion Express', 'FDX' => 'FedEx', 'UPS' => 'UPS', 'BCS' => 'DHL', 'DHK' => 'DHL', 'BOX' => 'DHL',
    'SWN' => 'West Air Sweden', 'NPT' => 'West Atlantic', 'TAY' => 'ASL Airlines', 'CLX' => 'Cargolux', 'NOR' => 'Norsk Luftambulanse',
    'DOC' => 'Norsk Luftambulanse', 'HKS' => 'CHC Helikopter', 'BHL' => 'Bristow', 'NOW' => 'Luftforsvaret',
];

/**
 * A small JSON cache for data that changes fast. Returns the decoded
 * document, from disk if it is younger than $ttl seconds; otherwise it is
 * downloaded. Null when there is neither a download nor a copy younger
 * than $maxAge. With $post, that data is sent as a JSON POST request.
 */
function quick_json(array $config, string $name, string $url, int $ttl, int $maxAge, int $timeout = 5, ?array $post = null): ?array
{
    if (!is_dir($config['cache_dir'])) {
        @mkdir($config['cache_dir'], 0775, true);
        @file_put_contents($config['cache_dir'] . '/.htaccess', "Require all denied\n");
    }
    $file = $config['cache_dir'] . '/' . $name . '.json';
    $age = is_file($file) ? time() - filemtime($file) : PHP_INT_MAX;
    if ($age >= $ttl) {
        $http = [
            'timeout'          => $timeout,
            'ignore_errors'    => true,
            // HTTP/1.1 with "Connection: close": some services send an empty
            // answer to the older HTTP/1.0 that PHP speaks by default.
            'protocol_version' => 1.1,
            'header'           => "User-Agent: kindle-weather-norway\r\nAccept: application/json\r\nConnection: close\r\n",
        ];
        if ($post !== null) {
            $http['method'] = 'POST';
            $http['header'] .= "Content-Type: application/json\r\n";
            $http['content'] = json_encode($post);
        }
        $context = stream_context_create(['http' => $http]);
        $body = @file_get_contents($url, false, $context);
        if ($body !== false && is_array(json_decode($body, true))) {
            @file_put_contents($file, $body, LOCK_EX);
            @unlink($file . '.err');
            $age = 0;
        } else {
            // Keep what went wrong, for the troubleshooting report.
            $status = $http_response_header[0] ?? 'no answer';
            @file_put_contents($file . '.err', $status . ' | ' . substr((string) $body, 0, 400));
        }
    }
    if ($age > $maxAge) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

/** Distance in km and compass bearing in degrees from one point to another. */
function distance_bearing(float $lat1, float $lon1, float $lat2, float $lon2): array
{
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dl = deg2rad($lon2 - $lon1);
    $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    $km = 2 * 6371.0 * asin(min(1.0, sqrt($a)));
    $bearing = rad2deg(atan2(sin($dl) * cos($p2), cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl)));
    return [$km, fmod($bearing + 360, 360)];
}

/**
 * Aircraft in the air within the configured range, nearest first. Null when
 * no feed answers. Each aircraft:
 *
 *   id       the transponder's address, used to remember a selection
 *   call     call sign, or the registration when it sends none
 *   reg      registration ("LN-RGI"), or ''
 *   type     aircraft type in words, or ''
 *   airline  from the call sign, or ''
 *   shape    the silhouette to draw: jet2, jet4, tailjet, prop2, prop4, small, fighter or heli
 *   alt      altitude in meters; speed in km/h; track in degrees
 *   trend    'up', 'down' or 'level'
 *   lat, lon, km (distance from home), bearing (direction from home)
 */
function load_aircraft(array $config): ?array
{
    $lat = (float) $config['lat'];
    $lon = (float) $config['lon'];
    $radius = (int) $config['flight_radius'];
    $nm = (int) ceil($radius / 1.852);
    $key = sprintf('aircraft_%.4F_%.4F_%d', $lat, $lon, $radius);

    $data = quick_json($config, $key, sprintf('https://api.adsb.lol/v2/point/%.4F/%.4F/%d', $lat, $lon, $nm), 20, 120)
        ?? quick_json($config, $key, sprintf('https://opendata.adsb.fi/api/v2/lat/%.4F/lon/%.4F/dist/%d', $lat, $lon, $nm), 20, 120);
    if ($data === null) {
        return null;
    }

    $aircraft = [];
    foreach ($data['ac'] ?? $data['aircraft'] ?? [] as $a) {
        if (!isset($a['lat'], $a['lon'], $a['hex']) || !is_numeric($a['alt_baro'] ?? $a['alt_geom'] ?? null)) {
            continue;                    // no position, or on the ground
        }
        if ((float) ($a['seen_pos'] ?? $a['seen'] ?? 0) > 60) {
            continue;                    // not heard from for a minute
        }
        [$km, $bearing] = distance_bearing($lat, $lon, (float) $a['lat'], (float) $a['lon']);
        if ($km > $radius) {
            continue;
        }
        $call = strtoupper(trim((string) ($a['flight'] ?? '')));
        $reg = strtoupper(trim((string) ($a['r'] ?? '')));
        $code = strtoupper(trim((string) ($a['t'] ?? '')));
        $rate = (float) ($a['baro_rate'] ?? $a['geom_rate'] ?? 0);
        $aircraft[] = [
            'id'      => preg_replace('/[^0-9a-f]/', '', strtolower((string) $a['hex'])),
            'call'    => $call !== '' ? $call : ($reg !== '' ? $reg : strtoupper((string) $a['hex'])),
            'reg'     => $reg,
            'type'    => AIRCRAFT_TYPES[$code] ?? (trim((string) ($a['desc'] ?? '')) ?: $code),
            'airline' => preg_match('/^([A-Z]{3})\d/', $call, $m) ? (AIRLINES[$m[1]] ?? '') : '',
            'shape'   => aircraft_shape($code, (string) ($a['category'] ?? ''), (float) ($a['gs'] ?? 0) * 1.852, (float) ($a['alt_baro'] ?? $a['alt_geom']) * 0.3048),
            'alt'     => (int) round((float) ($a['alt_baro'] ?? $a['alt_geom']) * 0.3048 / 50) * 50,
            'speed'   => (int) round((float) ($a['gs'] ?? 0) * 1.852 / 10) * 10,
            'track'   => (float) ($a['track'] ?? $a['true_heading'] ?? 0),
            'trend'   => $rate > 300 ? 'up' : ($rate < -300 ? 'down' : 'level'),
            'lat'     => (float) $a['lat'],
            'lon'     => (float) $a['lon'],
            'km'      => $km,
            'bearing' => $bearing,
        ];
    }
    usort($aircraft, fn($a, $b) => $a['km'] <=> $b['km']);
    return $aircraft;
}

/**
 * Where an aircraft is going: airline, from and to (each with city, code,
 * lat, lon), or null when unknown. Only airline flights have routes.
 *
 * Two open route databases are asked, and the first answer that can be
 * true for where the aircraft is now wins (see route_is_plausible):
 *
 *   1. adsb.lol's route list, one file per call sign
 *   2. adsbdb.com, by call sign alone
 *
 * Airlines reuse call signs, so either can be out of date; an answer that
 * does not fit the aircraft is dropped rather than shown.
 */
function load_route(array $config, array $aircraft): ?array
{
    $call = $aircraft['call'];
    if (!preg_match('/^[A-Z]{3}\d[A-Z0-9]{0,4}$/', $call)) {
        return null;
    }
    foreach (array_merge(routes_from_adsblol($config, $aircraft), routes_from_adsbdb($config, $call)) as $route) {
        if (route_is_plausible($route, $aircraft)) {
            return $route;
        }
    }
    return null;
}

/** One airport as the screen needs it, from the differently named fields of the two sources. Null if incomplete. */
function route_airport(array $a): ?array
{
    $airport = [
        'city' => (string) ($a['municipality'] ?? $a['location'] ?? $a['name'] ?? ''),
        'code' => (string) ($a['iata_code'] ?? $a['iata'] ?? $a['icao_code'] ?? $a['icao'] ?? ''),
        'lat'  => $a['latitude'] ?? $a['lat'] ?? null,
        'lon'  => $a['longitude'] ?? $a['lon'] ?? null,
    ];
    if ($airport['city'] === '' || !is_numeric($airport['lat']) || !is_numeric($airport['lon'])) {
        return null;
    }
    $airport['lat'] = (float) $airport['lat'];
    $airport['lon'] = (float) $airport['lon'];
    return $airport;
}

/**
 * Routes for a call sign from adsb.lol, which publishes the route list
 * that the "Virtual Radar Server" community keeps up to date, as one small
 * file per call sign. A flight with a stop on the way gives one route per
 * leg. Answers are kept for six hours.
 */
function routes_from_adsblol(array $config, array $aircraft): array
{
    $call = $aircraft['call'];
    $name = strtolower($call);
    $data = quick_json(
        $config,
        'route_vrs_' . $name,
        'https://vrs-standing-data.adsb.lol/routes/' . substr($call, 0, 2) . '/' . $call . '.json',
        6 * 3600,
        2 * 86400,
        4
    );
    return adsblol_routes($data);
}

/** Reads adsb.lol's route answer: one entry, or a list of entries, each with the airports along the way. */
function adsblol_routes(?array $data): array
{
    if ($data === null) {
        return [];
    }
    $routes = [];
    foreach (isset($data['_airports']) ? [$data] : $data as $entry) {
        if (!is_array($entry) || !is_array($entry['_airports'] ?? null)) {
            continue;
        }
        $airports = array_values(array_filter(array_map(
            fn($a) => is_array($a) ? route_airport($a) : null,
            $entry['_airports']
        )));
        $airline = AIRLINES[strtoupper((string) ($entry['airline_code'] ?? ''))] ?? '';
        for ($i = 0; $i + 1 < count($airports); $i++) {
            $routes[] = ['airline' => $airline, 'from' => $airports[$i], 'to' => $airports[$i + 1]];
        }
    }
    return $routes;
}

/** The route for a call sign from adsbdb.com, as a list of none or one. Answers are kept for a day. */
function routes_from_adsbdb(array $config, string $call): array
{
    $data = quick_json($config, 'route_' . strtolower($call), 'https://api.adsbdb.com/v0/callsign/' . $call, 86400, 7 * 86400, 4);
    $route = $data['response']['flightroute'] ?? null;
    if (!is_array($route) || !is_array($route['origin'] ?? null) || !is_array($route['destination'] ?? null)) {
        return [];
    }
    $from = route_airport($route['origin']);
    $to = route_airport($route['destination']);
    if ($from === null || $to === null) {
        return [];
    }
    return [['airline' => (string) ($route['airline']['name'] ?? ''), 'from' => $from, 'to' => $to]];
}

/**
 * The airline operating a flight, in words: from the route database when it
 * names one, otherwise from the first three letters of the call sign,
 * looked up in the short list above and then in map/airlines.json.
 * Empty for aircraft that are not on an airline call sign.
 */
function airline_name(array $config, array $aircraft, ?array $route): string
{
    if (!preg_match('/^([A-Z]{3})\d/', $aircraft['call'], $m)) {
        return '';
    }
    if (isset(AIRLINES[$m[1]])) {
        return AIRLINES[$m[1]];
    }
    if (($route['airline'] ?? '') !== '') {
        return $route['airline'];
    }
    static $names = null;
    $names ??= json_decode((string) @file_get_contents(dirname($config['icon_dir']) . '/map/airlines.json'), true)['airlines'] ?? [];
    return (string) ($names[$m[1]] ?? '');
}

/**
 * Roughly how long a flight has been in the air and how long it has left,
 * as two texts such as "2 t 50 min" and "10 min". Nobody publishes these
 * for free, so they are worked out from the distance flown and the
 * distance left, at the speed that kind of aircraft usually cruises at,
 * plus a few minutes for climbing out and for the approach.
 */
function flight_times(float $flownKm, float $leftKm, string $shape): array
{
    $cruise = in_array($shape, ['prop2', 'prop4'], true) ? 520 : ($shape === 'small' ? 200 : 820);   // km/h
    $words = function (float $km) use ($cruise): string {
        $minutes = $km / $cruise * 60 + 8;
        $minutes = (int) ($minutes > 25 ? round($minutes / 5) * 5 : round($minutes));
        return $minutes >= 60 ? intdiv($minutes, 60) . t(' t ') . ($minutes % 60 ? $minutes % 60 . ' min' : '') : $minutes . ' min';
    };
    return [rtrim($words($flownKm)), rtrim($words($leftKm))];
}

/**
 * Whether a looked-up route can be true for an aircraft where it is now.
 * Airlines reuse call signs, so the route database is sometimes out of
 * date: a flight "from Liverpool to Budapest" seen heading west over Norway
 * is on some other route. Such routes are better left out than shown.
 *
 * A route passes when the aircraft is not far off the straight line between
 * the two airports and, away from both airports, is flying roughly toward
 * the destination.
 */
function route_is_plausible(array $route, array $aircraft): bool
{
    [$total] = distance_bearing($route['from']['lat'], $route['from']['lon'], $route['to']['lat'], $route['to']['lon']);
    [$flown] = distance_bearing($route['from']['lat'], $route['from']['lon'], $aircraft['lat'], $aircraft['lon']);
    [$left, $toward] = distance_bearing($aircraft['lat'], $aircraft['lon'], $route['to']['lat'], $route['to']['lon']);

    // The detour through where the aircraft is: at most a fifth longer, plus room for approach patterns.
    if ($flown + $left > $total * 1.2 + 80) {
        return false;
    }
    // Near either airport an aircraft turns every which way; in between it should point at its destination.
    if ($flown > 60 && $left > 100) {
        $off = abs(fmod($aircraft['track'] - $toward + 540, 360) - 180);
        if ($off > 100) {
            return false;
        }
    }
    return true;
}

/**
 * Reads or writes what the flight screen remembers between requests: the
 * selected aircraft ('selected', an id or '') and where each aircraft was
 * drawn last time ('drawn', id => [x, y]), which is how a tap is matched
 * to an aircraft. One display is assumed.
 */
function flight_state(array $config, ?array $save = null): array
{
    $file = $config['cache_dir'] . '/flight_state.json';
    if ($save !== null) {
        @file_put_contents($file, json_encode($save), LOCK_EX);
        return $save;
    }
    $state = json_decode((string) @file_get_contents($file), true);
    return is_array($state) ? $state + ['selected' => '', 'drawn' => []] : ['selected' => '', 'drawn' => []];
}

/**
 * Picks the aircraft to show in the card. A tap selects the aircraft drawn
 * nearest to it; the selection stays while that aircraft is in range;
 * otherwise the nearest aircraft is shown. Returns [index, was it chosen by tap?].
 */
function select_aircraft(array $aircraft, array $state, ?array $tap, bool $reset): array
{
    $selected = $reset ? '' : (string) $state['selected'];
    if ($tap !== null) {
        $best = 90.0;                    // a tap counts within this many pixels of an aircraft
        foreach ($state['drawn'] as $id => [$x, $y]) {
            $d = hypot($x - $tap[0], $y - $tap[1]);
            if ($d < $best) {
                $best = $d;
                $selected = (string) $id;
            }
        }
    }
    foreach ($aircraft as $i => $a) {
        if ($a['id'] === $selected) {
            return [$i, true];
        }
    }
    return [0, false];
}

/**
 * A plain-text report for troubleshooting routes, shown at
 * /flights.php?debug=1: every aircraft in range, what each route source
 * answered for it, and whether that answer was accepted.
 */
function flight_debug_text(array $config): string
{
    $aircraft = load_aircraft($config);
    if ($aircraft === null) {
        return "Ingen kontakt med flytjenesten.\n";
    }
    $lines = [count($aircraft) . t(' fly innen ') . $config['flight_radius'] . ' km'];
    $map = basemap_file($config, (int) $config['flight_radius'], 1120);
    $lines[] = 'Kart: ' . ($map === null ? 'ingen' : (str_ends_with($map, '_kartverket.png') ? 'silhuett fra Kartverket' : 'grov kystlinje (reserve)'));
    foreach ($aircraft as $a) {
        $lines[] = '';
        $lines[] = sprintf('%s  %s  %d km unna, kurs %d°, %d m', $a['call'], $a['type'], round($a['km']), round($a['track']), $a['alt']);
        if (!preg_match('/^[A-Z]{3}\d[A-Z0-9]{0,4}$/', $a['call'])) {
            $lines[] = '  ikke et rutefly-kallesignal, ingen rute slås opp';
            continue;
        }
        $sources = ['adsb.lol' => routes_from_adsblol($config, $a), 'adsbdb' => routes_from_adsbdb($config, $a['call'])];
        foreach ($sources as $name => $routes) {
            if ($routes === []) {
                $lines[] = "  $name: ingen rute";
            }
            foreach ($routes as $r) {
                $lines[] = sprintf(
                    '  %s: %s (%s) -> %s (%s): %s',
                    $name,
                    $r['from']['city'],
                    $r['from']['code'],
                    $r['to']['city'],
                    $r['to']['code'],
                    route_is_plausible($r, $a) ? 'godtatt' : 'forkastet, passer ikke med posisjon og kurs'
                );
            }
        }
        // The raw answers from adsb.lol's two addresses, since their formats are not documented.
        foreach (['route_vrs_' => 'adsb.lol'] as $prefix => $label) {
            $file = $config['cache_dir'] . '/' . $prefix . strtolower($a['call']) . '.json';
            $raw = @file_get_contents($file);
            $error = @file_get_contents($file . '.err');
            if ($raw === false && $error === false) {
                continue;                // not asked
            }
            $lines[] = "  $label svarte: " . substr(preg_replace('/\s+/', ' ', $raw !== false ? $raw : '(ikke brukbart) ' . $error), 0, 500);
        }
    }
    return implode("\n", $lines) . "\n";
}
