<?php
/**
 * render.php — the drawing layer.
 *
 * Everything that turns a number of seconds into GIF bytes lives here, so
 * timer.php (which serves images to email clients) and admin.php (which shows
 * you a preview before you paste the tag) draw from exactly the same code. A
 * preview that renders through a second code path is a preview that lies.
 *
 * Requires PHP 7.4+ with the GD extension. Check a host with:
 *   php -r 'var_dump(extension_loaded("gd"), function_exists("imagettftext"));'
 */

declare(strict_types=1);

const WIDTH  = 480;
const HEIGHT = 96;

/**
 * Palettes, keyed by the name stored against each timer.
 *
 * Four colours each: background, the digits, the small unit captions, and the
 * hairline rules between columns. Add a palette here and it appears in the
 * admin dropdown automatically — nothing else to touch.
 */
const STYLES = [
    'citytech' => [
        'name'  => 'City Tech teal',
        'bg'    => [62, 138, 172],
        'fg'    => [255, 255, 255],
        'label' => [233, 206, 150],
        'rule'  => [126, 174, 198],
    ],
    'navy' => [
        'name'  => 'Navy / gold',
        'bg'    => [20, 36, 66],
        'fg'    => [255, 255, 255],
        'label' => [214, 178, 94],
        'rule'  => [70, 88, 122],
    ],
    'ink' => [
        'name'  => 'Charcoal',
        'bg'    => [34, 34, 36],
        'fg'    => [255, 255, 255],
        'label' => [166, 166, 170],
        'rule'  => [82, 82, 86],
    ],
    'paper' => [
        'name'  => 'Light (for white emails)',
        'bg'    => [246, 244, 239],
        'fg'    => [28, 32, 38],
        'label' => [122, 112, 96],
        'rule'  => [206, 198, 184],
    ],
    'crimson' => [
        'name'  => 'Crimson',
        'bg'    => [138, 30, 44],
        'fg'    => [255, 255, 255],
        'label' => [240, 200, 186],
        'rule'  => [176, 92, 102],
    ],
];

function style_or_default(string $key): array
{
    return STYLES[$key] ?? STYLES['citytech'];
}

/**
 * Font resolution.
 *
 * Looks for a bundled copy first, then the usual system locations, so this
 * works on a stock Linux host with nothing uploaded beside the script. If none
 * is found it falls back to GD's built-in bitmap font — uglier, but the image
 * still renders rather than erroring, and a broken image in 20,000 inboxes is
 * a much worse outcome than an ugly one.
 *
 * Drop your own .ttf in ./fonts/ to brand it.
 */
function font_path(bool $bold): string
{
    $names = $bold
        ? ['DejaVuSans-Bold.ttf', 'LiberationSans-Bold.ttf', 'Arial_Bold.ttf', 'arialbd.ttf']
        : ['DejaVuSans.ttf', 'LiberationSans-Regular.ttf', 'Arial.ttf', 'arial.ttf'];

    $dirs = [
        __DIR__ . '/../fonts',
        '/usr/share/fonts/truetype/dejavu',
        '/usr/share/fonts/truetype/liberation',
        '/usr/share/fonts/dejavu',
        '/usr/share/fonts/liberation',
        '/Library/Fonts',
        'C:/Windows/Fonts',
    ];

    foreach ($dirs as $dir) {
        foreach ($names as $name) {
            $p = $dir . '/' . $name;
            if (is_readable($p)) {
                return $p;
            }
        }
    }
    return '';
}

function has_truetype(): bool
{
    return function_exists('imagettftext') && font_path(true) !== '';
}

/** @param resource|\GdImage $im */
function clr($im, array $rgb): int
{
    return imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
}

/**
 * Draw $text centred on $cx, with $top as the top of the glyphs.
 *
 * imagettftext positions by BASELINE, not by the top of the text, so the
 * bounding box height is added to $top before the call. Skipping that is why
 * hand-rolled GD text usually sits a few pixels high.
 *
 * @param resource|\GdImage $im
 */
