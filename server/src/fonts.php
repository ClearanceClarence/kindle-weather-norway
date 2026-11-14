<?php

declare(strict_types=1);

/*
 * Typefaces: the built-in ones (FONTS in settings.php) plus any added from
 * Google Fonts on the admin page.
 *
 * A Google font is downloaded once, when it is added, and stored in
 * data/fonts. After that the server never contacts Google for it, and the
 * Kindle never does at all: it only ever receives finished pictures.
 */

// Most typefaces that can be installed at the same time.
const MAX_GOOGLE_FONTS = 12;

// Names suggested in the "add a typeface" field. Any Google Fonts family works.
const GOOGLE_FONT_SUGGESTIONS = [
    'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Nunito', 'Nunito Sans', 'Work Sans', 'DM Sans',
    'Manrope', 'Rubik', 'Figtree', 'Outfit', 'Lexend', 'Source Sans 3', 'IBM Plex Sans', 'Fira Sans',
    'Space Grotesk', 'Oswald', 'Bebas Neue', 'Archivo Narrow', 'Roboto Condensed', 'Roboto Slab',
    'Merriweather', 'Lora', 'Playfair Display', 'Libre Baskerville', 'EB Garamond', 'Bitter',
    'Crimson Pro', 'Zilla Slab', 'JetBrains Mono', 'Space Mono', 'Roboto Mono', 'Comfortaa', 'Quicksand',
];

// Characters every typeface must have, and ones that can be swapped for a
// plainer character when a typeface lacks them.
const FONT_REQUIRED_CHARS = ['0', 'A', 'æ', 'ø', 'å', 'Æ', 'Ø', 'Å', '°'];
const FONT_FALLBACK_CHARS = ['−' => '-', '–' => '-', '·' => '-', 'é' => 'e'];

/**
 * Every typeface that can be chosen: key => label, the full paths of the
 * 'Medium' and 'Bold' files, a 'scale' size factor, a 'replace' map of
 * characters to swap, and 'google' (true for downloaded ones).
 */
function font_catalog(string $serverDir): array
{
    $catalog = [];
    foreach (FONTS as $key => $font) {
        $catalog[$key] = [
            'label'   => $font['label'],
            'Medium'  => $serverDir . '/fonts/' . $font['Medium'],
            'Bold'    => $serverDir . '/fonts/' . $font['Bold'],
            'scale'   => $font['scale'],
            'replace' => [],
            'google'  => false,
        ];
    }
    foreach (load_store($serverDir)['google_fonts'] ?? [] as $key => $font) {
        $medium = google_font_dir($serverDir) . '/' . $font['Medium'];
        $bold = google_font_dir($serverDir) . '/' . $font['Bold'];
        if (is_file($medium) && is_file($bold)) {
            $catalog[$key] = [
                'label'   => $font['label'],
                'Medium'  => $medium,
                'Bold'    => $bold,
                'scale'   => (float) $font['scale'],
                'replace' => (array) $font['replace'],
                'google'  => true,
            ];
        }
    }
    return $catalog;
}

function google_font_dir(string $serverDir): string
{
    return $serverDir . '/data/fonts';
}

/**
 * Fetches a URL without identifying the site owner. Returns the body, an
 * empty string when the server answers with an error (such as "no such
 * font"), or null when there is no contact or the answer is too large.
 */
function plain_http_get(string $url, int $maxBytes): ?string
{
    $context = stream_context_create(['http' => [
        'timeout'       => 8,
        'ignore_errors' => true,
        // Google Fonts sends TrueType files to clients it does not recognize
        // as a modern browser, which is the format the drawing code needs.
        'header'        => "User-Agent: kindle-weather-norway\r\n",
    ]]);
    $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
    if ($body === false || strlen($body) > $maxBytes) {
        return null;
    }
    $ok = preg_match('~^HTTP/\S+ 2\d\d~', $http_response_header[0] ?? '') === 1;
    return $ok ? $body : '';
}

