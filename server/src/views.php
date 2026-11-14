<?php

declare(strict_types=1);

/*
 * The list of screens.
 *
 * To add a tab:
 *   1. Write the screen in src/views/<name>.php as a function
 *        render_<name>(array $m, array $req, array $config): string
 *      that returns PNG bytes. Copy an existing view to start from.
 *   2. Add it to TAB_LABELS and SCREENS below.
 *   3. Switch it on under "Faner" on the admin page (or in 'tabs' in
 *      config.php). The Kindle learns the tab list from the server.
 *
 * The tab bar divides the screen width evenly, so any number of tabs works;
 * four or five stay comfortable to tap.
 */

// Every tab on offer, in the order they appear: key => label.
const TAB_LABELS = [
    'now'   => 'Nå',
    'big'   => 'Stor',
    'graph' => 'Graf',
    'week'  => 'Uke',
    'hours' => 'Timer',
];

// Every screen that needs the forecast: key => function that draws it.
const SCREENS = [
    'now'     => 'render_now',
    'big'     => 'render_big',
    'graph'   => 'render_graph',
    'week'    => 'render_week',
    'hours'   => 'render_hours',
    'night'   => 'render_night',
    'offline' => 'render_offline',
];

/**
 * Whether the weather screens show the button that opens the flight screen.
 * render_request() sets it once per request; the tab bar reads it.
 */
function flight_button(?bool $show = null): bool
{
    static $on = false;
    if ($show !== null) {
        $on = $show;
    }
    return $on;
}

/**
 * The tabs that are switched on, as key => label. render_request() calls
 * this with the configured list once per request; the tab bar reads it.
 */
function active_tabs(?array $keys = null): array
{
    static $tabs = TAB_LABELS;
    if ($keys !== null) {
        $tabs = array_intersect_key(TAB_LABELS, array_flip($keys)) ?: TAB_LABELS;
    }
    return $tabs;
}
