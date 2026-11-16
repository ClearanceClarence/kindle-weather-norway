<?php

declare(strict_types=1);

/*
 * Canvas: the drawing surface every screen is painted on.
 *
 * It wraps PHP's GD library with the handful of operations the screens need
 * (rectangles, discs, round strokes, text, weather icons) and writes the
 * result as the exact PNG flavor the Kindle's "eips" tool accepts.
 */

// Gray levels, 0 (black) to 255 (white). The Kindle shows 16 levels, so keep
// to a few clearly different ones.
const INK   = 0;      // text and strong lines
const GRAY  = 74;     // secondary text
const LINE  = 138;    // thin dividers
const FAINT = 176;    // the close button
const PAPER = 255;    // background

// Fills used by the two-tone ("duotone") icon set.
const CLOUD_FILL = 200;
const SUN_FILL = 228;

/**
 * A small drawing surface. All coordinates are in final screen pixels; the
 * image is drawn at 2x and scaled down at the end so shapes come out smooth.
 */
final class Canvas
{
    /**
     * Which icon set icon() draws with. "outline" and "duotone" are drawn
     * by code; any other name is a folder of PNG files in $iconDir.
     * Set once per request, in render_request().
     */
    public static string $iconSet = 'outline';
    /** Typeface for all text: one entry of font_catalog(). Null = Inter from the fonts folder. */
    public static ?array $typeface = null;
    public static string $iconDir = '';

    private \GdImage $im;
    private int $scale = 2;
    private array $colors = [];

    public function __construct(private int $w, private int $h, private string $fontDir)
    {
        $this->im = imagecreatetruecolor($w * $this->scale, $h * $this->scale);
        imagefilledrectangle($this->im, 0, 0, $w * $this->scale, $h * $this->scale, $this->color(PAPER));
    }

    private function color(int $gray): int
    {
        return $this->colors[$gray] ??= imagecolorallocate($this->im, $gray, $gray, $gray);
    }

    private function font(string $weight): string
    {
        return self::$typeface[$weight] ?? $this->fontDir . '/' . FONTS['inter'][$weight];
    }

    public function rect(float $x, float $y, float $w, float $h, int $gray): void
    {
        $scale = $this->scale;
        imagefilledrectangle(
            $this->im,
            (int) round($x * $scale),
            (int) round($y * $scale),
            (int) round(($x + $w) * $scale) - 1,
            (int) round(($y + $h) * $scale) - 1,
            $this->color($gray)
        );
    }

    public function disc(float $cx, float $cy, float $r, int $gray): void
    {
        $scale = $this->scale;
        $d = max(1, (int) round($r * 2 * $scale));
        imagefilledellipse($this->im, (int) round($cx * $scale), (int) round($cy * $scale), $d, $d, $this->color($gray));
    }

    /** A filled bar with fully rounded ends; a circle when it is as wide as it is high. */
    public function pill(float $x, float $y, float $width, float $height, int $gray): void
    {
        $radius = $height / 2;
        $this->disc($x + $radius, $y + $radius, $radius, $gray);
        $this->disc($x + $width - $radius, $y + $radius, $radius, $gray);
        $this->rect($x + $radius, $y, $width - $height, $height, $gray);
    }

    /** The outline of a pill, $strokeWidth thick, with $fill inside. */
    public function pillOutline(float $x, float $y, float $width, float $height, float $strokeWidth, int $gray, int $fill = PAPER): void
    {
        $this->pill($x, $y, $width, $height, $gray);
        $this->pill($x + $strokeWidth, $y + $strokeWidth, $width - 2 * $strokeWidth, $height - 2 * $strokeWidth, $fill);
    }

