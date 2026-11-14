<?php

declare(strict_types=1);

/*
 * Kindle weather display: image server.
 *
 * Returns one screen as a 1072 x 1448, 8-bit grayscale PNG, drawn from MET
 * Norway's forecast. The Kindle script requests it on a schedule.
 *
 *   /                 the default screen
 *   /?view=graph      another screen; see src/views.php for the list
 *   /?batt=84&x=1     with a battery indicator and touch controls
 *   /?view=settings   the Kindle's settings, as text (see src/settings.php)
 *
 * All parameters are described in src/app.php. Settings are changed on the
 * admin page, admin.php.
 * Requires PHP 8.1+ with the gd (FreeType), zlib and openssl extensions.
 */

// Say so plainly on a PHP that is too old, instead of failing with a blank page.
if (PHP_VERSION_ID < 80100) {
    header('Content-Type: text/plain; charset=utf-8');
    exit('Værvarsel trenger PHP 8.1 eller nyere. Denne serveren har PHP ' . PHP_VERSION . ".\n");
}

require __DIR__ . '/src/bootstrap.php';

try {
    $config = load_config(__DIR__);
    if (($_GET['view'] ?? '') === 'settings') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo device_settings_text($config);
        exit;
    }
    if (($_GET['view'] ?? '') === 'status') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo status_text($config);
        exit;
    }
    $started = microtime(true);
    $png = render_request($config, $_GET);
    header('X-Render-Seconds: ' . round(microtime(true) - $started, 2));
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Værvarsel: ' . $e->getMessage() . "\n";
    exit;
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
// The samples on the admin page never change; everything else is always fresh.
$isSample = in_array($_GET['view'] ?? '', ['fonts', 'icons'], true);
header('Cache-Control: ' . ($isSample ? 'public, max-age=86400' : 'no-store'));
echo $png;
