<?php

declare(strict_types=1);

/*
 * Renders every screen with sample data, without contacting MET Norway.
 *
 *   php tools/preview.php              writes PNGs to docs/screenshots
 *   php tools/preview.php some/folder  writes them somewhere else
 *   php tools/preview.php some/folder yr   ... using another icon set
 *   php tools/preview.php some/folder yr poppins   ... and another typeface
 *   php tools/preview.php some/folder outline inter en   ... in English
 *
 * Run it after changing a screen to see the result, and to refresh the
 * screenshots in the README.
 */

$root = dirname(__DIR__);
require $root . '/server/src/bootstrap.php';
require __DIR__ . '/sample-data.php';

$out = $argv[1] ?? $root . '/docs/screenshots';
if (!is_dir($out)) {
    mkdir($out, 0775, true);
}

// A neutral configuration: the previews never use your own settings.
$config = [
    'place'       => 'Skien',
    'lat'         => 59.2096,
    'lon'         => 9.6090,
    'contact'     => 'preview',
    'icon_set'    => $argv[2] ?? 'outline',
    'font'        => $argv[3] ?? 'inter',
    'language'    => $argv[4] ?? 'no',
    'timezone'    => 'Europe/Oslo',
    'width'       => 1072,
    'height'      => 1448,
    'min_refetch' => 600,
    'font_dir'    => $root . '/server/fonts',
    'icon_dir'    => $root . '/server/icons',
    'fonts'       => font_catalog($root . '/server'),
    'cache_dir'   => sys_get_temp_dir() . '/kindle-weather-preview-' . getmypid(),
] + SETTING_DEFAULTS;
language($config['language']);
date_default_timezone_set($config['timezone']);
ini_set('memory_limit', '256M');

// file name => [query string, true = with a weather alert, 'aurora' = with an aurora notice]
$screens = [
    'now'         => ['view=now&batt=84&x=1', false],
    'big'         => ['view=big&batt=84&x=1', false],
    'graph'       => ['view=graph&batt=84&x=1', false],
    'week'        => ['view=week&batt=84&x=1', false],
    'hours'       => ['view=hours&batt=84&x=1', false],
    'hours-2'     => ['view=hours&page=1&batt=84&x=1', false],
    'alert'       => ['view=now&batt=84&x=1', true],
    'aurora'      => ['view=now&batt=84&x=1', 'aurora'],
    'low-battery' => ['view=now&batt=12&x=1', false],
    'night'       => ['view=night&wake=06:00&batt=84&x=1', false],
    'offline'     => ['view=offline&interval=10&batt=84&x=1', false],
    'closing'     => ['view=closing', false],
];

foreach ($screens as $name => [$queryString, $alert]) {
    write_sample_data($config, $alert === true, $alert === 'aurora');
    parse_str($queryString, $query);
    file_put_contents("$out/$name.png", render_request($config, $query));
    echo "$out/$name.png\n";
}

// The flight screen: just opened, after a tap on the helicopter, and an empty sky.
// It is drawn on the coarse fallback map; a real server uses Kartverket's map.
touch(sprintf('%s/map_v3_%.4F_%.4F_%d_%d_kartverket.failed', $config['cache_dir'], $config['lat'], $config['lon'], $config['flight_radius'], 1120));
write_sample_flights($config);
file_put_contents("$out/flights.png", render_flight_request($config, ['batt' => '84', 'x' => '1', 'open' => '1']));
$state = flight_state($config);
[$x, $y] = $state['drawn']['478001'];
file_put_contents("$out/flights-tapped.png", render_flight_request($config, ['batt' => '84', 'x' => '1', 'tap' => "$x,$y"]));
write_sample_flights($config, true);
file_put_contents("$out/flights-empty.png", render_flight_request($config, ['batt' => '84', 'x' => '1']));
echo "$out/flights.png\n$out/flights-tapped.png\n$out/flights-empty.png\n";

// Remove the temporary cache.
array_map('unlink', glob($config['cache_dir'] . '/*') ?: []);
@unlink($config['cache_dir'] . '/.htaccess');
@rmdir($config['cache_dir']);
