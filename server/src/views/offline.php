<?php

declare(strict_types=1);

/*
 * "No connection" screen.
 *
 * The Kindle downloads this together with each forecast and keeps it. When a
 * later refresh fails it shows this stored copy, possibly for hours. So the
 * screen is built to stay useful and true while it ages: a clear notice at
 * the top, then the last forecast hour by hour with clock times, which holds
 * good long after it was fetched. The battery level is left out, because it
 * would be the level at the time of the last contact.
 */

function render_offline(array $model, array $request, array $config): string
{
    $canvas = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($canvas, $model['place'], $model['date'], null, $request['touch']);

    // The notice: a black strip, like a weather alert.
    $canvas->rect(LEFT, 166, CONTENT, 92, INK);
    $canvas->warning(LEFT + 24, 189, 46, PAPER, INK);
    $canvas->text(t('Ingen forbindelse'), LEFT + 92, 212, 46, 'Bold', PAPER);
    $canvas->text(t('Prøver igjen hvert ') . $request['interval'] . t('. minutt'), RIGHT - 24, 214, 28, 'Medium', PAPER, 'right');

    // The weather as it was at the last contact.
    $canvas->icon($model['sym']['icon'], LEFT, 284, 124, 1.7);
    $tempSize = $canvas->fit($model['temp'], 124, 'Bold', 300);
    $tempWidth = $canvas->textWidth($model['temp'], $tempSize, 'Bold');
    $canvas->text($model['temp'], RIGHT, 346, $tempSize, 'Bold', INK, 'right');
    $room = CONTENT - 156 - $tempWidth - 30;
    $canvas->text($model['sym']['label'], LEFT + 156, 322, $canvas->fit($model['sym']['label'], 56, 'Bold', $room), 'Bold');
    $note = t('Sist kjente varsel') . ' · ' . t('hentet kl. ') . $model['updated'];
    $canvas->text($note, LEFT + 156, 378, $canvas->fit($note, 30, 'Medium', $room), 'Medium', GRAY);

    // The hours ahead, with clock times.
    $top = 440;
    $canvas->rect(LEFT, $top, CONTENT, 3, INK);
    $canvas->text(t('Timene framover'), LEFT, $top + 34, 28, 'Medium', GRAY);
    $canvas->text(t('Vind (kast) m/s'), RIGHT, $top + 34, 28, 'Medium', GRAY, 'right');
    $rowHeight = 90;
    $y = $top + 62;
    foreach (array_slice($model['hours'], 0, 9) as $i => $hour) {
        $cy = $y + $rowHeight / 2;
        $canvas->rect(LEFT, $y, CONTENT, 2, LINE);
        $canvas->text($hour['hour'], LEFT, $cy + 2, 42, 'Bold');
        if ($i === 0 || $hour['hour'] === '00') {
            $canvas->text($hour['day'], LEFT + 70, $cy + 4, 26, 'Medium', GRAY);
        }
        $canvas->icon($hour['sym']['icon'], LEFT + 160, $cy - 30, 60, 1.7);
        $canvas->text($hour['temp'], LEFT + 380, $cy + 2, 44, 'Bold', INK, 'right');
        $wet = $hour['mmRaw'] >= 0.05;
        $canvas->text($hour['mm'], LEFT + 650, $cy + 2, 36, $wet ? 'Bold' : 'Medium', $wet ? INK : GRAY, 'right');
        $canvas->pointer(RIGHT - 18, $cy + 2, 18, $hour['dir']);
        $gust = $hour['gust'] === null ? '' : ' (' . $hour['gust'] . ')';
        $canvas->text($gust, RIGHT - 56, $cy + 4, 30, 'Medium', GRAY, 'right');
        $canvas->text((string) $hour['wind'], RIGHT - 56 - $canvas->textWidth($gust, 30, 'Medium'), $cy + 2, 38, 'Bold', INK, 'right');
        $y += $rowHeight;
    }
    $canvas->rect(LEFT, $y, CONTENT, 3, INK);

    draw_footer($canvas, $model['updated']);
    return $canvas->png();
}
