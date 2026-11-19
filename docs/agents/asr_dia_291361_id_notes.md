# 291361 speaker-ID notes (2026-11-19)

Record: https://freesaas.likespa.hm/audio/291361 (badge **ДВМ** / admin 127).

## What went wrong

| Cluster | Talk | Soft match (old) | Human | Why |
|---------|------|------------------|-------|-----|
| S1 | 52s | ДВМ 0.70 | ДВМ | OK |
| S2 | 41s | **КДА** 0.46 | **ЭТА** | ЭТА profile = **1 sample / 5s**; КДА (8/52s) won. Soft rematch after save made it worse. |
| S0 | 20s | **МОВ** 0.51 | mixed / line2=ДВМ | Below honest thr; `saveForRecord` → `matchRecordAgainstProfiles` re-attached with cue threshold 0.50 + sample boost. Also dia merged early turns. |
| S3 | 25s | БНА 0.57 | ? | Borderline |

## Fixes applied

1. asr_dia: thr≥0.58, margin≥0.05, skip profiles with `<3` samples and `<15s` talk (drops ЭТА until enriched).
2. `SpeakerService::saveForRecord(..., rematch=false)` for `engine=asr_dia` — **no soft rematch overwrite**.
3. Re-applied 291361: only **ДВМ** labeled; S0/S2/S3 anonymous (no fake КДА/МОВ).

## Rebuild?

- **ЭТА (85): yes — enroll more manual samples** (now 1×5s). Without that, vectors cannot pick ЭТА over КДА.
- **ДВМ/МОВ/КДА centroids:** not the root bug; false IDs were threshold + rematch + weak ЭТА.
- Optional later: rebuild from dia concatenations **only after** human-confirmed turns.

## Recheck UI

Reload https://freesaas.likespa.hm/audio/291361 — lines 1–9 should be `S0` (not МОВ), 40–59 `S2` (not КДА), badge blocks `ДВМ`.
