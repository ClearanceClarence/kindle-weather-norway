<?php

declare(strict_types=1);

/*
 * "Closing" screen.
 *
 * Shown from the moment the X is tapped until the Kindle's own interface is
 * back, which takes about half a minute. It is the one screen drawn white
 * on black: a sun setting over water, as a goodbye.
 *
 * It uses no weather data, so it can be drawn even when MET is unreachable.
 */

function render_closing(array $request, array $config): string
{
    $canvas = new Canvas($config['width'], $config['height'], $config['font_dir']);
    $canvas->rect(0, 0, $config['width'], $config['height'], INK);

    $mid = (LEFT + RIGHT) / 2;
    $horizon = 700;

    // A few stars. Positions are fixed so the picture is the same every time.
    $stars = [
        [120, 150, 3], [250, 90, 2], [330, 250, 2.5], [470, 130, 2], [610, 70, 3], [760, 170, 2],
        [880, 100, 2.5], [960, 260, 2], [170, 330, 2], [905, 380, 3], [90, 470, 2.5], [1000, 520, 2],
    ];
    foreach ($stars as [$x, $y, $r]) {
        $canvas->disc($x, $y, $r, 190);
    }

    // Rings of light around the sun, fading outwards.
    $rings = [[310, 7, 235], [365, 6, 195], [420, 5, 155], [475, 4, 120], [530, 3, 90]];
    foreach ($rings as [$radius, $width, $gray]) {
        $arc = [];
        for ($a = 180; $a <= 360; $a += 2) {
            $arc[] = [$mid + cos(deg2rad($a)) * $radius, $horizon + sin(deg2rad($a)) * $radius];
        }
        $canvas->stroke($arc, $width, $gray);
    }

    // The sun, cut by dark bands that widen towards the horizon.
    $canvas->disc($mid, $horizon, 250, PAPER);
    foreach ([[548, 5], [590, 8], [628, 12], [662, 16]] as [$y, $h]) {
        $canvas->rect($mid - 260, $y, 520, $h, INK);
    }
    $canvas->rect(0, $horizon, $config['width'], 300, INK);       // everything below the horizon
    $canvas->rect(LEFT, $horizon - 2, CONTENT, 4, PAPER);

    // Its reflection on the water: shorter and fainter with distance.
    $bars = [[430, 16, 255], [350, 13, 225], [270, 11, 195], [195, 9, 165], [125, 7, 135], [65, 5, 105]];
    $y = $horizon + 30;
    foreach ($bars as [$w, $h, $gray]) {
        $canvas->rect($mid - $w / 2, $y, $w, $h, $gray);
        $y += $h + 26;
    }

    $canvas->text(t('Takk for nå'), $mid, 1090, 120, 'Bold', PAPER, 'center');
    $canvas->text(t('Lukker værvarselet'), $mid, 1215, 46, 'Medium', 215, 'center');
    $canvas->text(t('Kindle starter opp igjen om et halvt minutt'), $mid, 1285, 36, 'Medium', 165, 'center');
    return $canvas->png();
}
