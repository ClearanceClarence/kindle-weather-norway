<?php

declare(strict_types=1);

/*
 * The 24-hour meteogram used by the "Graf" screen.
 */

/**
 * Meteogram for the next 24 hours. From the top: weather icons, temperature
 * and "feels like" every third hour, the temperature curve over a labelled
 * grid, chance and amount of precipitation, hourly precipitation bars, and wind.
 */
function draw_chart(Canvas $c, array $pts, float $y0): void
{
    $n = count($pts);
    if ($n < 4) {
        $c->text(t('Ingen timevarsel tilgjengelig'), (LEFT + RIGHT) / 2, $y0 + 250, 36, 'Medium', GRAY, 'center');
        return;
    }
    $x0 = LEFT + 66;                      // room for the scale and row labels
    $x1 = RIGHT - 14;
    $step = ($x1 - $x0) / ($n - 1);
    $X = fn(int $i) => $x0 + $i * $step;
    $axisLabel = fn(string $text, float $cy) => $c->text($text, $x0 - 14, $cy, 24, 'Medium', GRAY, 'right');

    // The columns: every third hour. Text in the outermost columns is nudged
    // inwards so it stays on the screen.
    $cols = [];
    foreach ($pts as $i => $p) {
        if ((int) $p['hour'] % 3 === 0) {
            $cols[$i] = max($x0 + 20, min($x1 - 26, $X($i)));
        }
    }

    // Rows above the curve: icon, temperature, feels like.
    foreach ($cols as $i => $x) {
        $p = $pts[$i];
        $c->icon($p['icon'], $x - 28, $y0 + 8, 56, 1.9);
        $c->text(fmt_temp($p['temp']), $x, $y0 + 92, 36, 'Bold', INK, 'center');
        $c->text(fmt_temp($p['feels']), $x, $y0 + 134, 28, 'Medium', GRAY, 'center');
    }
    $axisLabel(t('føles'), $y0 + 134);

    // Temperature scale: round the range outwards to whole grid steps.
    $temps = array_column($pts, 'temp');
    $feels = array_column($pts, 'feels');
    $lo = min($temps);
    $hi = max($temps);
    // Draw the "feels like" curve only when it differs enough to matter.
    $showFeels = false;
    foreach ($pts as $p) {
        $showFeels = $showFeels || abs($p['temp'] - $p['feels']) >= 1.5;
    }
    $scaleLo = $showFeels ? min($lo, min($feels)) : $lo;
    $scaleHi = $showFeels ? max($hi, max($feels)) : $hi;
    $grid = match (true) {
        $scaleHi - $scaleLo <= 4  => 1,
        $scaleHi - $scaleLo <= 10 => 2,
        default                   => 5,
    };
    $axisLo = floor(($scaleLo - 0.3) / $grid) * $grid;
    $axisHi = ceil(($scaleHi + 0.3) / $grid) * $grid;
    $top = $y0 + 176;
    $bottom = $y0 + 500;
    $Y = fn(float $t) => $top + ($axisHi - $t) / ($axisHi - $axisLo) * ($bottom - $top);

    // Smooth curve through hourly values (Catmull-Rom spline).
    $spline = function (array $vals) use ($n, $X, $Y, $step, $axisLo, $axisHi): array {
        $out = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $vals[max(0, $i - 1)];
            $p1 = $vals[$i];
            $p2 = $vals[$i + 1];
            $p3 = $vals[min($n - 1, $i + 2)];
            for ($k = 0; $k < 8; $k++) {
                $u = $k / 8;
                $t = 0.5 * ((2 * $p1) + (-$p0 + $p2) * $u
                    + (2 * $p0 - 5 * $p1 + 4 * $p2 - $p3) * $u * $u
                    + (-$p0 + 3 * $p1 - 3 * $p2 + $p3) * $u * $u * $u);
                $out[] = [$X($i) + $u * $step, $Y(max($axisLo, min($axisHi, $t)))];
            }
        }
        $out[] = [$X($n - 1), $Y($vals[$n - 1])];
        return $out;
    };
    $curve = $spline($temps);

    // Light fill under the curve, then the grid on top of it.
    $c->polygon(array_merge($curve, [[$x1, $bottom], [$x0, $bottom]]), 232);
    for ($t = $axisLo; $t <= $axisHi + 0.01; $t += $grid) {
        $zero = abs($t) < 0.01;
        $c->rect($x0, $Y($t) - 1, $x1 - $x0, $zero ? 3 : 2, $zero ? GRAY : 190);
        $c->text(fmt_temp($t), $x0 - 14, $Y($t), 28, $zero ? 'Bold' : 'Medium', $zero ? INK : GRAY, 'right');
    }

    // Day change.
    foreach ($pts as $i => $p) {
        if ($i > 0 && $p['hour'] === '00') {
            for ($y = $top; $y < $bottom; $y += 14) {
                $c->rect($X($i) - 1, $y, 2, 8, GRAY);
            }
            $c->text(t(WEEKDAYS_SHORT[(int) date('w', $p['t'])]), $X($i) + 10, $bottom - 18, 26, 'Medium', GRAY);
        }
    }

    // "Feels like" as a dashed line, under the temperature curve.
    if ($showFeels) {
        $dashes = $spline($feels);
        for ($i = 0; $i + 3 < count($dashes); $i += 6) {
            $c->stroke(array_slice($dashes, $i, 4), 4, GRAY);
        }
    }
    $c->stroke($curve, 7, INK);

    // A dot on the curve for every column, larger on the warmest and coldest hour.
    $iMax = array_search($hi, $temps, true);
    $iMin = array_search($lo, $temps, true);
    foreach (array_keys($cols) as $i) {
        $c->disc($X($i), $Y($pts[$i]['temp']), 8, INK);
        $c->disc($X($i), $Y($pts[$i]['temp']), 4, PAPER);
    }
    foreach (array_unique([$iMax, $iMin]) as $i) {
        $c->disc($X($i), $Y($pts[$i]['temp']), 13, INK);
        $c->disc($X($i), $Y($pts[$i]['temp']), 7.5, PAPER);
    }

    // Rows under the curve: chance of precipitation and amount for the three hours.
    $mm = array_column($pts, 'mm');
    foreach ($cols as $i => $x) {
        if ($i >= $n - 1) {
            continue;
        }
        if ($pts[$i]['pop'] !== null) {
            $c->text((int) (round((float) $pts[$i]['pop'] / 5) * 5) . ' %', $x, $bottom + 34, 26, 'Medium', GRAY, 'center');
        }
        $sum = array_sum(array_slice($mm, $i, 3));
        $c->text($sum < 0.05 ? '0' : number_format($sum, 1, decimal_mark(), ''), $x, $bottom + 72, 28, $sum < 0.05 ? 'Medium' : 'Bold', $sum < 0.05 ? GRAY : INK, 'center');
    }
    $axisLabel('mm', $bottom + 72);

    // Precipitation per hour.
    $base = $bottom + 148;
    $maxMm = max(1.0, max($mm));
    for ($i = 0; $i < $n - 1; $i++) {
        if ($mm[$i] >= 0.05) {
            $h = max(6, $mm[$i] / $maxMm * 48);
            $c->rect($X($i) + 4, $base - $h, $step - 8, $h, INK);
        }
    }
    $c->rect($x0, $base, $x1 - $x0, 3, INK);

    // Hours along the bottom.
    foreach ($cols as $i => $x) {
        $c->rect($X($i) - 1, $base, 2, 10, INK);
        $c->text($pts[$i]['hour'], $x, $base + 36, 30, 'Medium', GRAY, 'center');
    }

    // Wind: direction arrow, speed, and gusts in parentheses.
    $axisLabel('m/s', $base + 86);
    foreach ($cols as $i => $x) {
        $p = $pts[$i];
        $c->arrow($x, $base + 86, 19, $p['dir'], 5);
        $speed = (string) (int) round($p['wind']);
        $gust = $p['gust'] === null ? '' : ' (' . (int) round((float) $p['gust']) . ')';
        $w = $c->textWidth($speed, 30, 'Bold') + $c->textWidth($gust, 24, 'Medium');
        $tx = max($x0 - 4, min($x1 + 10 - $w, $x - $w / 2));
        $c->text($speed, $tx, $base + 132, 30, 'Bold');
        if ($gust !== '') {
            $c->text($gust, $tx + $c->textWidth($speed, 30, 'Bold'), $base + 132, 24, 'Medium', GRAY);
        }
    }
}