function centred($im, string $text, float $cx, int $top, int $size, int $color, bool $bold): void
{
    $font = font_path($bold);
    if ($font !== '' && function_exists('imagettftext')) {
        $box = imagettfbbox($size, 0, $font, $text);
        $w   = $box[2] - $box[0];
        $h   = $box[1] - $box[7];
        imagettftext($im, (float) $size, 0.0, (int) round($cx - $w / 2), $top + $h, $color, $font, $text);
        return;
    }
    // Bitmap fallback: GD font 5 is a fixed 9x15px, so $size is ignored.
    $w = imagefontwidth(5) * strlen($text);
    imagestring($im, 5, (int) round($cx - $w / 2), $top, $text, $color);
}

/**
 * A single-line banner — used once the event is running or finished.
 *
 * @return resource|\GdImage
 */
function render_banner(string $text, array $style)
{
    $im = imagecreatetruecolor(WIDTH, HEIGHT);
    imagefilledrectangle($im, 0, 0, WIDTH, HEIGHT, clr($im, $style['bg']));

    // Long strings overflow a 480px canvas at 22pt. Step the size down rather
    // than letting the text run off the edge.
    $size = strlen($text) > 26 ? 16 : 22;
    centred($im, $text, WIDTH / 2, $size === 22 ? 34 : 38, $size, clr($im, $style['label']), true);
    return $im;
}

/**
 * The four-column countdown frame.
 *
 * @return resource|\GdImage
 */
function render_countdown(int $secondsLeft, array $style, string $caption = '')
{
    $im = imagecreatetruecolor(WIDTH, HEIGHT);
    imagefilledrectangle($im, 0, 0, WIDTH, HEIGHT, clr($im, $style['bg']));

    $fg    = clr($im, $style['fg']);
    $label = clr($im, $style['label']);
    $rule  = clr($im, $style['rule']);

    $units = [
        ['DAYS',    intdiv($secondsLeft, 86400)],
        ['HOURS',   intdiv($secondsLeft % 86400, 3600)],
        ['MINUTES', intdiv($secondsLeft % 3600, 60)],
        ['SECONDS', $secondsLeft % 60],
    ];

    // With a caption line the digits shift up to make room for it.
    $digitTop = $caption === '' ? 14 : 6;
    $unitTop  = $caption === '' ? 68 : 57;

    $col = WIDTH / 4;
    foreach ($units as $i => $pair) {
        list($name, $value) = $pair;
        $cx = $col * $i + $col / 2;
        centred($im, sprintf('%02d', $value), $cx, $digitTop, 34, $fg, true);
        centred($im, $name, $cx, $unitTop, 9, $label, false);
        if ($i < 3) {
            $x = (int) round($col * ($i + 1));
            imageline($im, $x, $digitTop + 10, $x, $unitTop - 6, $rule);
        }
    }

    if ($caption !== '') {
        centred($im, $caption, WIDTH / 2, 76, 10, $label, true);
    }

    return $im;
}

/**
 * The image served when a slug is not found.
 *
 * Deliberately NOT a 404. A 404 shows a broken-image icon in every mail client,
 * which looks like the college sent a broken email. A neutral panel is quiet.
 *
 * @return resource|\GdImage
 */
function render_blank()
{
    $im = imagecreatetruecolor(WIDTH, HEIGHT);
    imagefilledrectangle($im, 0, 0, WIDTH, HEIGHT, clr($im, STYLES['citytech']['bg']));
    return $im;
}

/** @param resource|\GdImage $im */
function send_gif($im): void
{
    header('Content-Type: image/gif');
    // Outlook and Apple Mail fetch directly and honour these. Gmail's proxy
    // largely ignores them, which is what the per-recipient ?r= is for.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    imagegif($im);
    imagedestroy($im);
}
