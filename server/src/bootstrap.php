<?php

declare(strict_types=1);

/*
 * Loads the code and the configuration. Used by index.php and by the tools.
 */

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/fonts.php';
require_once __DIR__ . '/setup.php';
require_once __DIR__ . '/flights.php';
require_once __DIR__ . '/flightmap.php';
require_once __DIR__ . '/met.php';
require_once __DIR__ . '/weather.php';
require_once __DIR__ . '/model.php';
require_once __DIR__ . '/Canvas.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/chart.php';
require_once __DIR__ . '/views.php';
foreach (glob(__DIR__ . '/views/*.php') as $view) {
    require_once $view;
}
require_once __DIR__ . '/app.php';

/**
 * Puts the configuration together and applies the time zone. Settings come
 * from, later ones winning: the defaults, config.php, and the admin page.
 */
function load_config(string $serverDir): array
{
    $file = $serverDir . '/config.php';
    $fromFile = is_file($file) ? (array) (require $file) : [];
    $fromAdmin = load_store($serverDir)['settings'];

    $config = $fromAdmin + $fromFile + SETTING_DEFAULTS + [
        'timezone'    => 'Europe/Oslo',
        'width'       => 1072,
        'height'      => 1448,
        'min_refetch' => 600,
        'cache_dir'   => $serverDir . '/cache',
        'font_dir'    => $serverDir . '/fonts',
        'icon_dir'    => $serverDir . '/icons',
        'fonts'       => font_catalog($serverDir),
    ];
    // Guard against a hand-edited config.php naming things that do not exist.
    if (!isset($config['fonts'][$config['font']])) {
        $config['font'] = SETTING_DEFAULTS['font'];
    }
    if (!isset(ICON_SETS[$config['icon_set']])) {
        $config['icon_set'] = SETTING_DEFAULTS['icon_set'];
    }
    $config['tabs'] = array_values(array_intersect(array_keys(TAB_LABELS), (array) $config['tabs'])) ?: SETTING_DEFAULTS['tabs'];
    if (!in_array($config['default_view'], $config['tabs'], true)) {
        $config['default_view'] = $config['tabs'][0];
    }

    if (!isset(LANGUAGES[$config['language']])) {
        $config['language'] = SETTING_DEFAULTS['language'];
    }
    language($config['language']);

    date_default_timezone_set($config['timezone']);
    ini_set('memory_limit', '256M');     // the canvas is drawn at twice the screen size
    return $config;
}
