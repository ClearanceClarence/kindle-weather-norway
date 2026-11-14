<?php

declare(strict_types=1);

/*
 * Icon sample: a strip of six icons from one icon set, for the admin page.
 * Requested as /?view=icons&set=<name>.
 */

function render_icon_sample(array $request, array $config): string
{
    $previous = Canvas::$iconSet;
    Canvas::$iconSet = isset(ICON_SETS[$request['set']]) ? $request['set'] : $config['icon_set'];

    $kinds = ['sun', 'partly', 'cloud', 'rain', 'snow', 'thunder', 'moon'];
    $size = 96;
    $gap = 16;
    $canvas = new Canvas(count($kinds) * ($size + $gap) + $gap, $size + 2 * $gap, $config['font_dir']);
    foreach ($kinds as $i => $kind) {
        $canvas->icon($kind, $gap + $i * ($size + $gap), $gap, $size, 1.7);
    }

    Canvas::$iconSet = $previous;
    return $canvas->png();
}
