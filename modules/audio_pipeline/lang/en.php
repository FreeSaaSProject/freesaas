<?php
declare(strict_types=1);

return [
    'audio_pipeline.module.name' => 'Audio pipeline',
    'audio_pipeline.perm.view' => 'Audio pipeline (status)',
    'audio_pipeline.module.description' => 'Ingest/ASR handshake/stem/collect workers for audio processing.',
    'audio_pipeline.module.summary' => 'Enable to run background audio processing workers. Requires audio + audio_dicts. Soft procedures. Needs worker tokens in module ini/health checks. Ops module — not only UI.',
];
