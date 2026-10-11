# Case: VPS → workweeks

**Goal:** From empty VPS (or Docker) to day-shift accounting (`workweeks`) with calendar soft-dep.

## Preconditions

- PHP 8.1+ / MySQL, **or** `docker compose up -d`
- Fresh DB (or Docker `freesaas` DB)

## Sequence

```bash
# A) Docker path
docker compose up -d --build
docker compose exec app php bin/freesaas.php bootstrap \
  --login=admin --password='changeme123' --agent-token='dev-agent-token' --format=json

# B) Bare metal: copy config, point vhost, then same bootstrap

php bin/freesaas.php module enable workweeks --accept-risk --format=json
php bin/freesaas.php module enable calendar --accept-risk --format=json
php bin/freesaas.php doctor --format=json
php bin/smoke.php --format=json
```

## Expected

- `bootstrap.ok == true` (or admin already exists on re-run)
- `module enable` for `workweeks` / `calendar` → `ok`
- `doctor.error_codes` does **not** contain `schema_pending`, `db_connect_fail`, `health_fail`
- `smoke.ok == true` (locale warnings may still surface as `locale_fail` on doctor — fix or note)

## Failure →

[repair.md](../repair.md), [debugging.md](../debugging.md)
