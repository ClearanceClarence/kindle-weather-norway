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

function render_offline(array $m, array $req, array $config): string
{
    $c = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($c, $m['place'], $m['date'], null, $req['touch']);

    // The notice: a black strip, like a weather alert.
    $c->rect(LEFT, 166, CONTENT, 92, INK);
    $c->warning(LEFT + 24, 189, 46, PAPER, INK);
    $c->text(t('Ingen forbindelse'), LEFT + 92, 212, 46, 'Bold', PAPER);
    $c->text(t('Prøver igjen hvert ') . $req['interval'] . t('. minutt'), RIGHT - 24, 214, 28, 'Medium', PAPER, 'right');

    // The weather as it was at the last contact.
    $c->icon($m['sym']['icon'], LEFT, 284, 124, 1.7);
    $tempSize = $c->fit($m['temp'], 124, 'Bold', 300);
    $tempWidth = $c->textWidth($m['temp'], $tempSize, 'Bold');
    $c->text($m['temp'], RIGHT, 346, $tempSize, 'Bold', INK, 'right');
    $room = CONTENT - 156 - $tempWidth - 30;
    $c->text($m['sym']['label'], LEFT + 156, 322, $c->fit($m['sym']['label'], 56, 'Bold', $room), 'Bold');
    $note = t('Sist kjente varsel') . ' · ' . t('hentet kl. ') . $m['updated'];
    $c->text($note, LEFT + 156, 378, $c->fit($note, 30, 'Medium', $room), 'Medium', GRAY);

    // The hours ahead, with clock times.
    $top = 440;
    $c->rect(LEFT, $top, CONTENT, 3, INK);
    $c->text(t('Timene framover'), LEFT, $top + 34, 28, 'Medium', GRAY);
    $c->text(t('Vind (kast) m/s'), RIGHT, $top + 34, 28, 'Medium', GRAY, 'right');
    $rowHeight = 68;
    $y = $top + 62;
    foreach (array_slice($m['hours'], 0, 12) as $i => $h) {
        $cy = $y + $rowHeight / 2;
        $c->rect(LEFT, $y, CONTENT, 2, LINE);
        $c->text($h['hour'], LEFT, $cy + 2, 38, 'Bold');
        if ($i === 0 || $h['hour'] === '00') {
            $c->text($h['day'], LEFT + 62, $cy + 4, 24, 'Medium', GRAY);
        }
        $c->icon($h['sym']['icon'], LEFT + 150, $cy - 25, 50, 1.7);
        $c->text($h['temp'], LEFT + 370, $cy + 2, 40, 'Bold', INK, 'right');
        $wet = $h['mmRaw'] >= 0.05;
        $c->text($h['mm'], LEFT + 640, $cy + 2, 32, $wet ? 'Bold' : 'Medium', $wet ? INK : GRAY, 'right');
        $c->arrow(RIGHT - 20, $cy + 2, 17, $h['dir'], 4.5);
        $gust = $h['gust'] === null ? '' : ' (' . $h['gust'] . ')';
        $c->text($gust, RIGHT - 54, $cy + 4, 28, 'Medium', GRAY, 'right');
        $c->text((string) $h['wind'], RIGHT - 54 - $c->textWidth($gust, 28, 'Medium'), $cy + 2, 34, 'Bold', INK, 'right');
        $y += $rowHeight;
    }
    $c->rect(LEFT, $y, CONTENT, 3, INK);

    draw_footer($c, $m['updated']);
    return $c->png();
}
