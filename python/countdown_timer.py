"""
Server-side countdown timer GIF generator for email.

Renders an animated GIF that counts down from request time to a target datetime.
Email clients cannot run JavaScript, so the only way to show a countdown is to
render it server-side as an image at the moment the image is requested.

Usage as a library:
    gif_bytes = render_countdown(target_utc, seconds=60)

Usage as a service (see serve.py):
    GET /timer.gif?to=2026-11-06T18:00:00Z&tz=America/New_York
"""

from __future__ import annotations

import io
from datetime import datetime, timezone

from PIL import Image, ImageDraw, ImageFont

# ---------------------------------------------------------------- appearance

WIDTH, HEIGHT = 480, 96
BG = (12, 35, 64)            # deep navy
FG = (255, 255, 255)         # digits
ACCENT = (255, 184, 28)      # label / separator
SEP = (90, 110, 138)

FONT_DIR = "/usr/share/fonts/truetype/dejavu"
DIGIT_FONT = ImageFont.truetype(f"{FONT_DIR}/DejaVuSans-Bold.ttf", 40)
LABEL_FONT = ImageFont.truetype(f"{FONT_DIR}/DejaVuSans.ttf", 12)

UNITS = ("DAYS", "HOURS", "MINUTES", "SECONDS")


def _split(total_seconds: int) -> tuple[int, int, int, int]:
    """Break a duration into days, hours, minutes, seconds (never negative)."""
    total_seconds = max(0, total_seconds)
    days, rem = divmod(total_seconds, 86400)
    hours, rem = divmod(rem, 3600)
    minutes, seconds = divmod(rem, 60)
    return days, hours, minutes, seconds


def _frame(values: tuple[int, int, int, int], expired: bool = False) -> Image.Image:
    img = Image.new("RGB", (WIDTH, HEIGHT), BG)
    draw = ImageDraw.Draw(img)

    if expired:
        msg = "THE DOORS ARE OPEN"
        box = draw.textbbox((0, 0), msg, font=DIGIT_FONT)
        draw.text(
            ((WIDTH - (box[2] - box[0])) / 2, (HEIGHT - (box[3] - box[1])) / 2 - 6),
            msg, font=DIGIT_FONT, fill=ACCENT,
        )
        return img

    col_w = WIDTH / 4
    for i, (value, label) in enumerate(zip(values, UNITS)):
        cx = col_w * i + col_w / 2
        text = f"{value:02d}"

        box = draw.textbbox((0, 0), text, font=DIGIT_FONT)
        draw.text((cx - (box[2] - box[0]) / 2, 14), text, font=DIGIT_FONT, fill=FG)

        box = draw.textbbox((0, 0), label, font=LABEL_FONT)
        draw.text((cx - (box[2] - box[0]) / 2, 66), label, font=LABEL_FONT, fill=ACCENT)

        if i < 3:
            x = col_w * (i + 1)
            draw.line([(x, 26), (x, 60)], fill=SEP, width=1)

    return img


def render_countdown(target: datetime, seconds: int = 60) -> bytes:
    """
    Build an animated GIF counting down to `target`, one frame per second.

    `seconds` is how many frames to render. Email GIFs are rendered fresh on every
    request, so ~60 frames is plenty: the viewer sees a live minute, then it holds.
    Keep it low — frames cost bytes, and some clients cap animation length.
    """
    if target.tzinfo is None:
        raise ValueError("target must be timezone-aware")

    remaining = int((target - datetime.now(timezone.utc)).total_seconds())

    frames = []
    for offset in range(seconds):
        left = remaining - offset
        frames.append(_frame(_split(left), expired=left <= 0))

    # Quantize to a tiny shared palette. Email GIFs must stay small — a few
    # colours is all this design uses, and 8 colours cuts the file ~10x.
    palette_src = frames[0].convert("P", palette=Image.ADAPTIVE, colors=8)
    frames = [f.quantize(palette=palette_src, dither=Image.Dither.NONE) for f in frames]

    buf = io.BytesIO()
    frames[0].save(
        buf,
        format="GIF",
        save_all=True,
        append_images=frames[1:],
        duration=1000,   # 1 second per frame
        loop=1,          # IMPORTANT: play once, then hold on the last frame.
                         # loop=0 restarts from the original time and shows a lie.
        optimize=True,
        disposal=1,      # leave previous frame in place; only changed pixels ship
    )
    return buf.getvalue()


if __name__ == "__main__":
    from datetime import timedelta

    target = datetime.now(timezone.utc) + timedelta(days=12, hours=7, minutes=42, seconds=18)
    data = render_countdown(target, seconds=60)
    with open("/mnt/user-data/outputs/open_house_timer_sample.gif", "wb") as fh:
        fh.write(data)
    print(f"wrote {len(data):,} bytes, target {target.isoformat()}")
