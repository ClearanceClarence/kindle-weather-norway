<?php

declare(strict_types=1);

/*
 * "Nå": everything on one screen.
 *
 * Top to bottom: current conditions; wind, rain and sun cells; a radar strip
 * for the next 90 minutes; six 3-hour steps; one line about tomorrow.
 *
 * When a weather alert is in force, aurora is possible or the battery is low, a banner goes in
 * right under the header. Everything below moves down to make room, and the
 * line about tomorrow is left out.
 */

const NOW_BANNER_SHIFT = 72;      // how far a banner pushes the rest of the screen down

// Where each part of the screen sits (y positions) when there is no banner.
const NOW = [
    'hero' => 14, 'line1' => 548, 'label' => 602, 'value' => 662, 'sep' => 580, 'sepH' => 108, 'pointer' => 640, 'line2' => 722,
    'radar' => 738, 'line3' => 858, 'hour' => 908, 'icon' => 936, 'temp' => 1068, 'mm' => 1126, 'pop' => 1168, 'tomorrow' => 1246,
];

function render_now(array $model, array $request, array $config): string
{
    $canvas = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($canvas, $model['place'], $model['date'], $request['batt'], $request['touch'], $model['alert'] !== null);

    // One banner at a time: a weather alert first, then an aurora notice,
    // then a low battery (which the header also shows).
    $banner = $model['alert'] ?? $model['aurora'] ?? low_battery_banner($request['batt']);
    $at = NOW;
    $dy = 0;
    if ($banner !== null) {
        draw_alert($canvas, $banner, 158, 62);
        $dy = NOW_BANNER_SHIFT;
    }
    $heroY = $dy + $at['hero'];

    // Current conditions.
    $canvas->icon($model['sym']['icon'], LEFT, 158 + $heroY, 250, 1.4);
    $size = $canvas->fit($model['temp'], 250, 'Bold', CONTENT - 250 - 24);
    $canvas->text($model['temp'], RIGHT, 260 + $heroY, $size, 'Bold', INK, 'right');
    $canvas->text($model['feels'], RIGHT, 392 + $heroY, 44, 'Medium', GRAY, 'right');

    // Long descriptions fall back to the short label rather than shrinking too far.
    $rangeWidth = $canvas->textWidth($model['range'], 46, 'Medium');
    $label = $model['sym']['label'];
    $size = $canvas->fit($label, 76, 'Bold', CONTENT - $rangeWidth - 32);
    if ($size < 52) {
        $label = $model['sym']['short'];
        $size = $canvas->fit($label, 76, 'Bold', CONTENT - $rangeWidth - 32);
    }
    $canvas->text($label, LEFT, 480 + $heroY, $size, 'Bold', INK, 'left', 'baseline');
    $canvas->text($model['range'], RIGHT, 480 + $heroY, 46, 'Medium', INK, 'right', 'baseline');

    // Wind (with gusts and a pointer for the direction), rain, sun (with UV index and moon phase).
    $canvas->rect(LEFT, $at['line1'] + $dy, CONTENT, 3, INK);
    $canvas->rect(LEFT, $at['line2'] + $dy, CONTENT, 3, INK);
    $colW = CONTENT / 3;
    $cells = [
        [t('Vind') . ($model['gust'] !== null ? t(' · kast ') . $model['gust'] : ''), $model['wind']],
        [t('Nedbør i dag'), $model['rain']],
        [t('Sol') . ($model['uv'] !== null ? ' · UV ' . $model['uv'] : ''), $model['sun']],
    ];
    $labelY = $at['label'] + $dy;
    $valueY = $at['value'] + $dy;
    foreach ($cells as $i => [$label, $value]) {
        $x = LEFT + $i * $colW;
        $right = LEFT + ($i + 1) * $colW - 16;
        if ($i > 0) {
            $canvas->rect($x, $at['sep'] + $dy, 2, $at['sepH'], LINE);
            $x += 34;
        }
        $room = $right - $x;
        if ($i === 0) {
            $canvas->pointer($right - 36, $at['pointer'] + $dy, 34, $model['windDir']);
            $room -= 88;
        }
        $labelSize = $canvas->fit($label, 30, 'Medium', $room);
        $canvas->text($label, $x, $labelY, $labelSize, 'Medium', GRAY);
        if ($i === 2) {
            // Moon phase, with its name when there is room for it.
            $canvas->moon($right - 17, $labelY, 17, $model['moon']['fraction']);
            $free = $right - 44 - ($x + $canvas->textWidth($label, $labelSize, 'Medium') + 18);
            if ($canvas->textWidth($model['moon']['name'], 22, 'Medium') <= $free) {
                $canvas->text($model['moon']['name'], $right - 44, $labelY, 22, 'Medium', GRAY, 'right');
            }
        }
        $canvas->text($value, $x, $valueY, $canvas->fit($value, 48, 'Bold', $room), 'Bold');
    }

    // Radar: will it rain in the next 90 minutes?
    draw_nowcast($canvas, $model['nowcast'], $at['radar'] + $dy);
    $canvas->rect(LEFT, $at['line3'] + $dy, CONTENT, 3, INK);

    // Next hours: temperature, amount and chance of precipitation.
    $gap = 12;
    $cellW = (CONTENT - 5 * $gap) / 6;
    foreach ($model['hourly'] as $i => $hour) {
        $cx = LEFT + $i * ($cellW + $gap) + $cellW / 2;
        $canvas->text($hour['hour'], $cx, $at['hour'] + $dy, 34, 'Medium', GRAY, 'center');
        $canvas->icon($hour['sym']['icon'], $cx - 42, $at['icon'] + $dy, 84, 1.8);
        $canvas->text($hour['temp'], $cx, $at['temp'] + $dy, 50, 'Bold', INK, 'center');
        $canvas->text($hour['mm'], $cx, $at['mm'] + $dy, 28, 'Medium', GRAY, 'center');
        if ($hour['pop'] !== '') {
            $canvas->text($hour['pop'], $cx, $at['pop'] + $dy, 26, 'Medium', GRAY, 'center');
        }
    }

    // Next days: one fewer when a banner has taken a row's worth of space.
    // Tomorrow in one line; the Uke tab has the days ahead. A banner takes its place.
    if ($model['tomorrow'] !== '' && $banner === null) {
        $canvas->rect(LEFT, $at['tomorrow'] - 46, CONTENT, 2, LINE);
        $canvas->text($model['tomorrow'], LEFT + CONTENT / 2, $at['tomorrow'], $canvas->fit($model['tomorrow'], 38, 'Medium', CONTENT), 'Medium', INK, 'center');
    }
    draw_footer($canvas, $model['updated'], $request['touch'], 'now', $model['stale']);
    return $canvas->png();
}
