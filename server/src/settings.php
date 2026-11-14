<?php

declare(strict_types=1);

/*
 * Settings changed on the admin page (admin.php).
 *
 * They are stored in data/store.php as a PHP file that returns an array.
 * A PHP file cannot be read through the web server the way a .json file
 * could, so the address, the email and the password hash stay private on
 * any host, with or without .htaccess support.
 *
 * Where a setting comes from, later ones winning:
 *   1. SETTING_DEFAULTS below
 *   2. config.php, if it exists
 *   3. the admin page
 */

// The icon sets on offer: folder or name => label on the admin page.
const ICON_SETS = [
    'outline'   => 'Strek',
    'duotone'   => 'Strek med gråtone',
    'yr'        => 'Yr (Meteorologisk institutt)',
    'meteocons' => 'Meteocons, to toner',
    'bold'      => 'Kraftig',
];

// The typefaces on offer: name => label on the admin page, the two font
// files (regular and bold weight, in the fonts folder), and a size factor
// that evens out how large each typeface runs so the layouts still fit.
const FONTS = [
    'inter'    => ['label' => 'Inter',                 'Medium' => 'Inter-Medium.otf',                 'Bold' => 'Inter-Bold.otf',                'scale' => 1.0],
    'atkinson' => ['label' => 'Atkinson Hyperlegible', 'Medium' => 'AtkinsonHyperlegible-Regular.ttf', 'Bold' => 'AtkinsonHyperlegible-Bold.ttf', 'scale' => 1.0],
    'poppins'  => ['label' => 'Poppins',               'Medium' => 'Poppins-Medium.ttf',               'Bold' => 'Poppins-Bold.ttf',              'scale' => 0.95],
    'barlow'   => ['label' => 'Barlow Condensed',      'Medium' => 'BarlowCondensed-Medium.ttf',       'Bold' => 'BarlowCondensed-Bold.ttf',      'scale' => 1.06],
    'spectral' => ['label' => 'Spectral',              'Medium' => 'Spectral-Medium.ttf',              'Bold' => 'Spectral-Bold.ttf',             'scale' => 1.0],
    'plexmono' => ['label' => 'IBM Plex Mono',         'Medium' => 'IBMPlexMono-Medium.ttf',           'Bold' => 'IBMPlexMono-Bold.ttf',          'scale' => 0.84],
];

const SETTING_DEFAULTS = [
    // Shown in the header.
    'place'              => '',
    'area'               => '',
    // Location of the forecast. null = not set up yet.
    'lat'                => null,
    'lon'                => null,
    // Sent to MET Norway to identify this client.
    'contact'            => '',
    'language'           => 'no',      // 'no' or 'en': the language on the screens and the admin page
    'icon_set'           => 'outline',
    'font'               => 'inter',
    // Tabs along the bottom, in order, and the one shown first.
    'tabs'               => ['now', 'big', 'graph', 'week', 'hours'],
    'default_view'       => 'now',
    // Settings the Kindle reads on every refresh (see device_settings_text).
    'interval'           => 10,      // minutes between refreshes
    'quiet_start'        => 0,       // quiet hours, 24-hour clock; equal = off
    'quiet_end'          => 6,
    'full_refresh_every' => 6,       // every Nth refresh flashes the screen
    'flights'            => true,    // show the button for the flight screen
    'flight_radius'      => 50,      // how far the flight screen looks, in km
    'aurora'             => true,    // show a notice when northern lights are possible
    'aurora_kp'          => 5,       // ... which means a forecast Kp of at least this
    'light_level'        => 0,       // front light 0-24; 0 = always off
    'light_from'         => 16,
    'light_to'           => 24,
];

function store_file(string $serverDir): string
{
    return $serverDir . '/data/store.php';
}

/**
 * Reads the store: ['password_hash', 'settings', 'failures', 'locked_until'].
 */
function load_store(string $serverDir): array
{
    $file = store_file($serverDir);
    $store = is_file($file) ? (include $file) : [];
    return (is_array($store) ? $store : []) + [
        'password_hash' => '',
        'settings'      => [],
        'google_fonts'  => [],
        'failures'      => 0,
        'locked_until'  => 0,
    ];
}

/** Writes the store. Returns false when the data folder is not writable. */
function save_store(string $serverDir, array $store): bool
{
    $dir = $serverDir . '/data';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    $file = store_file($serverDir);
    $ok = @file_put_contents($file, "<?php\n\n// Written by admin.php. Do not edit by hand.\nreturn " . var_export($store, true) . ";\n", LOCK_EX) !== false;
    if ($ok && function_exists('opcache_invalidate')) {
        opcache_invalidate($file, true);
    }
    return $ok;
}

/**
 * Checks values submitted on the admin page. Returns the cleaned settings
 * and fills $errors with one message per problem (in the language in use, for the page).
 */
