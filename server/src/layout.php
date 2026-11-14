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

function draw_header(Canvas $c, array $place, string $date, ?int $batt, bool $touch, bool $alert = false): void
{
    [$name, $area] = $place;
    $x = RIGHT;
    if ($touch) {
        // Close button. Kept faint on purpose; the tap area around it is much larger than the mark.
        $c->stroke([[$x - 32, 75], [$x, 107]], 4, FAINT);
        $c->stroke([[$x, 75], [$x - 32, 107]], 4, FAINT);
        $x -= 32 + 36;
    }

    if ($area === '') {
        // One line: place on the left; date and battery on the right.
        if ($batt !== null) {
            $low = $batt <= LOW_BATTERY;
            $label = $batt . ' %';
            $c->text($label, $x, 91, 30, $low ? 'Bold' : 'Medium', $low ? INK : GRAY, 'right');
            $x -= $c->textWidth($label, 30, $low ? 'Bold' : 'Medium') + 10 + 53;
            $c->battery($x, 76, $batt);
            $x -= 32;
        }
        $c->text($date, $x, 91, 46, 'Medium', INK, 'right');
        $room = $x - $c->textWidth($date, 46, 'Medium') - 24 - LEFT - ($alert ? 66 : 0);
        $size = $c->fit($name, 54, 'Bold', $room);
        $c->text($name, LEFT, 91, $size, 'Bold');
        $nameWidth = $c->textWidth($name, $size, 'Bold');
        $markY = 70;
    } else {
        // Two lines: street over town on the left; date over battery on the right.
        $c->text($date, $x, 76, 40, 'Medium', INK, 'right');
        if ($batt !== null) {
            $low = $batt <= LOW_BATTERY;
            $label = $batt . ' %' . ($low ? t(' · lad') : '');
            $c->text($label, $x, 119, 28, $low ? 'Bold' : 'Medium', $low ? INK : GRAY, 'right');
            $c->battery($x - $c->textWidth($label, 28, $low ? 'Bold' : 'Medium') - 10 - 53, 104, $batt);
        }
        $room = $x - $c->textWidth($date, 40, 'Medium') - 24 - LEFT - ($alert ? 62 : 0);
        $size = $c->fit($name, 46, 'Bold', $room);
        $c->text($name, LEFT, 76, $size, 'Bold');
        $c->text($area, LEFT, 119, 30, 'Medium', GRAY);
        $nameWidth = $c->textWidth($name, $size, 'Bold');
        $markY = 56;
    }
    if ($alert) {
        // Shown on every design while a weather alert is in force.
        $c->warning(LEFT + $nameWidth + 18, $markY, 44, INK, PAPER);
    }
    $c->rect(LEFT, 141, CONTENT, 5, INK);
}

/** Footer line, plus the tab bar when the screen is used with touch. */
function draw_footer(Canvas $c, string $updated, bool $touch = false, string $view = '', string $stale = ''): void
{
    $tabs = $touch && isset(active_tabs()[$view]);
    $y = $tabs ? 1312 : 1387;
    if ($stale !== '') {
        // The forecast itself is more than an hour old: make that hard to miss.
        $c->text(t('Oppdatert ') . $updated . t(' (varsel fra ') . $stale . ')', LEFT, $y, 28, 'Bold', INK);
    } else {
        $c->text(t('Oppdatert ') . $updated, LEFT, $y, 28, 'Medium', GRAY);
    }
    $c->text(t('Data fra Meteorologisk institutt'), RIGHT, $y, 28, 'Medium', GRAY, 'right');
    if (!$tabs) {
        return;
    }
    $c->rect(LEFT, TAB_TOP, CONTENT, 3, INK);
    // The flight button, when switched on, takes a fixed slice at the right end.
    $tabsWidth = CONTENT - (flight_button() ? FLIGHT_BUTTON_WIDTH : 0);
    if (flight_button()) {
        $c->rect(LEFT + $tabsWidth, TAB_TOP + 16, 2, TAB_HEIGHT - 26, LINE);
        $c->plane(LEFT + $tabsWidth + FLIGHT_BUTTON_WIDTH / 2, TAB_TOP + 36, 22, 45);
    }
    $w = $tabsWidth / count(active_tabs());
    $i = 0;
    $prevActive = true;
    foreach (active_tabs() as $key => $label) {
        $label = t($label);
        $x = LEFT + $i * $w;
        $active = $key === $view;
        if ($active) {
            $c->rect($x, TAB_TOP, $w, TAB_HEIGHT, INK);
        } elseif (!$prevActive) {
            $c->rect($x, TAB_TOP + 16, 2, TAB_HEIGHT - 26, LINE);
        }
        $c->text($label, $x + $w / 2, TAB_TOP + 37, 36, $active ? 'Bold' : 'Medium', $active ? PAPER : INK, 'center');
        $prevActive = $active;
        $i++;
    }
}

