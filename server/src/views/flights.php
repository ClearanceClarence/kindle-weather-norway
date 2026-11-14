<?php

declare(strict_types=1);

/*
 * "Fly over oss": the aircraft overhead, drawn by flights.php.
 *
 * Top to bottom: a round map with home in the middle and every aircraft in
 * range; a card about one aircraft (the nearest, or the one tapped); a bar
 * that leads back to the weather.
 *
 * $aircraft comes from load_aircraft() (null = no contact with the feed),
 * $index is the aircraft shown in the card, $tapped whether the user chose
 * it, and $route its route from load_route() or null.
 *
 * Returns [PNG, where each aircraft was drawn], so a later tap can be
 * matched to an aircraft.
 */

const MAP_CENTER_X = 536;
const MAP_CENTER_Y = 530;
const MAP_RADIUS = 264;           // pixels

// What to call an aircraft whose airline is not known, by silhouette.
const FLIGHT_KIND_NAMES = [
    'jet2' => 'Jetfly', 'jet4' => 'Stort jetfly', 'tailjet' => 'Jetfly', 'prop2' => 'Propellfly',
    'prop4' => 'Propellfly', 'small' => 'Småfly', 'fighter' => 'Jagerfly', 'heli' => 'Helikopter',
];

/**
 * Draws the flight screen.
 *
 * @param array|null $aircraft the aircraft nearby, nearest first; null when the flight service did not answer
 * @param int        $index    which aircraft the card is about
 * @param bool       $tapped   true when the user picked that aircraft with a tap
 * @param array|null $route    its route, when known
 * @return array{0: string, 1: array} the PNG, and where each aircraft was drawn (id => [x, y])
 */
function render_flights(?array $aircraft, int $index, bool $tapped, ?array $route, array $request, array $config): array
{
    $canvas = new Canvas($config['width'], $config['height'], $config['font_dir']);
    $now = time();
    $date = date_text($now);
    draw_header($canvas, [$config['place'], $config['area'] ?? ''], $date, $request['batt'], $request['touch']);

    $radius = (int) $config['flight_radius'];
    $count = $aircraft === null ? '' : count($aircraft) . t(' fly innen ') . $radius . ' km';
    // The title stays clear of the map's "N" also when it is longer, as in English.
    $title = t('Fly over oss');
    $canvas->text($title, LEFT, 196, $canvas->fit($title, 56, 'Bold', MAP_CENTER_X - LEFT - 60), 'Bold');
    $canvas->text($count, RIGHT, 198, 32, 'Medium', GRAY, 'right');

    $drawn = [];
    $map = basemap_file($config, $radius, 4 * MAP_RADIUS);
    if ($aircraft === null || $aircraft === []) {
        draw_flight_map($canvas, $map, $radius);
        draw_airports($canvas, $config, $radius);
        $canvas->plane(536, 960, 70, 45, FAINT);
        $canvas->text($aircraft === null ? t('Får ikke kontakt med flytjenesten') : t('Ingen fly akkurat nå'), 536, 1100, 54, 'Bold', INK, 'center');
        $canvas->text(
            $aircraft === null ? t('Skjermen prøver igjen om et minutt.') : t('Himmelen er tom. Skjermen ser etter nye fly hvert minutt.'),
            536,
            1170,
            32,
            'Medium',
            GRAY,
            'center'
        );
    } else {
        draw_flight_map($canvas, $map, $radius);
        $airports = draw_airports($canvas, $config, $radius);
        $drawn = draw_aircraft($canvas, $aircraft, $index, $config, $radius, $airports);
        draw_flight_card($canvas, $aircraft[$index], $tapped, $route, $config);
    }

    $canvas->text($aircraft ? t('Trykk på et fly for å se det') : '', LEFT, 1312, 28, 'Medium', GRAY);
    // Credit the map only when it is Kartverket's.
    $credit = ($map !== null && str_ends_with($map, '_kartverket.png')) ? ' · © Kartverket' : '';
    $canvas->text(t('Oppdatert ') . date('H:i', $now) . ' · adsb.lol' . $credit, RIGHT, 1312, 28, 'Medium', GRAY, 'right');

    // Bottom bar: back to the weather, and the plane button shown as active.
    if ($request['touch']) {
        $canvas->rect(LEFT, TAB_TOP, CONTENT, 3, INK);
        $canvas->text(t('‹  Tilbake til været'), LEFT + (CONTENT - FLIGHT_BUTTON_WIDTH) / 2, TAB_TOP + 37, 36, 'Medium', INK, 'center');
        $canvas->rect(RIGHT - FLIGHT_BUTTON_WIDTH, TAB_TOP, FLIGHT_BUTTON_WIDTH, TAB_HEIGHT, INK);
        $canvas->plane(RIGHT - FLIGHT_BUTTON_WIDTH / 2, TAB_TOP + 36, 22, 45, PAPER);
    }
    return [$canvas->png(), $drawn];
}

