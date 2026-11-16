<?php

declare(strict_types=1);

/*
 * "Graf": the next 24 hours as a meteogram.
 *
 * The chart itself is drawn by draw_chart() in src/chart.php.
 */

function render_graph(array $model, array $request, array $config): string
{
    $canvas = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($canvas, $model['place'], $model['date'], $request['batt'], $request['touch'], $model['alert'] !== null);
    $wind = t('Vind ') . $model['wind'] . ($model['gust'] !== null ? t(', kast ') . $model['gust'] : '');
    draw_current($canvas, $model, 170, 190, [$model['feels'], $wind]);

    $canvas->rect(LEFT, 366, CONTENT, 3, INK);
    $canvas->text(t('Neste 24 timer'), LEFT, 414, 38, 'Bold');
    $total = array_sum(array_column($model['graph'], 'mm'));
    $canvas->text(t('Nedbør ') . fmt_mm($total), RIGHT, 414, 26, 'Medium', GRAY, 'right');
    draw_chart($canvas, $model['graph'], 452, $model['graphSun']);

    draw_footer($canvas, $model['updated'], $request['touch'], 'graph', $model['stale']);
    return $canvas->png();
}
