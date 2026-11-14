<?php

declare(strict_types=1);

/*
 * MET Norway client.
 *
 * Downloads JSON from api.met.no and keeps it on disk until MET says it has
 * expired. Three products are used:
 *
 *   Locationforecast  the main forecast (load_forecast)
 *   Nowcast           radar precipitation for the next two hours (load_nowcast)
 *   MetAlerts         weather alerts in force (load_alerts)
 *
 * One more document comes from NOAA's Space Weather Prediction Center: the
 * forecast of geomagnetic activity (load_kp_forecast), for the aurora notice.
 *
 * MET's terms of service: identify yourself in the User-Agent, use at most
 * four decimals in coordinates, and do not fetch more often than needed.
 * https://api.met.no/doc/TermsOfService
 */

/**
 * Where a cached document lives, without its extension. The name includes
 * the coordinates, so changing location never shows the old place's data.
 */
function cache_path(array $config, string $name): string
{
    return sprintf('%s/%s_%.4F_%.4F', $config['cache_dir'], $name, $config['lat'], $config['lon']);
}

/**
 * Downloads a JSON document, with an on-disk cache that follows the
 * Expires / Last-Modified headers the server sends. $identify adds the
 * contact address to the User-Agent, as MET Norway requires.
 *
 * Returns ['data' => decoded JSON, 'fetched' => unix time], or null when
 * there is neither a fresh download nor a cached copy.
 */
function cached_fetch(array $config, string $name, string $url, int $minRefetch, int $timeout = 8, bool $identify = true): ?array
{
    // Once MET has failed to answer during this request, the other products
    // are not tried: a picture from saved data now beats a complete one late.
    static $metIsDown = false;

    if (!is_dir($config['cache_dir'])) {
        @mkdir($config['cache_dir'], 0775, true);
        @file_put_contents($config['cache_dir'] . '/.htaccess', "Require all denied\n");
    }
    $jsonFile = cache_path($config, $name) . '.json';
    $metaFile = cache_path($config, $name) . '.meta.json';
    $meta = is_file($metaFile) ? (json_decode((string) file_get_contents($metaFile), true) ?: []) : [];
    $now = time();
    $haveCache = is_file($jsonFile);

    $fresh = $haveCache && $now < (int) ($meta['expires'] ?? 0);
    // Back off after an attempt: one minute normally, five after a failure.
    $wait = ($meta['status'] ?? 200) === 200 || ($meta['status'] ?? 0) === 304 ? 60 : 300;
    $tooSoon = $now - (int) ($meta['attempted'] ?? 0) < $wait;

    if (!$fresh && !$tooSoon && !$metIsDown) {
        $started = microtime(true);
        $headers = [
            // The contact address is for MET Norway only.
            'User-Agent: KindleWeatherDisplay/1.0' . ($identify ? ' ' . $config['contact'] : ''),
            'Accept: application/json',
        ];
        if ($haveCache && !empty($meta['last_modified'])) {
            $headers[] = 'If-Modified-Since: ' . $meta['last_modified'];
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", $headers),
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);

        $status = 0;
        $parsed = [];
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            } elseif (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $parsed[strtolower(trim($k))] = trim($v);
            }
        }

        $meta['attempted'] = $now;
        $meta['status'] = $status;                       // 0 = no answer
        $meta['seconds'] = round(microtime(true) - $started, 1);
        if ($status === 0) {
            $metIsDown = true;
        }
        $expires = isset($parsed['expires']) ? (int) strtotime($parsed['expires']) : 0;
        $expires = max($expires, $now + $minRefetch);

        if ($status === 200 && $body !== false && json_decode($body, true) !== null) {
            file_put_contents($jsonFile, $body, LOCK_EX);
            $meta['fetched'] = $now;
            $meta['expires'] = $expires;
            $meta['last_modified'] = $parsed['last-modified'] ?? null;
            $haveCache = true;
        } elseif ($status === 304 && $haveCache) {
            $meta['fetched'] = $now;
            $meta['expires'] = $expires;
        }
        file_put_contents($metaFile, json_encode($meta), LOCK_EX);
    }

    if (!$haveCache) {
        return null;
    }
    $data = json_decode((string) file_get_contents($jsonFile), true);
    if (!is_array($data)) {
        return null;
    }
    return ['data' => $data, 'fetched' => (int) ($meta['fetched'] ?? filemtime($jsonFile))];
}

function met_url(array $config, string $path, string $extra = ''): string
{
    return sprintf('https://api.met.no/weatherapi/%s?lat=%.4F&lon=%.4F%s', $path, $config['lat'], $config['lon'], $extra);
}

/** The main forecast (hourly for about two days, then six-hourly). */
function load_forecast(array $config): ?array
{
    $r = cached_fetch($config, 'forecast', met_url($config, 'locationforecast/2.0/complete'), (int) $config['min_refetch']);
    return ($r !== null && !empty($r['data']['properties']['timeseries'])) ? $r : null;
}

/** Radar-based precipitation for the next two hours, in 5-minute steps. */
function load_nowcast(array $config): ?array
{
    $r = cached_fetch($config, 'nowcast', met_url($config, 'nowcast/2.0/complete'), 300, 4);
    // Ignore a copy that is too old to say anything about the next minutes.
    return ($r !== null && time() - $r['fetched'] < 1800) ? $r['data'] : null;
}

/** Weather alerts in force for this location. */
function load_alerts(array $config): ?array
{
    // MET writes its alerts in Norwegian and English; each language has its own cached copy.
    $lang = language() === 'en' ? 'en' : 'no';
    $r = cached_fetch($config, $lang === 'en' ? 'alerts_en' : 'alerts', met_url($config, 'metalerts/2.0/current.json', '&lang=' . $lang), 600, 4);
    return ($r !== null && time() - $r['fetched'] < 6 * 3600) ? $r['data'] : null;
}

/**
 * NOAA's forecast of the planetary K index (Kp), the usual measure of how
 * far south the northern lights reach: about three days in 3-hour steps.
 * Returns [unix time of step start => Kp], or null when unavailable.
 */
function load_kp_forecast(array $config): ?array
{
    $url = 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index-forecast.json';
    $r = cached_fetch($config, 'kp', $url, 3600, 4, false);
    if ($r === null || time() - $r['fetched'] > 12 * 3600) {
        return null;
    }
    // The document is a table: either a header row followed by rows of
    // [time, kp, ...], or a list of {time_tag, kp, ...} objects. Times are UTC.
    $steps = [];
    foreach ($r['data'] as $row) {
        $time = $row['time_tag'] ?? $row[0] ?? null;
        $kp = $row['kp'] ?? $row[1] ?? null;
        if (!is_string($time) || !is_numeric($kp)) {
            continue;                    // the header row, or something unexpected
        }
        $t = strtotime(str_replace('T', ' ', rtrim($time, 'Z')) . ' UTC');
        if ($t !== false) {
            $steps[$t] = (float) $kp;
        }
    }
    ksort($steps);
    return $steps === [] ? null : $steps;
}