    /** Draws a round-capped stroke through the given points by stamping discs. */
    public function stroke(array $pts, float $strokeWidth, int $gray): void
    {
        $step = max(0.25, $strokeWidth / 6);
        $n = count($pts);
        if ($n === 1) {
            $this->disc($pts[0][0], $pts[0][1], $strokeWidth / 2, $gray);
            return;
        }
        for ($i = 0; $i < $n - 1; $i++) {
            [$x1, $y1] = $pts[$i];
            [$x2, $y2] = $pts[$i + 1];
            $len = hypot($x2 - $x1, $y2 - $y1);
            $count = max(1, (int) ceil($len / $step));
            for ($k = 0; $k <= $count; $k++) {
                $f = $k / $count;
                $this->disc($x1 + ($x2 - $x1) * $f, $y1 + ($y2 - $y1) * $f, $strokeWidth / 2, $gray);
            }
        }
    }

    public function textWidth(string $text, float $size, string $weight): float
    {
        $text = strtr($text, self::$typeface['replace'] ?? []);
        $b = imagettfbbox($size * $this->scale * 0.75 * (self::$typeface['scale'] ?? 1.0), 0, $this->font($weight), $text);
        return ($b[2] - $b[0]) / $this->scale;
    }

    /** Largest size up to $size at which the text fits in $maxWidth. */
    public function fit(string $text, float $size, string $weight, float $maxWidth): float
    {
        $w = $this->textWidth($text, $size, $weight);
        return $w <= $maxWidth ? $size : floor($size * $maxWidth / $w);
    }

    /**
     * Draws text. $y is the vertical middle of capital letters and digits
     * ($valign 'middle') or the baseline ($valign 'baseline').
     */
    public function text(
        string $text,
        float $x,
        float $y,
        float $size,
        string $weight,
        int $gray = INK,
        string $align = 'left',
        string $valign = 'middle'
    ): void {
        $scale = $this->scale;
        $text = strtr($text, self::$typeface['replace'] ?? []);
        $pt = $size * $scale * 0.75 * (self::$typeface['scale'] ?? 1.0);
        $font = $this->font($weight);
        $b = imagettfbbox($pt, 0, $font, $text);
        $px = match ($align) {
            'right'  => $x * $scale - $b[2],
            'center' => $x * $scale - ($b[0] + $b[2]) / 2,
            default  => $x * $scale - $b[0],
        };
        $baseline = $valign === 'baseline' ? $y * $scale : ($y + 0.364 * $size) * $scale;
        imagettftext($this->im, $pt, 0, (int) round($px), (int) round($baseline), $this->color($gray), $font, $text);
    }

    // --- icons -------------------------------------------------------------
    // Icons are defined on a 24x24 grid, like the SVG icons in the design.

    /** Cloud outline. ($tx,$ty,$k) place and scale it within the icon grid. */
    private function cloud(float $x, float $y, float $u, float $strokeWidth, float $tx = 0, float $ty = 0, float $k = 1, bool $mask = false): void
    {
        $circles = [[7, 10, 4], [11.6, 8, 5.5], [16.07, 10.6, 3.5]];
        $P = fn(float $ux, float $uy) => [$x + ($tx + $ux * $k) * $u, $y + ($ty + $uy * $k) * $u];
        $half = $strokeWidth / 2;
        $passes = $mask ? [[$half + 0.9 * $u, PAPER]] : [];
        $passes[] = [$half, INK];
        $passes[] = [-$half, self::$iconSet === 'duotone' ? CLOUD_FILL : PAPER];
        foreach ($passes as [$grow, $color]) {
            foreach ($circles as [$cx, $cy, $r]) {
                [$px, $py] = $P($cx, $cy);
                $this->disc($px, $py, $r * $k * $u + $grow, $color);
            }
            [$x1, $y1] = $P(7, 8);
            [$x2, $y2] = $P(16.07, 14);
            $this->rect($x1, $y1, $x2 - $x1, $y2 - $y1 + $grow, $color);
        }
    }

    private function ring(float $cx, float $cy, float $r, float $strokeWidth, int $fill = PAPER): void
    {
        $this->disc($cx, $cy, $r + $strokeWidth / 2, INK);
        $this->disc($cx, $cy, $r - $strokeWidth / 2, $fill);
    }

