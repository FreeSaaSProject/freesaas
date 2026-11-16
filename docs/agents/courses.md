# Courses

LMS programs with timed enrollments for **clients** and **staff**, drip lessons, private media proxy.

## Enable

```bash
php bin/freesaas.php module enable courses --accept-risk --format=json
php bin/freesaas.php migrate run --module=courses --format=json
php bin/freesaas.php module enable catalog --format=json
# Optional:
php bin/freesaas.php module enable client_portal acquiring --accept-risk --format=json
```

## Model

- Programs: `audience` = clients|staff|both; optional `product_id` for shop sale; `access_days`
- Lessons: `drip_days` from enrollment `access_from`
- Enrollments: `subject_type` client|staff + `subject_id`
- Media: files under `courses.storage_root` (not web root); `GET /courses/media/{id}?exp&sig&st&sid`

Payment → enroll: listen `acquiring.payment.succeeded` → map order cart product ids → programs.

Staff learn: `/courses/learn` (permission `courses.learn`). Clients: portal section `courses`.

## Host tip

Use a dedicated `base_site_hosts` row (e.g. `learn.example.com`) with catalog roots limited to course SKUs; cabinet at `/cabinet`.

## Agent ops

See `module.json` → `agent.ops`. Media route is public but HMAC + enrollment gated.
