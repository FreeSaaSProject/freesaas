"""Localhost-only status UI + JSON API for agents (compact, i18n)."""
from __future__ import annotations

import hmac
import json
import secrets
import sys
import threading
import time
import urllib.parse
import webbrowser
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Dict, List, Optional, Tuple

from .. import supervisor
from ..config import load_host, load_json, save_json
from ..i18n import catalog_for_js, clear_cache, get_locale, normalize_locale, t
from ..logs import resolve_log_file, tail_lines
from .. import autostart as autostart_mod
from ..notify import (
    NotifySettings,
    load_notify_settings,
    public_status as notify_public_status,
    save_notify_settings,
)
from ..paths import HOST_LOCAL
from ..plugin import load_plugin
from . import widgets as widgets_mod

_LOOPBACK_HOSTS = frozenset({"127.0.0.1", "::1", "localhost"})


def _client_ok(handler: BaseHTTPRequestHandler) -> bool:
    host = handler.client_address[0]
    return host in ("127.0.0.1", "::1", "localhost")


def _split_host_port(raw: str) -> Tuple[str, Optional[int]]:
    raw = (raw or "").strip().lower()
    if not raw:
        return "", None
    if raw.startswith("["):
        end = raw.find("]")
        if end < 0:
            return raw, None
        host = raw[1:end]
        rest = raw[end + 1 :]
        if rest.startswith(":") and rest[1:].isdigit():
            return host, int(rest[1:])
        return host, None
    if raw.count(":") == 1:
        host, _, port_s = raw.partition(":")
        if port_s.isdigit():
            return host, int(port_s)
    return raw, None


def _url_is_local_dashboard(url: str, listen_port: int) -> bool:
    try:
        u = urllib.parse.urlparse(url)
    except ValueError:
        return False
    if u.scheme not in ("http", "https"):
        return False
    host, port = _split_host_port(u.netloc)
    if host not in _LOOPBACK_HOSTS:
        return False
    if port is None:
        port = 443 if u.scheme == "https" else 80
    return int(port) == int(listen_port)


def _host_header_ok(handler: BaseHTTPRequestHandler, listen_port: int) -> bool:
    host, port = _split_host_port(handler.headers.get("Host") or "")
    if host not in _LOOPBACK_HOSTS:
        return False
    if port is None:
        return True
    return int(port) == int(listen_port)


def _origin_ok(handler: BaseHTTPRequestHandler, listen_port: int) -> bool:
    """Reject cross-site browser CSRF. Missing Origin/Referer OK (curl) if CSRF passes."""
    origin = (handler.headers.get("Origin") or "").strip()
    if origin:
        return _url_is_local_dashboard(origin, listen_port)
    referer = (handler.headers.get("Referer") or "").strip()
    if referer:
        return _url_is_local_dashboard(referer, listen_port)
    return True


def _csrf_ok(handler: BaseHTTPRequestHandler, payload: Dict[str, Any]) -> bool:
    expected = getattr(handler, "csrf_token", "") or ""
    if not expected:
        return False
    got = (handler.headers.get("X-CSRF-Token") or "").strip()
    if not got:
        got = str(payload.get("csrf") or "").strip()
    if not got:
        return False
    return hmac.compare_digest(got, expected)


def _json_bytes(data: Any) -> bytes:
    return json.dumps(data, ensure_ascii=False, indent=2).encode("utf-8")


def _whisper_asr_language() -> str:
    spec = load_plugin("whisper")
    if not spec:
        return ""
    cfg = load_json(spec.config_local)
    wh = cfg.get("whisper") if isinstance(cfg.get("whisper"), dict) else {}
    fp = wh.get("form_params") if isinstance(wh.get("form_params"), dict) else {}
    return str(fp.get("language") or "ru")


def _set_whisper_asr_language(lang: str) -> str:
    spec = load_plugin("whisper")
    if not spec:
        return "whisper plugin missing"
    lang = (lang or "ru").strip().lower()[:16] or "ru"
    cfg = load_json(spec.config_local) if spec.config_local.is_file() else load_json(spec.config_example)
    wh = dict(cfg.get("whisper") or {})
    fp = dict(wh.get("form_params") or {})
    fp["language"] = lang
    wh["form_params"] = fp
    cfg["whisper"] = wh
    save_json(spec.config_local, cfg)
    return supervisor.restart_plugin("whisper")


