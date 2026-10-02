"""
Countdown timer image service for email.

    pip install flask pillow
    python serve.py

Then in an email:
    <img src="https://timer.example.edu/timer.gif?to=2026-11-06T18:00:00-05:00&r={{GUID}}"
         width="480" height="96" alt="Open House countdown"
         style="display:block;width:100%;max-width:480px;height:auto;" border="0">

The `r=` parameter is cache-busting. Give every recipient a unique value (in Slate,
a merge field such as the record GUID). Without it, Gmail's proxy caches one copy of
the image and shows every recipient the same frozen countdown.
"""

from __future__ import annotations

from datetime import datetime

from flask import Flask, Response, request

from countdown_timer import render_countdown

app = Flask(__name__)

# Never let a target be set by anyone who can guess a URL — pin the events you
# support here. An open `to=` parameter is an open redirect for your brand:
# anyone could embed your domain in their own email with their own deadline.
EVENTS = {
    "openhouse-fall26": "2026-11-06T18:00:00-05:00",
    "deadline-spring27": "2026-12-01T23:59:59-05:00",
}


@app.route("/timer.gif")
def timer():
    event = request.args.get("e", "")
    if event not in EVENTS:
        return Response("unknown event", status=404)

    target = datetime.fromisoformat(EVENTS[event])
    gif = render_countdown(target, seconds=60)

    resp = Response(gif, mimetype="image/gif")
    # Outlook and Apple Mail fetch directly and do respect these.
    # Gmail's proxy largely ignores them — that is what `r=` is for.
    resp.headers["Cache-Control"] = "no-store, no-cache, must-revalidate, max-age=0"
    resp.headers["Pragma"] = "no-cache"
    resp.headers["Expires"] = "0"
    return resp


@app.route("/health")
def health():
    return {"ok": True}


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8080)
