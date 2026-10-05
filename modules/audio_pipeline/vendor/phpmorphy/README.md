# phpMorphy (vendored)

Copy of oper `libs/phpmorphy` (Kamaev Vladimir), shipped with FreeSaaS audio_pipeline.

- License: **GNU LGPL 2.1+** — see `LICENSE.LGPL-2.1.txt` and headers in `src/`.
- Morphology dictionaries under `dicts/` (incl. `*.ru_ru.bin`) are part of the phpMorphy distribution used in production oper; keep the LGPL notice when redistributing.
- Default load path: `modules/audio_pipeline/vendor/phpmorphy` (no ini required).
- Override: `cfg/modules/audio.ini` → `phpmorphy_dir=...`

Do not strip `LICENSE.LGPL-2.1.txt` from releases.
