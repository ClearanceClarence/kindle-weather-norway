<?php

declare(strict_types=1);

/*
 * The map under the flight screen.
 *
 * In Norway it is a silhouette of land and water, worked out once per
 * location from Kartverket's topographic map and kept. Elsewhere, or when Kartverket
 * cannot be reached, a coarse coastline from Natural Earth (map/coast.json)
 * is drawn instead.
 *
 * Both use the same projection (web mercator), so aircraft land in the same
 * place whichever map is underneath.
 */

// Kartverket's topographic map in color, as tiles: zoom, row, column.
// Another tile server with the same layout can be set as 'map_tiles' in
// config.php, as long as its water is blue.
const KARTVERKET_TILES = 'https://cache.kartverket.no/v1/wmts/1.0.0/topo/default/webmercator/%d/%d/%d.png';

// A map pixel counts as water when its blue exceeds its red by at least this much.
const WATER_BLUENESS = 18;

/** A position as kilometers east and north of home, in the map's projection. */
function map_offset_km(array $config, float $lat, float $lon): array
{
    $home = mercator((float) $config['lat'], (float) $config['lon']);
    $point = mercator($lat, $lon);
    $unitsPerKm = 1 / (40075.017 * cos(deg2rad((float) $config['lat'])));
    return [($point[0] - $home[0]) / $unitsPerKm, ($home[1] - $point[1]) / $unitsPerKm];
}

/** Web mercator: latitude and longitude => x, y, each from 0 to 1 across the world map. */
function mercator(float $lat, float $lon): array
{
    $lat = max(-85.0, min(85.0, $lat));
    return [($lon + 180) / 360, (1 - asinh(tan(deg2rad($lat))) / M_PI) / 2];
}

/**
 * The file name of a round map picture, $size pixels across, covering
 * $radius km around home. Made on first use and kept in the cache folder.
 * Null if no map could be made at all.
 */
function basemap_file(array $config, int $radius, int $size): ?string
{
    // "v3" in the name: earlier versions kept the whole street map, so
    // their pictures are made again.
    $base = sprintf('%s/map_v3_%.4F_%.4F_%d_%d', $config['cache_dir'], $config['lat'], $config['lon'], $radius, $size);
    if (is_file($base . '_kartverket.png')) {
        return $base . '_kartverket.png';
    }

    // Kartverket covers Norway. After a failure, wait six hours before trying again.
    $inNorway = $config['lat'] > 57.5 && $config['lat'] < 71.6 && $config['lon'] > 4.0 && $config['lon'] < 31.6;
    $failed = $base . '_kartverket.failed';
    if ($inNorway && (!is_file($failed) || time() - filemtime($failed) > 6 * 3600)) {
        $map = kartverket_map($config, $radius, $size);
        if ($map !== null) {
            round_off($map, $size);
            imagepng($map, $base . '_kartverket.png');
            return $base . '_kartverket.png';
        }
        @touch($failed);
    }

    if (!is_file($base . '_coast.png')) {
        $map = coast_map($config, $radius, $size);
        if ($map === null) {
            return null;
        }
        round_off($map, $size);
        imagepng($map, $base . '_coast.png');
    }
    return $base . '_coast.png';
}

/**
 * A silhouette of land and water for the square around home, made from
 * Kartverket's topographic map: everything the map colors blue (sea, lakes,
 * wide rivers) becomes white, everything else light gray, with a darker
 * line along the shore. Roads, names and contour lines disappear. Null if
 * any tile is missing.
 */
function kartverket_map(array $config, int $radius, int $size): ?\GdImage
{
    // The zoom level whose tiles are at least as detailed as the picture needs.
    $kmAcrossWorld = 40075.017 * cos(deg2rad((float) $config['lat']));
    $zoom = 5;
    while ($zoom < 12 && 256 * 2 ** $zoom / $kmAcrossWorld < $size / (2 * $radius) / 2) {
        $zoom++;
    }
    $world = 256 * 2 ** $zoom;                              // the world map's width in tile pixels
    [$hx, $hy] = mercator((float) $config['lat'], (float) $config['lon']);
    $half = $radius * $world / $kmAcrossWorld;              // radius in tile pixels
    $left = $hx * $world - $half;
    $top = $hy * $world - $half;

    $firstX = (int) floor($left / 256);
    $firstY = (int) floor($top / 256);
    $lastX = (int) floor(($left + 2 * $half) / 256);
    $lastY = (int) floor(($top + 2 * $half) / 256);
    if (($lastX - $firstX + 1) * ($lastY - $firstY + 1) > 36) {
        return null;
    }

    // Water-colored underneath: outside Norway's waters the tiles are
    // transparent, and that is open sea.
    $sheet = imagecreatetruecolor(($lastX - $firstX + 1) * 256, ($lastY - $firstY + 1) * 256);
    imagefilledrectangle($sheet, 0, 0, imagesx($sheet), imagesy($sheet), 0xBEE0F5);
    $pattern = $config['map_tiles'] ?? KARTVERKET_TILES;
    $context = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: kindle-weather-norway\r\n"]]);
    for ($ty = $firstY; $ty <= $lastY; $ty++) {
        for ($tx = $firstX; $tx <= $lastX; $tx++) {
            $png = @file_get_contents(sprintf($pattern, $zoom, $ty, $tx), false, $context);
            $tile = $png === false ? false : @imagecreatefromstring($png);
            if ($tile === false) {
                return null;
            }
            imagecopy($sheet, $tile, ($tx - $firstX) * 256, ($ty - $firstY) * 256, 0, 0, 256, 256);
        }
    }

    // Work at half the final size: shrinking the map blends thin lines
    // (roads, ferry routes, lettering) into what lies around them, so they
    // do not end up as specks in the silhouette.
    $n = intdiv($size, 2);
    $small = imagecreatetruecolor($n, $n);
    imagecopyresampled(
        $small,
        $sheet,
        0,
        0,
        (int) round($left - $firstX * 256),
        (int) round($top - $firstY * 256),
        $n,
        $n,
        (int) round(2 * $half),
        (int) round(2 * $half)
    );

    // Water is whatever is clearly more blue than red.
    $water = [];
    for ($y = 0; $y < $n; $y++) {
        $row = '';
        for ($x = 0; $x < $n; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $row .= ($b - $r >= WATER_BLUENESS && $b >= $g - 4) ? '1' : '0';
        }
        $water[] = $row;
    }

    $silhouette = imagecreatetruecolor($n, $n);
    imagefilledrectangle($silhouette, 0, 0, $n, $n, 0xFFFFFF);
    $land = imagecolorallocate($silhouette, 226, 226, 226);
    $shore = imagecolorallocate($silhouette, 150, 150, 150);
    for ($y = 0; $y < $n; $y++) {
        $row = $water[$y];
        $above = $water[max(0, $y - 1)];
        $below = $water[min($n - 1, $y + 1)];
        for ($x = 0; $x < $n; $x++) {
            if ($row[$x] === '1') {
                continue;
            }
            $atShore = $above[$x] === '1' || $below[$x] === '1'
                || ($x > 0 && $row[$x - 1] === '1') || ($x < $n - 1 && $row[$x + 1] === '1');
            imagesetpixel($silhouette, $x, $y, $atShore ? $shore : $land);
        }
    }

    $map = imagecreatetruecolor($size, $size);
    imagecopyresampled($map, $silhouette, 0, 0, 0, 0, $size, $size, $n, $n);
    return $map;
}

