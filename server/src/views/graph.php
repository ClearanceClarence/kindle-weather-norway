<?php

declare(strict_types=1);

/*
 * "Graf": the next 24 hours as a meteogram.
 *
 * The chart itself is drawn by draw_chart() in src/chart.php.
 */

function render_graph(array $m, array $req, array $config): string
{
    $c = new Canvas($config['width'], $config['height'], $config['font_dir']);
    draw_header($c, $m['place'], $m['date'], $req['batt'], $req['touch'], $m['alert'] !== null);
    $wind = t('Vind ') . $m['wind'] . ($m['gust'] !== null ? t(', kast ') . $m['gust'] : '');
    draw_current($c, $m, 170, 190, [$m['feels'], $wind]);

    $c->rect(LEFT, 366, CONTENT, 3, INK);
    $c->text(t('Neste 24 timer'), LEFT, 408, 38, 'Bold');
    $total = array_sum(array_column($m['graph'], 'mm'));
    $c->text(t('Stiplet: føles som · Nedbør ') . fmt_mm($total), RIGHT, 408, 26, 'Medium', GRAY, 'right');
    draw_chart($c, $m['graph'], 440);

    draw_footer($c, $m['updated'], $req['touch'], 'graph', $m['stale']);
    return $c->png();
}
