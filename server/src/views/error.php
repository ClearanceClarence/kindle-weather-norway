<?php

declare(strict_types=1);

/*
 * Error screen: there is no forecast at all.
 *
 * Only seen on a first run when the server cannot reach MET Norway.
 */

function render_error(array $req, array $config): string
{
    $c = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($c, [$config['place'], $config['area'] ?? ''], date_text(time()), $req['batt'], $req['touch']);
    $mid = (LEFT + RIGHT) / 2;
    $c->icon('offline', $mid - 150, 440, 300, 1.4);
    $c->text(t('Ingen værdata'), $mid, 830, 84, 'Bold', INK, 'center');
    $c->text(t('Serveren får ikke kontakt med'), $mid, 945, 42, 'Medium', GRAY, 'center');
    $c->text(t('Meteorologisk institutt.'), $mid, 1002, 42, 'Medium', GRAY, 'center');
    return $c->png();
}