/** The coarse fallback map: light gray land, white sea and lakes. Null without map/coast.json. */
function coast_map(array $config, int $radius, int $size): ?\GdImage
{
    $coast = json_decode(read_file(dirname($config['icon_dir']) . '/map/coast.json'), true);
    if (!is_array($coast)) {
        return null;
    }
    $map = imagecreatetruecolor($size, $size);
    imageantialias($map, true);
    imagefilledrectangle($map, 0, 0, $size, $size, 0xFFFFFF);
    $land = imagecolorallocate($map, 226, 226, 226);
    $shore = imagecolorallocate($map, 150, 150, 150);
    $water = imagecolorallocate($map, 255, 255, 255);

    $scale = $size / (2 * $radius);
    $degrees = $radius / 111.0 * 1.6;                       // generous box, in degrees of latitude
    $draw = function (array $rings, int $fill) use ($map, $config, $scale, $size, $degrees, $shore): void {
        foreach ($rings as $ring) {
            $near = false;
            foreach ($ring as [$lon, $lat]) {
                if (abs($lat - $config['lat']) < $degrees && abs($lon - $config['lon']) < $degrees * 2.2) {
                    $near = true;
                    break;
                }
            }
            if (!$near || count($ring) < 3) {
                continue;
            }
            $points = [];
            foreach ($ring as [$lon, $lat]) {
                [$east, $north] = map_offset_km($config, (float) $lat, (float) $lon);
                // Far-away corners of big shapes are pulled in so the numbers stay small.
                $points[] = (int) round(max(-4 * $size, min(5 * $size, $size / 2 + $east * $scale)));
                $points[] = (int) round(max(-4 * $size, min(5 * $size, $size / 2 - $north * $scale)));
            }
            imagefilledpolygon($map, $points, $fill);
            imagesetthickness($map, max(2, (int) round($size / 330)));
            imagepolygon($map, $points, $shore);
        }
    };
    $draw($coast['land'] ?? [], $land);
    $draw($coast['lakes'] ?? [], $water);
    return $map;
}

/**
 * The large and medium airports within $radius km of home, nearest first:
 * each with code, name, lat, lon and km. From map/airports.json.
 */
function nearby_airports(array $config, int $radius): array
{
    $data = json_decode(read_file(dirname($config['icon_dir']) . '/map/airports.json'), true);
    $lat = (float) $config['lat'];
    $lon = (float) $config['lon'];
    $reach = $radius / 111.0;                               // the radius in degrees of latitude
    $found = [];
    foreach ($data['airports'] ?? [] as [$code, $aLat, $aLon, $large, $name]) {
        // A quick box test first; the list covers the whole world.
        if (abs($aLat - $lat) > $reach || abs($aLon - $lon) > $reach * 2.5) {
            continue;
        }
        [$km] = distance_bearing($lat, $lon, (float) $aLat, (float) $aLon);
        if ($km <= $radius) {
            $found[] = ['code' => (string) $code, 'name' => (string) $name, 'lat' => (float) $aLat, 'lon' => (float) $aLon, 'km' => $km];
        }
    }
    usort($found, fn($a, $b) => $a['km'] <=> $b['km']);
    return $found;
}

/** Paints everything outside the circle white, so the map becomes round. */
function round_off(\GdImage $map, int $size): void
{
    $r = $size / 2;
    for ($y = 0; $y < $size; $y++) {
        $dy = $y + 0.5 - $r;
        $halfWidth = $dy * $dy < $r * $r ? sqrt($r * $r - $dy * $dy) : 0;
        imagefilledrectangle($map, 0, $y, (int) floor($r - $halfWidth), $y, 0xFFFFFF);
        imagefilledrectangle($map, (int) ceil($r + $halfWidth), $y, $size, $y, 0xFFFFFF);
    }
}
