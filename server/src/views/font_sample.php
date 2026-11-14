<?php

declare(strict_types=1);

/*
 * Font sample: a temperature and a line of text in one typeface, for the
 * admin page. Requested as /?view=fonts&set=<name>.
 */

function render_font_sample(array $req, array $config): string
{
    $previous = Canvas::$typeface;
    Canvas::$typeface = $config['fonts'][$req['set']] ?? $previous;

    $c = new Canvas(560, 112, $config['font_dir']);
    $c->text('−3°', 16, 56, 84, 'Bold');
    $c->text(t('Lett snø'), 544, 36, 40, 'Bold', INK, 'right');
    $c->text(t('Føles som ') . '−8° · ' . t('Lørdag'), 544, 82, 26, 'Medium', GRAY, 'right');

    Canvas::$typeface = $previous;
    return $c->png();
}
