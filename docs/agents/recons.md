# Reconciliations board

Product module `recons`: day board of ledger account reconciliations (сверки) across all accounts — what matched, what is still open, edit bank figures for today or the latest reconciliation day. Does **not** own tables; reads/writes `base_account_reconciliations` via ledger. Catalog maturity: `release`.

## Enable

```bash
php bin/freesaas.php module enable recons --format=json
```

Or `[modules] recons=1` in `cfg/config.ini`.

Permissions:

| Permission | Role |
|------------|------|
| `recons.view` | Open `/recons/day` (HTML + GET JSON) |
| `ledger.reconcile` | Create / update / delete from the board (POST) |

Grant both to staff who should edit bank balances on the board.

## Dependencies

- Requires: `core` >=1.0.0, `ledger` >=1.0.0
- Soft: none
- Tables: `[]` — uses ledger-owned `base_account_reconciliations` / `base_accounts` / `base_account_moves`

## Human UI

- `/recons/day?day=Y-m-d` — day board (menu **Сверки** under finance).
- `/recons` — redirect to `/recons/day`.

| Path | Permission |
|------|------------|
| `/recons/day` | `recons.view` (POST also needs `ledger.reconcile`) |
| `/recons` | `recons.view` (redirect) |

Per-account markers on `/ledger/moves?account_id=` remain (see [ledger.md](ledger.md)); the board is the cross-account day view.

### Landing (no `day`)

1. If today has ≥1 active recon → open today.
2. Else if any historical recon exists → open that latest day + CTA to create for today.
3. Else → empty today board.

### Navigation

- Prev/next jump to the **nearest day that has reconciliations** (not every calendar day).
- Keyboard ← / → via core `.pager` (`data-pager-prev` / `data-pager-next`).

### Edit rules

| Day | Bank edit |
|-----|-----------|
| Calendar **today** | create / update / delete |
| **Latest** recon day (even if past) | create / update / delete; SYSTEM snapshot kept for past |
| Older history | read-only |

SYSTEM column for rows with a recon always shows the saved snapshot (`SYSTEM_BALANCE`). Missing rows show live (today) or as-of end of day (past).

### Stale vs today

When the latest recon day is **not** calendar today: date marked `*`, note under nav, rows where snapshot ≠ live ledger get `*` (title shows live balance). Summary includes `stale` count.

## Agent JSON

Session auth; `?format=json` or `Accept: application/json`. See `module.json` → `agent.ops` (2 ops). Surface B — not `agent_token`.

| Op | Method | Path | Permission |
|----|--------|------|------------|
| `recons.day` | GET | `/recons/day` | `recons.view` |
| `recons.day.write` | POST | `/recons/day` | `ledger.reconcile` |

### GET `recons.day`

Params: optional `day=Y-m-d` (omit for landing resolution).

```bash
curl -sS -b jar.txt 'https://example/recons/day?format=json'
curl -sS -b jar.txt 'https://example/recons/day?day=2026-10-31&format=json'
```

Response (shape):

```json
{
  "ok": true,
  "board": {
    "day": "2026-10-31",
    "is_today": false,
    "is_latest_recon_day": true,
    "is_editable": true,
    "stale_vs_today": true,
    "prev_day": "2026-10-30",
    "next_day": null,
    "today": "2026-11-01",
    "latest_recon_day": "2026-10-31",
    "summary": { "matched": 2, "in_progress": 1, "missing": 3, "stale": 4 },
    "rows": [
      {
        "account_id": 1,
        "account_name": "…",
        "recon_id": 12,
        "bank_balance": 1000.0,
        "system_balance": 1000.0,
        "live_today": 1050.0,
        "stale": true,
        "delta": 0.0,
        "status": "matched"
      }
    ]
  },
  "show_create_today_cta": true,
  "can_recon": true,
  "can_edit": true
}
```

Row `status`: `matched` | `in_progress` | `missing`.

### POST `recons.day.write`

Same path; pass `day=Y-m-d` as query **or** POST field (must be editable). Body fields:

| `recon_action` | Fields |
|----------------|--------|
| `save` | `recon_account_id`, `bank_balance` |
| `update` | `recon_id`, `recon_account_id`, `bank_balance` |
| `delete` | `recon_id`, `recon_account_id` |

```bash
curl -sS -b jar.txt -X POST 'https://example/recons/day?day=2026-11-01&format=json' \
  -d 'recon_action=save&recon_account_id=1&bank_balance=1203323.23'
```

Success returns `{ ok, action, recon_id, account_id, board }`. Prefer this board for cross-account day edits; single-account flow remains `ledger.moves.recon` on `/ledger/moves`.

## Notes

- Advisory markers only — do not lock moves (same as ledger recon).
- Related: [ledger.md](ledger.md) reconciliations section, [finance.md](finance.md) reports.
