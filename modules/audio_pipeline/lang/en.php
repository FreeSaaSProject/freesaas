<?php
declare(strict_types=1);

return [
    'audio_pipeline.module.name' => 'Audio pipeline',
    'audio_pipeline.perm.view' => 'Audio pipeline (status)',
    'audio_pipeline.module.description' => 'Ingest / cabin ASR / stem / collect workers for cabin audio analytics.',
    'audio_pipeline.module.summary' => 'Enable for cabin recording background jobs. Requires asr + audio + audio_dicts. Soft procedures. Messenger voice lives in module asr.',
];