    private function sunAt(float $cx, float $cy, float $r, float $rayIn, float $rayOut, float $strokeWidth): void
    {
        $this->ring($cx, $cy, $r, $strokeWidth, self::$iconSet === 'duotone' ? SUN_FILL : PAPER);
        for ($i = 0; $i < 8; $i++) {
            $a = $i * M_PI / 4;
            $this->stroke([
                [$cx + cos($a) * $rayIn, $cy + sin($a) * $rayIn],
                [$cx + cos($a) * $rayOut, $cy + sin($a) * $rayOut],
            ], $strokeWidth, INK);
        }
    }

    private function moonAt(float $cx, float $cy, float $r): void
    {
        $bx = $cx + $r * 0.45;
        $by = $cy - $r * 0.35;
        $this->crescent($cx, $cy, $r, $bx, $by, $r * 0.88, INK);
        if (self::$iconSet === 'duotone') {
            $t = $r * 0.14;
            $this->crescent($cx, $cy, $r - $t, $bx, $by, $r * 0.88 + $t, CLOUD_FILL);
        }
    }

    /**
     * Fills the part of disc A (center $ax,$ay, radius $ar) that lies outside
     * disc B, one thin row at a time.
     */
    private function crescent(float $ax, float $ay, float $ar, float $bx, float $by, float $br, int $gray): void
    {
        for ($y = $ay - $ar; $y <= $ay + $ar; $y += 0.5) {
            $a = $ar * $ar - ($y - $ay) ** 2;
            if ($a <= 0) {
                continue;
            }
            $a1 = $ax - sqrt($a);
            $a2 = $ax + sqrt($a);
            $b = $br * $br - ($y - $by) ** 2;
            if ($b <= 0) {
                $this->rect($a1, $y, $a2 - $a1, 0.5, $gray);
                continue;
            }
            // B covers the span [$b1, $b2] of this row; draw what is left of A on either side.
            $b1 = $bx - sqrt($b);
            $b2 = $bx + sqrt($b);
            if ($b1 > $a1) {
                $this->rect($a1, $y, min($a2, $b1) - $a1, 0.5, $gray);
            }
            if ($b2 < $a2) {
                $this->rect(max($a1, $b2), $y, $a2 - max($a1, $b2), 0.5, $gray);
            }
        }
    }

    /**
     * Draws an icon from a PNG file of the current icon set. Returns false
     * when the set has no file for it, so the caller can draw it by code.
     */
    private function bitmapIcon(string $kind, string $code, float $x, float $y, float $size): bool
    {
        static $cache = [];
        foreach ([$code, $kind] as $name) {
            if ($name === '' || !preg_match('/^[a-z_]+$/', $name)) {
                continue;
            }
            $file = self::$iconDir . '/' . self::$iconSet . '/' . $name . '.png';
            $cache[$file] ??= is_file($file) ? @imagecreatefrompng($file) : false;
            if ($cache[$file] === false) {
                continue;
            }
            $scale = $this->scale;
            imagealphablending($this->im, true);
            imagecopyresampled(
                $this->im,
                $cache[$file],
                (int) round($x * $scale),
                (int) round($y * $scale),
                0,
                0,
                (int) round($size * $scale),
                (int) round($size * $scale),
                imagesx($cache[$file]),
                imagesy($cache[$file])
            );
            return true;
        }
        return false;
    }

