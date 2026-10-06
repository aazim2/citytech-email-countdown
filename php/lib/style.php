<style>
/* Shared styling for admin.php. Inline rather than a .css file so the whole
   system stays "copy these files into a folder" with nothing to link up. */
:root {
  --bg: #f6f5f2; --panel: #ffffff; --ink: #1c2026; --muted: #6b7280;
  --line: #e2e0da; --accent: #2f6f8a; --ok: #1d6b4a; --err: #99302c; --warn: #8a5a12;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #15171a; --panel: #1d2024; --ink: #e8e6e1; --muted: #9aa0a8;
    --line: #2d3137; --accent: #6fb6d4; --ok: #6fc49a; --err: #e3918d; --warn: #d9b06a;
  }
}
* { box-sizing: border-box; }
body {
  margin: 0; background: var(--bg); color: var(--ink);
  font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}
.wrap { max-width: 860px; margin: 0 auto; padding: 32px 16px 64px; }
.wrap.narrow { max-width: 420px; padding-top: 72px; }
h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: -0.01em; }
h2 { font-size: 17px; margin: 32px 0 12px; }
h3 { font-size: 16px; margin: 0 0 2px; }
.top { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; margin-bottom: 8px; }
.lede, .meta, .hint { color: var(--muted); }
.hint { font-size: 12.5px; font-weight: 400; display: block; }
.meta { font-size: 13px; margin: 0; }
.count { color: var(--muted); font-weight: 400; font-size: 14px; }

.card {
  background: var(--panel); border: 1px solid var(--line); border-radius: 10px;
  padding: 20px; margin: 16px 0;
}
.card h2 { margin-top: 0; }

form.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
form.grid .span2 { grid-column: 1 / -1; }
@media (max-width: 560px) { form.grid { grid-template-columns: 1fr; } }

label { display: block; font-size: 13px; font-weight: 600; }
input, select, textarea, button {
  font: inherit; color: inherit;
}
input[type=text], input:not([type]), input[type=password], input[type=date], input[type=time], select, textarea {
  width: 100%; margin-top: 5px; padding: 9px 10px; background: var(--bg);
  border: 1px solid var(--line); border-radius: 7px; font-weight: 400;
}
textarea { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; resize: vertical; }
input:focus, select:focus, textarea:focus { outline: 2px solid var(--accent); outline-offset: -1px; }

button {
  padding: 9px 18px; background: var(--accent); color: #fff; border: 0;
  border-radius: 7px; font-weight: 600; cursor: pointer;
}
button:hover { filter: brightness(1.08); }
button.link, a.link {
  background: none; color: var(--accent); padding: 0; font-weight: 600;
  text-decoration: none; font-size: 13.5px; cursor: pointer; border: 0;
}
button.link:hover, a.link:hover { text-decoration: underline; filter: none; }
button.link.danger { color: var(--err); }
.actions { display: flex; align-items: center; gap: 18px; }
form.inline { display: inline; }

.ok, .err, .warn {
  padding: 10px 13px; border-radius: 7px; font-size: 13.5px; margin: 12px 0;
  border: 1px solid currentColor;
}
.ok { color: var(--ok); } .err { color: var(--err); } .warn { color: var(--warn); }
.ok, .err, .warn { background: color-mix(in srgb, currentColor 8%, transparent); }

.timer-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
.timer-act { display: flex; gap: 14px; white-space: nowrap; }
.preview {
  display: block; margin: 16px 0 12px; max-width: 100%; height: auto;
  border-radius: 6px; border: 1px solid var(--line);
}
.snippet { margin-top: 8px; }
.state { font-weight: 600; }
.state-counting-down { color: var(--accent); }
.state-happening-now { color: var(--ok); }
.state-finished, .state-broken { color: var(--muted); }
.state-broken { color: var(--err); }

code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.9em; background: var(--bg); padding: 1px 5px; border-radius: 4px;
}
.foot { margin-top: 40px; padding-top: 16px; border-top: 1px solid var(--line); }
.foot p { color: var(--muted); font-size: 13px; margin: 0; }
</style>