/** The round map ($map is a picture file from basemap_file(), or null): land and sea, distance rings, compass letters, and home in the middle. */
function draw_flight_map(Canvas $canvas, ?string $map, int $radius): void
{
    $cx = MAP_CENTER_X;
    $cy = MAP_CENTER_Y;
    $r = MAP_RADIUS;

    if ($map !== null) {
        $canvas->image($map, $cx - $r, $cy - $r, 2 * $r, 2 * $r);
    }

    // A ring at half the range, and the outer edge.
    $dashes = function (float $ringRadius, float $width, int $gray, float $on, float $step) use ($canvas, $cx, $cy): void {
        for ($angle = 0; $angle < 360; $angle += $step) {
            $start = deg2rad($angle);
            $end = deg2rad($angle + $on);
            $canvas->stroke(
                [[$cx + sin($start) * $ringRadius, $cy - cos($start) * $ringRadius], [$cx + sin($end) * $ringRadius, $cy - cos($end) * $ringRadius]],
                $width,
                $gray
            );
        }
    };
    $dashes($r / 2, 2.5, GRAY, 3.2, 6);
    $dashes($r, 4, INK, 2.2, 2);
    $label = (int) round($radius / 2) . ' km';
    $w = $canvas->textWidth($label, 20, 'Medium');
    $canvas->rect($cx - $w / 2 - 5, $cy + $r / 2 - 13, $w + 10, 26, PAPER);
    $canvas->text($label, $cx, $cy + $r / 2, 20, 'Medium', GRAY, 'center');

    foreach ([['N', 0, -1], [t('Ø'), 1, 0], ['S', 0, 1], [t('V'), -1, 0]] as [$letter, $dx, $dy]) {
        $canvas->text($letter, $cx + $dx * ($r + 28), $cy + $dy * ($r + 28), 32, 'Bold', INK, 'center');
    }

    // Home: a small house.
    $canvas->disc($cx, $cy, 22, PAPER);
    $canvas->polygon([[$cx - 15, $cy + 1], [$cx, $cy - 15], [$cx + 15, $cy + 1]], INK);
    $canvas->rect($cx - 10, $cy + 1, 20, 12, INK);
}

/**
 * Marks the airports in range: a small black square with a white plane,
 * and the airport's code beside it. Returns the boxes used, as [left, top, right, bottom].
 */
function draw_airports(Canvas $canvas, array $config, int $radius): array
{
    $scale = MAP_RADIUS / $radius;
    $boxes = [];
    foreach (nearby_airports($config, $radius) as $airport) {
        [$east, $north] = map_offset_km($config, $airport['lat'], $airport['lon']);
        $x = MAP_CENTER_X + $east * $scale;
        $y = MAP_CENTER_Y - $north * $scale;
        if (hypot($x - MAP_CENTER_X, $y - MAP_CENTER_Y) > MAP_RADIUS - 16) {
            continue;                    // would poke out of the map
        }
        // Skip an airport whose mark would sit on one already drawn (or on the house).
        $box = [$x - 14, $y - 14, $x + 14, $y + 14];
        $clear = hypot($x - MAP_CENTER_X, $y - MAP_CENTER_Y) > 30;
        foreach ($boxes as $other) {
            if ($box[0] < $other[2] && $box[2] > $other[0] && $box[1] < $other[3] && $box[3] > $other[1]) {
                $clear = false;
            }
        }
        if (!$clear) {
            continue;
        }
        $canvas->rect($x - 13, $y - 13, 26, 26, INK);
        $canvas->plane($x, $y, 10, 0, PAPER);
        $boxes[] = $box;

        // The code goes to the right, or to the left near the map's right edge.
        $w = $canvas->textWidth($airport['code'], 22, 'Bold');
        $left = $x + 18 + $w < MAP_CENTER_X + MAP_RADIUS - 6 ? $x + 18 : $x - 18 - $w;
        $canvas->rect($left - 4, $y - 14, $w + 8, 28, PAPER);
        $canvas->text($airport['code'], $left, $y, 22, 'Bold', GRAY);
        $boxes[] = [$left - 4, $y - 14, $left + $w + 4, $y + 14];
    }
    return $boxes;
}

