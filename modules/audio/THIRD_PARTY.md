# Third-party libraries (audio family)

| Component | Location | License | Decision |
|---|---|---|---|
| phpMorphy (src + dicts) | `modules/audio_pipeline/vendor/phpmorphy` (from oper `libs/phpmorphy`) | LGPL-2.1+ | **Shipped** with release; notice in vendor README + `LICENSE.LGPL-2.1.txt` |
| LinguaStemRu | `modules/audio_pipeline/lib/LinguaStemRu.php` | Porter/Snowball-style RU stemmer (no upstream LICENSE in oper tree) | Fallback when morph unavailable; used after morph |
| getID3 | — | GPL-2+ / commercial | Not vendored; prefer minimal duration reader |
| audio.js / SWF | — | MIT / obsolete | Use native HTML5 `<audio>` |

Secrets (FTP / LLM / worker tokens) must never be copied from oper `config.ini` into the FreeSaaS repo.

Whisper Python workers remain external (GPU host).
