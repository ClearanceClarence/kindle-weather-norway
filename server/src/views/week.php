<?php

declare(strict_types=1);

/*
 * "Uke": seven days ahead, one row per day.
 *
 * Each row: weekday and date, icon, description, precipitation with a bar, high / low.
 */

function render_week(array $model, array $request, array $config): string
{
    $canvas = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($canvas, $model['place'], $model['date'], $request['batt'], $request['touch'], $model['alert'] !== null);
    draw_current($canvas, $model, 180, 200, [$model['feels'] . ' · ' . $model['wind'], $model['range']]);

    $top = 380;
    $rowH = 127;
    $canvas->rect(LEFT, $top, CONTENT, 3, INK);
    $days = array_slice($model['daily'], 0, 7);
    $maxMm = max(10, max(array_column($days, 'mmRaw') ?: [0]));
    foreach ($days as $i => $day) {
        $y = $top + 3 + $i * ($rowH + 2);
        $cy = $y + $rowH / 2;
        $canvas->text($day['day'], LEFT, $cy, 46, 'Bold');
        $canvas->text($day['date'], LEFT + $canvas->textWidth($day['day'], 46, 'Bold') + 14, $cy + 3, 32, 'Medium', GRAY);
        $canvas->icon($day['sym']['icon'], LEFT + 210, $cy - 36, 72, 1.8);
        // The description shrinks when it would run into the rain column ("Partly cloudy").
        $room = RIGHT - 250 - $canvas->textWidth($day['mm'], 34, 'Medium') - 24 - (LEFT + 320);
        $canvas->text($day['sym']['short'], LEFT + 320, $cy, $canvas->fit($day['sym']['short'], 40, 'Medium', $room), 'Medium');
        $canvas->text($day['mm'], RIGHT - 250, $cy - 12, 34, 'Medium', GRAY, 'right');
        if ($day['mmRaw'] >= 0.05) {
            $w = max(6, $day['mmRaw'] / $maxMm * 150);
            $canvas->rect(RIGHT - 250 - $w, $cy + 18, $w, 10, INK);
        }
        $canvas->text($day['temps'], RIGHT, $cy, 46, 'Bold', INK, 'right');
        if ($i < count($days) - 1) {
            $canvas->rect(LEFT, $y + $rowH, CONTENT, 2, LINE);
        }
    }
    $canvas->rect(LEFT, $top + 3 + count($days) * ($rowH + 2) - 2, CONTENT, 3, INK);

    draw_footer($canvas, $model['updated'], $request['touch'], 'week', $model['stale']);
    return $canvas->png();
}
