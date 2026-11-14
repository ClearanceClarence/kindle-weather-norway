<?php

declare(strict_types=1);

/*
 * Night screen, shown during quiet hours.
 *
 * The Kindle fetches it once when quiet hours begin and leaves it up until morning.
 */

function render_night(array $model, array $request, array $config): string
{
    $canvas = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($canvas, $model['place'], $model['date'], $request['batt'], $request['touch'], $model['alert'] !== null);
    $mid = (LEFT + RIGHT) / 2;

    $canvas->moon($mid, 310, 130, $model['moon']['fraction']);
    $canvas->text(t('God natt'), $mid, 520, 96, 'Bold', INK, 'center');
    $canvas->text($model['moon']['name'], $mid, 610, 38, 'Medium', GRAY, 'center');

    // Before midnight the coming day is "tomorrow"; after midnight it is today.
    $day = (int) date('G') < 12 ? ($model['today'] ?? $model['daily'][0] ?? null) : ($model['daily'][0] ?? null);
    if ($day !== null) {
        $canvas->rect(LEFT, 720, CONTENT, 3, INK);
        $canvas->text($day['dayFull'], LEFT, 778, 38, 'Medium', GRAY);
        $canvas->icon($day['sym']['icon'], LEFT, 820, 220, 1.5);
        $canvas->text($day['sym']['short'], LEFT + 260, 890, $canvas->fit($day['sym']['short'], 72, 'Bold', 300), 'Bold');
        $canvas->text($day['mm'], LEFT + 260, 975, 40, 'Medium', GRAY);
        $canvas->text($day['temps'], RIGHT, 930, $canvas->fit($day['temps'], 96, 'Bold', 360), 'Bold', INK, 'right');
        $canvas->rect(LEFT, 1080, CONTENT, 3, INK);
    }
    if ($model['alert'] !== null) {
        draw_alert($canvas, $model['alert'], 1120, 78);
    }
    if ($request['wake'] !== '') {
        $canvas->text(t('Oppdateres igjen kl. ') . $request['wake'], $mid, 1260, 36, 'Medium', GRAY, 'center');
    }
    draw_footer($canvas, $model['updated']);
    return $canvas->png();
}
