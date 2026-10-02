<?php
/**
 * timer.php — server-rendered countdown image for email.
 *
 * Email clients cannot run JavaScript, so a countdown has to be an <img> whose
 * server redraws it on every request. This script does that with PHP's GD
 * extension and nothing else — no Composer, no dependencies, one file.
 *
 * It renders a SINGLE-FRAME GIF rather than an animation. The frame is drawn at
 * request time, so the numbers are correct the moment the reader opens the mail.
 * Animation would only make the digits tick for the first minute of viewing; it
 * costs an LZW frame assembler and a 10x bigger file for decoration. Accuracy is
 * the requirement here, movement is not.
 *
 * Usage:
 *   <img src="https://host/timer.php?e=openhouse-fall26&r=UNIQUE"
 *        width="480" height="96" alt="Countdown to Open House">
 *
 * The ?r= value should differ per recipient (a merge field). Gmail's image proxy
 * caches by URL for 24-72h; a unique URL per person means each reader's first
 * open renders fresh. Nothing can do better than that.
 *
 * Deploy: drop on any PHP 7.4+ host with GD. Check with:
 *   php -r 'var_dump(function_exists("imagegif"), function_exists("imagettftext"));'
 */

declare(strict_types=1);

// ---------------------------------------------------------------- config

/**
 * Events are whitelisted here, never taken from the query string.
 *
 * An open `?to=` parameter would let anyone embed this institution's domain in
 * their own email with their own deadline. Times are ISO-8601 WITH an offset, so
 * daylight saving is explicit rather than guessed: New York is -04:00 in summer
 * and -05:00 in winter, and an Open House in November is -05:00 even when the
 * mail is written in October. Getting this wrong is the single most common bug
 * in email countdowns.
 */
const EVENTS = [
    'openhouse-fall26' => [
        'start' => '2026-11-14T10:00:00-05:00',  // Sat 14 Nov 2026, 10:00 EST
        'end'   => '2026-11-14T15:00:00-05:00',  // runs until 3:00 PM
        'live'  => 'THE DOORS ARE OPEN',
        'past'  => 'THANK YOU FOR JOINING US',
        'label' => 'UNTIL OPEN HOUSE',
        'when'  => 'SAT NOV 14  -  10 AM',
    ],
];

const WIDTH  = 480;
const HEIGHT = 96;

// City Tech teal-blue, matched to the admissions email header block.
const RGB_BG    = [62, 138, 172];
const RGB_FG    = [255, 255, 255];
const RGB_LABEL = [233, 206, 150];
const RGB_RULE  = [126, 174, 198];

/**
 * Font resolution. Looks for a bundled copy first, then the usual system
 * locations, so this works on a stock Linux host with nothing uploaded beside
 * the script. If none is found it falls back to GD's built-in bitmap font —
 * uglier, but the image still renders rather than erroring.
 *
 * Drop your own .ttf in ./fonts/ to brand it.
 */
function font_path(bool $bold): string
{
    $names = $bold
        ? ['DejaVuSans-Bold.ttf', 'LiberationSans-Bold.ttf', 'Arial_Bold.ttf', 'arialbd.ttf']
        : ['DejaVuSans.ttf', 'LiberationSans-Regular.ttf', 'Arial.ttf', 'arial.ttf'];

    $dirs = [
        __DIR__ . '/fonts',
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

// Round the countdown down to this many seconds before drawing, and cache that
// render. Two readers opening within the same bucket get one byte-identical
// image off disk. At 20,000 recipients the proxy fetches arrive in bursts and
// GIF drawing is CPU work; without this you are rendering thousands of
// near-identical images a minute.
const CACHE_BUCKET_SECONDS = 10;
const CACHE_DIR = null;  // e.g. '/tmp/timercache'; null disables caching

// ---------------------------------------------------------------- helpers

function clr($im, array $rgb): int
{
    return imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
}

function has_truetype(): bool
{
    return function_exists('imagettftext') && font_path(true) !== '';
}

/** Draw $text centred on $cx. Uses TrueType when available, bitmap otherwise. */
function centred($im, string $text, float $cx, int $top, int $size, int $color, bool $bold): void
{
    $font = font_path($bold);
    if ($font !== '' && function_exists('imagettftext')) {
        $box = imagettfbbox($size, 0, $font, $text);
        $w   = $box[2] - $box[0];
        $h   = $box[1] - $box[7];
        imagettftext($im, $size, 0, (int) round($cx - $w / 2), $top + $h, $color, $font, $text);
        return;
    }
    // Bitmap fallback: GD font 5 is 9x15px, so size is fixed.
    $w = imagefontwidth(5) * strlen($text);
    imagestring($im, 5, (int) round($cx - $w / 2), $top, $text, $color);
}

/** A banner frame — used when the event is running or finished. */
function render_banner(string $text)
{
    $im = imagecreatetruecolor(WIDTH, HEIGHT);
    imagefilledrectangle($im, 0, 0, WIDTH, HEIGHT, clr($im, RGB_BG));
    centred($im, $text, WIDTH / 2, 34, 22, clr($im, RGB_LABEL), true);
    return $im;
}

/** The four-unit countdown frame. */
function render_countdown(int $secondsLeft)
{
    $im = imagecreatetruecolor(WIDTH, HEIGHT);
    imagefilledrectangle($im, 0, 0, WIDTH, HEIGHT, clr($im, RGB_BG));

    $fg    = clr($im, RGB_FG);
    $label = clr($im, RGB_LABEL);
    $rule  = clr($im, RGB_RULE);

    $units = [
        ['DAYS',    intdiv($secondsLeft, 86400)],
        ['HOURS',   intdiv($secondsLeft % 86400, 3600)],
        ['MINUTES', intdiv($secondsLeft % 3600, 60)],
        ['SECONDS', $secondsLeft % 60],
    ];

    $col = WIDTH / 4;
    foreach ($units as $i => [$name, $value]) {
        $cx = $col * $i + $col / 2;
        centred($im, sprintf('%02d', $value), $cx, 14, 34, $fg, true);
        centred($im, $name, $cx, 68, 9, $label, false);
        if ($i < 3) {
            $x = (int) round($col * ($i + 1));
            imageline($im, $x, 24, $x, 62, $rule);
        }
    }
    return $im;
}

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

function fail(int $code, string $why): never
{
    header('Content-Type: text/plain', true, $code);
    echo $why;
    exit;
}

// ---------------------------------------------------------------- request

$key = $_GET['e'] ?? '';
if (!is_string($key) || !isset(EVENTS[$key])) {
    fail(404, 'unknown event');
}

$event = EVENTS[$key];
$now   = time();
$start = (new DateTimeImmutable($event['start']))->getTimestamp();
$end   = (new DateTimeImmutable($event['end']))->getTimestamp();

if ($now >= $start) {
    send_gif(render_banner($now <= $end ? $event['live'] : $event['past']));
    exit;
}

$left   = $start - $now;
$bucket = intdiv($left, CACHE_BUCKET_SECONDS) * CACHE_BUCKET_SECONDS;

if (CACHE_DIR !== null) {
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
    $path = CACHE_DIR . '/' . $key . '-' . $bucket . '.gif';
    if (is_readable($path)) {
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        readfile($path);
        exit;
    }
    $im = render_countdown($bucket);
    imagegif($im, $path);          // write once, serve to everyone in this bucket
    imagedestroy($im);
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    readfile($path);
    exit;
}

send_gif(render_countdown($bucket));
