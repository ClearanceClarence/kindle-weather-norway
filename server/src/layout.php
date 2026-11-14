<?php

declare(strict_types=1);

/*
 * Pieces shared between screens: header, footer with tab bar, alert banner,
 * radar strip, day rows, and the compact "current conditions" block.
 *
 * All coordinates are screen pixels. Text is positioned by the vertical
 * middle of its capital letters unless a call says 'baseline'.
 */

// Horizontal layout shared by every screen (the screen is 1072 x 1448).
const LEFT = 64;
const RIGHT = 1008;
const CONTENT = 944;                 // RIGHT - LEFT

// Touch layout. The Kindle script (server/kindle/kindle-weather.sh) uses the same
// numbers to decide what a tap means: the close button sits in the top right
// corner and the tab bar fills the strip below TAB_TOP.
const TAB_TOP = 1340;
const TAB_HEIGHT = 68;
const FLIGHT_BUTTON_WIDTH = 130;   // the plane button at the right end of the tab bar

// At or below this battery level the display asks to be charged.
const LOW_BATTERY = 15;

/** The top of every screen: place, date, battery, the close button, and a warning triangle while an alert is in force. */
function draw_header(Canvas $canvas, array $place, string $date, ?int $battery, bool $touch, bool $alert = false): void
{
    [$name, $area] = $place;
    $x = RIGHT;
    if ($touch) {
        // Close button. Kept faint on purpose; the tap area around it is much larger than the mark.
        $canvas->stroke([[$x - 32, 75], [$x, 107]], 4, FAINT);
        $canvas->stroke([[$x, 75], [$x - 32, 107]], 4, FAINT);
        $x -= 32 + 36;
    }

    if ($area === '') {
        // One line: place on the left; date and battery on the right.
        if ($battery !== null) {
            $low = $battery <= LOW_BATTERY;
            $label = $battery . ' %';
            $canvas->text($label, $x, 91, 30, $low ? 'Bold' : 'Medium', $low ? INK : GRAY, 'right');
            $x -= $canvas->textWidth($label, 30, $low ? 'Bold' : 'Medium') + 10 + 53;
            $canvas->battery($x, 76, $battery);
            $x -= 32;
        }
        $canvas->text($date, $x, 91, 46, 'Medium', INK, 'right');
        $room = $x - $canvas->textWidth($date, 46, 'Medium') - 24 - LEFT - ($alert ? 66 : 0);
        $size = $canvas->fit($name, 54, 'Bold', $room);
        $canvas->text($name, LEFT, 91, $size, 'Bold');
        $nameWidth = $canvas->textWidth($name, $size, 'Bold');
        $markY = 70;
    } else {
        // Two lines: street over town on the left; date over battery on the right.
        $canvas->text($date, $x, 76, 40, 'Medium', INK, 'right');
        if ($battery !== null) {
            $low = $battery <= LOW_BATTERY;
            $label = $battery . ' %' . ($low ? t(' · lad') : '');
            $canvas->text($label, $x, 119, 28, $low ? 'Bold' : 'Medium', $low ? INK : GRAY, 'right');
            $canvas->battery($x - $canvas->textWidth($label, 28, $low ? 'Bold' : 'Medium') - 10 - 53, 104, $battery);
        }
        $room = $x - $canvas->textWidth($date, 40, 'Medium') - 24 - LEFT - ($alert ? 62 : 0);
        $size = $canvas->fit($name, 46, 'Bold', $room);
        $canvas->text($name, LEFT, 76, $size, 'Bold');
        $canvas->text($area, LEFT, 119, 30, 'Medium', GRAY);
        $nameWidth = $canvas->textWidth($name, $size, 'Bold');
        $markY = 56;
    }
    if ($alert) {
        // Shown on every design while a weather alert is in force.
        $canvas->warning(LEFT + $nameWidth + 18, $markY, 44, INK, PAPER);
    }
    $canvas->rect(LEFT, 141, CONTENT, 5, INK);
}

