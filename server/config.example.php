<?php

/*
 * Settings for the image server. Copy this file to config.php and edit it.
 * config.php is ignored by git, so your address and email stay private.
 */

return [
    // Header text. 'place' is the first line; 'area' is a smaller second
    // line. Leave 'area' as '' for a one-line header.
    'place' => 'Storgata 1',
    'area'  => 'Skien',

    // Where to get the forecast for. At most 4 decimals (MET Norway's terms).
    'lat' => 59.2096,
    'lon' => 9.6090,

    // MET Norway asks every client to identify itself. Put your email or
    // website here; requests without one may be blocked.
    'contact' => 'you@example.com',

    // Optional settings, shown with their defaults:
    // 'language'    => 'no',     // 'no' (Norwegian) or 'en' (English)
    // 'timezone'    => 'Europe/Oslo',
    // 'min_refetch' => 600,      // never ask MET more often than this (seconds)
    // 'cache_dir'   => __DIR__ . '/cache',
    // 'font_dir'    => __DIR__ . '/fonts',
];
