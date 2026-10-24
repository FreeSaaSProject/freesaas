# Security Policy

## Supported versions

Security fixes are applied to the current `main` / `master` branch and to the latest published release.

Older tags and forks are out of scope unless arranged separately.

## Reporting a vulnerability

**Do not** open a public GitHub Issue for security vulnerabilities.

Prefer one of:

1. **[Private vulnerability reporting](https://github.com/FreeSaaSProject/freesaas/security/advisories/new)** on this repository (GitHub Security Advisories).
2. Email **[msms2003@mail.ru](mailto:msms2003@mail.ru)** with subject `FreeSaaS security` and enough detail to reproduce (affected version/commit, impact, steps, and a PoC if you have one).

Please include:

- Description of the issue and its impact
- Affected component (module path, route, CLI command, or agent API)
- Steps to reproduce
- Whether you plan to disclose publicly and on what timeline

## What to expect

- Acknowledgement within **7 days** (usually sooner)
- A fix or mitigation plan within **14 days** for confirmed high-impact issues when feasible
- Credit in the advisory or release notes if you want it (optional)

We may ask for clarification or a minimal reproduction. Coordinated disclosure is preferred: please wait until a fix is available (or we agree a date) before publishing details.

## Out of scope (examples)

- Issues that require already-compromised credentials, physical access, or a malicious staff role with intended privileges
- Misconfiguration of a self-hosted instance (`cfg/config.ini`, weak passwords, exposed `agent_token`, disabled TLS)
- Theoretical findings without a practical path to exploit
- Reports against third-party / vendored code listed in [`THIRD_PARTY.md`](THIRD_PARTY.md) — report upstream when possible; we still welcome a heads-up if FreeSaaS packaging is affected

## Secrets in the repository

Do not commit live secrets (`cfg/config.ini`, `cfg/modules/*.ini` with real passwords, API tokens). Use the `*.example.ini` templates. CI runs gitleaks; GitHub secret scanning and push protection are enabled for this repository.
