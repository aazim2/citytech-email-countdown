<?php
/**
 * admin.php — create and manage countdown timers.
 *
 * Fill in a date, a time and a label; this writes the timer to timers.json and
 * hands back the <img> tag to paste into the email. No PHP editing, no deploy
 * step — timer.php reads the same file on the next request.
 *
 * Requires PHP 7.4+ with GD. The directory must be writable by PHP so that
 * config.php and timers.json can be created.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/render.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/auth.php';

load_config();
start_session();

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute URL of timer.php as a mail client will request it. */
function timer_url(): string
{
    if (defined('PUBLIC_TIMER_URL') && PUBLIC_TIMER_URL !== '') {
        return rtrim(PUBLIC_TIMER_URL, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https://' : 'http://') . $host . $dir . '/timer.php';
}

function redirect(string $qs = ''): void
{
    header('Location: ' . basename(__FILE__) . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

$notice = '';
$errors = [];

// ---------------------------------------------------------------- first run

if (!is_configured()) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $p1 = (string) ($_POST['password'] ?? '');
        $p2 = (string) ($_POST['password2'] ?? '');
        if ($p1 !== $p2) {
            $errors[] = 'The two passwords do not match.';
        } else {
            try {
                write_config($p1);
                redirect();
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Set up — Email Countdown Timers</title>
<?php require __DIR__ . '/lib/style.php'; ?>
</head><body>
<main class="wrap narrow">
  <h1>Set a password</h1>
  <p class="lede">This is the first time this page has been opened, so there is no password yet.
  Set one now — until you do, anyone who finds this URL can set it themselves.</p>
  <?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>
  <form method="post">
    <label>Password <span class="hint">at least 12 characters</span>
      <input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
    <label>Repeat it
      <input type="password" name="password2" required minlength="12" autocomplete="new-password"></label>
    <button type="submit">Save password</button>
  </form>
  <p class="hint">Stored as a one-way hash in <code>config.php</code>, never as text.
  To change it later, delete that file and reload this page.</p>
</main>
</body></html>
    <?php
    exit;
}

// ---------------------------------------------------------------- login

if (!is_logged_in()) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $wait = lockout_remaining();
        if ($wait > 0) {
            $errors[] = 'Too many failed attempts. Try again in ' . ceil($wait / 60) . ' minute(s).';
        } elseif (attempt_login((string) ($_POST['password'] ?? ''))) {
            redirect();
        } else {
            $errors[] = 'Wrong password.';
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — Email Countdown Timers</title>
<?php require __DIR__ . '/lib/style.php'; ?>
</head><body>
<main class="wrap narrow">
  <h1>Email countdown timers</h1>
  <?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>
  <form method="post">
    <label>Password<input type="password" name="password" required autocomplete="current-password" autofocus></label>
    <button type="submit">Sign in</button>
  </form>
</main>
</body></html>
    <?php
    exit;
}

// ---------------------------------------------------------------- actions

$timers = load_timers();
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$edit   = (string) ($_GET['edit'] ?? '');
$form   = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $errors[] = 'This form expired. Try again.';
    } elseif ($action === 'logout') {
        logout();
        redirect();
    } elseif ($action === 'delete') {
        $slug = (string) ($_POST['slug'] ?? '');
        if (isset($timers[$slug])) {
            try {
                $timers = update_timers(function (array $all) use ($slug) {
                    unset($all[$slug]);
                    return $all;
                });
                redirect('deleted=' . urlencode($slug));
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    } elseif ($action === 'save') {
        $editing = (string) ($_POST['editing'] ?? '');
        list($timer, $slug, $errs) = validate_timer($_POST, $timers, $editing);
        $errors = array_merge($errors, $errs);
        if (!$errors) {
            // Renaming the URL name of an existing timer breaks every email
            // already sent with the old one, so the old entry is kept as well
            // and the operator is told. Nothing silently stops working.
            $renamed = ($editing !== '' && $editing !== $slug);
            try {
                $timers = update_timers(function (array $all) use ($slug, $timer) {
                    $all[$slug] = $timer;
                    return $all;
                });
                redirect('saved=' . urlencode($slug) . ($renamed ? '&renamed=' . urlencode($editing) : ''));
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        $form = $_POST;
        $edit = $editing;
    }
}

if (isset($_GET['saved'])) {
    $notice = 'Saved "' . (string) $_GET['saved'] . '".';
    if (isset($_GET['renamed'])) {
        $notice .= ' The old URL name "' . (string) $_GET['renamed'] . '" still works — emails already sent'
                 . ' point at it. Delete it once those campaigns are finished.';
    }
}
if (isset($_GET['deleted'])) {
    $notice = 'Deleted "' . (string) $_GET['deleted'] . '". Any email already sent with that URL now shows a blank panel.';
}

// Prefill the form: an edit, a failed submission, or sensible defaults.
if (!$form) {
    if ($edit !== '' && isset($timers[$edit])) {
        $form = $timers[$edit];
    } else {
        $form = [
            'date'     => (new DateTimeImmutable('+30 days', new DateTimeZone('America/New_York')))->format('Y-m-d'),
            'time'     => '10:00',
            'end_time' => '15:00',
            'tz'       => 'America/New_York',
            'style'    => 'citytech',
            'label'    => '',
            'live'     => 'HAPPENING NOW',
            'past'     => 'THANK YOU FOR JOINING US',
        ];
    }
}

$base = timer_url();
uasort($timers, function (array $a, array $b): int {
    return strcmp(($a['date'] ?? '') . ($a['time'] ?? ''), ($b['date'] ?? '') . ($b['time'] ?? ''));
});
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Email Countdown Timers</title>
<?php require __DIR__ . '/lib/style.php'; ?>
</head><body>
<main class="wrap">

<header class="top">
  <h1>Email countdown timers</h1>
  <form method="post" class="inline">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="logout">
    <button type="submit" class="link">Sign out</button>
  </form>
</header>

<?php if (!has_truetype()): ?>
<p class="warn"><strong>No TrueType font found.</strong> Images will render with GD's bitmap font, which looks
rough. Upload a <code>.ttf</code> (DejaVuSans-Bold.ttf and DejaVuSans.ttf) into the <code>fonts/</code>
folder beside this script to fix it.</p>
<?php endif; ?>

<?php if ($notice !== ''): ?><p class="ok"><?= h($notice) ?></p><?php endif; ?>
<?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>

<section class="card">
  <h2><?= $edit !== '' ? 'Edit "' . h($edit) . '"' : 'New timer' ?></h2>
  <form method="post" class="grid">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="editing" value="<?= h($edit) ?>">

    <label class="span2">Event name
      <input name="name" required maxlength="80" value="<?= h($form['name'] ?? '') ?>"
             placeholder="Spring 2027 Open House">
      <span class="hint">For your own reference. Not drawn on the image.</span></label>

    <label class="span2">URL name
      <input name="slug" maxlength="48" pattern="[a-z0-9][a-z0-9-]{1,47}"
             value="<?= h($edit !== '' ? $edit : ($form['slug'] ?? '')) ?>"
             placeholder="leave blank to generate from the event name">
      <span class="hint">Goes in the image URL. Once emails are sent with it, changing it breaks them.</span></label>

    <label>Date<input type="date" name="date" required value="<?= h($form['date'] ?? '') ?>"></label>

    <label>Time zone
      <select name="tz">
        <?php foreach (TIMEZONES as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= ($form['tz'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="hint">Daylight saving is worked out for you from the date.</span></label>

    <label>Starts at<input type="time" name="time" required value="<?= h($form['time'] ?? '') ?>"></label>

    <label>Ends at <span class="hint">optional</span>
      <input type="time" name="end_time" value="<?= h($form['end_time'] ?? '') ?>"></label>

    <label class="span2">Caption under the digits <span class="hint">optional</span>
      <input name="label" maxlength="34" value="<?= h($form['label'] ?? '') ?>" placeholder="UNTIL OPEN HOUSE"></label>

    <label>While it is happening
      <input name="live" maxlength="34" value="<?= h($form['live'] ?? '') ?>" placeholder="THE DOORS ARE OPEN"></label>

    <label>After it has finished
      <input name="past" maxlength="34" value="<?= h($form['past'] ?? '') ?>" placeholder="THANK YOU FOR JOINING US"></label>

    <label class="span2">Colours
      <select name="style">
        <?php foreach (STYLES as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= ($form['style'] ?? '') === $k ? 'selected' : '' ?>><?= h($v['name']) ?></option>
        <?php endforeach; ?>
      </select></label>

    <div class="span2 actions">
      <button type="submit"><?= $edit !== '' ? 'Save changes' : 'Create timer' ?></button>
      <?php if ($edit !== ''): ?><a class="link" href="<?= h(basename(__FILE__)) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
</section>

<h2>Your timers <span class="count"><?= count($timers) ?></span></h2>

<?php if (!$timers): ?>
  <p class="lede">None yet. Create one above and the image tag will appear here.</p>
<?php endif; ?>

<?php foreach ($timers as $slug => $t):
    try {
        list($s, $e) = timer_timestamps($t);
        $when = (new DateTimeImmutable('@' . $s))
            ->setTimezone(new DateTimeZone($t['tz'] ?? 'America/New_York'))
            ->format('D j M Y, g:ia T');
        $state = time() < $s ? 'counting down' : (time() <= $e ? 'happening now' : 'finished');
    } catch (Exception $ex) {
        $when  = 'invalid date';
        $state = 'broken';
    }
    $src = $base . '?e=' . rawurlencode($slug);
?>
<section class="card timer">
  <div class="timer-head">
    <div>
      <h3><?= h($t['name'] ?? $slug) ?></h3>
      <p class="meta"><code><?= h($slug) ?></code> &middot; <?= h($when) ?> &middot;
         <span class="state state-<?= h(str_replace(' ', '-', $state)) ?>"><?= h($state) ?></span></p>
    </div>
    <div class="timer-act">
      <a class="link" href="<?= h(basename(__FILE__)) ?>?edit=<?= rawurlencode($slug) ?>">Edit</a>
      <form method="post" class="inline" onsubmit="return confirm('Delete <?= h(addslashes($slug)) ?>? Emails already sent with it will show a blank panel.')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="slug" value="<?= h($slug) ?>">
        <button type="submit" class="link danger">Delete</button>
      </form>
    </div>
  </div>

  <img class="preview" src="<?= h($src . '&_=' . time()) ?>" width="480" height="96"
       alt="Preview of the <?= h($slug) ?> countdown">

  <label class="snippet">Paste this into the email
    <textarea readonly rows="3" onclick="this.select()">&lt;img src="<?= h($src) ?>&amp;r=RECIPIENT_ID" width="480" height="96" alt="<?= h($t['name'] ?? 'Countdown') ?>" style="display:block;border:0;outline:none;text-decoration:none"&gt;</textarea>
  </label>
  <p class="hint">Replace <code>RECIPIENT_ID</code> with a per-person merge field (in Slate, something like
  <code>{{Ref}}</code>). Gmail caches each distinct image URL for 24&ndash;72 hours and ignores cache headers,
  so a URL unique to each reader is the only way their first open renders a fresh image. Without it, everyone
  who opens after the first person sees that first person's numbers.</p>
</section>
<?php endforeach; ?>

<footer class="foot">
  <p>Images are drawn by <code>timer.php</code> at the moment each one is requested — nothing is pre-generated,
  so the numbers are right whenever the email is opened. Timers are stored in <code>timers.json</code>.</p>
</footer>

</main>
</body></html>
