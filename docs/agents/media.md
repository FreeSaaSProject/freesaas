# media — attachments & photo requirements

Alpha module: store resized images (local `var/media` or FTP) keyed by **owner table + id** (`OWNER_TABLE` / `OWNER_ID`), plus **requirements** on scope tables (`base_procedure_types`, `base_products`).

## Access

| Surface | Who |
|---------|-----|
| `/media`, `/media/requirements` journal, `/media/settings`, bulk delete | `SUPERADMIN_ACCESS` + `media.manage` |
| Upload / view file | Logged-in; ACL via EventBus `media.owner.acl` (procedures → `ProceduresRepository::canViewRow`) |
| Photo kinds (`before` / `after` / `zone` / `other`) | Fixed allowlist in `MediaConfig::kinds()` (+ i18n `media.kind.*`). Not editable in UI in v1. |
| Requirement text (`LABEL`) | Set on procedure type or product card; shown in unmet badges on the procedure. |
| Requirements form | Superadmin, or `procedures.types` / `catalog.edit` for that scope |

Files are **never** under the web root. Serve only `/media/file/{id}` and `/media/thumb/{id}` after ACL.

## Settings (`cfg/modules/media.ini`)

- `mode` = `local` \| `ftp`
- Resize: `max_upload_bytes`, `max_edge_px` (default 2048), `thumb_edge_px` (400), `jpeg_quality`, `strip_exif`
- FTP + **Copy FTP from audio/backups** (credentials; path `/freesaas-media`)

## First consumer

Procedure edit embeds a panel (`procedures.edit.sections`). Unmet requirements show as badges (no hard block on save in v0.1).

## Agent

See `module.json` → `agent.ops`. Enable with risk ack: `module enable media --accept-risk`.
