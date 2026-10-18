# BI reports (`bi`)

JSON-recipe operational reports (director). **BI is the engine only** — datasets, domain derive steps, and shipped recipes are contributed by enabled product modules via EventBus (`bi.datasets`, `bi.derive_steps`, `bi.recipes`). Saving in the constructor writes a DB override in `base_bi_family_defs`. Live share links with TTL.

Surface B (staff session / MCP) — permission **`bi.manage`**. Runbook twin: UI `/bi`. Module catalog maturity: **beta**.

## Enable

```bash
php bin/freesaas.php module enable bi --accept-risk --format=json
# Grant role permission bi.manage (director / report owner).
```

Datasets appear in legend only when the owning module is enabled (and its table exists). Examples: `procedures` ← procedures, `workweeks` + `expand_shift_lanes` ← workweeks, `guests` ← terminals, `records` ← audio (+ enrich from audio_reports), `users_staff` ← core.

## Contrib contract (modules)

In owning `bootstrap.php`:

```php
EventBus::on('bi.datasets', [MyBiContrib::class, 'onDatasets']);
EventBus::on('bi.derive_steps', ...); // optional expand_* handlers
EventBus::on('bi.recipes', ...);      // optional shipped JSON path or inline recipe
```

Use `BiDatasetCatalog::define($id, $label, $table, $moduleId, $extra)`. No arbitrary SQL. Duplicate dataset ids from different modules → runtime conflict.

## Agent workflow (build → edit → run → share)

Always send `Accept: application/json` or `?format=json` with a staff session that has `bi.manage`.

```bash
# 1) List families
curl -sS -b jar.txt -H 'Accept: application/json' 'https://example/bi'

# 2) Legend (datasets / expr / derive / section types / examples) — instance-specific
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
- **Expr `acronym`** = plain shortName text. **Expr `badge`** = safe token → colored `UsersRepository::badgeHtml` at render (also column `format: user_badge` + `user_field`).
- Ship overrides: save with a **shipped** `family_id` → DB override; `action=delete` + `def_id` restores the file from owning module.
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

Derive pipeline (in order): contrib expand steps (e.g. `expand_shift_lanes` from workweeks) → `join_frame` → `filter` → `select`/`compute` → `group_by`/`aggs` → `compute_after` → `having` → `window` → `sort` → `limit`.

Engine opts (general): join-only step followed by sole-consumer `group_by` is **fused** (join product never stored); `group_by` streams accumulators (no member-row lists). Put `join_frame` + `group_by` on one derive step for the same effect.

Full allowlist: **`GET /bi/legend`** (`agent_howto`, `recipe_shape`, `datasets`, `expr_ops`, `group_aggs`, `examples`).

## Shipped recipes (by owning module)

| Id | Owner | Path |
|----|-------|------|
| `demo_counts` | procedures | `modules/procedures/recipes/bi/` |
| `staff_kpi` | procedures | same |
| `masseur_shifts` | workweeks | `modules/workweeks/recipes/bi/` |
| `admin_conversion` | terminals | `modules/terminals/recipes/bi/` |
| `audio_desk_month` | audio_reports | `modules/audio_reports/recipes/bi/` |

`/audio/summaries` (summary profiles UI) remains available alongside the BI family for comparison / gradual migration.

## Shares

| Rule | Detail |
|------|--------|
| Paths | `/bi/s/{token}` (public route; guest or staff audience) |
| TTL | `1h` \| `1d` \| `1mo` \| `3mo` \| `1y` (`1y` **staff only**) |
| Allowlist | Optional `staff_user_ids` and/or `staff_status_ids`. **Both empty** = any logged-in staff. Match is **union** |
| Rate limit | ~8 runs/min per share |

## Engine budgets

Runtime caps (also enforced on validate for step counts): query frames ≤ 50 000 rows; derive frames ≤ 500 000; cross-join ≤ 500 000 cells; ≤ 40 queries / 60 derive steps; wall clock ≤ 20 s. Each SQL SELECT sets session `MAX_EXECUTION_TIME` / MariaDB `max_statement_time`.