    /**
     * Draws a weather icon in the current icon set.
     *
     * $icon is what parse_symbol() returns as 'icon': the kind of weather,
     * optionally followed by ":" and MET's own symbol code, for example
     * "rain:lightrainshowers_day". Sets with an icon per code use the code;
     * the others use the kind. $strokeWidth is the stroke width in icon-grid units
     * and only matters for the sets drawn by code.
     */
    public function icon(string $icon, float $x, float $y, float $size, float $strokeWidth): void
    {
        [$kind, $code] = array_pad(explode(':', $icon, 2), 2, '');
        $drawnByCode = in_array(self::$iconSet, ['outline', 'duotone'], true);
        // "offline" is an interface icon, not weather, so it is always drawn by code.
        if (!$drawnByCode && $kind !== 'offline' && $this->bitmapIcon($kind, $code, $x, $y, $size)) {
            return;
        }
        $u = $size / 24;
        $w = $strokeWidth * $u;
        $P = fn(float $ux, float $uy) => [$x + $ux * $u, $y + $uy * $u];
        $lines = function (array $segments) use ($P, $w): void {
            foreach ($segments as $seg) {
                $this->stroke(array_map(fn($p) => $P($p[0], $p[1]), $seg), $w, INK);
            }
        };
        $dots = function (array $points) use ($P, $w): void {
            foreach ($points as $p) {
                [$px, $py] = $P($p[0], $p[1]);
                $this->disc($px, $py, $w * 0.75, INK);
            }
        };

        switch ($kind) {
            case 'sun':
                [$cx, $cy] = $P(12, 12);
                $this->sunAt($cx, $cy, 4 * $u, 7 * $u, 9 * $u, $w);
                break;
            case 'moon':
                [$cx, $cy] = $P(11.5, 12.5);
                $this->moonAt($cx, $cy, 7.5 * $u);
                break;
            case 'partly':
                [$cx, $cy] = $P(8.5, 8.5);
                $this->sunAt($cx, $cy, 3 * $u, 5.2 * $u, 6.6 * $u, $w);
                $this->cloud($x, $y, $u, $w, 5.7, 8.1, 0.78, true);
                break;
            case 'partly_night':
                [$cx, $cy] = $P(8.5, 8.5);
                $this->moonAt($cx, $cy, 5 * $u);
                $this->cloud($x, $y, $u, $w, 5.7, 8.1, 0.78, true);
                break;
            case 'cloud':
                $this->cloud($x, $y, $u, $w, 0, 3.5);
                break;
            case 'rain':
                $this->cloud($x, $y, $u, $w);
                $lines([[[8, 17], [8, 20]], [[12, 17], [12, 21]], [[16, 17], [16, 20]]]);
                break;
            case 'sleet':
                $this->cloud($x, $y, $u, $w);
                $lines([[[8, 17], [8, 20]], [[16, 17], [16, 20]]]);
                $dots([[12, 19.5]]);
                break;
            case 'snow':
                $this->cloud($x, $y, $u, $w);
                $dots([[8, 18], [12, 20], [16, 18]]);
                break;
            case 'thunder':
                $this->cloud($x, $y, $u, $w);
                $lines([[[13, 16], [10.5, 19.3], [13.2, 19.3], [11, 22.6]]]);
                break;
            case 'fog':
                $lines([[[4, 8], [20, 8]], [[6, 12], [18, 12]], [[4, 16], [20, 16]]]);
                break;
            case 'offline':
                foreach ([13.5, 8.8, 4.2] as $r) {
                    $arc = [];
                    for ($a = -138; $a <= -42; $a += 3) {
                        $arc[] = $P(12 + cos(deg2rad($a)) * $r, 19 + sin(deg2rad($a)) * $r);
                    }
                    $this->stroke($arc, $w, INK);
                }
                $dots([[12, 19.4]]);
                // Slash with a paper-colored halo so it reads against the arcs.
                $slash = [$P(4.5, 3.5), $P(19.5, 20.5)];
                $this->stroke($slash, $w * 2.6, PAPER);
                $this->stroke($slash, $w, INK);
                break;
        }
    }

    public function polygon(array $pts, int $gray): void
    {
        $flat = [];
        foreach ($pts as [$px, $py]) {
            $flat[] = (int) round($px * $this->scale);
            $flat[] = (int) round($py * $this->scale);
        }
        imagefilledpolygon($this->im, $flat, $this->color($gray));
    }

