"""Localhost-only status UI + JSON API for agents."""
from __future__ import annotations

import json
import os
import sys
import threading
import time
import urllib.parse
import webbrowser
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Dict, Optional, Tuple

from .. import supervisor
from ..config import load_host
from ..logs import resolve_log_file, tail_lines
from ..plugin import load_plugin
from . import widgets as widgets_mod

DASHBOARD_LOG: list = []


def _client_ok(handler: BaseHTTPRequestHandler) -> bool:
    host = handler.client_address[0]
    return host in ("127.0.0.1", "::1", "localhost")


def _json_bytes(data: Any) -> bytes:
    return json.dumps(data, ensure_ascii=False, indent=2).encode("utf-8")


def api_status(plugin: str = "") -> Dict[str, Any]:
    data = supervisor.all_runtime(sample_cpu=True)
    if plugin:
        for p in data.get("plugins") or []:
            if p.get("id") == plugin:
                return p
        return {"error": f"plugin not enabled: {plugin}"}
    return data


def api_logs(plugin: str, source: str = "worker", tail: int = 200) -> Dict[str, Any]:
    spec = load_plugin(plugin)
    if not spec:
        return {"error": f"unknown plugin: {plugin}", "lines": []}
    path = resolve_log_file(spec, source)
    lines = tail_lines(path, tail)
    return {
        "plugin": plugin,
        "source": source,
        "path": str(path),
        "lines": lines,
    }


def do_core_action(action: str, payload: Dict[str, Any]) -> str:
    plugin = str(payload.get("plugin") or "").strip()
    if action == "start":
        if plugin:
            spec = load_plugin(plugin)
            return supervisor.start_plugin(spec) if spec else f"unknown {plugin}"
        return "; ".join(supervisor.start_enabled())
    if action == "stop":
        if plugin:
            return supervisor.stop_plugin(plugin)
        return "; ".join(supervisor.stop_enabled())
    if action == "restart":
        if not plugin:
            return "restart needs plugin"
        return supervisor.restart_plugin(plugin)
    if action.startswith("plugin:"):
        # plugin:<id>:<action>
        parts = action.split(":", 2)
        if len(parts) == 3:
            return widgets_mod.dispatch_action(parts[1], parts[2], payload)
    if plugin and action:
        return widgets_mod.dispatch_action(plugin, action, payload)
    return f"unknown action: {action}"


PAGE_CSS = """
:root { --bg:#0f1419; --card:#1a2332; --fg:#e7ecf3; --muted:#8b9bb4; --ok:#3dd68c; --bad:#f07178; --acc:#59c2ff; }
* { box-sizing: border-box; }
body { margin:0; font:14px/1.45 ui-sans-serif, system-ui, -apple-system, Segoe UI, sans-serif; background:var(--bg); color:var(--fg); }
header { padding:16px 20px; border-bottom:1px solid #243044; display:flex; gap:16px; align-items:center; flex-wrap:wrap; }
header h1 { margin:0; font-size:18px; font-weight:650; }
header .meta { color:var(--muted); font-size:12px; }
main { padding:16px 20px 40px; display:grid; gap:16px; }
.row { display:grid; gap:12px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
.card { background:var(--card); border:1px solid #243044; border-radius:10px; padding:14px 16px; }
.card h2 { margin:0 0 10px; font-size:15px; }
.muted { color:var(--muted); }
.ok { color:var(--ok); } .bad { color:var(--bad); } .acc { color:var(--acc); }
table { width:100%; border-collapse:collapse; font-size:13px; }
th, td { text-align:left; padding:6px 8px; border-bottom:1px solid #243044; vertical-align:top; }
th { color:var(--muted); font-weight:600; }
button, .btn { background:#243044; color:var(--fg); border:1px solid #334155; border-radius:8px; padding:6px 10px; cursor:pointer; font:inherit; }
button:hover { border-color:var(--acc); }
.activity { font-size:15px; font-weight:600; }
.activity .phase { color:var(--acc); }
.logbox { font:12px/1.35 ui-monospace, SFMono-Regular, Menlo, monospace; background:#0b1016; border:1px solid #243044; border-radius:8px; padding:10px; height:280px; overflow:auto; white-space:pre-wrap; word-break:break-word; }
.toolbar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px; align-items:center; }
select { background:#0b1016; color:var(--fg); border:1px solid #334155; border-radius:6px; padding:4px 8px; }
.err { color:var(--bad); }
"""


def render_page() -> str:
    return f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>FreeSaaS Workers</title>
