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
function draw_chart(Canvas $canvas, array $points, float $y0): void
{
    $count = count($points);
    if ($count < 4) {
        $canvas->text(t('Ingen timevarsel tilgjengelig'), (LEFT + RIGHT) / 2, $y0 + 250, 36, 'Medium', GRAY, 'center');
        return;
    }
    $x0 = LEFT + 66;                      // room for the scale and row labels
    $x1 = RIGHT - 14;
    $step = ($x1 - $x0) / ($count - 1);
    $xAt = fn(int $i) => $x0 + $i * $step;
    $axisLabel = fn(string $text, float $cy) => $canvas->text($text, $x0 - 14, $cy, 24, 'Medium', GRAY, 'right');

    // The columns: every third hour. Text in the outermost columns is nudged
    // inwards so it stays on the screen.
    $cols = [];
    foreach ($points as $i => $point) {
        if ((int) $point['hour'] % 3 === 0) {
            $cols[$i] = max($x0 + 20, min($x1 - 26, $xAt($i)));
        }
    }

    // Rows above the curve: icon, temperature, feels like.
    foreach ($cols as $i => $x) {
        $point = $points[$i];
        $canvas->icon($point['icon'], $x - 28, $y0 + 8, 56, 1.9);
        $canvas->text(fmt_temp($point['temp']), $x, $y0 + 96, 36, 'Bold', INK, 'center');
        $canvas->text(fmt_temp($point['feels']), $x, $y0 + 140, 28, 'Medium', GRAY, 'center');
    }
    $axisLabel(t('føles'), $y0 + 140);

    // Temperature scale: round the range outwards to whole grid steps.
    $temps = array_column($points, 'temp');
    $feels = array_column($points, 'feels');
    $lowest = min($temps);
    $highest = max($temps);
    // Draw the "feels like" curve only when it differs enough to matter.
    $showFeels = false;
    foreach ($points as $point) {
        $showFeels = $showFeels || abs($point['temp'] - $point['feels']) >= 1.5;
    }
    $scaleLo = $showFeels ? min($lowest, min($feels)) : $lowest;
    $scaleHi = $showFeels ? max($highest, max($feels)) : $highest;
    $grid = match (true) {
        $scaleHi - $scaleLo <= 4  => 1,
        $scaleHi - $scaleLo <= 10 => 2,
        default                   => 5,
    };
    $axisLo = floor(($scaleLo - 0.3) / $grid) * $grid;
    $axisHi = ceil(($scaleHi + 0.3) / $grid) * $grid;
    $top = $y0 + 188;
    $bottom = $y0 + 490;
    $yAt = fn(float $temperature) => $top + ($axisHi - $temperature) / ($axisHi - $axisLo) * ($bottom - $top);

    // Smooth curve through hourly values (Catmull-Rom spline).
    $spline = function (array $vals) use ($count, $xAt, $yAt, $step, $axisLo, $axisHi): array {
        $out = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $p0 = $vals[max(0, $i - 1)];
            $p1 = $vals[$i];
            $p2 = $vals[$i + 1];
            $p3 = $vals[min($count - 1, $i + 2)];
            for ($part = 0; $part < 8; $part++) {
                $fraction = $part / 8;
                $temperature = 0.5 * ((2 * $p1) + (-$p0 + $p2) * $fraction
                    + (2 * $p0 - 5 * $p1 + 4 * $p2 - $p3) * $fraction * $fraction
                    + (-$p0 + 3 * $p1 - 3 * $p2 + $p3) * $fraction * $fraction * $fraction);
                $out[] = [$xAt($i) + $fraction * $step, $yAt(max($axisLo, min($axisHi, $temperature)))];
            }
        }
        $out[] = [$xAt($count - 1), $yAt($vals[$count - 1])];
        return $out;
    };
    $curve = $spline($temps);

    // Light fill under the curve, then the grid on top of it.
    $canvas->polygon(array_merge($curve, [[$x1, $bottom], [$x0, $bottom]]), 232);
    for ($temperature = $axisLo; $temperature <= $axisHi + 0.01; $temperature += $grid) {
        $zero = abs($temperature) < 0.01;
        $canvas->rect($x0, $yAt($temperature) - 1, $x1 - $x0, $zero ? 3 : 2, $zero ? GRAY : 190);
        $canvas->text(fmt_temp($temperature), $x0 - 14, $yAt($temperature), 28, $zero ? 'Bold' : 'Medium', $zero ? INK : GRAY, 'right');
    }

    // Day change.
    foreach ($points as $i => $point) {
        if ($i > 0 && $point['hour'] === '00') {
            for ($y = $top; $y < $bottom; $y += 14) {
                $canvas->rect($xAt($i) - 1, $y, 2, 8, GRAY);
            }
            $canvas->text(t(WEEKDAYS_SHORT[(int) date('w', $point['t'])]), $xAt($i) + 10, $bottom - 18, 26, 'Medium', GRAY);
        }
    }

    // "Feels like" as a dashed line, under the temperature curve.
    if ($showFeels) {
        $dashes = $spline($feels);
        for ($i = 0; $i + 3 < count($dashes); $i += 6) {
            $canvas->stroke(array_slice($dashes, $i, 4), 4, GRAY);
        }
    }
    $canvas->stroke($curve, 7, INK);

    // A dot on the curve for every column, larger on the warmest and coldest hour.
    $iMax = array_search($highest, $temps, true);
    $iMin = array_search($lowest, $temps, true);
    foreach (array_keys($cols) as $i) {
        $canvas->disc($xAt($i), $yAt($points[$i]['temp']), 8, INK);
        $canvas->disc($xAt($i), $yAt($points[$i]['temp']), 4, PAPER);
    }
    foreach (array_unique([$iMax, $iMin]) as $i) {
        $canvas->disc($xAt($i), $yAt($points[$i]['temp']), 13, INK);
        $canvas->disc($xAt($i), $yAt($points[$i]['temp']), 7.5, PAPER);
    }

    // Rows under the curve: chance of precipitation and amount for the three hours.
    $mm = array_column($points, 'mm');
    foreach ($cols as $i => $x) {
        if ($i >= $count - 1) {
            continue;
        }
        if ($points[$i]['pop'] !== null) {
            $canvas->text((int) (round((float) $points[$i]['pop'] / 5) * 5) . ' %', $x, $bottom + 40, 26, 'Medium', GRAY, 'center');
        }
        $sum = array_sum(array_slice($mm, $i, 3));
        $dry = $sum < 0.05;
        $amount = $dry ? '0' : number_format($sum, 1, decimal_mark(), '');
        $canvas->text($amount, $x, $bottom + 82, 28, $dry ? 'Medium' : 'Bold', $dry ? GRAY : INK, 'center');
    }
    $axisLabel('mm', $bottom + 82);

    // Precipitation per hour.
    $base = $bottom + 164;
    $maxMm = max(1.0, max($mm));
    for ($i = 0; $i < $count - 1; $i++) {
        if ($mm[$i] >= 0.05) {
            $barHeight = max(6, $mm[$i] / $maxMm * 48);
            $canvas->rect($xAt($i) + 4, $base - $barHeight, $step - 8, $barHeight, INK);
        }
    }
    $canvas->rect($x0, $base, $x1 - $x0, 3, INK);

    // Hours along the bottom.
    foreach ($cols as $i => $x) {
        $canvas->rect($xAt($i) - 1, $base, 2, 10, INK);
        $canvas->text($points[$i]['hour'], $x, $base + 40, 30, 'Medium', GRAY, 'center');
    }

    // Wind: direction pointer, speed, and gusts in parentheses.
    $axisLabel('m/s', $base + 96);
    foreach ($cols as $i => $x) {
        $point = $points[$i];
        $canvas->pointer($x, $base + 96, 18, $point['dir']);
        $speed = (string) (int) round($point['wind']);
        $gust = $point['gust'] === null ? '' : ' (' . (int) round((float) $point['gust']) . ')';
        $w = $canvas->textWidth($speed, 30, 'Bold') + $canvas->textWidth($gust, 24, 'Medium');
        $tx = max($x0 - 4, min($x1 + 10 - $w, $x - $w / 2));
        $canvas->text($speed, $tx, $base + 146, 30, 'Bold');
        if ($gust !== '') {
            $canvas->text($gust, $tx + $canvas->textWidth($speed, 30, 'Bold'), $base + 146, 24, 'Medium', GRAY);
        }
    }
}
