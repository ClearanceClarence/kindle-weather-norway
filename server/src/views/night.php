<?php

declare(strict_types=1);

/*
 * Night screen, shown during quiet hours.
 *
 * The Kindle fetches it once when quiet hours begin and leaves it up until morning.
 */

function render_night(array $m, array $req, array $config): string
{
    $c = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($c, $m['place'], $m['date'], $req['batt'], $req['touch'], $m['alert'] !== null);
    $mid = (LEFT + RIGHT) / 2;

    $c->moon($mid, 310, 130, $m['moon']['fraction']);
    $c->text(t('God natt'), $mid, 520, 96, 'Bold', INK, 'center');
    $c->text($m['moon']['name'], $mid, 610, 38, 'Medium', GRAY, 'center');

    // Before midnight the coming day is "tomorrow"; after midnight it is today.
    $d = (int) date('G') < 12 ? ($m['today'] ?? $m['daily'][0] ?? null) : ($m['daily'][0] ?? null);
    if ($d !== null) {
        $c->rect(LEFT, 720, CONTENT, 3, INK);
        $c->text($d['dayFull'], LEFT, 778, 38, 'Medium', GRAY);
        $c->icon($d['sym']['icon'], LEFT, 820, 220, 1.5);
        $c->text($d['sym']['short'], LEFT + 260, 890, $c->fit($d['sym']['short'], 72, 'Bold', 300), 'Bold');
        $c->text($d['mm'], LEFT + 260, 975, 40, 'Medium', GRAY);
        $c->text($d['temps'], RIGHT, 930, $c->fit($d['temps'], 96, 'Bold', 360), 'Bold', INK, 'right');
        $c->rect(LEFT, 1080, CONTENT, 3, INK);
    }
    if ($m['alert'] !== null) {
        draw_alert($c, $m['alert'], 1120, 78);
    }
    if ($req['wake'] !== '') {
        $c->text(t('Oppdateres igjen kl. ') . $req['wake'], $mid, 1260, 36, 'Medium', GRAY, 'center');
    }
    draw_footer($c, $m['updated']);
    return $c->png();
}
