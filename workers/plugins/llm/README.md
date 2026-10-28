# Local LLM plugin (stub)

Reserved slot for a future on-Mac LLM worker (llama.cpp / MLX / Ollama-backed).

**Not enabled** in `host.example.json`. The host core already supports:

- separate process via supervisor
- `plugin.json` models[] + `install download-models`
- status `activity` + live logs
- dashboard widget module

When implementing: add `worker.py` (claim/result against FreeSaaS `llm` or a local queue), `dashboard.py`, real `models[]` URLs, then add `"llm"` to `enabled_plugins`.
