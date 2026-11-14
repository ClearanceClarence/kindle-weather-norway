<?php

declare(strict_types=1);

/*
 * "Uke": seven days ahead, one row per day.
 *
 * Each row: weekday and date, icon, description, precipitation with a bar, high / low.
 */

function render_week(array $m, array $req, array $config): string
{
    $c = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($c, $m['place'], $m['date'], $req['batt'], $req['touch'], $m['alert'] !== null);
    draw_current($c, $m, 180, 200, [$m['feels'] . ' · ' . $m['wind'], $m['range']]);

    $top = 380;
    $rowH = 127;
    $c->rect(LEFT, $top, CONTENT, 3, INK);
    $days = array_slice($m['daily'], 0, 7);
    $maxMm = max(10, max(array_column($days, 'mmRaw') ?: [0]));
    foreach ($days as $i => $d) {
        $y = $top + 3 + $i * ($rowH + 2);
        $cy = $y + $rowH / 2;
        $c->text($d['day'], LEFT, $cy, 46, 'Bold');
        $c->text($d['date'], LEFT + $c->textWidth($d['day'], 46, 'Bold') + 14, $cy + 3, 32, 'Medium', GRAY);
        $c->icon($d['sym']['icon'], LEFT + 210, $cy - 36, 72, 1.8);
        // The description shrinks when it would run into the rain column ("Partly cloudy").
        $room = RIGHT - 250 - $c->textWidth($d['mm'], 34, 'Medium') - 24 - (LEFT + 320);
        $c->text($d['sym']['short'], LEFT + 320, $cy, $c->fit($d['sym']['short'], 40, 'Medium', $room), 'Medium');
        $c->text($d['mm'], RIGHT - 250, $cy - 12, 34, 'Medium', GRAY, 'right');
        if ($d['mmRaw'] >= 0.05) {
            $w = max(6, $d['mmRaw'] / $maxMm * 150);
            $c->rect(RIGHT - 250 - $w, $cy + 18, $w, 10, INK);
        }
        $c->text($d['temps'], RIGHT, $cy, 46, 'Bold', INK, 'right');
        if ($i < count($days) - 1) {
            $c->rect(LEFT, $y + $rowH, CONTENT, 2, LINE);
        }
    }
    $c->rect(LEFT, $top + 3 + count($days) * ($rowH + 2) - 2, CONTENT, 3, INK);

    draw_footer($c, $m['updated'], $req['touch'], 'week', $m['stale']);
    return $c->png();
}