    /** Draws a picture file (PNG) into the box at ($x,$y), $w wide and $h high. */
    public function image(string $file, float $x, float $y, float $w, float $h): void
    {
        $picture = @imagecreatefrompng($file);
        if ($picture === false) {
            return;
        }
        $scale = $this->scale;
        imagecopyresampled(
            $this->im,
            $picture,
            (int) round($x * $scale),
            (int) round($y * $scale),
            0,
            0,
            (int) round($w * $scale),
            (int) round($h * $scale),
            imagesx($picture),
            imagesy($picture)
        );
    }

    /**
     * An aircraft seen from above, centered on ($cx,$cy) and pointing along
     * $track (degrees, 0 = up/north). $size is half its length. $kind picks
     * the silhouette:
     *
     *   jet2     airliner with an engine under each wing (the default)
     *   jet4     big airliner with two engines under each wing
     *   tailjet  jet with its engines beside the tail (business and regional jets)
     *   prop2    straight-winged plane with a propeller on each wing
     *   prop4    the same with two propellers on each wing
     *   small    light plane with one propeller on the nose
     *   fighter  delta-winged military jet
     */
    public function plane(float $cx, float $cy, float $size, float $track, int $gray = INK, string $kind = 'jet2'): void
    {
        // Each shape is the right half of the aircraft, nose (y = -1) to tail
        // (y = 1); the left half is its mirror image. "body" is the outline;
        // "parts" are boxes [x1, y1, x2, y2] for engines and propellers.
        $swept = [[0, -1], [0.1, -0.86], [0.14, -0.6], [0.14, -0.22], [1, 0.34], [1, 0.52], [0.14, 0.2], [0.11, 0.66], [0.4, 0.88], [0.4, 1.0], [0.06, 0.92], [0, 1.0]];
        $straight = [[0, -1], [0.1, -0.86], [0.13, -0.6], [0.13, -0.24], [1, -0.16], [1, 0.04], [0.13, 0.1], [0.1, 0.7], [0.4, 0.76], [0.4, 0.92], [0.06, 0.92], [0, 1.0]];
        $shapes = [
            'jet2'    => ['body' => $swept, 'parts' => [[0.34, -0.3, 0.5, 0.06]]],
            'jet4'    => ['body' => $swept, 'parts' => [[0.3, -0.34, 0.44, 0.0], [0.6, -0.16, 0.74, 0.18]]],
            'tailjet' => [
                'body'  => [[0, -1], [0.09, -0.84], [0.12, -0.5], [0.12, -0.02], [0.95, 0.44], [0.95, 0.58], [0.12, 0.3], [0.1, 0.74], [0.36, 0.9], [0.36, 1.0], [0.05, 0.94], [0, 1.0]],
                'parts' => [[0.12, 0.36, 0.3, 0.7]],
            ],
            'prop2'   => ['body' => $straight, 'parts' => [[0.34, -0.42, 0.48, 0.02], [0.2, -0.47, 0.62, -0.41]]],
            'prop4'   => ['body' => $straight, 'parts' => [[0.28, -0.42, 0.4, 0.02], [0.16, -0.47, 0.52, -0.41], [0.62, -0.4, 0.74, 0.02], [0.5, -0.45, 0.86, -0.39]]],
            'small'   => [
                'body'  => [[0, -0.92], [0.12, -0.8], [0.14, -0.5], [1, -0.46], [1, -0.18], [0.14, -0.12], [0.08, 0.66], [0.42, 0.7], [0.42, 0.9], [0.05, 0.9], [0, 1.0]],
                'parts' => [[0.0, -1.0, 0.34, -0.92]],
            ],
            'fighter' => [
                'body'  => [[0, -1], [0.07, -0.72], [0.12, -0.3], [0.86, 0.5], [0.86, 0.64], [0.18, 0.56], [0.5, 0.92], [0.5, 1.0], [0.1, 0.92], [0, 1.0]],
                'parts' => [],
            ],
        ];
        $shape = $shapes[$kind] ?? $shapes['jet2'];

        $a = deg2rad($track);
        $place = function (array $points) use ($cx, $cy, $size, $a): array {
            $out = [];
            foreach ($points as [$x, $y]) {
                $out[] = [$cx + ($x * cos($a) - $y * sin($a)) * $size, $cy + ($x * sin($a) + $y * cos($a)) * $size];
            }
            return $out;
        };
        $outline = $shape['body'];
        foreach (array_reverse($shape['body']) as [$x, $y]) {
            if ((float) $x !== 0.0) {
                $outline[] = [-$x, $y];
            }
        }
        $this->polygon($place($outline), $gray);
        foreach ($shape['parts'] as [$x1, $y1, $x2, $y2]) {
            $this->polygon($place([[$x1, $y1], [$x2, $y1], [$x2, $y2], [$x1, $y2]]), $gray);
            $this->polygon($place([[-$x1, $y1], [-$x2, $y1], [-$x2, $y2], [-$x1, $y2]]), $gray);
        }
    }

