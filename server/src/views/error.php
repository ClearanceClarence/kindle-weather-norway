<?php

declare(strict_types=1);

/*
 * Error screen: there is no forecast at all.
 *
 * Only seen on a first run when the server cannot reach MET Norway.
 */

function render_error(array $request, array $config): string
{
    $canvas = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($canvas, [$config['place'], $config['area'] ?? ''], date_text(time()), $request['batt'], $request['touch']);
    $mid = (LEFT + RIGHT) / 2;
    $canvas->icon('offline', $mid - 150, 440, 300, 1.4);
    $canvas->text(t('Ingen værdata'), $mid, 830, 84, 'Bold', INK, 'center');
    $canvas->text(t('Serveren får ikke kontakt med'), $mid, 945, 42, 'Medium', GRAY, 'center');
    $canvas->text(t('Meteorologisk institutt.'), $mid, 1002, 42, 'Medium', GRAY, 'center');
    return $canvas->png();
}