/**
 * Draws every aircraft with its call sign and returns where each one ended
 * up: id => [x, y]. $airports are the boxes the airport labels occupy, which
 * call signs keep clear of. The selected one is drawn white on a black disc.
 */
function draw_aircraft(Canvas $canvas, array $aircraft, int $index, array $config, int $radius, array $airports = []): array
{
    $scale = MAP_RADIUS / $radius;
    $drawn = [];
    // Boxes already used, as [left, top, right, bottom]: the house in the
    // middle and the compass letters, then each aircraft and call sign.
    $taken = array_merge([[MAP_CENTER_X - 24, MAP_CENTER_Y - 24, MAP_CENTER_X + 24, MAP_CENTER_Y + 24]], $airports);
    foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dy]) {
        $lx = MAP_CENTER_X + $dx * (MAP_RADIUS + 28);
        $ly = MAP_CENTER_Y + $dy * (MAP_RADIUS + 28);
        $taken[] = [$lx - 20, $ly - 20, $lx + 20, $ly + 20];
    }
    foreach ($aircraft as $i => $plane) {
        [$east, $north] = map_offset_km($config, $plane['lat'], $plane['lon']);
        // Keep aircraft near the edge inside the circle.
        $far = hypot($east, $north) * $scale;
        $shrink = $far > MAP_RADIUS - 14 ? (MAP_RADIUS - 14) / $far : 1;
        $x = MAP_CENTER_X + $east * $scale * $shrink;
        $y = MAP_CENTER_Y - $north * $scale * $shrink;
        $drawn[$plane['id']] = [(int) round($x), (int) round($y)];
        $taken[] = [$x - 26, $y - 26, $x + 26, $y + 26];
    }

    // The selected aircraft is drawn last, so it is never hidden.
    $order = array_keys($aircraft);
    usort($order, fn($a, $b) => ($a === $index) <=> ($b === $index));
    foreach ($order as $i) {
        $plane = $aircraft[$i];
        [$x, $y] = $drawn[$plane['id']];
        $selected = $i === $index;
        if ($selected) {
            $canvas->disc($x, $y, 36, INK);
        }
        $color = $selected ? PAPER : INK;
        $size = AIRCRAFT_SIZES[$plane['shape']];
        $plane['shape'] === 'heli' ? $canvas->rotor($x, $y, $size, $color) : $canvas->plane($x, $y, $size, $plane['track'], $color, $plane['shape']);
    }

    // Call signs: beside each aircraft, wherever there is room; the selected one first.
    foreach (array_reverse($order) as $i) {
        $plane = $aircraft[$i];
        [$x, $y] = $drawn[$plane['id']];
        $gap = $i === $index ? 46 : 34;
        $w = $canvas->textWidth($plane['call'], 26, 'Bold');
        $spots = [
            [$x + $gap, $y - 16],                 // right
            [$x - $gap - $w, $y - 16],            // left
            [$x - $w / 2, $y - $gap - 30],        // above
            [$x - $w / 2, $y + $gap - 2],         // below
        ];
        foreach ($spots as [$left, $top]) {
            $box = [$left - 5, $top, $left + $w + 5, $top + 32];
            $free = $left > LEFT && $left + $w < RIGHT;
            foreach ($taken as $other) {
                if ($box[0] < $other[2] && $box[2] > $other[0] && $box[1] < $other[3] && $box[3] > $other[1]) {
                    $free = false;
                    break;
                }
            }
            if ($free) {
                $canvas->rect($box[0], $box[1], $box[2] - $box[0], 32, PAPER);
                $canvas->text($plane['call'], $left, $top + 16, 26, 'Bold');
                $taken[] = $box;
                break;
            }
        }
    }
    return $drawn;
}

/**
 * The card about one aircraft: a black strip with its call sign and the
 * airline flying it, the route with how long it has flown and has left,
 * and four facts side by side.
 */