    /** A helicopter seen from above: a body with crossed rotor blades. $size is half the rotor's width. */
    public function rotor(float $cx, float $cy, float $size, int $gray = INK): void
    {
        $this->disc($cx, $cy, $size * 0.42, $gray);
        $d = $size * 0.74;
        $this->stroke([[$cx - $d, $cy - $d], [$cx + $d, $cy + $d]], max(3, $size * 0.16), $gray);
        $this->stroke([[$cx - $d, $cy + $d], [$cx + $d, $cy - $d]], max(3, $size * 0.16), $gray);
    }

    /** A four-pointed star with a small companion: the mark for the aurora notice. */
    public function sparkle(float $cx, float $cy, float $r, int $gray): void
    {
        foreach ([[$cx - 4, $cy + 2, $r], [$cx + $r * 0.72, $cy - $r * 0.62, $r * 0.42]] as [$x, $y, $size]) {
            $pts = [];
            for ($i = 0; $i < 8; $i++) {
                $d = $i % 2 === 0 ? $size : $size * 0.3;
                $pts[] = [$x + sin($i * M_PI / 4) * $d, $y - cos($i * M_PI / 4) * $d];
            }
            $this->polygon($pts, $gray);
        }
    }

    /**
     * Wind pointer: a solid dart that points the way the wind is blowing.
     * $len is half its length; $fromDeg is where the wind comes from (0 = north).
     */
    public function pointer(float $cx, float $cy, float $len, float $fromDeg, int $gray = INK): void
    {
        $b = deg2rad($fromDeg + 180);
        $dx = sin($b);
        $dy = -cos($b);
        $w = $len * 0.6;                       // half the width at the tail
        $tail = [$cx - $dx * $len, $cy - $dy * $len];
        $this->polygon([
            [$cx + $dx * $len, $cy + $dy * $len],
            [$tail[0] - $dy * $w, $tail[1] + $dx * $w],
            [$cx - $dx * $len * 0.38, $cy - $dy * $len * 0.38],      // the notch in the tail
            [$tail[0] + $dy * $w, $tail[1] - $dx * $w],
        ], $gray);
    }

    /**
     * Wind arrow centered on ($cx,$cy), pointing the way the wind blows.
     * $len is half its length; $fromDeg is where the wind comes from.
     */
    public function arrow(float $cx, float $cy, float $len, float $fromDeg, float $strokeWidth): void
    {
        $b = deg2rad($fromDeg + 180);
        $dx = sin($b);
        $dy = -cos($b);
        $head = $len * 0.68;
        $tip = [$cx + $dx * $len, $cy + $dy * $len];
        $base = [$cx + $dx * ($len - $head), $cy + $dy * ($len - $head)];
        $this->stroke([[$cx - $dx * $len, $cy - $dy * $len], $base], $strokeWidth, INK);
        $half = $head * 0.62;
        $this->polygon([
            $tip,
            [$base[0] - $dy * $half, $base[1] + $dx * $half],
            [$base[0] + $dy * $half, $base[1] - $dx * $half],
        ], INK);
    }

