# Whisper cabin worker (FreeSaaS)

Pull client for cabin ASR: claims files from FreeSaaS, runs local `whisper-server`, posts transcripts, then triggers stem.

**Agent deploy runbook:** [`docs/agents/whisper-worker.md`](../../docs/agents/whisper-worker.md)

API contract / oper mapping: [`docs/agents/audio_pipeline.md`](../../docs/agents/audio_pipeline.md)

This directory ships the **Python client only**. Build [whisper.cpp](https://github.com/ggerganov/whisper.cpp) separately on the Mac (or GPU host). Do not commit `configs/local.json` or real tokens.
