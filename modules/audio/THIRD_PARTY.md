# Third-party libraries (audio family)

| Component | Location | License | Decision |
|---|---|---|---|
| phpMorphy (src + dicts) | `modules/audio_pipeline/vendor/phpmorphy` (from oper `libs/phpmorphy`) | LGPL-2.1+ | **Shipped** with release; sole lemmatizer for stem/reparse (`StemRunner::libsAvailable`). Notice in vendor README + `LICENSE.LGPL-2.1.txt` |
| getID3 | — | GPL-2+ / commercial | Not vendored; prefer minimal duration reader |
| audio.js / SWF | — | MIT / obsolete | Use native HTML5 `<audio>` |

LinguaStemRu (Porter/Snowball RU port from oper) is **not shipped** — unclear provenance; morph miss keeps the surface form.

Secrets (FTP / LLM / worker tokens) must never be copied from oper `config.ini` into the FreeSaaS repo.

Whisper Python workers remain external (GPU host).
