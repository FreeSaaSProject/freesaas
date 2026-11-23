# fp_berd (private)

Berdnikova financial plan pack: articles ↔ DPS move types, budget scenarios, week envelopes, break-even. Soft `ledger`. Does **not** use `funds` % envelopes.

## Packaging

- Lives in a **private** repo (gitignored in the public FreeSaaS tree as `modules/fp_berd/`).
- Install/update via `cfg/updates.ini` → `[module.fp_berd]` (`repository`, token, optional `path_prefix`).
- Maturity **alpha** — accept risk on enable.

## Tables (owned)

`base_fp_berd_articles`, `base_fp_berd_scenarios`, `base_fp_berd_scenario_lines`, `base_fp_berd_week_envelopes`.

## Instance bind (checklist)

1. Enable `ledger`, optionally `acquisition` + `rates`.
2. `php bin/freesaas.php module activate fp_berd --accept-risk` (or UI).
3. Run migrator `seed_marketing_types` (marketing DPS types).
4. `/fp-berd/articles` — bind articles to `base_account_move_types.ID`.
5. Create + activate a scenario; set week envelopes; open `/fp-berd/be`.
6. Optional: enable `notify` rules for unmarked acquisition / plan gaps (product rules, not hardcoded).

## Agent ops

**Session (staff):** `module.json` → `agent.ops` on `/fp-berd/*`.

**Platform token (`X-FreeSaaS-Agent-Token`):** Surface A under `/agent/fp-berd/*`:

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/agent/fp-berd/status` | Diagnose + issues[] |
| GET/POST | `/agent/fp-berd/articles` | List / save article |
| GET/POST | `/agent/fp-berd/scenarios` | List / save scenario + lines (`amount` ₽ and/or `pct` of `be_revenue`; save normalizes both) |
| GET/POST | `/agent/fp-berd/weeks` | Envelopes; POST `refresh_facts=1` or `seed_history=1` (`from`/`to`, optional `monthly_amounts`) |
| GET | `/agent/fp-berd/be` | Break-even + three scenarios + month plan/fact |
| POST | `/agent/fp-berd/seed-demo` | Idempotent demo: 9 articles, 3 scenarios, **history** week envelopes (~90d) |

### Demo scenarios (like Berdnikova tables)

One scenario is not enough for her slide/table demos. Seed creates:

| Scenario | Role |
|----------|------|
| Пессимистичный | Lower BE, compressed MKT/FOT/OPEX (~0.75×, MKT ~0.55×) |
| Базовый | Plan / BE month (active by default) |
| Оптимистичный | Stretch revenue + higher marketing (~1.25× / MKT 1.4×) |

```bash
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H "Content-Type: application/json" \
  -d '{"activate":"base"}' https://HOST/agent/fp-berd/seed-demo
```

Optional body: `be_low`, `be_base`, `be_high`, `week`, `from`/`to` (history window), `bind_types=0` to keep existing article↔DPS binds.

Fill plan/fact for every Monday with finance data:

```bash
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H "Content-Type: application/json" \
  -d '{"seed_history":1,"from":"2026-08-25","to":"2026-11-23","include_rev":1}' \
  https://HOST/agent/fp-berd/weeks
```

Board `/fp-berd` and BE `/fp-berd/be` show week + month plan/fact (fact from DPS by article `type_id`).