function draw_flight_card(Canvas $canvas, array $plane, bool $tapped, ?array $route, array $config): void
{
    $directions = [t('nord'), t('nordøst'), t('øst'), t('sørøst'), t('sør'), t('sørvest'), t('vest'), t('nordvest')];
    $compass = fn(float $degrees) => $directions[(int) round($degrees / 45) % 8];
    $top = 866;

    // Black strip: call sign on the left, who flies it on the right.
    $canvas->rect(LEFT, $top, CONTENT, 76, INK);
    $who = airline_name($config, $plane, $route) ?: t(FLIGHT_KIND_NAMES[$plane['shape']]);
    $callSize = $canvas->fit($plane['call'], 50, 'Bold', CONTENT * 0.42);
    $canvas->text($plane['call'], LEFT + 22, $top + 39, $callSize, 'Bold', PAPER);
    $room = CONTENT - 44 - $canvas->textWidth($plane['call'], $callSize, 'Bold') - 40;
    $canvas->text($who, RIGHT - 22, $top + 39, $canvas->fit($who, 38, 'Medium', $room), 'Medium', PAPER, 'right');

    // The route, with the aircraft on its way along the line and the two times underneath.
    $y = $top + 138;
    if ($route !== null) {
        $room = (CONTENT - 200) / 2;
        $fromSize = $canvas->fit($route['from']['city'], 46, 'Bold', $room);
        $toSize = $canvas->fit($route['to']['city'], 46, 'Bold', $room);
        $canvas->text($route['from']['city'], LEFT, $y, $fromSize, 'Bold');
        $canvas->text($route['to']['city'], RIGHT, $y, $toSize, 'Bold', INK, 'right');

        [$flown] = distance_bearing($route['from']['lat'], $route['from']['lon'], $plane['lat'], $plane['lon']);
        [$left] = distance_bearing($plane['lat'], $plane['lon'], $route['to']['lat'], $route['to']['lon']);
        $x1 = LEFT + $canvas->textWidth($route['from']['city'], $fromSize, 'Bold') + 34;
        $x2 = RIGHT - $canvas->textWidth($route['to']['city'], $toSize, 'Bold') - 34;
        $xp = $x1 + ($x2 - $x1) * max(0.1, min(0.9, $flown / max(1.0, $flown + $left)));
        $canvas->rect($x1, $y - 3, $xp - $x1, 6, INK);
        for ($x = $xp + 30; $x < $x2 - 8; $x += 18) {
            $canvas->rect($x, $y - 2, 9, 4, LINE);
        }
        $canvas->disc($x1, $y, 9, INK);
        $canvas->disc($x2, $y, 9, INK);
        $canvas->disc($x2, $y, 5, PAPER);
        $canvas->plane($xp, $y, 24, 90, INK, $plane['shape'] === 'heli' ? 'jet2' : $plane['shape']);

        [$since, $remaining] = flight_times($flown, $left, $plane['shape']);
        $canvas->text($route['from']['code'] . t(' · dro for ca. ') . $since . t(' siden'), LEFT, $y + 44, 26, 'Medium', GRAY);
        $canvas->text(t('lander om ca. ') . $remaining . ' · ' . $route['to']['code'], RIGHT, $y + 44, 26, 'Medium', GRAY, 'right');
    } else {
        $canvas->text(t('Ruten er ikke kjent'), LEFT, $y + 14, 40, 'Medium', GRAY);
    }

    // Four facts side by side.
    $y = $top + 236;
    $canvas->rect(LEFT, $y, CONTENT, 2, LINE);
    $column = CONTENT / 4;
    $typeName = $plane['type'] !== '' ? $plane['type'] : t(FLIGHT_KIND_NAMES[$plane['shape']]);
    $showRegistration = $plane['reg'] !== '' && $plane['reg'] !== $plane['call'];
    $typeNote = ($tapped ? t('Valgt') : t('Nærmest')) . ($showRegistration ? ' · ' . $plane['reg'] : '');
    $facts = [
        [t('Avstand'), max(1, (int) round($plane['km'])) . ' km', t('se mot ') . $compass($plane['bearing'])],
        [t('Høyde'), thousands($plane['alt']) . ' m', ['up' => t('stiger'), 'down' => t('synker'), 'level' => t('flyr rett fram')][$plane['trend']]],
        [t('Fart'), $plane['speed'] . t(' km/t'), t('flyr mot ') . $compass($plane['track'])],
        [t('Flytype'), $typeName, $typeNote],
    ];
    foreach ($facts as $i => [$label, $value, $note]) {
        $x = LEFT + $i * $column;
        if ($i > 0) {
            $canvas->rect($x - 12, $y + 22, 2, 136, LINE);
        }
        $canvas->text($label, $x, $y + 38, 24, 'Medium', GRAY);
        $canvas->text($value, $x, $y + 86, $canvas->fit($value, 40, 'Bold', $column - 28), 'Bold');
        $canvas->text($note, $x, $y + 132, $canvas->fit($note, 24, 'Medium', $column - 28), 'Medium', GRAY);
    }
}
