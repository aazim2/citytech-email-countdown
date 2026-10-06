<?php
/**
 * store.php — reading, writing and validating the timer list.
 *
 * Timers live in a JSON file rather than a database so the whole system is
 * "copy these files to a directory". A college web host will give you a folder
 * and PHP long before it gives you a MySQL grant.
 *
 * Requires PHP 7.4+.
 */

declare(strict_types=1);

/** Where the timer list is kept. Override in config.php if you can put it
 *  outside the web root — that is strictly better, but many hosts only give
 *  you public_html, so the shipped .htaccess blocks HTTP access instead. */
function timers_file(): string
{
    return defined('TIMERS_FILE') ? TIMERS_FILE : __DIR__ . '/../timers.json';
}

/**
 * @return array<string,array> slug => timer
 */
function load_timers(): array
{
    $path = timers_file();
    if (!is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Replace the whole list.
 *
 * Prefer update_timers() for anything that modifies what is already there: this
 * overwrites, so a change saved by someone else between your load and your save
 * is lost. Locking and the atomic rename happen in update_timers().
 *
 * @param array<string,array> $timers
 * @throws RuntimeException
 */
function save_timers(array $timers): void
{
    update_timers(function () use ($timers) {
        return $timers;
    });
}

/** The write half of save_timers(), with no locking of its own. */
function write_timers_unlocked(string $path, array $timers): void
{
    $json = json_encode($timers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Cannot encode timers: ' . json_last_error_msg());
    }
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $json . "\n") === false) {
        throw new RuntimeException('Cannot write ' . $tmp);
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot replace ' . $path);
    }
    @chmod($path, 0664);
}

/**
 * Read-modify-write under one lock.
 *
 * Load-then-save in two steps has a window between them: if someone else saves
 * during it, their change is read, kept in the other process's stale copy, and
 * then overwritten. The file stays valid JSON, so nothing looks broken — the
 * edit just quietly disappears, which is worse than an error. Holding the lock
 * across the read closes the window.
 *
 * $fn receives the current timers and returns the new set.
 *
 * @param callable(array<string,array>):array<string,array> $fn
 * @return array<string,array> what was written
 * @throws RuntimeException
 */
