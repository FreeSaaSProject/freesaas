# BI reports (`bi`)

JSON-recipe operational reports (director). Shipped defaults: `modules/bi/recipes/*.json`. Saving in the constructor writes a DB override in `base_bi_family_defs`. Live share links with TTL.

Surface B (staff session / MCP) — permission **`bi.manage`**. Runbook twin: UI `/bi`. Module catalog maturity: **beta**.

## Enable

```bash
php bin/freesaas.php module enable bi --accept-risk --format=json
# Grant role permission bi.manage (director / report owner).
```

Soft deps (datasets become available when tables exist): `procedures`, `workweeks`, `vacations`, `terminals`, `polls` / `poll_invites`, `audio`, `catalog`.

## Agent workflow (build → edit → run → share)

Always send `Accept: application/json` or `?format=json` with a staff session that has `bi.manage`.

```bash
# 1) List families
curl -sS -b jar.txt -H 'Accept: application/json' 'https://example/bi'

# 2) Legend (datasets / expr / derive / section types / recipe_shape / examples)
curl -sS -b jar.txt -H 'Accept: application/json' 'https://example/bi/legend'

# 3) Load one recipe (shipped or DB override)
curl -sS -b jar.txt -H 'Accept: application/json' \
  'https://example/bi/families/masseur_shifts'

# 4) Validate (no write)
curl -sS -b jar.txt -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -X POST 'https://example/bi/families/new' \
  -d '{"action":"validate","family_id":"my_report","name":"My report","recipe":{...}}'

# 5) Save (custom id or override shipped id)
curl -sS -b jar.txt -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -X POST 'https://example/bi/families/new' \
  -d '{"action":"save","family_id":"my_report","name":"My report","enabled":1,"recipe":{...}}'

# 6) Run and read data (prefer report.sections; section_summary is a skim)
curl -sS -b jar.txt -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -X POST 'https://example/bi/work' \
  -d '{"action":"run","family":"masseur_shifts","year":2026,"month":10}'

# 7) Shares
curl -sS -b jar.txt -H 'Accept: application/json' 'https://example/bi/shares'
curl -sS -b jar.txt -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -X POST 'https://example/bi/shares' \
  -d '{"action":"create","family":"masseur_shifts","audience":"staff","ttl":"1y","staff_user_ids":[12,34]}'
```

### Reading results

`bi.report.run` returns:

| Field | Use |
|-------|-----|
| `report.title` | Plain title from recipe |
| `report.sections[]` | **Source of truth** — `metrics` / `table` / `text` (and pivot as table) |
| `section_summary[]` | Quick skim (row counts, metric counts) |
| `html` | Optional UI HTML (escaped); agents should prefer `report` |
| `params` | Normalized year/month/filters |
| `stages` | Progress log from the engine |

### Editing rules for agents

- **Titles, column labels, metric labels, `text.body`** = plain constructor text (UTF-8). Not module i18n keys.
- **`type: text`** bodies are validated (`BiText`): no HTML/PHP/event handlers/template injection; rendered with `e_nl`.
- **Expr `acronym`** = system shortName (same as calendar badges).
- Ship overrides: save with a **shipped** `family_id` → DB override; `action=delete` + `def_id` restores the file.
- Never invent SQL tables outside `legend.datasets` allowlist. Joins must match catalog `joins[]`.

## Recipe shape (minimal)

```json
{
  "v": 1,
  "id": "my_report",
  "title": "My report",
  "requires": ["procedures"],
  "queries": [
    {
      "as": "stats",
      "from": "procedures",
      "where": [{"field": "enabled", "op": ">", "value": 0}],
      "select": [{"agg": "count", "as": "n"}]
    }
  ],
  "derive": [],
  "sections": [
    {
      "type": "metrics",
      "title": "Summary",
      "items": [{"label": "Count", "from": "stats", "field": "n"}]
    },
    {
      "type": "text",
      "title": "Note",
      "body": "Plain explanation for readers."
    }
  ]
}
```

Derive pipeline (in order): `join_frame` → `filter` → `select`/`compute` → `group_by`/`aggs` → `compute_after` → `having` → `window` → `sort` → `limit`.

Full allowlist: **`GET /bi/legend`** (`agent_howto`, `recipe_shape`, `datasets`, `expr_ops`, `group_aggs`, `examples`).

## Shipped recipes

| Id | File | Notes |
|----|------|--------|
| `demo_counts` | `recipes/demo_counts.json` | Procedures + guests metrics |
| `masseur_shifts` | `recipes/masseur_shifts.json` | Oper show_kpi11 (shifts / slots / vacations) |
| `admin_conversion` | `recipes/admin_conversion.json` | Admin revenue ranking |
| `staff_kpi` | `recipes/staff_kpi.json` | Pivot matrix stub |

## Shares

| Rule | Detail |
|------|--------|
| Paths | `/bi/s/{token}` (public route; guest or staff audience) |
| TTL | `1h` \| `1d` \| `1mo` \| `3mo` \| `1y` (`1y` **staff only**) |
| Allowlist | Optional `staff_user_ids`; **intentionally empty** (missing/`[]`/blank) = any logged-in staff. Non-blank input that yields no valid picker IDs (`[0]`, `[-1]`, `["invalid"]`, unknown ids) → `staff_allowlist_empty` |
| Assist team | Omitted from picker; stripped on create |
| Superadmin | Can open any staff share (and revoke all) |
| Locked filters | `id_operator` / `id_admin` / `id_good` bind only on datasets that declare them. Create refuses partial coverage unless `accept_partial_filters=1` |
| Rate limit | Atomic `beginRun` / `endRun`: per-share `GET_LOCK` + minute-bucket `RUN_COUNT` (8/min); concurrent recomputes serialize |

## Engine budgets

Runtime caps (also enforced on validate for step counts): query frames ≤ 5 000 rows; derive frames ≤ 25 000; cross-join ≤ 100 000 cells; ≤ 40 queries / 60 derive steps; wall clock ≤ 20 s (`microtime` ticks in derive/join/group/window/sections). Each SQL SELECT sets session `MAX_EXECUTION_TIME` / MariaDB `max_statement_time` = remaining budget ms and **verifies** the var; if neither backend accepts the limit → `sql_timeout_unavailable` (run aborted, no unbounded query). Query kill / wall exceed → `recipe_timeout`.

## Agent ops (`module.json`)

| Op | Method | Path |
|----|--------|------|
| `bi.families.list` | GET | `/bi` |
| `bi.legend` | GET | `/bi/legend` |
| `bi.family.get` | GET | `/bi/families/{id}` |
| `bi.family.validate` | POST | `/bi/families/new` (`action=validate`) |
| `bi.family.save` | POST | `/bi/families/new` (`action=save`) |
| `bi.family.delete` | POST | `/bi/families/{id}` (`action=delete`) |
| `bi.report.run` | POST | `/bi/work` (`action=run`) |
| `bi.share.list` | GET | `/bi/shares` |
| `bi.share.create` | POST | `/bi/shares` |
| `bi.share.revoke` | POST | `/bi/shares` (`action=revoke`) |

Same routes as the UI; JSON via `AgentHttp::wantsJson()`. See [module-agent-api.md](module-agent-api.md).

## UI

`/bi` · `/bi/run?family=` (edit button on run page) · `/bi/legend` · `/bi/families/…` · `/bi/shares`

## Rights

Director: **`bi.manage`**.