/**
 * Downloads a Google Fonts family and adds it to the store (which the
 * caller saves). Returns the new typeface's key, or null with a message for
 * the admin page in $error.
 *
 * $fetch replaces the HTTP client in tests; see plain_http_get() for what it returns.
 */
function google_font_install(string $serverDir, string $family, array &$store, ?string &$error, ?callable $fetch = null): ?string
{
    $fetch ??= 'plain_http_get';
    $error = null;
    $family = trim(preg_replace('/\s+/', ' ', $family) ?? '');
    if (!preg_match('/^[A-Za-z0-9 ]{2,60}$/', $family)) {
        $error = t('Skriv navnet slik det står på Google Fonts, for eksempel «Lora».');
        return null;
    }
    $key = 'g-' . preg_replace('/[^a-z0-9]+/', '', strtolower($family));
    $installed = $store['google_fonts'] ?? [];
    if (!isset($installed[$key]) && count($installed) >= MAX_GOOGLE_FONTS) {
        $error = t('Det er plass til ') . MAX_GOOGLE_FONTS . t(' skrifter. Fjern en først.');
        return null;
    }

    // Ask for a regular and a bold weight, settling for less if the family
    // does not have them. Google answers with an error when a weight is missing.
    $files = [];
    $label = $family;
    foreach (['500;700', '400;700', '400;600', '400'] as $weights) {
        $css = $fetch('https://fonts.googleapis.com/css2?family=' . rawurlencode($family) . ':wght@' . $weights, 200000);
        if ($css === null) {
            $error = t('Tjeneren fikk ikke kontakt med Google Fonts. Prøv igjen senere.');
            return null;
        }
        $files = google_font_urls($css, $label);
        if ($files !== []) {
            break;
        }
    }
    if ($files === []) {
        $error = t('Fant ikke «') . $family . t('» på Google Fonts. Sjekk stavemåten.');
        return null;
    }
    ksort($files);
    $urls = ['Medium' => reset($files), 'Bold' => end($files)];

    $dir = google_font_dir($serverDir);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        $error = t('Kunne ikke lagre. Serveren må kunne skrive til mappen «data».');
        return null;
    }
    $names = [];
    foreach ($urls as $weight => $url) {
        $data = $fetch($url, 6000000);
        // TrueType and OpenType files start with one of these four bytes.
        if ($data === null || $data === '' || !in_array(substr($data, 0, 4), ["\x00\x01\x00\x00", 'OTTO', 'true'], true)) {
            $error = t('Kunne ikke laste ned skriften fra Google.');
            return null;
        }
        $names[$weight] = $key . '-' . $weight . '.ttf';
        if (@file_put_contents($dir . '/' . $names[$weight], $data, LOCK_EX) === false) {
            $error = t('Kunne ikke lagre. Serveren må kunne skrive til mappen «data».');
            return null;
        }
    }

    $medium = $dir . '/' . $names['Medium'];
    $bold = $dir . '/' . $names['Bold'];
    $missing = array_unique(array_merge(
        font_missing_chars($medium, FONT_REQUIRED_CHARS),
        font_missing_chars($bold, FONT_REQUIRED_CHARS)
    ));
    if ($missing !== []) {
        @unlink($medium);
        @unlink($bold);
        $error = t('«') . $label . t('» mangler tegn som skjermen trenger: ') . implode(' ', $missing);
        return null;
    }
    $replace = [];
    foreach (array_unique(array_merge(
        font_missing_chars($medium, array_keys(FONT_FALLBACK_CHARS)),
        font_missing_chars($bold, array_keys(FONT_FALLBACK_CHARS))
    )) as $char) {
        $replace[$char] = FONT_FALLBACK_CHARS[$char];
    }

    $store['google_fonts'][$key] = [
        'label'   => $label,
        'Medium'  => $names['Medium'],
        'Bold'    => $names['Bold'],
        'scale'   => font_auto_scale($serverDir, $medium, $bold),
        'replace' => $replace,
    ];
    return $key;
}

