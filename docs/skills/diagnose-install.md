# Skill: diagnose-install

```bash
php bin/freesaas.php doctor --format=json
# or: curl -H "X-FreeSaaS-Agent-Token: $TOKEN" https://HOST/agent/doctor
```

Use `error_codes` + `hints`. Full map: [`../agents/repair.md`](../agents/repair.md).  
If opening upstream: paste `issue_body`; CLA before PR.
