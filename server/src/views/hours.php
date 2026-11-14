<?php

declare(strict_types=1);

/*
 * "Timer": the next 24 hours, one row per hour, in three pages of eight.
 *
 * Each row: time, icon, temperature with "feels like", precipitation with
 * its chance, and wind with gusts and direction. ?page=1 and ?page=2 show
 * the later hours; on the Kindle a swipe up or down switches page (the
 * Kindle script's HOUR_PAGES must match the number of pages).
 */

const HOURS_PER_PAGE = 8;

function render_hours(array $model, array $request, array $config): string
{
    $canvas = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($canvas, $model['place'], $model['date'], $request['batt'], $request['touch'], $model['alert'] !== null);

    $pages = max(1, (int) ceil(count($model['hours']) / HOURS_PER_PAGE));
    $page = min($request['page'], $pages - 1);
    $rows = array_slice($model['hours'], $page * HOURS_PER_PAGE, HOURS_PER_PAGE);

    // Column positions: where each column's text starts or ends.
    $timeX = LEFT;
    $iconX = LEFT + 160;
    $tempX = LEFT + 400;          // right edge of the temperature
    $rainX = LEFT + 690;          // right edge of the precipitation
    $windX = RIGHT;               // right edge of the wind

    $top = 166;
    $canvas->text(t('Kl.'), $timeX, $top + 18, 26, 'Medium', GRAY);
    $canvas->text(t('Temperatur'), $tempX, $top + 18, 26, 'Medium', GRAY, 'right');
    $canvas->text(t('Nedbør'), $rainX, $top + 18, 26, 'Medium', GRAY, 'right');
    $canvas->text(t('Vind (kast) m/s'), $windX, $top + 18, 26, 'Medium', GRAY, 'right');
    $canvas->rect(LEFT, $top + 38, CONTENT, 3, INK);

    $rowH = 128;
    $y = $top + 41;
    foreach ($rows as $i => $hour) {
        $cy = $y + $rowH / 2;

        // Time, with the weekday under it on the first row and at midnight.
        if ($hour['day'] !== '' && ($i === 0 || $hour['hour'] === '00')) {
            $canvas->text($hour['hour'], $timeX, $cy - 16, 50, 'Bold');
            $canvas->text($hour['day'], $timeX, $cy + 28, 26, 'Medium', GRAY);
        } else {
            $canvas->text($hour['hour'], $timeX, $cy, 50, 'Bold');
        }

        $canvas->icon($hour['sym']['icon'], $iconX, $cy - 38, 76, 1.7);

        $canvas->text($hour['temp'], $tempX, $cy - 16, 52, 'Bold', INK, 'right');
        $canvas->text(t('føles ') . $hour['feels'], $tempX, $cy + 28, 26, 'Medium', GRAY, 'right');

        // Dry hours are printed faintly so the wet ones stand out.
        $wet = $hour['mmRaw'] >= 0.05;
        $canvas->text($hour['mm'], $rainX, $cy - 16, 42, $wet ? 'Bold' : 'Medium', $wet ? INK : GRAY, 'right');
        if ($hour['pop'] !== '') {
            $canvas->text($hour['pop'], $rainX, $cy + 28, 26, 'Medium', GRAY, 'right');
        }

        $canvas->pointer($windX - 20, $cy, 20, $hour['dir']);
        $gust = $hour['gust'] === null ? '' : ' (' . $hour['gust'] . ')';
        $canvas->text($gust, $windX - 62, $cy + 2, 32, 'Medium', GRAY, 'right');
        $canvas->text((string) $hour['wind'], $windX - 62 - $canvas->textWidth($gust, 32, 'Medium'), $cy, 44, 'Bold', INK, 'right');

        $y += $rowH;
        $last = $i === count($rows) - 1;
        $canvas->rect(LEFT, $y - 2, CONTENT, $last ? 3 : 2, $last ? INK : LINE);
    }

    // Page marker, and how to get to the other page.
    if ($pages > 1) {
        $py = $top + 41 + HOURS_PER_PAGE * $rowH + 28;
        $hint = match (true) {
            $page === 0          => t('Sveip opp for de neste timene'),
            $page === $pages - 1 => t('Sveip ned for de forrige timene'),
            default              => t('Sveip opp eller ned for flere timer'),
        };
        if ($request['touch']) {
            $canvas->text($hint, LEFT, $py, 26, 'Medium', GRAY);
        }
        for ($dot = 0; $dot < $pages; $dot++) {
            $x = RIGHT - 12 - ($pages - 1 - $dot) * 34;
            $canvas->disc($x, $py, 10, INK);
            if ($dot !== $page) {
                $canvas->disc($x, $py, 6, PAPER);
            }
        }
    }

    draw_footer($canvas, $model['updated'], $request['touch'], 'hours', $model['stale']);
    return $canvas->png();
}
