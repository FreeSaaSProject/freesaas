"""Optional post-ASR hook used by the whisper plugin (same Mac host)."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any, Dict, Optional

from .engine import DiarizeConfig, config_from_dict, diarize_file

PLUGIN_ROOT = Path(__file__).resolve().parent.parent


def try_diarize(
	audio_path: Path,
	*,
	transcription: str = "",
	config: Optional[Dict[str, Any]] = None,
	profiles_ready: Optional[bool] = None,
	profiles: Optional[list] = None,
	match_cue: Optional[float] = None,
) -> Optional[Dict[str, Any]]:
	"""
	Run diarization if the model is present and staff voice profiles exist.
	Returns payload dict or None when skipped/unavailable.
	"""
	cfg_raw = config if isinstance(config, dict) else {}
	# Gate: do not diarize until manual profiles are built (caller may pre-check).
	if profiles_ready is False:
		return None
	require = cfg_raw.get("require_profiles")
	if require is None:
		require = True
	if require and profiles_ready is not True:
		# Unknown readiness → skip (safer than wrong labels).
		return None
	try:
		dcfg = config_from_dict(cfg_raw, PLUGIN_ROOT)
	except Exception:
		dcfg = DiarizeConfig(onnx_path=PLUGIN_ROOT / "models" / "voxceleb_CAM++_LM.onnx")
	if match_cue is not None:
		try:
			dcfg.match_cue = float(match_cue)
		except (TypeError, ValueError):
			pass
	if dcfg.backend != "mlx" and not Path(dcfg.onnx_path).is_file():
		return None
	if dcfg.backend == "mlx" and (dcfg.mlx_dir is None or not (Path(dcfg.mlx_dir) / "weights.npz").is_file()):
		return None
	profile_list = [p for p in (profiles or []) if isinstance(p, dict)]
	try:
		result = diarize_file(
			Path(audio_path),
			dcfg,
			transcription=transcription,
			profiles=profile_list or None,
		)
		return result.to_payload()
	except Exception:
		return None


def speakers_form_fields(payload: Dict[str, Any]) -> Dict[str, str]:
	"""Form fields for POST /audio/asr/result (and diarize-result)."""
	return {
		"speakers": json.dumps(payload.get("speakers") or [], ensure_ascii=False),
		"segments": json.dumps(payload.get("segments") or [], ensure_ascii=False),
		"cue_speakers": json.dumps(payload.get("cue_speakers") or [], ensure_ascii=False),
		"dominant_speaker": str(payload.get("dominant_speaker") or ""),
	}
