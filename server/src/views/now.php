<?php

declare(strict_types=1);

/*
 * "Nå": everything on one screen.
 *
 * Top to bottom: current conditions; wind, rain and sun cells; a radar strip
 * for the next 90 minutes; six 3-hour steps; three days ahead.
 *
 * When a weather alert is in force, aurora is possible or the battery is low, a banner goes in
 * right under the header. Everything below moves down to make room, and the
 * screen shows two days ahead instead of three.
 */

const NOW_BANNER_SHIFT = 72;      // how far a banner pushes the rest of the screen down

function render_now(array $m, array $req, array $config): string
{
    $c = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($c, $m['place'], $m['date'], $req['batt'], $req['touch'], $m['alert'] !== null);

    // One banner at a time: a weather alert first, then an aurora notice,
    // then a low battery (which the header also shows).
    $banner = $m['alert'] ?? $m['aurora'] ?? low_battery_banner($req['batt']);
    $dy = 0;
    if ($banner !== null) {
        draw_alert($c, $banner, 158, 62);
        $dy = NOW_BANNER_SHIFT;
    }

    // Current conditions.
    $c->icon($m['sym']['icon'], LEFT, 158 + $dy, 250, 1.4);
    $size = $c->fit($m['temp'], 250, 'Bold', CONTENT - 250 - 24);
    $c->text($m['temp'], RIGHT, 260 + $dy, $size, 'Bold', INK, 'right');
    $c->text($m['feels'], RIGHT, 392 + $dy, 44, 'Medium', GRAY, 'right');

    // Long descriptions fall back to the short label rather than shrinking too far.
    $rangeWidth = $c->textWidth($m['range'], 46, 'Medium');
    $label = $m['sym']['label'];
    $size = $c->fit($label, 76, 'Bold', CONTENT - $rangeWidth - 32);
    if ($size < 52) {
        $label = $m['sym']['short'];
        $size = $c->fit($label, 76, 'Bold', CONTENT - $rangeWidth - 32);
    }
    $c->text($label, LEFT, 480 + $dy, $size, 'Bold', INK, 'left', 'baseline');
    $c->text($m['range'], RIGHT, 480 + $dy, 46, 'Medium', INK, 'right', 'baseline');

    // Wind (with gusts and wind rose), rain, sun (with UV index and moon phase).
    $c->rect(LEFT, 506 + $dy, CONTENT, 3, INK);
    $c->rect(LEFT, 653 + $dy, CONTENT, 3, INK);
    $colW = CONTENT / 3;
    $cells = [
        [t('Vind') . ($m['gust'] !== null ? t(' · kast ') . $m['gust'] : ''), $m['wind']],
        [t('Nedbør i dag'), $m['rain']],
        [t('Sol') . ($m['uv'] !== null ? ' · UV ' . $m['uv'] : ''), $m['sun']],
    ];
    $labelY = 549 + $dy;
    $valueY = 602 + $dy;
    foreach ($cells as $i => [$label, $value]) {
        $x = LEFT + $i * $colW;
        $right = LEFT + ($i + 1) * $colW - 16;
        if ($i > 0) {
            $c->rect($x, 531 + $dy, 2, 100, LINE);
            $x += 34;
        }
        $room = $right - $x;
        if ($i === 0) {
            $c->windrose($right - 46, 592 + $dy, 44, $m['windDir']);
            $room -= 104;
        }
        $labelSize = $c->fit($label, 30, 'Medium', $room);
        $c->text($label, $x, $labelY, $labelSize, 'Medium', GRAY);
        if ($i === 2) {
            // Moon phase, with its name when there is room for it.
            $c->moon($right - 17, $labelY, 17, $m['moon']['fraction']);
            $free = $right - 44 - ($x + $c->textWidth($label, $labelSize, 'Medium') + 18);
            if ($c->textWidth($m['moon']['name'], 22, 'Medium') <= $free) {
                $c->text($m['moon']['name'], $right - 44, $labelY, 22, 'Medium', GRAY, 'right');
            }
        }
        $c->text($value, $x, $valueY, $c->fit($value, 48, 'Bold', $room), 'Bold');
    }

    // Radar: will it rain in the next 90 minutes?
    draw_nowcast($c, $m['nowcast'], 656 + $dy);
    $c->rect(LEFT, 752 + $dy, CONTENT, 3, INK);

    // Next hours: temperature, amount and chance of precipitation.
    $gap = 12;
    $cellW = (CONTENT - 5 * $gap) / 6;
    foreach ($m['hourly'] as $i => $h) {
        $cx = LEFT + $i * ($cellW + $gap) + $cellW / 2;
        $c->text($h['hour'], $cx, 785 + $dy, 34, 'Medium', GRAY, 'center');
        $c->icon($h['sym']['icon'], $cx - 42, 806 + $dy, 84, 1.8);
        $c->text($h['temp'], $cx, 928 + $dy, 50, 'Bold', INK, 'center');
        $c->text($h['mm'], $cx, 978 + $dy, 28, 'Medium', GRAY, 'center');
        if ($h['pop'] !== '') {
            $c->text($h['pop'], $cx, 1014 + $dy, 26, 'Medium', GRAY, 'center');
        }
    }

    // Next days: one fewer when a banner has taken a row's worth of space.
    draw_days($c, array_slice($m['daily'], 0, $banner !== null ? 2 : 3), 1048 + $dy);
    draw_footer($c, $m['updated'], $req['touch'], 'now', $m['stale']);
    return $c->png();
}