/**
 * Reads Google's stylesheet: weight => font file address. Only TrueType and
 * OpenType files on Google's font host are accepted. Sets $label to the
 * family name as Google spells it.
 */
function google_font_urls(string $css, string &$label): array
{
    $files = [];
    preg_match_all('/@font-face\s*\{([^}]*)\}/', $css, $blocks);
    foreach ($blocks[1] as $block) {
        if (
            preg_match('/font-style:\s*normal/', $block)
            && preg_match('/font-weight:\s*(\d{3})/', $block, $weight)
            && preg_match('~url\((https://fonts\.gstatic\.com/[A-Za-z0-9/_.\-]+\.(?:ttf|otf))\)~', $block, $url)
        ) {
            $files[(int) $weight[1]] ??= $url[1];
            if (preg_match("/font-family:\s*'([A-Za-z0-9 ]{2,60})'/", $block, $name)) {
                $label = $name[1];
            }
        }
    }
    return $files;
}

/** Removes a downloaded typeface from the store (which the caller saves) and from disk. */
function google_font_remove(string $serverDir, string $key, array &$store): void
{
    $font = $store['google_fonts'][$key] ?? null;
    if ($font === null) {
        return;
    }
    foreach (['Medium', 'Bold'] as $weight) {
        @unlink(google_font_dir($serverDir) . '/' . basename((string) $font[$weight]));
    }
    unset($store['google_fonts'][$key]);
    if (($store['settings']['font'] ?? '') === $key) {
        $store['settings']['font'] = SETTING_DEFAULTS['font'];
    }
}

/**
 * Which of $chars a font file cannot draw. A font draws its "missing glyph"
 * box (or nothing) for characters it lacks, so each character is drawn and
 * compared with a character no text font has.
 */
function font_missing_chars(string $file, array $chars): array
{
    $draw = function (string $char) use ($file): string {
        $im = imagecreatetruecolor(96, 96);
        imagefilledrectangle($im, 0, 0, 96, 96, 0xFFFFFF);
        @imagettftext($im, 40, 0, 20, 70, 0x000000, $file, $char);
        $pixels = '';
        for ($y = 0; $y < 96; $y += 2) {
            for ($x = 0; $x < 96; $x += 2) {
                $pixels .= (imagecolorat($im, $x, $y) & 0xFF) < 128 ? '1' : '0';
            }
        }
        return $pixels;
    };
    $unknown = $draw("\u{E0FF}");        // private-use character
    $blank = str_repeat('0', strlen($unknown));
    $missing = [];
    foreach ($chars as $char) {
        $shape = $draw($char);
        if ($shape === $unknown || $shape === $blank) {
            $missing[] = $char;
        }
    }
    return $missing;
}

/**
 * Size factor for a new typeface: how wide it runs compared with Inter,
 * which the layouts were drawn for. Wide typefaces are shrunk so text
 * does not collide; narrow ones are left as they are.
 */
function font_auto_scale(string $serverDir, string $medium, string $bold): float
{
    $width = function (string $file, string $text): float {
        $box = imagettfbbox(40, 0, $file, $text);
        return (float) ($box[2] - $box[0]);
    };
    $samples = [
        ['Medium', 'Delvis skyet 08:07–16:04 Føles som 12°'],
        ['Bold', 'Lett regn 7 m/s SV 3,4 mm 6° / 4°'],
    ];
    $ratio = 0.0;
    foreach ($samples as [$weight, $text]) {
        $reference = $serverDir . '/fonts/' . FONTS['inter'][$weight];
        $ratio += $width($reference, $text) / max(1.0, $width($weight === 'Bold' ? $bold : $medium, $text));
    }
    // Lean toward smaller: text that is a little small still reads, text that collides does not.
    return round(max(0.8, min(1.0, $ratio / 2 * 0.96)), 2);
}
