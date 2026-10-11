# Skill: enable-module-set

```bash
for m in clients catalog orders; do
  php bin/freesaas.php module enable "$m" --accept-risk --format=json
done
php bin/freesaas.php doctor --format=json
```

Alpha/beta require `--accept-risk`. Scenarios: [`../agents/cases/`](../agents/cases/). Soft deps: check `module inspect <id>`.
