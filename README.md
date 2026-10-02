# Email countdown timer

A self-hosted countdown image for marketing email. One PHP file, no dependencies.

![sample](samples/countdown.gif)

## Why this exists

Email clients do not run JavaScript. The only way to show a countdown in an email
is an `<img>` pointing at a server that redraws the image on every request.

Commercial services (CleverTimer, Tickvio, MotionMail, Sendtric) all do exactly
this. Running it yourself means no third-party tracking pixel on your recipients,
images served from your own domain, and no vendor account to expire mid-campaign.

## Quick start

Copy `php/timer.php` and `php/fonts/` to any PHP 7.4+ host with the GD extension.

```bash
php -r 'var_dump(function_exists("imagegif"), function_exists("imagettftext"));'
# both must be true
```

Edit the `EVENTS` array at the top of `timer.php`, then reference it from your email:

```html
<img src="https://example.edu/timer.php?e=openhouse-fall26&r=UNIQUE"
     width="480" height="96" border="0"
     alt="Countdown to Open House: Saturday, November 14"
     style="display:block;width:100%;max-width:480px;height:auto;">
```

See [`email/snippet.html`](email/snippet.html) for a version with the table
wrapper that Outlook needs.

## Three things that break email countdowns

These are the failure modes worth knowing before you ship one.

### 1. Daylight saving

A countdown set in October for an event in November is the classic off-by-one-hour
bug. New York is UTC−04:00 in summer and UTC−05:00 in winter; US daylight saving
ends on the first Sunday in November.

Enter the target as ISO-8601 **with an explicit offset**, never as a bare local
time:

```php
'start' => '2026-11-14T10:00:00-05:00',   // correct: November is EST
'start' => '2026-11-14T10:00:00',         // wrong: whose 10 AM?
```

### 2. Looping animation

If you serve an animated GIF with `loop=0`, it replays from the beginning after
its last frame — so the clock jumps backwards and counts the same minute forever.
Animations must play **once** and hold on the final frame.

This script sidesteps the problem by rendering a single frame per request. The
numbers are correct at the moment the reader opens the mail, which is the part
that matters. Ticking digits are decoration, and they cost an LZW frame assembler
and roughly ten times the file size.

### 3. Gmail's image proxy

Gmail does not fetch images from your server. It proxies them through
`googleusercontent.com` and **caches each URL for 24–72 hours**, ignoring
`Cache-Control`. The first fetch freezes that render for every subsequent view.

Nobody can fix this — not this script, not any commercial service. The only
mitigation is a **unique URL per recipient**, so each person's first open renders
fresh:

```
?e=openhouse-fall26&r={{GUID}}       Slate
?e=openhouse-fall26&r={{contact.id}} most other ESPs
```

Repeat opens still show the cached copy. Plan around it: if accuracy matters more
than movement, show days only — a days figure stays truthful for a full 24 hours.

## Load

GIF rendering is CPU work, roughly 10–20 ms per request. Email opens arrive in
bursts: 20,000 recipients can mean thousands of fetches in the first few minutes,
and Gmail's proxy fetches on top of that.

Set `CACHE_DIR` to enable the disk cache. The countdown is rounded down to a
10-second bucket and each bucket is rendered once, so everyone opening within the
same bucket is served one byte-identical file. Visually indistinguishable, and it
cuts rendering by an order of magnitude.

## Security

Events are whitelisted in the `EVENTS` array. The target date is deliberately
**not** accepted from the query string — an open `?to=` parameter would let anyone
embed your institution's domain in their own email with their own deadline.

## Repository layout

```
php/timer.php         single-file generator — the deployable artifact
php/fonts/            DejaVu faces (bundled; swap for your brand's)
python/               Flask equivalent, for container or serverless hosts
email/snippet.html    Outlook-safe <img> block
samples/              example output
```

The Python version in `python/` does the same job for hosts where PHP is not an
option (Cloud Run, Lambda, a container platform). It can also emit an animated
GIF — correctly set to play once.

## Hosting notes

Avoid free tiers that sleep. A platform that spins down after inactivity and takes
30–60 seconds to wake will serve broken images, because mail clients give up long
before that. Cloud Run and Lambda cold-start in 1–3 seconds, which is fine; an
always-on small instance is simpler still.

## Licence

MIT. See [LICENSE](LICENSE). Bundled DejaVu fonts are under the
[DejaVu Fonts Licence](https://dejavu-fonts.github.io/License.html).
