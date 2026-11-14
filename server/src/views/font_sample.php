<?php

declare(strict_types=1);

/*
 * Font sample: a temperature and a line of text in one typeface, for the
 * admin page. Requested as /?view=fonts&set=<name>.
 */

function render_font_sample(array $request, array $config): string
{
    $previous = Canvas::$typeface;
    Canvas::$typeface = $config['fonts'][$request['set']] ?? $previous;

    $canvas = new Canvas(560, 112, $config['font_dir']);
    $canvas->text('−3°', 16, 56, 84, 'Bold');
    $canvas->text(t('Lett snø'), 544, 36, 40, 'Bold', INK, 'right');
    $canvas->text(t('Føles som ') . '−8° · ' . t('Lørdag'), 544, 82, 26, 'Medium', GRAY, 'right');

    Canvas::$typeface = $previous;
    return $canvas->png();
}
