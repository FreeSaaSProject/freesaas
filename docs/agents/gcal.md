# Google Calendar (`gcal`)

Sync TYPE=2 staff my-tasks into personal Google Calendars. OAuth app credentials in `cfg/modules/gcal.ini`; per-staff connect stays browser OAuth.

## Enable

```bash
cp modules/gcal/config.example.ini cfg/modules/gcal.ini
php bin/freesaas.php module enable gcal --format=json
php bin/freesaas.php migrate run --module=gcal --format=json
php bin/freesaas.php health --module=gcal --format=json
```

## Platform agent repair (Surface A — `agent_token`)

Same token as `/agent/*` (not staff session). `client_secret` is write-only.

```bash
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent/gcal/status"
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent/gcal/settings"
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"enabled":true,"client_id":"…","client_secret":"…"}' \
  "https://example.com/agent/gcal/settings"
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" \
  -H "Content-Type: application/json" -d '{"limit":20}' \
  "https://example.com/agent/gcal/queue/drain"
```

Per-staff Google connect: each user opens `/gcal/oauth/start` (browser). Agent can only diagnose `staff_connected` / `issues[]`.

## Product session JSON

See `module.json` → `agent.ops` (`/gcal/settings`, `/gcal/work`, …) — Surface B.