function update_timers(callable $fn): array
{
    $path = timers_file();
    $dir  = dirname($path);

    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException('Cannot create ' . $dir);
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('Directory is not writable: ' . $dir . ' — chmod it to 775 (or 755 if PHP owns it).');
    }

    $lock = fopen($path . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Cannot open lock file');
    }
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        throw new RuntimeException('Cannot acquire lock');
    }

    try {
        $next = $fn(load_timers());
        write_timers_unlocked($path, $next);
        return $next;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Turn a human event name into a URL-safe slug.
 *
 * The slug ends up in an <img src> inside tens of thousands of emails and can
 * never be changed afterwards without breaking every mail already sent, which
 * is why the admin form shows it and lets you edit it before saving.
 */
function slugify(string $s): string
{
    $s = strtolower(trim($s));
    // Strip accents where iconv can; harmless if it cannot.
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    if ($t !== false) {
        $s = $t;
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return substr($s, 0, 48);
}

/** Timezones offered in the admin dropdown. Add any IANA name you need. */
const TIMEZONES = [
    'America/New_York'    => 'New York (Eastern)',
    'America/Chicago'     => 'Chicago (Central)',
    'America/Denver'      => 'Denver (Mountain)',
    'America/Los_Angeles' => 'Los Angeles (Pacific)',
    'UTC'                 => 'UTC',
];

/**
 * Resolve a timer's start (and end) to Unix timestamps.
 *
 * The date, time and IANA zone are stored separately and combined here, so
 * DateTimeZone works out the UTC offset for that specific date. This is the
 * whole reason the system does not store a fixed offset: New York is -04:00 in
 * summer and -05:00 in winter, and an event in November is -05:00 even when
 * the email is written in October. A timer built the old way, by typing an
 * offset, is wrong by an hour for a third of the year — and whoever types it
 * will not notice, because it looks right on the day they type it.
 *
 * @return array{0:int,1:int} [startTs, endTs]
 */
function timer_timestamps(array $t): array
{
    $tz    = new DateTimeZone($t['tz'] ?? 'America/New_York');
    $start = new DateTimeImmutable(($t['date'] ?? '1970-01-01') . ' ' . ($t['time'] ?? '00:00'), $tz);

    $endTime = $t['end_time'] ?? '';
    if ($endTime === '') {
        // No end given: treat the event as lasting an hour, purely so the
        // "happening now" banner has a window to show in.
        $end = $start->modify('+1 hour');
    } else {
        $end = new DateTimeImmutable(($t['date'] ?? '1970-01-01') . ' ' . $endTime, $tz);
        if ($end <= $start) {
            // End before start means it runs past midnight.
            $end = $end->modify('+1 day');
        }
    }

    return [$start->getTimestamp(), $end->getTimestamp()];
}

/** Strip anything that would break GD text drawing or the admin table. */
function clean_line(string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

/**
 * Validate and normalise a submitted timer.
 *
 * @param array<string,mixed>        $in     raw $_POST
 * @param array<string,array>        $all    existing timers, for slug collisions
 * @param string                     $editing slug being edited, '' when creating
 * @return array{0:array,1:string,2:array<string>} [timer, slug, errors]
 */
function validate_timer(array $in, array $all, string $editing = ''): array
{
    $errors = [];

    $name = clean_line((string) ($in['name'] ?? ''), 80);
    if ($name === '') {
        $errors[] = 'Event name is required.';
    }

    $slug = slugify((string) ($in['slug'] ?? '')) ?: slugify($name);
    if ($slug === '') {
        $errors[] = 'Could not build a URL name from that event name — type one in the URL name field.';
    } elseif (!preg_match('/^[a-z0-9][a-z0-9-]{1,47}$/', $slug)) {
        $errors[] = 'URL name must be 2-48 characters of lowercase letters, numbers and hyphens.';
    } elseif ($slug !== $editing && isset($all[$slug])) {
        $errors[] = 'A timer with the URL name "' . $slug . '" already exists.';
    }

    $date = (string) ($in['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = 'Date must be in YYYY-MM-DD form.';
    } else {
        list($y, $m, $d) = array_map('intval', explode('-', $date));
        if (!checkdate($m, $d, $y)) {
            $errors[] = 'That date does not exist.';
        }
    }

    $time = (string) ($in['time'] ?? '');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $errors[] = 'Start time must be in 24-hour HH:MM form.';
    }

    $endTime = (string) ($in['end_time'] ?? '');
    if ($endTime !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $endTime)) {
        $errors[] = 'End time must be in 24-hour HH:MM form, or left blank.';
    }

    $tz = (string) ($in['tz'] ?? 'America/New_York');
    if (!isset(TIMEZONES[$tz])) {
        $errors[] = 'Unknown time zone.';
    }

    $style = (string) ($in['style'] ?? 'citytech');
    if (!isset(STYLES[$style])) {
        $errors[] = 'Unknown colour style.';
    }

    // Captions are drawn at 480px wide. Past about 30 characters the banner
    // text has to shrink to fit, so the limit is a legibility limit.
    $timer = [
        'name'     => $name,
        'date'     => $date,
        'time'     => $time,
        'end_time' => $endTime,
        'tz'       => $tz,
        'label'    => clean_line((string) ($in['label'] ?? ''), 34),
        'live'     => clean_line((string) ($in['live'] ?? ''), 34),
        'past'     => clean_line((string) ($in['past'] ?? ''), 34),
        'style'    => $style,
    ];

    if ($editing !== '' && isset($all[$editing])) {
        $timer['created']    = $all[$editing]['created'] ?? '';
        $timer['created_by'] = $all[$editing]['created_by'] ?? '';
    } else {
        $timer['created'] = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('c');
    }

    return [$timer, $slug, $errors];
}