/** Footer line, plus the tab bar when the screen is used with touch. */
function draw_footer(Canvas $canvas, string $updated, bool $touch = false, string $view = '', string $stale = ''): void
{
    $tabs = $touch && isset(active_tabs()[$view]);
    $y = $tabs ? 1312 : 1387;
    if ($stale !== '') {
        // The forecast itself is more than an hour old: make that hard to miss.
        $canvas->text(t('Oppdatert ') . $updated . t(' (varsel fra ') . $stale . ')', LEFT, $y, 28, 'Bold', INK);
    } else {
        $canvas->text(t('Oppdatert ') . $updated, LEFT, $y, 28, 'Medium', GRAY);
    }
    $canvas->text(t('Data fra Meteorologisk institutt'), RIGHT, $y, 28, 'Medium', GRAY, 'right');
    if (!$tabs) {
        return;
    }
    $canvas->rect(LEFT, TAB_TOP, CONTENT, 3, INK);
    // The flight button, when switched on, takes a fixed slice at the right end.
    $tabsWidth = CONTENT - (flight_button() ? FLIGHT_BUTTON_WIDTH : 0);
    if (flight_button()) {
        $canvas->rect(LEFT + $tabsWidth, TAB_TOP + 16, 2, TAB_HEIGHT - 26, LINE);
        $canvas->plane(LEFT + $tabsWidth + FLIGHT_BUTTON_WIDTH / 2, TAB_TOP + 36, 22, 45);
    }
    $w = $tabsWidth / count(active_tabs());
    $i = 0;
    $prevActive = true;
    foreach (active_tabs() as $key => $label) {
        $label = t($label);
        $x = LEFT + $i * $w;
        $active = $key === $view;
        if ($active) {
            $canvas->rect($x, TAB_TOP, $w, TAB_HEIGHT, INK);
        } elseif (!$prevActive) {
            $canvas->rect($x, TAB_TOP + 16, 2, TAB_HEIGHT - 26, LINE);
        }
        $canvas->text($label, $x + $w / 2, TAB_TOP + 37, 36, $active ? 'Bold' : 'Medium', $active ? PAPER : INK, 'center');
        $prevActive = $active;
        $i++;
    }
}

/** Black banner with the alert in force (or another notice: aurora, low battery). */
function draw_alert(Canvas $canvas, array $alert, float $y, float $height): void
{
    $canvas->rect(LEFT, $y, CONTENT, $height, INK);
    $cy = $y + $height / 2;
    if (($alert['icon'] ?? '') === 'aurora') {
        $canvas->sparkle(LEFT + 45, $cy, 24, PAPER);
    } else {
        $canvas->warning(LEFT + 22, $cy - 21, 46, PAPER, INK);
    }
    $room = CONTENT - 90 - 24;
    if ($alert['when'] !== '') {
        $canvas->text($alert['when'], RIGHT - 22, $cy, 30, 'Medium', PAPER, 'right');
        $room -= $canvas->textWidth($alert['when'], 30, 'Medium') + 24;
    }
    $canvas->text($alert['text'], LEFT + 90, $cy, $canvas->fit($alert['text'], 36, 'Bold', $room), 'Bold', PAPER);
}

function low_battery_banner(?int $battery): ?array
{
    return ($battery !== null && $battery <= LOW_BATTERY)
        ? ['text' => t('Lavt batteri: sett Kindle til lading'), 'when' => $battery . ' %']
        : null;
}

/** Radar strip: will it rain in the next 90 minutes? */
function draw_nowcast(Canvas $canvas, ?array $nowcast, float $y): void
{
    $canvas->text(t('Neste 90 minutter'), LEFT, $y + 28, 26, 'Medium', GRAY);
    if ($nowcast === null) {
        $canvas->text(t('Ingen radardata'), LEFT, $y + 68, 34, 'Bold');
        return;
    }
    $canvas->text($nowcast['text'], LEFT, $y + 68, $canvas->fit($nowcast['text'], 34, 'Bold', 390), 'Bold');

    $x0 = 480;
    $base = $y + 82;
    $pitch = (RIGHT - $x0) / count($nowcast['rates']);
    foreach ($nowcast['rates'] as $i => $rate) {
        $x = $x0 + $i * $pitch;
        if ($rate < 0.1) {
            $canvas->rect($x, $base - 3, $pitch - 8, 3, LINE);
        } else {
            // Square-root scale, so light rain is still visible next to heavy rain.
            $height = max(8, 44 * sqrt(min($rate, 6) / 6));
            $canvas->rect($x, $base - $height, $pitch - 8, $height, INK);
        }
    }
    $canvas->text(t('nå'), $x0, $y + 20, 22, 'Medium', GRAY);
    $canvas->text('90 min', RIGHT - 8, $y + 20, 22, 'Medium', GRAY, 'right');
}

/** Compact current conditions: icon and temperature left, three lines of text right. */
function draw_current(Canvas $canvas, array $model, float $icon, float $tempSize, array $lines): void
{
    $canvas->icon($model['sym']['icon'], LEFT, 170, $icon, 1.5);
    $tempSize = $canvas->fit($model['temp'], $tempSize, 'Bold', 330);
    $tempX = LEFT + $icon + 28;
    $cy = 170 + $icon / 2;
    $canvas->text($model['temp'], $tempX, $cy, $tempSize, 'Bold');
    $room = RIGHT - $tempX - $canvas->textWidth($model['temp'], $tempSize, 'Bold') - 24;
    $label = $model['sym']['label'];
    $size = $canvas->fit($label, 64, 'Bold', $room);
    if ($size < 44) {
        $label = $model['sym']['short'];
        $size = $canvas->fit($label, 64, 'Bold', $room);
    }
    $canvas->text($label, RIGHT, $cy - 60, $size, 'Bold', INK, 'right');
    foreach ($lines as $i => $line) {
        $canvas->text($line, RIGHT, $cy + 8 + $i * 52, $canvas->fit($line, 38, 'Medium', $room), 'Medium', GRAY, 'right');
    }
}