    /** Moon phase: white is the lit part. $f is 0 at new moon and 0.5 at full moon. */
    public function moon(float $cx, float $cy, float $r, float $f): void
    {
        // A picture of the real moon in this phase, when the pictures are installed.
        $frame = ((int) round($f * 32)) % 32;
        $file = sprintf('%s/moon/%02d.png', self::$iconDir, $frame);
        $picture = is_file($file) ? @imagecreatefrompng($file) : false;
        if ($picture !== false) {
            $scale = $this->scale;
            imagealphablending($this->im, true);
            imagecopyresampled(
                $this->im,
                $picture,
                (int) round(($cx - $r) * $scale),
                (int) round(($cy - $r) * $scale),
                0,
                0,
                (int) round(2 * $r * $scale),
                (int) round(2 * $r * $scale),
                imagesx($picture),
                imagesy($picture)
            );
            return;
        }

        // Otherwise a plain black-and-white drawing.
        $this->disc($cx, $cy, $r, INK);
        $inner = $r - 2.5;
        $waxing = $f < 0.5;
        $k = cos(2 * M_PI * ($waxing ? $f : 1 - $f));     // 1 = none lit, -1 = all lit
        for ($y = -$inner; $y <= $inner; $y += 0.5) {
            $w = sqrt(max(0, $inner * $inner - $y * $y));
            $edge = $w * $k;
            // Waxing moons are lit on the right, waning moons on the left.
            [$x1, $x2] = $waxing ? [$edge, $w] : [-$w, -$edge];
            if ($x2 - $x1 > 0.3) {
                $this->rect($cx + $x1, $cy + $y, $x2 - $x1, 0.5, PAPER);
            }
        }
    }

    /** Warning triangle with an exclamation mark. */
    public function warning(float $x, float $y, float $size, int $fg, int $bg): void
    {
        $h = $size * 0.88;
        $this->polygon([[$x + $size / 2, $y], [$x + $size, $y + $h], [$x, $y + $h]], $fg);
        $this->stroke([[$x + $size / 2, $y + $h * 0.38], [$x + $size / 2, $y + $h * 0.62]], $size * 0.11, $bg);
        $this->disc($x + $size / 2, $y + $h * 0.80, $size * 0.065, $bg);
    }

    /** Battery icon, 53x30 px, $level 0-100. */
    public function battery(float $x, float $y, int $level): void
    {
        $k = 53 / 28;
        $this->rect($x, $y, 24 * $k, 16 * $k, GRAY);
        $this->rect($x + 2 * $k, $y + 2 * $k, 20 * $k, 12 * $k, PAPER);
        $this->rect($x + 25 * $k, $y + 5 * $k, 2 * $k, 6 * $k, GRAY);
        $fill = 16 * $k * max(0, min(100, $level)) / 100;
        $this->rect($x + 4 * $k, $y + 4 * $k, $fill, 8 * $k, GRAY);
    }

    /** Scales down and encodes as an 8-bit grayscale PNG (no alpha), as eips expects. */
    public function png(): string
    {
        $out = imagecreatetruecolor($this->w, $this->h);
        imagecopyresampled($out, $this->im, 0, 0, 0, 0, $this->w, $this->h, $this->w * $this->scale, $this->h * $this->scale);
        $raw = '';
        for ($y = 0; $y < $this->h; $y++) {
            $row = "\0";
            for ($x = 0; $x < $this->w; $x++) {
                $row .= chr((imagecolorat($out, $x, $y) >> 8) & 0xFF);
            }
            $raw .= $row;
        }
        $chunk = fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $this->w, $this->h, 8, 0, 0, 0, 0))
            . $chunk('IDAT', gzcompress($raw, 6))
            . $chunk('IEND', '');
    }
}
