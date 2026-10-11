# Upstream flywheel (agent → issue → PR)

Maturity policy (also on `GET /agent/modules`): diagnose → local fix → check issues → open issue → attach PR when universal.

## From doctor to issue

```bash
php bin/freesaas.php doctor --format=json
```

1. Copy `issue_body` into GitHub → [Agent / install bug](../../.github/ISSUE_TEMPLATE/agent-bug.yml).
2. Or use helper:

```bash
php bin/freesaas.php doctor --issue-body > /tmp/freesaas-issue.md
```

## From fix to PR

1. Accept [CLA](../../CLA.md) ([CONTRIBUTING.md](../../CONTRIBUTING.md)).
2. Validate: `module validate`, `health`, `smoke` — [PR template](../../.github/PULL_REQUEST_TEMPLATE.md).
3. New module: `php bin/build-modules-catalog.php` and commit `modules-catalog.json`.

## Discussions

Use GitHub Discussions “Agent install reports” for successful case runs ([cases/](cases/)) even without a bug — fuels distribution.
