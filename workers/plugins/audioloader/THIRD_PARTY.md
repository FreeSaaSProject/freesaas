# Third-party components (AudioLoader plugin)

This plugin is AGPL-3.0-or-later (same as FreeSaaS). External tools are **not** shipped in the repo.

## LAME

- **What:** MP3 encoder invoked as a separate process (`lame` / `lame.exe`).
- **License:** LAME Project — typically LGPL (see [lame.sourceforge.io](https://lame.sourceforge.io/)).
- **Redistribution:** Do **not** commit `lame.exe` / `lame_enc.dll` into FreeSaaS. Operators obtain LAME themselves and place `lame.exe` next to the plugin data dir or on `PATH`, or set `lame_path` in config.
- **AGPL note:** Calling LAME via subprocess does not relicense this plugin; keep LAME’s notices with any binary you distribute yourself.

## FFmpeg

- Required for Speechmate OGG / CHINA3 slicing (pydub). Install system FFmpeg with MP3 encode support (`libmp3lame`) when possible.
- Prefer FFmpeg on `PATH`; `doctor` checks it.

## PyPI

See `requirements.txt` (`requests`, `pydub`, `mutagen` GPLv2+, `tzdata`, `sounddevice`, `numpy`).