<style>{PAGE_CSS}</style>
</head>
<body>
<header>
  <h1>FreeSaaS Workers</h1>
  <span class="meta" id="hostMeta">localhost</span>
  <div class="toolbar">
    <button type="button" data-act="start">Start all</button>
    <button type="button" data-act="stop">Stop all</button>
    <button type="button" id="btnRefresh">Refresh</button>
    <label class="muted"><input type="checkbox" id="pauseLogs"/> Pause logs</label>
  </div>
</header>
<main>
  <section class="card" id="hostCard"><h2>Host</h2><div id="hostBody" class="muted">loading…</div></section>
  <section class="card"><h2>Plugins</h2><div id="pluginsBody">loading…</div></section>
  <section class="card">
    <h2>Live log</h2>
    <div class="toolbar">
      <label>Plugin <select id="logPlugin"></select></label>
      <label>Source <select id="logSource"><option value="worker">worker</option></select></label>
      <button type="button" id="btnCopyLog">Copy</button>
    </div>
    <div class="logbox" id="logBox"></div>
  </section>
  <section id="widgets"></section>
</main>
<script>
const $ = (id) => document.getElementById(id);
let pause = false;

async function api(path) {{
  const r = await fetch(path, {{ headers: {{ 'Accept': 'application/json' }} }});
  return r.json();
}}

async function postAction(action, payload={{}}) {{
  const r = await fetch('/action', {{
    method: 'POST',
    headers: {{ 'Content-Type': 'application/json' }},
    body: JSON.stringify({{ action, ...payload }}),
  }});
  return r.json();
}}

