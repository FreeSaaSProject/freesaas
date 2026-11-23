# Berdnikova instance bind

One-shot checklist after code is on the host.

```bash
# Soft OSS
php bin/freesaas.php module activate acquisition --accept-risk
php bin/freesaas.php module activate rates   # if not already
# Private FP (after overlay of modules/fp_berd)
php bin/freesaas.php module activate fp_berd --accept-risk

php bin/freesaas.php migrate run --module=acquisition
php bin/freesaas.php migrate run --module=rates
php bin/freesaas.php migrate run --module=fp_berd

# Seed marketing DPS types (fp_berd migrator)
php bin/data-migrate.php --module=fp_berd --id=seed_marketing_types
```

Then in UI:

1. `/acquisition/sources` — channels; `/acquisition/calls` — inquiry journal.
2. `/rates/plans` — month plan / ₽/hour / bonus tiers.
3. `/fp-berd/articles` — map articles to DPS type ids from marketing seed.
4. `/fp-berd/scenarios` — active scenario + BE revenue; `/fp-berd/weeks` envelopes.
5. Optional `notify`: rule on unmarked calls / primary without source (notices also come from acquisition `core.notices.collect`).

Disable `acquisition` → client/calendar/ledger lose source/call UI; FP can stay on.
