<?php

declare(strict_types=1);

/*
 * "Timer": the next 24 hours, one row per hour, in two pages of twelve.
 *
 * Each row: time, icon, temperature with "feels like", precipitation with
 * its chance, and wind with gusts and direction. ?page=1 shows the second
 * twelve hours; on the Kindle a swipe up or down switches page.
 */

const HOURS_PER_PAGE = 12;

function render_hours(array $m, array $req, array $config): string
{
    $c = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($c, $m['place'], $m['date'], $req['batt'], $req['touch'], $m['alert'] !== null);

    $pages = max(1, (int) ceil(count($m['hours']) / HOURS_PER_PAGE));
    $page = min($req['page'], $pages - 1);
    $rows = array_slice($m['hours'], $page * HOURS_PER_PAGE, HOURS_PER_PAGE);

    // Column positions: where each column's text starts or ends.
    $timeX = LEFT;
    $iconX = LEFT + 150;
    $tempX = LEFT + 400;          // right edge of the temperature
    $rainX = LEFT + 690;          // right edge of the precipitation
    $windX = RIGHT;               // right edge of the wind

    $top = 166;
    $c->text(t('Kl.'), $timeX, $top + 18, 26, 'Medium', GRAY);
    $c->text(t('Temperatur'), $tempX, $top + 18, 26, 'Medium', GRAY, 'right');
    $c->text(t('Nedbør'), $rainX, $top + 18, 26, 'Medium', GRAY, 'right');
    $c->text(t('Vind (kast) m/s'), $windX, $top + 18, 26, 'Medium', GRAY, 'right');
    $c->rect(LEFT, $top + 38, CONTENT, 3, INK);

    $rowH = 86;
    $y = $top + 41;
    foreach ($rows as $i => $h) {
        $cy = $y + $rowH / 2;

        // Time, with the weekday under it on the first row and at midnight.
        if ($h['day'] !== '' && ($i === 0 || $h['hour'] === '00')) {
            $c->text($h['hour'], $timeX, $cy - 13, 44, 'Bold');
            $c->text($h['day'], $timeX, $cy + 24, 24, 'Medium', GRAY);
        } else {
            $c->text($h['hour'], $timeX, $cy, 44, 'Bold');
        }

        $c->icon($h['sym']['icon'], $iconX, $cy - 32, 64, 1.7);

        $c->text($h['temp'], $tempX, $cy - 13, 46, 'Bold', INK, 'right');
        $c->text(t('føles ') . $h['feels'], $tempX, $cy + 24, 24, 'Medium', GRAY, 'right');

        // Dry hours are printed faintly so the wet ones stand out.
        $wet = $h['mmRaw'] >= 0.05;
        $c->text($h['mm'], $rainX, $cy - 13, 38, $wet ? 'Bold' : 'Medium', $wet ? INK : GRAY, 'right');
        if ($h['pop'] !== '') {
            $c->text($h['pop'], $rainX, $cy + 24, 24, 'Medium', GRAY, 'right');
        }

        $c->arrow($windX - 22, $cy, 20, $h['dir'], 5);
        $gust = $h['gust'] === null ? '' : ' (' . $h['gust'] . ')';
        $c->text($gust, $windX - 62, $cy + 2, 30, 'Medium', GRAY, 'right');
        $c->text((string) $h['wind'], $windX - 62 - $c->textWidth($gust, 30, 'Medium'), $cy, 40, 'Bold', INK, 'right');

        $y += $rowH;
        $last = $i === count($rows) - 1;
        $c->rect(LEFT, $y - 2, CONTENT, $last ? 3 : 2, $last ? INK : LINE);
    }

    // Page marker, and how to get to the other page.
    if ($pages > 1) {
        $py = $top + 41 + HOURS_PER_PAGE * $rowH + 24;
        $hint = $page === 0 ? t('Sveip opp for de neste timene') : t('Sveip ned for de første timene');
        if ($req['touch']) {
            $c->text($hint, LEFT, $py, 26, 'Medium', GRAY);
        }
        for ($p = 0; $p < $pages; $p++) {
            $x = RIGHT - 12 - ($pages - 1 - $p) * 34;
            $c->disc($x, $py, 10, INK);
            if ($p !== $page) {
                $c->disc($x, $py, 6, PAPER);
            }
        }
    }

    draw_footer($c, $m['updated'], $req['touch'], 'hours', $m['stale']);
    return $c->png();
}
