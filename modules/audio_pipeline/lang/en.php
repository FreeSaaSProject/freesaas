<?php
declare(strict_types=1);

return [
    'audio_pipeline.module.name' => 'Audio pipeline',
    'audio_pipeline.perm.view' => 'Audio pipeline (status)',
    'audio_pipeline.module.description' => 'Ingest / cabin ASR / stem / collect workers for cabin audio analytics.',
    'audio_pipeline.module.summary' => 'Enable for cabin recording background jobs. Requires asr + audio + audio_dicts. Soft procedures. Messenger voice lives in module asr.',
    'audio_pipeline.settings.coverage' => 'Record coverage (disable idle)',
    'audio_pipeline.settings.coverage_hint' => 'Global defaults. Per-role / per-staff overrides below (optional). Outside likespa leave match off for plain ® analytics.',
    'audio_pipeline.settings.disable_outside' => 'Disable records outside coverage after collect',
    'audio_pipeline.settings.disable_outside_hint' => 'Records kept enabled: overlap with a rated collection, a paid procedure (± buffer), or pending review queue. Roles need DISABLE_NON_PROCEDURE_TIME.',
    'audio_pipeline.settings.match_procedures' => 'Compare / match paid procedures with script sessions',
    'audio_pipeline.settings.match_procedures_hint' => 'Day-report procedure panel + use procedure windows when disabling idle. Off by default; enable globally or per role/staff.',
    'audio_pipeline.settings.procedure_buffer' => 'Procedure buffer (sec)',
    'audio_pipeline.settings.min_procedure' => 'Min procedure duration (sec)',
    'audio_pipeline.settings.coverage_by_position' => 'Override by role',
    'audio_pipeline.settings.coverage_by_position_hint' => 'When enabled, replaces global match/disable/min/buffer for staff in this role.',
    'audio_pipeline.settings.coverage_by_admin' => 'Override by staff',
    'audio_pipeline.settings.coverage_by_admin_hint' => 'Staff override wins over role profile.',
    'audio_pipeline.settings.position' => 'Role',
    'audio_pipeline.settings.admin' => 'Staff',
    'audio_pipeline.settings.profile_enabled' => 'Use this override',
];