function validate_settings(array $in, array &$errors, array $fonts = FONTS): array
{
    $text = function (string $key, int $max) use ($in): string {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($in[$key] ?? '')) ?? '');
        return mb_substr($value, 0, $max);
    };
    $int = function (string $key, int $min, int $max, string $label) use ($in, &$errors): int {
        $raw = trim((string) ($in[$key] ?? ''));
        if (!preg_match('/^\d{1,4}$/', $raw) || (int) $raw < $min || (int) $raw > $max) {
            $errors[] = sprintf(t('%s må være et tall fra %d til %d.'), t($label), $min, $max);
            return (int) SETTING_DEFAULTS[$key];
        }
        return (int) $raw;
    };
    $coordinate = function (string $key, float $limit, string $label) use ($in, &$errors): ?float {
        $raw = str_replace(',', '.', trim((string) ($in[$key] ?? '')));
        if (!is_numeric($raw) || abs((float) $raw) > $limit) {
            $errors[] = sprintf(t('%s må være et tall mellom -%d og %d.'), t($label), $limit, $limit);
            return null;
        }
        return round((float) $raw, 4);       // MET Norway allows at most four decimals
    };

    $out = [
        'place'   => $text('place', 40),
        'area'    => $text('area', 40),
        'lat'     => $coordinate('lat', 90, 'Breddegrad'),
        'lon'     => $coordinate('lon', 180, 'Lengdegrad'),
        'contact' => $text('contact', 100),
    ];
    if ($out['place'] === '') {
        $errors[] = t('Stedsnavn mangler.');
    }
    if ($out['contact'] === '') {
        $errors[] = t('Kontakt mangler. Meteorologisk institutt krever e-post eller nettsted.');
    }

    $out['icon_set'] = (string) ($in['icon_set'] ?? '');
    if (!isset(ICON_SETS[$out['icon_set']])) {
        $errors[] = t('Ukjent ikonsett.');
        $out['icon_set'] = SETTING_DEFAULTS['icon_set'];
    }

    $out['language'] = isset(LANGUAGES[$in['language'] ?? '']) ? (string) $in['language'] : SETTING_DEFAULTS['language'];

    $out['font'] = (string) ($in['font'] ?? '');
    if (!isset($fonts[$out['font']])) {
        $errors[] = t('Ukjent skrift.');
        $out['font'] = SETTING_DEFAULTS['font'];
    }

    // Tabs keep the order of TAB_LABELS; at least one must be on.
    $chosen = array_map('strval', (array) ($in['tabs'] ?? []));
    $out['tabs'] = array_values(array_filter(array_keys(TAB_LABELS), fn($key) => in_array($key, $chosen, true)));
    if ($out['tabs'] === []) {
        $errors[] = t('Velg minst én fane.');
        $out['tabs'] = SETTING_DEFAULTS['tabs'];
    }
    $out['default_view'] = (string) ($in['default_view'] ?? '');
    if (!in_array($out['default_view'], $out['tabs'], true)) {
        $out['default_view'] = $out['tabs'][0];
    }

    $out['interval']           = $int('interval', 5, 120, 'Oppdatering');
    $out['quiet_start']        = $int('quiet_start', 0, 23, 'Stilletid fra');
    $out['quiet_end']          = $int('quiet_end', 0, 23, 'Stilletid til');
    $out['full_refresh_every'] = $int('full_refresh_every', 1, 20, 'Full oppfrisking');
    $out['light_level']        = $int('light_level', 0, 24, 'Lysstyrke');
    $out['flights']            = !empty($in['flights']);
    $out['flight_radius']      = $int('flight_radius', 10, 200, 'Rekkevidde for fly');
    $out['aurora']             = !empty($in['aurora']);
    $out['aurora_kp']          = $int('aurora_kp', 3, 9, 'Kp-grense');
    $out['light_from']         = $int('light_from', 0, 24, 'Lys fra');
    $out['light_to']           = $int('light_to', 0, 24, 'Lys til');
    return $out;
}

/**
 * The settings the Kindle script reads, as KEY=value lines. Values hold only
 * lowercase letters, digits and spaces; the script refuses anything else.
 */
function device_settings_text(array $config): string
{
    $lines = [
        'TABS'               => implode(' ', $config['tabs']),
        'DEFAULT_VIEW'       => $config['default_view'],
        'INTERVAL_MIN'       => $config['interval'],
        'QUIET_START'        => $config['quiet_start'],
        'QUIET_END'          => $config['quiet_end'],
        'FULL_REFRESH_EVERY' => $config['full_refresh_every'],
        'LIGHT_LEVEL'        => $config['light_level'],
        'LIGHT_FROM'         => $config['light_from'],
        'LIGHT_TO'           => $config['light_to'],
        'FLIGHTS'            => $config['flights'] ? 1 : 0,
        'LANGUAGE'           => $config['language'],
    ];
    $text = '';
    foreach ($lines as $key => $value) {
        $text .= $key . '=' . $value . "\n";
    }
    return $text;
}
