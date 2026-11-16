<?php

declare(strict_types=1);

/*
 * Helpers that make a new installation easy: a check that the web host has
 * what the display needs, and a Kindle script with this server's address
 * already filled in.
 */

/**
 * What this web host is missing, as messages for the admin page. Empty
 * when everything is in place.
 */
function requirement_problems(string $serverDir): array
{
    $problems = [];
    if (!extension_loaded('gd') || !function_exists('imagettftext')) {
        $problems[] = t('PHP mangler utvidelsen «gd» med FreeType, som tegner bildene. Be webhotellet slå den på.');
    }
    if (!function_exists('gzcompress')) {
        $problems[] = t('PHP mangler utvidelsen «zlib».');
    }
    if (!extension_loaded('openssl') || !ini_get('allow_url_fopen')) {
        $problems[] = t('PHP får ikke hente data over HTTPS (trenger «openssl» og «allow_url_fopen»). Uten det kommer det ikke noe værvarsel.');
    }
    foreach (['data', 'cache'] as $folder) {
        $path = $serverDir . '/' . $folder;
        if (!(is_dir($path) ? is_writable($path) : is_writable($serverDir))) {
            $problems[] = t('Serveren får ikke skrive til mappen «') . $folder . t('». Gi PHP skrivetilgang til mappen der index.php ligger.');
        }
    }
    return $problems;
}

/**
 * The address of this installation as the Kindle should use it, such as
 * https://example.no/vaer/. Null when it cannot be worked out safely: the
 * result is written into a shell script, so only plain characters pass.
 */
function site_url(): ?string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
    if (!preg_match('/^[A-Za-z0-9.-]{1,200}(:\d{1,5})?$/', $host) || !preg_match('#^/[A-Za-z0-9/_.~-]*$#', $path)) {
        return null;
    }
    // Behind a proxy or CDN, PHP itself may be reached over plain HTTP, so
    // every common sign of an HTTPS visit is checked.
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on'
        || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https');
    return ($https ? 'https' : 'http') . '://' . $host . $path;
}

/** The version of the Kindle script that this server hands out, or '' when the file is missing. */
function script_version(string $serverDir): string
{
    preg_match('/^SCRIPT_VERSION=(\S+)/m', read_file($serverDir . '/kindle/kindle-weather.sh'), $match);
    return preg_match(VERSION_PATTERN, $match[1] ?? '') === 1 ? $match[1] : '';
}

/**
 * The version of the script the Kindle runs: it says so each time it asks
 * for its settings. Give $reported to store it; returns the last one stored,
 * or '' when the Kindle has not said (a script from before v20261116).
 */
function kindle_version(array $config, ?string $reported = null): string
{
    $file = $config['cache_dir'] . '/kindle_version.txt';
    if ($reported !== null && preg_match(VERSION_PATTERN, $reported) === 1 && is_dir($config['cache_dir'])) {
        @file_put_contents($file, $reported, LOCK_EX);
    }
    $stored = trim(read_file($file));
    return preg_match(VERSION_PATTERN, $stored) === 1 ? $stored : '';
}

/**
 * The Kindle script with SERVER_URL set to $url, ready to copy to the
 * Kindle. Null if the script is missing or has no SERVER_URL line.
 */
function kindle_script(string $serverDir, string $url): ?string
{
    $script = read_file($serverDir . '/kindle/kindle-weather.sh');
    if ($script === '') {
        return null;
    }
    $script = preg_replace('/^SERVER_URL=.*$/m', 'SERVER_URL="' . $url . '"', $script, 1, $count);
    // The language of the script's own messages, and its name in the Kindle library.
    if (language() === 'en') {
        $script = preg_replace('/^LANGUAGE=.*$/m', 'LANGUAGE=en', $script, 1);
        $script = preg_replace('/^# Name: .*$/m', '# Name: Weather', $script, 1);
    }
    // The Kindle only runs scripts with Unix line endings.
    return $count === 1 ? str_replace("\r\n", "\n", $script) : null;
}
