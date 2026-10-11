# Contributing to FreeSaaS

Thank you for your interest in FreeSaaS.

## CLA required

**External contributions are accepted only after you accept the
[Contributor License Agreement](CLA.md).**

Send acceptance to **msms2003@mail.ru** as described in `CLA.md` before (or with)
your first pull request. Contributions without an accepted CLA will not be merged.

The CLA is a copyright and patent **license grant** to the copyright holder
(Ilya Kleshnin). You keep your copyright; the Maintainer needs the grant in order
to distribute FreeSaaS under AGPL-3.0-or-later and under optional commercial
licenses ([`COMMERCIAL-LICENSE.md`](COMMERCIAL-LICENSE.md)).

### Maintainer AI / automation (no CLA)

Commits created by AI agents or automation operated by the copyright
holder/maintainer (Cursor Agent, Codex, Claude, and similar tools the
maintainer runs) are contributions **on behalf of the maintainer**. They are
not external contributions and do **not** require a separate CLA.
Maintainer-operated automation/AI accounts are not external contributors.

`Co-authored-by` / agent attribution is welcome for transparency; it does not
trigger a CLA requirement for maintainer-operated agents.

### External AI-assisted PRs

If you open a PR that your Cursor/Claude/Codex (or other AI) helped write,
**you** (or your organization) still sign the CLA. You are responsible for
having the rights to contribute. AI generation does not remove that duty.
See [`CLA.md`](CLA.md) (“Maintainer-operated automation and AI”).

## Project conventions

- Architecture and module rules: [`AGENTS.md`](AGENTS.md), [`docs/GUIDE.md`](docs/GUIDE.md).
- Runtime SQL only against `base_*` tables; new schema via module migrations.
- UI chrome via i18n (`t()` / `te()` / `tj()`); ship both `lang/ru.php` and `lang/en.php`.
- Do not commit secrets (`cfg/config.ini`, `cfg/modules/*.ini` with real passwords).

## License headers for new first-party files

New FreeSaaS source files (not third-party / vendored) should include:

```php
/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
```

Do not alter license headers inside `vendor/`, minified third-party assets, or
other third-party material listed in [`THIRD_PARTY.md`](THIRD_PARTY.md).

## Licensing overview

- Default open-source license: AGPL-3.0-or-later — see [`LICENSE`](LICENSE).
- Optional commercial license: [`COMMERCIAL-LICENSE.md`](COMMERCIAL-LICENSE.md).
- Copyright holder: Ilya Kleshnin. “FreeSaaS” is the project name only.
