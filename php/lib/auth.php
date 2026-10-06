<?php
/**
 * auth.php — one shared password, session cookie, CSRF token.
 *
 * This guards who can CREATE timers. It is not protecting student data — the
 * timer list is event names and dates — so a single shared password is the
 * right weight of lock. What it does prevent is a stranger finding admin.php
 * and rendering arbitrary text on a cuny.edu domain, which is the actual risk
 * worth closing.
 *
 * The password is never stored. config.php holds a password_hash() digest, and
 * password_verify() checks against it.
 *
 * Requires PHP 7.4+.
 */

declare(strict_types=1);

function config_file(): string
{
    return __DIR__ . '/../config.php';
}

function is_configured(): bool
{
    return is_readable(config_file());
}

function load_config(): void
{
    if (is_configured()) {
        require_once config_file();
    }
}

/**
 * Write config.php on first run.
 *
 * @throws RuntimeException
 */
function write_config(string $password): void
{
    if (is_configured()) {
        throw new RuntimeException('Already configured.');
    }
    if (strlen($password) < 12) {
        throw new RuntimeException('Use at least 12 characters.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('Could not hash the password.');
    }

    $body = "<?php\n"
        . "// Written by admin.php on first run. Keep this file out of version control.\n"
        . "// To change the password, delete this file and reload admin.php.\n\n"
        . "declare(strict_types=1);\n\n"
        . "const ADMIN_PASSWORD_HASH = " . var_export($hash, true) . ";\n\n"
        . "// Absolute URL of timer.php as mail clients will request it. Used to build\n"
        . "// the <img> tag the admin page gives you to paste.\n"
        . "const PUBLIC_TIMER_URL = '';\n\n"
        . "// Optional: a directory PHP can write to, for the render cache. Null disables it.\n"
        . "const CACHE_DIR = null;\n";

    if (file_put_contents(config_file(), $body) === false) {
        throw new RuntimeException('Cannot write config.php — the directory is not writable by PHP.');
    }
    @chmod(config_file(), 0640);
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('tmrsess');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
    session_start();
}

function is_logged_in(): bool
{
    return !empty($_SESSION['admin']);
}

/**
 * Throttle file: how many failures from this IP, and when the last one was.
 *
 * Crude on purpose. Without it a shared password is one scripted loop away
 * from being guessed; with it an attacker gets ten tries per quarter hour.
 */
function attempts_file(): string
{
    return sys_get_temp_dir() . '/timer-admin-attempts.json';
}

const MAX_ATTEMPTS  = 10;
const LOCKOUT_SECS  = 900;

function attempt_key(): string
{
    return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function lockout_remaining(): int
{
    $all = @json_decode((string) @file_get_contents(attempts_file()), true);
    if (!is_array($all)) {
        return 0;
    }
    $rec = $all[attempt_key()] ?? null;
    if (!is_array($rec) || ($rec['n'] ?? 0) < MAX_ATTEMPTS) {
        return 0;
    }
    $left = LOCKOUT_SECS - (time() - (int) ($rec['t'] ?? 0));
    return $left > 0 ? $left : 0;
}

function record_attempt(bool $ok): void
{
    $file = attempts_file();
    $all  = @json_decode((string) @file_get_contents($file), true);
    if (!is_array($all)) {
        $all = [];
    }
    $key = attempt_key();

    if ($ok) {
        unset($all[$key]);
    } else {
        $rec = $all[$key] ?? ['n' => 0, 't' => 0];
        // A window that has fully elapsed starts the count over.
        if (time() - (int) $rec['t'] > LOCKOUT_SECS) {
            $rec = ['n' => 0, 't' => 0];
        }
        $rec['n'] = (int) $rec['n'] + 1;
        $rec['t'] = time();
        $all[$key] = $rec;
    }

    // Drop anything stale so the file cannot grow without bound.
    foreach ($all as $k => $v) {
        if (time() - (int) ($v['t'] ?? 0) > LOCKOUT_SECS * 4) {
            unset($all[$k]);
        }
    }

    @file_put_contents($file, json_encode($all), LOCK_EX);
}

function attempt_login(string $password): bool
{
    if (!defined('ADMIN_PASSWORD_HASH')) {
        return false;
    }
    $ok = password_verify($password, ADMIN_PASSWORD_HASH);
    record_attempt($ok);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
    }
    return $ok;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(?string $sent): bool
{
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}