def _set_locale(locale: str) -> str:
    loc = normalize_locale(locale)
    host = load_host()
    host["locale"] = loc
    save_json(HOST_LOCAL, host)
    clear_cache()
    return loc


def _apply_notify_payload(payload: Dict[str, Any]) -> NotifySettings:
    cur = load_notify_settings()
    enabled = payload.get("enabled")
    if enabled is not None:
        cur.enabled = bool(enabled)
    if "edit_last" in payload:
        cur.edit_last = bool(payload.get("edit_last"))
    events = payload.get("events")
    if isinstance(events, dict):
        for k in ("whisper_down", "recognition_summary", "novad_fallback"):
            if k in events:
                cur.events[k] = bool(events[k])
    channels = payload.get("channels")
    if isinstance(channels, dict):
        mx = channels.get("max") if isinstance(channels.get("max"), dict) else {}
        tg = channels.get("telegram") if isinstance(channels.get("telegram"), dict) else {}
        if "enabled" in mx:
            cur.max.enabled = bool(mx["enabled"])
        if "chat_id" in mx:
            cur.max.chat_id = str(mx.get("chat_id") or "").strip()
        tok = str(mx.get("token") or "").strip()
        if tok and tok != cur.max.token and "…" not in tok:
            cur.max.token = tok
        if "enabled" in tg:
            cur.telegram.enabled = bool(tg["enabled"])
        if "chat_id" in tg:
            cur.telegram.chat_id = str(tg.get("chat_id") or "").strip()
        btok = str(tg.get("bot_token") or "").strip()
        if btok and btok != cur.telegram.bot_token and "…" not in btok:
            cur.telegram.bot_token = btok
    save_notify_settings(cur)
    return cur