/** Black banner with the alert in force (or another notice: aurora, low battery). */
function draw_alert(Canvas $c, array $alert, float $y, float $h): void
{
    $c->rect(LEFT, $y, CONTENT, $h, INK);
    $cy = $y + $h / 2;
    if (($alert['icon'] ?? '') === 'aurora') {
        $c->sparkle(LEFT + 45, $cy, 24, PAPER);
    } else {
        $c->warning(LEFT + 22, $cy - 21, 46, PAPER, INK);
    }
    $room = CONTENT - 90 - 24;
    if ($alert['when'] !== '') {
        $c->text($alert['when'], RIGHT - 22, $cy, 30, 'Medium', PAPER, 'right');
        $room -= $c->textWidth($alert['when'], 30, 'Medium') + 24;
    }
    $c->text($alert['text'], LEFT + 90, $cy, $c->fit($alert['text'], 36, 'Bold', $room), 'Bold', PAPER);
}

function low_battery_banner(?int $batt): ?array
{
    return ($batt !== null && $batt <= LOW_BATTERY)
        ? ['text' => t('Lavt batteri: sett Kindle til lading'), 'when' => $batt . ' %']
        : null;
}

/** Radar strip: will it rain in the next 90 minutes? */
function draw_nowcast(Canvas $c, ?array $nc, float $y): void
{
    $c->text(t('Neste 90 minutter'), LEFT, $y + 28, 26, 'Medium', GRAY);
    if ($nc === null) {
        $c->text(t('Ingen radardata'), LEFT, $y + 68, 34, 'Bold');
        return;
    }
    $c->text($nc['text'], LEFT, $y + 68, $c->fit($nc['text'], 34, 'Bold', 390), 'Bold');

    $x0 = 480;
    $base = $y + 82;
    $pitch = (RIGHT - $x0) / count($nc['rates']);
    foreach ($nc['rates'] as $i => $rate) {
        $x = $x0 + $i * $pitch;
        if ($rate < 0.1) {
            $c->rect($x, $base - 3, $pitch - 8, 3, LINE);
        } else {
            // Square-root scale, so light rain is still visible next to heavy rain.
            $h = max(8, 44 * sqrt(min($rate, 6) / 6));
            $c->rect($x, $base - $h, $pitch - 8, $h, INK);
        }
    }
    $c->text(t('nå'), $x0, $y + 20, 22, 'Medium', GRAY);
    $c->text('90 min', RIGHT - 8, $y + 20, 22, 'Medium', GRAY, 'right');
}

/** Day rows: name, icon, short description, high / low. */
function draw_days(Canvas $c, array $days, float $top): void
{
    $c->rect(LEFT, $top, CONTENT, 3, INK);
    foreach ($days as $i => $d) {
        $y = $top + 3 + $i * 70;
        $c->text($d['day'], LEFT, $y + 34, 42, 'Bold');
        $c->icon($d['sym']['icon'], LEFT + 150, $y + 6, 56, 1.9);
        $c->text($d['sym']['short'], LEFT + 240, $y + 34, 38, 'Medium', GRAY);
        $c->text($d['temps'], RIGHT, $y + 34, 42, 'Bold', INK, 'right');
        if ($i < count($days) - 1) {
            $c->rect(LEFT, $y + 68, CONTENT, 2, LINE);
        }
    }
}

/** Compact current conditions: icon and temperature left, three lines of text right. */
function draw_current(Canvas $c, array $m, float $icon, float $tempSize, array $lines): void
{
    $c->icon($m['sym']['icon'], LEFT, 170, $icon, 1.5);
    $tempSize = $c->fit($m['temp'], $tempSize, 'Bold', 330);
    $tempX = LEFT + $icon + 28;
    $cy = 170 + $icon / 2;
    $c->text($m['temp'], $tempX, $cy, $tempSize, 'Bold');
    $room = RIGHT - $tempX - $c->textWidth($m['temp'], $tempSize, 'Bold') - 24;
    $label = $m['sym']['label'];
    $size = $c->fit($label, 64, 'Bold', $room);
    if ($size < 44) {
        $label = $m['sym']['short'];
        $size = $c->fit($label, 64, 'Bold', $room);
    }
    $c->text($label, RIGHT, $cy - 60, $size, 'Bold', INK, 'right');
    foreach ($lines as $i => $line) {
        $c->text($line, RIGHT, $cy + 8 + $i * 52, $c->fit($line, 38, 'Medium', $room), 'Medium', GRAY, 'right');
    }
}
