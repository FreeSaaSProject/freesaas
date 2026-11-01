# Install path cheat sheet

## Docker Compose

```bash
docker compose up -d
# then bootstrap via app PHP (see Dockerfile / entrypoint) or exec into container:
# docker compose exec web php bin/freesaas.php bootstrap ...
```

Docroot must be the repository root (`index.php`, `site.php`).

## CLI greenfield

```bash
cp cfg/config.example.ini cfg/config.ini
# edit connect + salts
php bin/freesaas.php bootstrap \
  --login=admin --password='…' --agent-token='…' --format=json
php bin/freesaas.php doctor --format=json
```

Optional initial modules: `--modules=clients,catalog,orders`.

## Shared hosting HTTP

1. Upload tree; create DB; write `cfg/config.ini`.
2. Set `app.agent_token` **or** upload `var/setup.key`.
3. `POST /agent/bootstrap` with JSON body `{ "login", "password", ... }` and header `X-FreeSaaS-Agent-Token` or `X-FreeSaaS-Setup-Key`.
4. `GET /agent/doctor` with the same token header.

Full curl examples: `docs/agents/install.md`, `docs/agents/QUICKSTART.md`.

## Existing DB (migrate only)

```bash
php bin/freesaas.php migrate run --format=json
php bin/smoke.php --format=json
```

## Benchmarks

Reproducible sequences: `docs/agents/cases/` (`vps-workweeks`, `salon-stack`, `bank-connect`).
