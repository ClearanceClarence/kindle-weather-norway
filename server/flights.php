<?php

declare(strict_types=1);

/*
 * Kindle weather display: the flight screen.
 *
 * Returns "Fly over oss" as a PNG: a map of the aircraft overhead and a
 * card about one of them. The Kindle script requests it while the flight
 * screen is open; the plane button on the weather screens leads here.
 *
 *   /flights.php?batt=84&x=1      with a battery indicator and touch controls
 *   /flights.php?tap=530,412      the user tapped there: select the aircraft drawn nearest
 *   /flights.php?open=1           just opened: forget the earlier selection
 *   /flights.php?debug=1          plain text: what the route sources answered for each aircraft
 *
 * The data is fetched in src/flights.php, the map in src/flightmap.php, and
 * the drawing is in src/views/flights.php.
 */

if (PHP_VERSION_ID < 80100) {
    header('Content-Type: text/plain; charset=utf-8');
    exit('Værvarsel trenger PHP 8.1 eller nyere. Denne serveren har PHP ' . PHP_VERSION . ".\n");
}

require __DIR__ . '/src/bootstrap.php';

try {
    $config = load_config(__DIR__);
    if (isset($_GET['debug'])) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo flight_debug_text($config);
        exit;
    }
    $png = render_flight_request($config, $_GET);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Værvarsel: ' . $e->getMessage() . "\n";
    exit;
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: no-store');
echo $png;