def api_status(plugin: str = "") -> Dict[str, Any]:
    data = supervisor.all_runtime(sample_cpu=True)
    host = load_host()
    data["locale"] = get_locale(host)
    data["asr_language"] = _whisper_asr_language() if "whisper" in (host.get("enabled_plugins") or []) else ""
    data["notify"] = notify_public_status()
    data["autostart"] = autostart_mod.status()
    # stale: no status update longer than idle+90
    now = int(time.time())
    for p in data.get("plugins") or []:
        st = p.get("status") or {}
        updated = int(st.get("updated_at") or 0)
        idle = 180
        try:
            spec = load_plugin(str(p.get("id") or ""))
            if spec:
                cfg = load_json(spec.config_local)
                loop = cfg.get("loop") if isinstance(cfg.get("loop"), dict) else {}
                idle = int(loop.get("sleep_seconds_idle") or idle)
        except Exception:  # noqa: BLE001
            pass
        p["stale"] = bool(p.get("running") and updated and (now - updated) > (idle + 90))
        p["status_age_sec"] = (now - updated) if updated else None
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
    return {
        "plugin": plugin,
        "source": source,
        "path": str(path),
        "lines": tail_lines(path, tail),
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
    if action == "set_locale":
        loc = _set_locale(str(payload.get("locale") or "ru"))
        return f"{t('dash.locale_saved')} ({loc}). {t('dash.restart_for_logs')}"
    if action == "set_asr_language":
        msg = _set_whisper_asr_language(str(payload.get("language") or "ru"))
        return f"{t('dash.asr_saved_restart')}: {msg}"
    if action == "set_notify":
        s = _apply_notify_payload(payload)
        restart_msg = ""
        if "whisper" in (load_host().get("enabled_plugins") or []):
            restart_msg = " " + supervisor.restart_plugin("whisper")
        return f"{t('dash.notify_saved')}: {s.summary()}.{restart_msg}"
    if action == "set_autostart":
        enabled = bool(payload.get("enabled"))
        res = autostart_mod.set_enabled(enabled)
        msg = res.get("message") or res.get("error") or ("on" if enabled else "off")
        if not res.get("ok", True) and res.get("error"):
            return f"{t('dash.autostart_fail')}: {res.get('error')}"
        return f"{t('dash.autostart_saved')}: {msg}"
    if action.startswith("plugin:"):
        parts = action.split(":", 2)
        if len(parts) == 3:
            return widgets_mod.dispatch_action(parts[1], parts[2], payload)
    if plugin and action:
        return widgets_mod.dispatch_action(plugin, action, payload)
    return f"unknown action: {action}"


PAGE_CSS = """
:root { --bg:#0f1419; --card:#1a2332; --fg:#e7ecf3; --muted:#8b9bb4; --ok:#3dd68c; --bad:#f07178; --acc:#59c2ff; --warn:#e6b450; }
* { box-sizing: border-box; }
body { margin:0; font:13px/1.35 ui-sans-serif, system-ui, -apple-system, Segoe UI, sans-serif; background:var(--bg); color:var(--fg); }
header { padding:8px 12px; border-bottom:1px solid #243044; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
header h1 { margin:0; font-size:15px; font-weight:650; }
header .meta { color:var(--muted); font-size:11px; }
main { padding:10px 12px 24px; display:grid; gap:10px; }
.row { display:grid; gap:8px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.card { background:var(--card); border:1px solid #243044; border-radius:8px; padding:8px 10px; }
.card h2 { margin:0 0 6px; font-size:12px; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.03em; }
.muted { color:var(--muted); }
.ok { color:var(--ok); } .bad { color:var(--bad); } .acc { color:var(--acc); } .warn { color:var(--warn); }
table { width:100%; border-collapse:collapse; font-size:12px; }
th, td { text-align:left; padding:4px 6px; border-bottom:1px solid #243044; vertical-align:top; }
th { color:var(--muted); font-weight:600; }
button, .btn { background:#243044; color:var(--fg); border:1px solid #334155; border-radius:6px; padding:3px 8px; cursor:pointer; font:inherit; font-size:12px; }
button:hover { border-color:var(--acc); }
.activity { font-size:12px; font-weight:600; }
.activity .phase { color:var(--acc); }
.logbox { font:11px/1.3 ui-monospace, SFMono-Regular, Menlo, monospace; background:#0b1016; border:1px solid #243044; border-radius:6px; padding:6px 8px; height:170px; overflow:auto; white-space:pre-wrap; word-break:break-word; }
.toolbar { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:6px; align-items:center; }
select { background:#0b1016; color:var(--fg); border:1px solid #334155; border-radius:5px; padding:2px 6px; font:inherit; }
.err { color:var(--bad); }
.badge { font-size:10px; padding:1px 5px; border-radius:4px; border:1px solid #334155; }
.badge.stale { border-color:var(--warn); color:var(--warn); }
.notify-grid { display:grid; gap:6px 10px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); align-items:end; }
.notify-grid label { display:flex; flex-direction:column; gap:2px; font-size:11px; color:var(--muted); }
.notify-grid input[type=text], .notify-grid input[type=password] {
  background:#0b1016; color:var(--fg); border:1px solid #334155; border-radius:5px; padding:3px 6px; font:inherit;
}
.notify-checks { display:flex; flex-wrap:wrap; gap:8px 12px; align-items:center; margin:4px 0; font-size:12px; }
.fold-btn { display:inline-flex; align-items:center; gap:6px; }
.fold-btn .mark.ok { color:var(--ok); font-weight:700; }
.fold-btn .mark.bad { color:var(--bad); font-weight:700; }
.fold-panel[hidden] { display:none !important; }
button[hidden] { display:none !important; }
"""


def render_page(*, csrf_token: str) -> str:
    host = load_host()
    locale = get_locale(host)
    i18n = catalog_for_js(locale)
    asr = _whisper_asr_language() if "whisper" in (host.get("enabled_plugins") or []) else ""
    show_asr = bool(asr)
    asr_opts = "".join(
        f'<option value="{c}"{" selected" if c == asr else ""}>{c}</option>'
        for c in ("ru", "en", "auto")
    )
    csrf_js = json.dumps(csrf_token)
    return f"""<!DOCTYPE html>
<html lang="{locale}">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>{t('dash.title', locale=locale)}</title>
<style>{PAGE_CSS}</style>
</head>
<body>
<header>
  <h1>{t('dash.title', locale=locale)}</h1>
  <span class="meta" id="hostMeta">—</span>
  <label class="muted">{t('dash.ui_locale', locale=locale)}
    <select id="uiLocale">
      <option value="ru"{" selected" if locale == "ru" else ""}>RU</option>
      <option value="en"{" selected" if locale == "en" else ""}>EN</option>
    </select>
  </label>
  {"<label class='muted'>" + t('dash.asr_lang', locale=locale) + " <select id='asrLang'>" + asr_opts + "</select></label>" if show_asr else ""}
  <div class="toolbar" style="margin:0">
    <button type="button" data-act="start">{t('dash.start_all', locale=locale)}</button>
    <button type="button" data-act="stop">{t('dash.stop_all', locale=locale)}</button>
    <button type="button" id="btnRefresh">{t('dash.refresh', locale=locale)}</button>
    <button type="button" class="fold-btn" id="btnNotifyToggle" aria-expanded="false">
      <span>{t('dash.notify', locale=locale)}</span>
      <span class="mark bad" id="notifyMark">✗</span>
    </button>
    <label class="muted"><input type="checkbox" id="pauseLogs"/> {t('dash.pause_logs', locale=locale)}</label>
  </div>
</header>
<main>
  <section class="card fold-panel" id="notifyPanel" hidden>
    <h2>{t('dash.notify', locale=locale)}</h2>
    <div class="notify-checks">
      <label><input type="checkbox" id="nEnabled"/> {t('dash.notify_enabled', locale=locale)}</label>
      <label><input type="checkbox" id="nEditLast"/> {t('dash.notify_edit_last', locale=locale)}</label>
      <label><input type="checkbox" id="nEvDown"/> {t('dash.notify_ev_down', locale=locale)}</label>
      <label><input type="checkbox" id="nEvSum"/> {t('dash.notify_ev_summary', locale=locale)}</label>
      <label><input type="checkbox" id="nEvNovad"/> {t('dash.notify_ev_novad', locale=locale)}</label>
    </div>
    <div class="notify-grid">
      <label>{t('dash.notify_max', locale=locale)}
        <span class="notify-checks" style="margin:0"><input type="checkbox" id="nMaxOn"/> {t('dash.notify_channel_on', locale=locale)}</span>
      </label>
      <label>Max token <input type="password" id="nMaxToken" placeholder="••••" autocomplete="off"/></label>
      <label>Max chat_id <input type="text" id="nMaxChat" autocomplete="off"/></label>
      <label>{t('dash.notify_telegram', locale=locale)}
        <span class="notify-checks" style="margin:0"><input type="checkbox" id="nTgOn"/> {t('dash.notify_channel_on', locale=locale)}</span>
      </label>
      <label>Telegram bot_token <input type="password" id="nTgToken" placeholder="••••" autocomplete="off"/></label>
      <label>Telegram chat_id <input type="text" id="nTgChat" autocomplete="off"/></label>
    </div>
    <div class="toolbar" style="margin-top:8px">
      <button type="button" id="btnNotifySave">{t('dash.notify_save', locale=locale)}</button>
      <span class="muted" id="notifyMeta"></span>
    </div>
  </section>
  <section class="card">
    <h2>{t('dash.plugins', locale=locale)}</h2>
    <div id="pluginsBody">{t('dash.loading', locale=locale)}</div>
  </section>
  <section class="card">
    <h2>{t('dash.autostart', locale=locale)}</h2>
    <div class="notify-checks">
      <label><input type="checkbox" id="aEnabled"/> {t('dash.autostart_enabled', locale=locale)}</label>
      <button type="button" id="btnAutostartSave" hidden>{t('dash.autostart_save', locale=locale)}</button>
      <span class="muted" id="autostartMeta"></span>
    </div>
    <p class="muted" style="margin:6px 0 0;font-size:11px">{t('dash.autostart_hint', locale=locale)}</p>
  </section>
  <section class="card">
    <h2>{t('dash.live_log', locale=locale)}</h2>
    <div class="toolbar">
      <label>{t('dash.plugin', locale=locale)} <select id="logPlugin"></select></label>
      <label>{t('dash.source', locale=locale)} <select id="logSource"><option value="worker">worker</option></select></label>
      <button type="button" id="btnCopyLog">{t('dash.copy', locale=locale)}</button>
    </div>
    <div class="logbox" id="logBox"></div>
  </section>
  <section id="widgets"></section>
</main>
<script>
window.I18N = {json.dumps(i18n, ensure_ascii=False)};
window.CSRF = {csrf_js};
const $ = (id) => document.getElementById(id);
const tt = (k) => (window.I18N && window.I18N[k]) || k;
let pause = false;

async function api(path) {{
  const r = await fetch(path, {{ headers: {{ 'Accept': 'application/json' }} }});
  return r.json();
}}
async function postAction(action, payload={{}}) {{
  const r = await fetch('/action', {{
    method: 'POST',
    headers: {{
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.CSRF || '',
    }},
    body: JSON.stringify({{ action, csrf: window.CSRF || '', ...payload }}),
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
function phaseLabel(ph) {{
  return tt('activity.phase.' + (ph || '')) || ph || '—';
}}

let notifyHydrated = false;
let autostartHydrated = false;
let autostartActual = false;
function syncAutostartApply() {{
  const dirty = $('aEnabled').checked !== autostartActual;
  const btn = $('btnAutostartSave');
  if (dirty) btn.removeAttribute('hidden'); else btn.setAttribute('hidden', '');
}}
function fillAutostart(a, force=false) {{
  if (!a) return;
  const on = !!(a.wanted || a.loaded);
  const bits = [
    on ? tt('dash.autostart_wanted') : tt('dash.autostart_unwanted'),
    a.loaded ? tt('dash.autostart_loaded') : tt('dash.autostart_unloaded'),
  ];
  $('autostartMeta').textContent = bits.join(' · ');
  if (!autostartHydrated || force) {{
    autostartActual = on;
    $('aEnabled').checked = on;
    autostartHydrated = true;
  }}
  syncAutostartApply();
}}
function setNotifyMark(on) {{
  const m = $('notifyMark');
  if (!m) return;
  m.textContent = on ? '✓' : '✗';
  m.className = 'mark ' + (on ? 'ok' : 'bad');
}}
function fillNotify(n, force=false) {{
  if (!n) return;
  setNotifyMark(!!n.enabled);
  $('notifyMeta').textContent = n.summary || '';
  if (notifyHydrated && !force) return;
  $('nEnabled').checked = !!n.enabled;
  $('nEditLast').checked = n.edit_last !== false;
  const ev = n.events || {{}};
  $('nEvDown').checked = ev.whisper_down !== false;
  $('nEvSum').checked = ev.recognition_summary !== false;
  $('nEvNovad').checked = ev.novad_fallback !== false;
  const ch = n.channels || {{}};
  const mx = ch.max || {{}};
  const tg = ch.telegram || {{}};
  $('nMaxOn').checked = !!mx.enabled;
  $('nMaxChat').value = mx.chat_id || '';
  $('nMaxToken').placeholder = mx.token_masked || '••••';
  $('nTgOn').checked = !!tg.enabled;
  $('nTgChat').value = tg.chat_id || '';
  $('nTgToken').placeholder = tg.bot_token_masked || '••••';
  notifyHydrated = true;
}}

function renderPlugins(data) {{
  const plugins = data.plugins || [];
  const sel = $('logPlugin');
  const cur = sel.value;
  sel.innerHTML = plugins.map(p => `<option value="${{esc(p.id)}}">${{esc(p.title || p.id)}}</option>`).join('');
  if (cur) sel.value = cur;
  const hm = data.host_metrics || {{}};
  $('hostMeta').textContent =
    `CPU ${{fmtCpu(hm.cpu_percent)}} · RAM ${{hm.mem_used_mb ?? '—'}}/${{hm.mem_total_mb ?? '—'}} MB · ${{tt('dash.enabled')}} ${{(data.host && data.host.enabled_plugins || []).join(',')}}`;
  fillNotify(data.notify);
  fillAutostart(data.autostart);
  let html = `<table><thead><tr>
    <th></th><th>${{tt('dash.state')}}</th><th>${{tt('dash.cpu')}}</th><th>${{tt('dash.rss')}}</th>
    <th>${{tt('dash.activity')}}</th><th></th></tr></thead><tbody>`;
  for (const p of plugins) {{
    const act = p.activity || {{}};
    const st = p.running
      ? `<span class="ok">${{tt('dash.running')}}</span>`
      : `<span class="bad">${{tt('dash.stopped')}}</span>`;
    const stale = p.stale ? ` <span class="badge stale">${{tt('dash.stale')}}</span>` : '';
    html += `<tr>
      <td><strong>${{esc(p.title || p.id)}}</strong><div class="muted">pid ${{p.pid || '—'}}</div></td>
      <td>${{st}}${{stale}}</td>
      <td>${{fmtCpu(p.cpu_percent)}}</td>
      <td>${{p.rss_mb != null ? p.rss_mb + ' MB' : '—'}}</td>
      <td class="activity"><span class="phase">${{esc(phaseLabel(act.phase))}}</span>
        <div class="muted">${{esc(act.detail || '')}}</div></td>
      <td>
        <button type="button" data-act="start" data-plugin="${{esc(p.id)}}">${{tt('dash.start')}}</button>
        <button type="button" data-act="stop" data-plugin="${{esc(p.id)}}">${{tt('dash.stop')}}</button>
        <button type="button" data-act="restart" data-plugin="${{esc(p.id)}}">${{tt('dash.restart')}}</button>
      </td>
    </tr>`;
  }}
  html += '</tbody></table>';
  $('pluginsBody').innerHTML = html;
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
  $('widgets').innerHTML = (w.widgets || []).map(x =>
    `<div class="card" style="margin-top:8px"><h2>${{esc(x.title)}}</h2>${{x.html}}</div>`
  ).join('') || '';
}}
async function refreshLogs() {{
  if (pause) return;
  const plugin = $('logPlugin').value;
  if (!plugin) return;
  const source = $('logSource').value || 'worker';
  const data = await api(`/api/logs?plugin=${{encodeURIComponent(plugin)}}&source=${{encodeURIComponent(source)}}&tail=200`);
  const box = $('logBox');
  const atBottom = box.scrollTop + box.clientHeight >= box.scrollHeight - 24;
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
  if (res.message) console.log(res.message);
  refreshStatus();
}});
$('btnRefresh').onclick = () => {{ refreshStatus(); refreshLogs(); }};
$('pauseLogs').onchange = (e) => {{ pause = e.target.checked; }};
$('btnCopyLog').onclick = async () => {{
  try {{ await navigator.clipboard.writeText($('logBox').textContent || ''); }} catch (e) {{}}
}};
$('logPlugin').onchange = () => {{ refreshStatus(); refreshLogs(); }};
$('logSource').onchange = () => refreshLogs();
$('uiLocale').onchange = async (e) => {{
  await postAction('set_locale', {{ locale: e.target.value }});
  location.reload();
}};
const asrEl = $('asrLang');
if (asrEl) {{
  asrEl.onchange = async (e) => {{
    const res = await postAction('set_asr_language', {{ language: e.target.value }});
    if (res.message) alert(res.message);
    refreshStatus();
  }};
}}
$('btnNotifyToggle').onclick = () => {{
  const panel = $('notifyPanel');
  const open = panel.hasAttribute('hidden');
  if (open) panel.removeAttribute('hidden'); else panel.setAttribute('hidden', '');
  $('btnNotifyToggle').setAttribute('aria-expanded', open ? 'true' : 'false');
}};
$('nEnabled').onchange = () => setNotifyMark($('nEnabled').checked);
$('aEnabled').onchange = () => syncAutostartApply();
$('nMaxToken').oninput = () => {{ $('nMaxToken').dataset.touched = '1'; }};
$('nTgToken').oninput = () => {{ $('nTgToken').dataset.touched = '1'; }};
$('btnAutostartSave').onclick = async () => {{
  const res = await postAction('set_autostart', {{ enabled: $('aEnabled').checked }});
  if (res.message) alert(res.message);
  autostartHydrated = false;
  refreshStatus();
}};
$('btnNotifySave').onclick = async () => {{
  const payload = {{
    enabled: $('nEnabled').checked,
    edit_last: $('nEditLast').checked,
    events: {{
      whisper_down: $('nEvDown').checked,
      recognition_summary: $('nEvSum').checked,
      novad_fallback: $('nEvNovad').checked,
    }},
    channels: {{
      max: {{
        enabled: $('nMaxOn').checked,
        chat_id: $('nMaxChat').value,
        token: $('nMaxToken').dataset.touched ? $('nMaxToken').value : '',
      }},
      telegram: {{
        enabled: $('nTgOn').checked,
        chat_id: $('nTgChat').value,
        bot_token: $('nTgToken').dataset.touched ? $('nTgToken').value : '',
      }},
    }},
  }};
  const res = await postAction('set_notify', payload);
  if (res.message) alert(res.message);
  $('nMaxToken').value = '';
  $('nTgToken').value = '';
  delete $('nMaxToken').dataset.touched;
  delete $('nTgToken').dataset.touched;
  notifyHydrated = false;
  refreshStatus();
}};
refreshStatus();
refreshLogs();
setInterval(refreshStatus, 3000);
setInterval(refreshLogs, 1500);
</script>
</body>
</html>"""


class Handler(BaseHTTPRequestHandler):
    listen_port: int = 8766
    csrf_token: str = ""

    def log_message(self, fmt: str, *args: Any) -> None:  # noqa: A003
        sys.stderr.write("%s - %s\n" % (self.address_string(), fmt % args))

    def _forbid(self, reason: str = "localhost only") -> None:
        self.send_response(403)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.end_headers()
        self.wfile.write(("forbidden: %s\n" % reason).encode("utf-8"))

    def _send(self, code: int, body: bytes, content_type: str) -> None:
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(body)))
        # Never grant cross-origin browser access.
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        self.wfile.write(body)

    def _mutate_ok(self, payload: Dict[str, Any]) -> Optional[str]:
        if not _host_header_ok(self, self.listen_port):
            return "bad Host"
        if not _origin_ok(self, self.listen_port):
            return "bad Origin"
        if not _csrf_ok(self, payload):
            return "bad CSRF"
        return None

    def do_GET(self) -> None:  # noqa: N802
        if not _client_ok(self):
            self._forbid()
            return
        if not _host_header_ok(self, self.listen_port):
            self._forbid("bad Host")
            return
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path.rstrip("/") or "/"
        qs = urllib.parse.parse_qs(parsed.query)
        if path in ("/", "/index.html"):
            page = render_page(csrf_token=self.csrf_token)
            self._send(200, page.encode("utf-8"), "text/html; charset=utf-8")
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

    def do_OPTIONS(self) -> None:  # noqa: N802
        # No CORS: cross-site preflight must fail closed.
        self._forbid("no CORS")

    def do_POST(self) -> None:  # noqa: N802
        if not _client_ok(self):
            self._forbid()
            return
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path.rstrip("/") or "/"
        length = int(self.headers.get("Content-Length") or 0)
        # Cap body to avoid abuse (actions are small JSON).
        if length < 0 or length > 1_000_000:
            self._forbid("body too large")
            return
        raw = self.rfile.read(length) if length else b"{}"
        try:
            payload = json.loads(raw.decode("utf-8") or "{}")
        except json.JSONDecodeError:
            payload = {}
        if not isinstance(payload, dict):
            payload = {}
        bad = self._mutate_ok(payload)
        if bad:
            self._forbid(bad)
            return
        if path == "/action":
            action = str(payload.get("action") or "")
            msg = do_core_action(action, payload)
            self._send(200, _json_bytes({"ok": True, "message": msg}), "application/json; charset=utf-8")
            return
        self._send(404, b'{"error":"not found"}\n', "application/json; charset=utf-8")


def run_dashboard(*, host: str = "127.0.0.1", port: int = 8766, open_browser: bool = False) -> int:
    try:
        import psutil

        psutil.cpu_percent(interval=None)
    except Exception:  # noqa: BLE001
        pass
    Handler.listen_port = int(port)
    Handler.csrf_token = secrets.token_urlsafe(32)
    server = ThreadingHTTPServer((host, port), Handler)
    url = f"http://{host}:{port}/"
    print(f"FreeSaaS workers dashboard: {url} (localhost only, CSRF on)", flush=True)
    if open_browser:
        threading.Timer(0.4, lambda: webbrowser.open(url)).start()
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nbye", flush=True)
    finally:
        server.server_close()
    return 0
