<?php

declare(strict_types=1);

/*
 * "Stor": one huge temperature, readable from across the room.
 *
 * Below the temperature: a one-line hint for the day and tomorrow's weather
 * (or an alert banner), then wind, rain and sun times.
 */

function render_big(array $model, array $request, array $config): string
{
    $canvas = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($canvas, $model['place'], $model['date'], $request['batt'], $request['touch'], $model['alert'] !== null);
    $mid = (LEFT + RIGHT) / 2;

    // Icon and description, centered as one group.
    $label = $model['sym']['label'];
    $size = $canvas->fit($label, 92, 'Bold', CONTENT - 170 - 36);
    if ($size < 60) {
        $label = $model['sym']['short'];
        $size = $canvas->fit($label, 92, 'Bold', CONTENT - 170 - 36);
    }
    $groupW = 170 + 36 + $canvas->textWidth($label, $size, 'Bold');
    $x = $mid - $groupW / 2;
    $canvas->icon($model['sym']['icon'], $x, 200, 170, 1.6);
    $canvas->text($label, $x + 206, 285, $size, 'Bold');

    $size = $canvas->fit($model['temp'], 520, 'Bold', CONTENT);
    $canvas->text($model['temp'], $mid, 622, $size, 'Bold', INK, 'center');
    $sub = $model['feels'] . ' · ' . $model['range'];
    $canvas->text($sub, $mid, 880, $canvas->fit($sub, 48, 'Medium', CONTENT), 'Medium', GRAY, 'center');

    // An alert (or low battery) takes the place of today's hint.
    $banner = $model['alert'] ?? low_battery_banner($request['batt']);
    if ($banner !== null) {
        draw_alert($canvas, $banner, 918, 62);
    } elseif ($model['hint'] !== '') {
        $canvas->text($model['hint'], $mid, 948, $canvas->fit($model['hint'], 42, 'Bold', CONTENT), 'Bold', INK, 'center');
    }
    if ($model['tomorrow'] !== '') {
        $canvas->text($model['tomorrow'], $mid, 1012, $canvas->fit($model['tomorrow'], 36, 'Medium', CONTENT), 'Medium', GRAY, 'center');
    }

    $canvas->rect(LEFT, 1060, CONTENT, 3, INK);
    $canvas->rect(LEFT, 1207, CONTENT, 3, INK);
    $colW = CONTENT / 4;
    $cells = [[t('Vind'), $model['wind']], [t('Nedbør i dag'), $model['rain']], [t('Sol opp'), $model['sunrise']], [t('Sol ned'), $model['sunset']]];
    foreach ($cells as $i => [$label, $value]) {
        $x = LEFT + $i * $colW;
        if ($i > 0) {
            $canvas->rect($x, 1085, 2, 100, LINE);
            $x += 28;
        }
        $canvas->text($label, $x, 1103, 30, 'Medium', GRAY);
        $room = LEFT + ($i + 1) * $colW - $x - 14;
        if ($i === 0) {
            $canvas->pointer(LEFT + $colW - 14 - 32, 1146, 30, $model['windDir']);
            $room -= 78;
        }
        $canvas->text($value, $x, 1156, $canvas->fit($value, 46, 'Bold', $room), 'Bold');
    }

    draw_footer($canvas, $model['updated'], $request['touch'], 'big', $model['stale']);
    return $canvas->png();
}
