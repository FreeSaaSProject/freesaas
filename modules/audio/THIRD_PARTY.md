# Third-party libraries (audio family)

Audit status for shipping FreeSaaS with audio pipeline vendors. Do **not** vendor-copy into `modules/*/vendor/` until the decision column is resolved.

| Component | Upstream | Preliminary license | Decision |
|---|---|---|---|
| phpMorphy (src) | oper `libs/phpmorphy` (Kamaev) | LGPL-2.0+ | Ship as separate library + LGPL notice; keep replaceable |
| phpMorphy dicts (`morph_data.*.bin`) | bundled with phpMorphy / AOT-derived | **TBD — verify dict provenance** | Blocker until cleared; optional download package if incompatible |
| getID3 | James Heinrich / getid3.org | GPL-2+ **or** commercial license | Prefer replace with minimal MP3 duration reader **or** purchase commercial; do not ship GPL into closed product without compliance |
| LinguaStemRu / `stem01.php` | oper `libs/stem` | **No LICENSE file in tree** | Establish provenance (Snowball/Porter ports often BSD) or replace with known BSD Snowball RU |
| audio.js + `audiojs.swf` | Kolber audio.js | Usually MIT; Flash obsolete | Use native HTML5 `<audio>`; do not ship SWF |

Secrets (FTP / LLM / worker tokens) must never be copied from oper `config.ini` into the FreeSaaS repo.

Whisper Python workers are **out of scope** for wave B (external GPU host only).