function esc(s) {{
  return String(s ?? '').replace(/[&<>"']/g, c => ({{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}}[c]));
}}

function fmtCpu(v) {{
  if (v === null || v === undefined) return '—';
  return Number(v).toFixed(1) + '%';
}}

function renderPlugins(data) {{
  const plugins = data.plugins || [];
  const sel = $('logPlugin');
  const cur = sel.value;
  sel.innerHTML = plugins.map(p => `<option value="${{esc(p.id)}}">${{esc(p.title || p.id)}}</option>`).join('');
  if (cur) sel.value = cur;
  const hm = data.host_metrics || {{}};
  $('hostBody').innerHTML = `
    <div>CPU ${{fmtCpu(hm.cpu_percent)}} · RAM ${{hm.mem_used_mb ?? '—'}} / ${{hm.mem_total_mb ?? '—'}} MB (${{hm.mem_percent ?? '—'}}%)</div>
    <div class="muted">enabled: ${{esc((data.host && data.host.enabled_plugins || []).join(', '))}}</div>`;
  let html = '<table><thead><tr><th>Plugin</th><th>State</th><th>CPU</th><th>RSS</th><th>Activity</th><th></th></tr></thead><tbody>';
  for (const p of plugins) {{
    const act = p.activity || {{}};
    const st = p.running ? '<span class="ok">running</span>' : '<span class="bad">stopped</span>';
    html += `<tr>
      <td><strong>${{esc(p.title || p.id)}}</strong><div class="muted">pid ${{p.pid || '—'}}</div></td>
      <td>${{st}}</td>
      <td>${{fmtCpu(p.cpu_percent)}}</td>
      <td>${{p.rss_mb != null ? p.rss_mb + ' MB' : '—'}}</td>
      <td class="activity"><span class="phase">${{esc(act.phase || '—')}}</span>
        <div class="muted">${{esc(act.detail || '')}}</div></td>
      <td>
        <button type="button" data-act="start" data-plugin="${{esc(p.id)}}">Start</button>
        <button type="button" data-act="stop" data-plugin="${{esc(p.id)}}">Stop</button>
        <button type="button" data-act="restart" data-plugin="${{esc(p.id)}}">Restart</button>
      </td>
    </tr>`;
    // log sources
  }}
  html += '</tbody></table>';
  $('pluginsBody').innerHTML = html;
  // update log source options for selected plugin
  const p = plugins.find(x => x.id === sel.value) || plugins[0];
  if (p) {{
    const src = $('logSource');
    const curS = src.value;
    const keys = ['worker'].concat(Object.keys(p.log_sources || {{}}));
    const uniq = [...new Set(keys)];
    src.innerHTML = uniq.map(k => `<option value="${{esc(k)}}">${{esc(k)}}</option>`).join('');
    if (uniq.includes(curS)) src.value = curS;
  }}
}}

async function refreshStatus() {{
  const data = await api('/api/status');
  renderPlugins(data);
  const w = await api('/api/widgets');
  const box = $('widgets');
  box.innerHTML = (w.widgets || []).map(x =>
    `<div class="card" style="margin-top:12px"><h2>${{esc(x.title)}}</h2>${{x.html}}</div>`
  ).join('') || '';
}}

async function refreshLogs() {{
  if (pause) return;
  const plugin = $('logPlugin').value;
  if (!plugin) return;
  const source = $('logSource').value || 'worker';
  const data = await api(`/api/logs?plugin=${{encodeURIComponent(plugin)}}&source=${{encodeURIComponent(source)}}&tail=250`);
  const box = $('logBox');
  const atBottom = box.scrollTop + box.clientHeight >= box.scrollHeight - 40;
  box.textContent = (data.lines || []).join('\\n');
  if (atBottom) box.scrollTop = box.scrollHeight;
}}

document.addEventListener('click', async (ev) => {{
  const t = ev.target;
  if (!(t instanceof HTMLElement)) return;
  const act = t.getAttribute('data-act');
  if (!act) return;
  const plugin = t.getAttribute('data-plugin') || '';
  const res = await postAction(act, {{ plugin }});
  alert(res.message || JSON.stringify(res));
  refreshStatus();
}});

$('btnRefresh').onclick = () => {{ refreshStatus(); refreshLogs(); }};
$('pauseLogs').onchange = (e) => {{ pause = e.target.checked; }};
$('btnCopyLog').onclick = async () => {{
  try {{ await navigator.clipboard.writeText($('logBox').textContent || ''); }} catch (e) {{ alert(e); }}
}};
$('logPlugin').onchange = () => {{ refreshStatus(); refreshLogs(); }};
$('logSource').onchange = () => refreshLogs();

refreshStatus();
refreshLogs();
setInterval(refreshStatus, 3000);
setInterval(refreshLogs, 1500);
</script>
</body>
</html>"""


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt: str, *args: Any) -> None:  # noqa: A003
        # quiet — optional stderr
        sys.stderr.write("%s - %s\n" % (self.address_string(), fmt % args))

    def _forbid(self) -> None:
        self.send_response(403)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.end_headers()
        self.wfile.write(b"forbidden: localhost only\n")

    def _send(self, code: int, body: bytes, content_type: str) -> None:
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:  # noqa: N802
        if not _client_ok(self):
            self._forbid()
            return
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path.rstrip("/") or "/"
        qs = urllib.parse.parse_qs(parsed.query)

        if path in ("/", "/index.html"):
            self._send(200, render_page().encode("utf-8"), "text/html; charset=utf-8")
            return
        if path == "/api/status":
            plugin = (qs.get("plugin") or [""])[0]
            self._send(200, _json_bytes(api_status(plugin)), "application/json; charset=utf-8")
            return
        if path == "/api/logs":
            plugin = (qs.get("plugin") or [""])[0]
            source = (qs.get("source") or ["worker"])[0]
            try:
                tail = int((qs.get("tail") or ["200"])[0])
            except ValueError:
                tail = 200
            self._send(200, _json_bytes(api_logs(plugin, source, tail)), "application/json; charset=utf-8")
            return
        if path == "/api/widgets":
            data = supervisor.all_runtime(sample_cpu=False)
            by_id = {p["id"]: p for p in (data.get("plugins") or [])}
            w = widgets_mod.collect_widget_html(by_id)
            self._send(200, _json_bytes({"widgets": w}), "application/json; charset=utf-8")
            return
        self._send(404, b'{"error":"not found"}\n', "application/json; charset=utf-8")

    def do_POST(self) -> None:  # noqa: N802
        if not _client_ok(self):
            self._forbid()
            return
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path.rstrip("/") or "/"
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b"{}"
        try:
            payload = json.loads(raw.decode("utf-8") or "{}")
        except json.JSONDecodeError:
            payload = {}
        if not isinstance(payload, dict):
            payload = {}
        if path == "/action":
            action = str(payload.get("action") or "")
            msg = do_core_action(action, payload)
            self._send(200, _json_bytes({"ok": True, "message": msg}), "application/json; charset=utf-8")
            return
        self._send(404, b'{"error":"not found"}\n', "application/json; charset=utf-8")


def run_dashboard(*, host: str = "127.0.0.1", port: int = 8766, open_browser: bool = False) -> int:
    # Warm CPU counters
    try:
        import psutil

        psutil.cpu_percent(interval=None)
    except Exception:  # noqa: BLE001
        pass
    server = ThreadingHTTPServer((host, port), Handler)
    url = f"http://{host}:{port}/"
    print(f"FreeSaaS workers dashboard: {url} (localhost only)", flush=True)
    if open_browser:
        threading.Timer(0.4, lambda: webbrowser.open(url)).start()
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nbye", flush=True)
    finally:
        server.server_close()
    return 0
