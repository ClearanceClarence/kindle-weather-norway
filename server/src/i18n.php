<?php

declare(strict_types=1);

/*
 * Language: Norwegian or English.
 *
 * The screens are written in Norwegian in the code. t() hands a text back
 * unchanged for Norwegian and looks it up in lang/en.php for English. Texts
 * that are put together from pieces ("Føles som " . $temp) are translated
 * piece by piece, so the pieces in lang/en.php keep their spaces.
 *
 * The admin page is translated differently: see lang/admin.en.php.
 */

const LANGUAGES = ['no' => 'Norsk', 'en' => 'English'];

/** The language in use: 'no' or 'en'. Give a value to set it. */
function language(?string $set = null): string
{
    static $language = 'no';
    if ($set !== null && isset(LANGUAGES[$set])) {
        $language = $set;
    }
    return $language;
}

/** A text in the language in use. Texts without an English version stay as they are. */
function t(string $text): string
{
    if (language() === 'no') {
        return $text;
    }
    static $english = null;
    $english ??= require __DIR__ . '/lang/en.php';
    return $english[$text] ?? $text;
}

/** A date for the header: "Lørdag 14. nov." or "Saturday 14 Nov". */
function date_text(int $time): string
{
    $weekday = t(WEEKDAYS[(int) date('w', $time)]);
    $month = t(MONTHS[(int) date('n', $time) - 1]);
    return $weekday . ' ' . date('j', $time) . (language() === 'no' ? '. ' : ' ') . $month;
}

/** The decimal mark: a comma in Norwegian, a point in English. */
function decimal_mark(): string
{
    return language() === 'no' ? ',' : '.';
}

/** A whole number with thousands separated: "10 400" or "10,400". */
function thousands(int $number): string
{
    return number_format($number, 0, '', language() === 'no' ? ' ' : ',');
}

/**
 * Turns the finished admin page into English, using lang/admin.en.php:
 * whole texts between tags, the texts of a few attributes, and quoted
 * texts in the page's script. Anything not listed there is left as it is.
 */
function translate_admin_page(string $html): string
{
    if (language() === 'no') {
        return $html;
    }
    $english = require __DIR__ . '/lang/admin.en.php';
    $text = $english['text'];

    // The script first: its quoted texts are swapped as they stand.
    $html = preg_replace_callback(
        '~<script\b[^>]*>.*?</script>~s',
        fn(array $match) => strtr($match[0], $english['script']),
        $html
    ) ?? $html;

    // Texts between tags, keeping the spaces around them.
    $html = preg_replace_callback(
        '~>([^<>]+)<~',
        function (array $match) use ($text): string {
            $trimmed = trim($match[1]);
            if ($trimmed === '' || !isset($text[$trimmed])) {
                return $match[0];
            }
            return '>' . str_replace($trimmed, htmlspecialchars($text[$trimmed], ENT_NOQUOTES), $match[1]) . '<';
        },
        $html
    ) ?? $html;

    // Attributes that hold text for the reader.
    return preg_replace_callback(
        '~\b(placeholder|aria-label|alt|title)="([^"]*)"~',
        fn(array $match) => isset($text[$match[2]]) ? $match[1] . '="' . htmlspecialchars($text[$match[2]]) . '"' : $match[0],
        $html
    ) ?? $html;
}
