# phpMorphy (vendored)

Copy of oper `libs/phpmorphy` (Kamaev Vladimir), shipped with FreeSaaS audio_pipeline.

- License: **GNU LGPL 2.1+** — see `LICENSE.LGPL-2.1.txt` and headers in `src/`.
- Morphology dictionaries under `dicts/` (incl. `*.ru_ru.bin`, EN/DE/UA packs) are the standard phpMorphy morphology binaries as distributed with phpMorphy (Kamaev); historically derived from AOT / OpenCorpora-family morphology data. Keep the LGPL notice when redistributing; do not claim first-party authorship of dict binaries.
- Default load path: `modules/audio_pipeline/vendor/phpmorphy` (no ini required).
- Override: `cfg/modules/audio.ini` → `phpmorphy_dir=...`

Local patches (keep when re-vendoring from oper):

- `src/morphiers.php` — PHP 8.1+ return types on `Countable` / `ArrayAccess` / `IteratorAggregate`; declare `$annots` / `$__obj` (lazy proxies).
- `src/common.php`, `storage.php`, `gramtab.php` — declare lazy `$__*` props and `unset()` in ctor so `__get` still runs (PHP 8.2).
- `src/graminfo/graminfo.php` — RuntimeCaching uses declared `$flexia` instead of dynamic `$flexia_all`.

Do not strip `LICENSE.LGPL-2.1.txt` from releases.
