<?php

declare(strict_types=1);

/*
 * "Stor": one huge temperature, readable from across the room.
 *
 * Below the temperature: a one-line hint for the day and tomorrow's weather
 * (or an alert banner), then wind, rain and sun times.
 */

function render_big(array $m, array $req, array $config): string
{
    $c = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($c, $m['place'], $m['date'], $req['batt'], $req['touch'], $m['alert'] !== null);
    $mid = (LEFT + RIGHT) / 2;

    // Icon and description, centered as one group.
    $label = $m['sym']['label'];
    $size = $c->fit($label, 92, 'Bold', CONTENT - 170 - 36);
    if ($size < 60) {
        $label = $m['sym']['short'];
        $size = $c->fit($label, 92, 'Bold', CONTENT - 170 - 36);
    }
    $groupW = 170 + 36 + $c->textWidth($label, $size, 'Bold');
    $x = $mid - $groupW / 2;
    $c->icon($m['sym']['icon'], $x, 200, 170, 1.6);
    $c->text($label, $x + 206, 285, $size, 'Bold');

    $size = $c->fit($m['temp'], 520, 'Bold', CONTENT);
    $c->text($m['temp'], $mid, 622, $size, 'Bold', INK, 'center');
    $sub = $m['feels'] . ' · ' . $m['range'];
    $c->text($sub, $mid, 880, $c->fit($sub, 48, 'Medium', CONTENT), 'Medium', GRAY, 'center');

    // An alert (or low battery) takes the place of today's hint.
    $banner = $m['alert'] ?? low_battery_banner($req['batt']);
    if ($banner !== null) {
        draw_alert($c, $banner, 918, 62);
    } elseif ($m['hint'] !== '') {
        $c->text($m['hint'], $mid, 948, $c->fit($m['hint'], 42, 'Bold', CONTENT), 'Bold', INK, 'center');
    }
    if ($m['tomorrow'] !== '') {
        $c->text($m['tomorrow'], $mid, 1012, $c->fit($m['tomorrow'], 36, 'Medium', CONTENT), 'Medium', GRAY, 'center');
    }

    $c->rect(LEFT, 1060, CONTENT, 3, INK);
    $c->rect(LEFT, 1207, CONTENT, 3, INK);
    $colW = CONTENT / 4;
    $cells = [[t('Vind'), $m['wind']], [t('Nedbør i dag'), $m['rain']], [t('Sol opp'), $m['sunrise']], [t('Sol ned'), $m['sunset']]];
    foreach ($cells as $i => [$label, $value]) {
        $x = LEFT + $i * $colW;
        if ($i > 0) {
            $c->rect($x, 1085, 2, 100, LINE);
            $x += 28;
        }
        $c->text($label, $x, 1103, 30, 'Medium', GRAY);
        $room = LEFT + ($i + 1) * $colW - $x - 14;
        if ($i === 0) {
            $c->windrose(LEFT + $colW - 14 - 40, 1146, 38, $m['windDir']);
            $room -= 90;
        }
        $c->text($value, $x, 1156, $c->fit($value, 46, 'Bold', $room), 'Bold');
    }

    draw_footer($c, $m['updated'], $req['touch'], 'big', $m['stale']);
    return $c->png();
}
