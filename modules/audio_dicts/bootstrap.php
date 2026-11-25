<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/RecordsTrashesRepository.php';
require_once __DIR__ . '/lib/RecordsDictsRepository.php';
require_once __DIR__ . '/lib/RecordsKeysRepository.php';
require_once __DIR__ . '/lib/KeyStemConflict.php';
require_once __DIR__ . '/lib/RecordsTagsRepository.php';
require_once __DIR__ . '/lib/RecordsScriptsRepository.php';
require_once __DIR__ . '/lib/ScriptVersionsRepository.php';
require_once __DIR__ . '/lib/ScriptPack.php';
require_once __DIR__ . '/lib/DictDescrSuggest.php';
require_once __DIR__ . '/lib/AudioDictsLongJob.php';
require_once __DIR__ . '/lib/KeyReplaceSuggest.php';
require_once __DIR__ . '/lib/KeyReplaceLlm.php';
require_once __DIR__ . '/lib/KeyReplaceLongJob.php';
require_once __DIR__ . '/lib/AudioDictsAgentOps.php';
require_once __DIR__ . '/lib/AudioDictsBiContrib.php';

AudioDictsAgentOps::register();
EventBus::on('bi.datasets', [AudioDictsBiContrib::class, 'onDatasets']);

Assets::register('audio_dicts', [
    'css' => ['audio_dicts.css'],
    'js' => ['audio_dicts.js'],
]);

LongJob::register(KeyReplaceLongJob::TYPE, [
    'module' => 'audio_dicts',
    'permission' => 'audio_dicts.edit',
    'defaults' => ['tick_seconds' => 90],
    'contexts' => [
        'ui' => ['tick_seconds' => 90],
        'cli' => ['tick_seconds' => 120],
    ],
    'agent' => ['mode' => 'tick', 'assistant' => false],
    'on_start' => [KeyReplaceLongJob::class, 'onStart'],
    'tick' => [KeyReplaceLongJob::class, 'tick'],
]);

LongJob::register(AudioDictsLongJob::TYPE_SUGGEST_DESCR, [
    'module' => 'audio_dicts',
    'permission' => 'audio_dicts.edit',
    'defaults' => ['tick_seconds' => 90],
    'contexts' => [
        'ui' => ['tick_seconds' => 90],
        'cli' => ['tick_seconds' => 120],
    ],
    'agent' => ['mode' => 'tick', 'assistant' => false],
    'on_start' => [AudioDictsLongJob::class, 'suggestOnStart'],
    'tick' => [AudioDictsLongJob::class, 'suggestTick'],
]);

$entities = [
    'dicts' => [
        'list' => '/audio/dicts',
        'repo' => RecordsDictsRepository::class,
        'title' => 'audio_dicts.menu.dicts',
        'list_id' => 'audio_dicts',
    ],
    'keys' => [
        'list' => '/audio/keys',
        'repo' => RecordsKeysRepository::class,
        'title' => 'audio_dicts.menu.keys',
        'list_id' => 'audio_keys',
    ],
    'tags' => [
        'list' => '/audio/tags',
        'repo' => RecordsTagsRepository::class,
        'title' => 'audio_dicts.menu.tags',
        'list_id' => 'audio_tags',
    ],
    'trashes' => [
        'list' => '/audio/trashes',
        'repo' => RecordsTrashesRepository::class,
        'title' => 'audio_dicts.menu.trashes',
        'list_id' => 'audio_trashes',
    ],
];

if (!function_exists('audioKeysVariantsField')) {
    /** Bulk field: what to do with variants (ID_REPLACE → this key) of canonical keys. */
    function audioKeysVariantsField(): array
    {
        return [
            'name' => 'on_dependents',
            'type' => 'select',
            'label' => 'audio_dicts.replace.field.on_dependents',
            'options' => [
                '' => t('audio_dicts.replace.policy.ask'),
                'cascade' => t('audio_dicts.replace.policy.cascade'),
                'detach' => t('audio_dicts.replace.policy.detach'),
            ],
        ];
    }

    /**
     * Canonical keys with variants are skipped until the operator picks a policy.
     *
     * @param list<mixed> $ids
     * @param array<string,mixed> $params
     * @param callable(list<int>,string):int $apply
     */
    function audioKeysVariantsRun(array $ids, array $params, callable $apply): array
    {
        $mode = (string)($params['on_dependents'] ?? '');
        if (!in_array($mode, ['', 'cascade', 'detach'], true)) {
            $mode = '';
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        $blockers = $mode === '' ? RecordsKeysRepository::dependentBlockers($ids) : [];
        $work = [];
        $skipped = [];
        foreach ($ids as $id) {
            if (isset($blockers[$id])) {
                $skipped[] = ['id' => $id, 'reason' => $blockers[$id]];
                continue;
            }
            $work[] = $id;
        }
        $done = $work !== [] ? (int)$apply($work, $mode) : 0;

        return ['done' => $done, 'skipped' => $skipped];
    }
}

foreach ($entities as $entity => $meta) {
    $listPath = $meta['list'];
    ModuleManager::registerRoute($listPath, [
        'module' => 'audio_dicts',
        'methods' => ['GET', 'POST'],
        'permission' => 'audio_dicts.view',
        'handler' => static function () use ($entity): void {
            $GLOBALS['audio_dicts_entity'] = $entity;
            require base_path('modules/audio_dicts/pages/entity_list.php');
        },
    ]);
    ModuleManager::registerRoute($listPath . '/new', [
        'module' => 'audio_dicts',
        'methods' => ['GET', 'POST'],
        'permission' => 'audio_dicts.edit',
        'handler' => static function () use ($entity): void {
            $GLOBALS['audio_dicts_entity'] = $entity;
            require base_path('modules/audio_dicts/pages/entity_edit.php');
        },
    ]);
    ModuleManager::registerRoute($listPath . '/{id}/edit', [
        'module' => 'audio_dicts',
        'methods' => ['GET', 'POST'],
        'permission' => 'audio_dicts.edit',
        'handler' => static function () use ($entity): void {
            $GLOBALS['audio_dicts_entity'] = $entity;
            require base_path('modules/audio_dicts/pages/entity_edit.php');
        },
    ]);

    $repo = $meta['repo'];
    $listId = $meta['list_id'];
    $isKeys = $entity === 'keys';
    BulkActions::register($listId, 'delete', [
        'label' => 'core.bulk.delete',
        'permission' => 'audio_dicts.delete',
        'confirm' => 'core.bulk.confirm_delete',
        'fields' => $isKeys ? [audioKeysVariantsField()] : [],
        'run' => static function (array $ids, array $params) use ($repo, $isKeys): array {
            if (!$isKeys) {
                return ['done' => $repo::deleteByIds($ids), 'skipped' => []];
            }

            return audioKeysVariantsRun($ids, $params, static function (array $work, string $mode) use ($repo): int {
                return $repo::deleteByIds($work, $mode);
            });
        },
    ]);
}

BulkActions::register('audio_keys', 'enable', [
    'label' => 'core.bulk.enable',
    'group' => 'audio_dicts.bulk.group.enablers',
    'permission' => 'audio_dicts.edit',
    'confirm' => 'audio_dicts.bulk.confirm_enable_keys',
    'run' => static function (array $ids, array $params): array {
        unset($params);

        return ['done' => RecordsKeysRepository::setDisabledByIds($ids, false), 'skipped' => []];
    },
]);
BulkActions::register('audio_keys', 'disable', [
    'label' => 'core.bulk.disable',
    'group' => 'audio_dicts.bulk.group.enablers',
    'permission' => 'audio_dicts.edit',
    'confirm' => 'audio_dicts.bulk.confirm_disable_keys',
    'fields' => [audioKeysVariantsField()],
    'run' => static function (array $ids, array $params): array {
        return audioKeysVariantsRun($ids, $params, static function (array $work, string $mode): int {
            return RecordsKeysRepository::setDisabledByIds($work, true, $mode);
        });
    },
]);

// ASR replacement review (state only; links are created by accept, never by LLM directly).
BulkActions::register('audio_keys', 'replace_pending', [
    'label' => 'audio_dicts.replace.bulk.pending',
    'group' => 'audio_dicts.bulk.group.enablers',
    'permission' => 'audio_dicts.edit',
    'confirm' => 'audio_dicts.replace.bulk.confirm_pending',
    'run' => static function (array $ids): array {
        $r = RecordsKeysRepository::setReplaceStateByIds($ids, 'pending');

        return ['done' => $r['done'], 'skipped' => $r['skipped']];
    },
]);
BulkActions::register('audio_keys', 'replace_keep', [
    'label' => 'audio_dicts.replace.bulk.keep',
    'group' => 'audio_dicts.bulk.group.enablers',
    'permission' => 'audio_dicts.edit',
    'confirm' => 'audio_dicts.replace.bulk.confirm_keep',
    'run' => static function (array $ids): array {
        $r = RecordsKeysRepository::setReplaceStateByIds($ids, 'keep');

        return ['done' => $r['done'], 'skipped' => $r['skipped']];
    },
]);
BulkActions::register('audio_keys', 'replace_accept', [
    'label' => 'audio_dicts.replace.bulk.accept',
    'group' => 'audio_dicts.bulk.group.enablers',
    'permission' => 'audio_dicts.edit',
    'confirm' => 'audio_dicts.replace.bulk.confirm_accept',
    'run' => static function (array $ids): array {
        $r = RecordsKeysRepository::acceptSuggestionsByIds($ids);

        return ['done' => $r['done'], 'skipped' => $r['skipped']];
    },
]);
BulkActions::register('audio_keys', 'replace_reject', [
    'label' => 'audio_dicts.replace.bulk.reject',
    'group' => 'audio_dicts.bulk.group.enablers',
    'permission' => 'audio_dicts.edit',
    'confirm' => 'audio_dicts.replace.bulk.confirm_reject',
    'run' => static function (array $ids): array {
        $r = RecordsKeysRepository::rejectSuggestionsByIds($ids);

        return ['done' => $r['done'], 'skipped' => $r['skipped']];
    },
]);

// LLM analysis: LongJobPanel on the keys list (JS intercepts, like suggest_descr). Sync run is a no-op.
if (ModuleManager::isEnabled('llm')) {
    BulkActions::register('audio_keys', 'replace_llm', [
        'label' => 'audio_dicts.replace.bulk.llm',
        'group' => 'audio_dicts.bulk.group.enablers',
        'permission' => 'audio_dicts.edit',
        'confirm' => 'audio_dicts.replace.bulk.confirm_llm',
        'max_selection' => 200,
        'run' => static function (array $ids): array {
            $reason = t('audio_dicts.replace.bulk.need_progress');
            $skipped = [];
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $skipped[] = ['id' => $id, 'reason' => $reason];
                }
            }

            return ['done' => 0, 'skipped' => $skipped];
        },
    ]);
}

// Soft: only when llm is enabled in config (profile check happens at run time).
if (ModuleManager::isEnabled('llm')) {
    // LLM per row is slow — LongJobPanel (one dict per tick). Sync bulk must not call LLM.
    BulkActions::register('audio_dicts', 'suggest_descr', [
        'label' => 'audio_dicts.bulk.suggest_descr',
        'permission' => 'audio_dicts.edit',
        'confirm' => 'audio_dicts.bulk.suggest_descr_confirm',
        'max_selection' => 80,
        'fields' => [
            [
                'name' => 'overwrite',
                'type' => 'checkbox',
                'label' => 'audio_dicts.bulk.suggest_overwrite',
            ],
        ],
        'run' => static function (array $ids, array $params): array {
            unset($params);
            $reason = t('audio_dicts.bulk.suggest_need_progress');
            $skipped = [];
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $skipped[] = ['id' => $id, 'reason' => $reason];
                }
            }

            return ['done' => 0, 'skipped' => $skipped];
        },
    ]);
}

ModuleManager::registerRoute('/audio/dicts/{id}/suggest-descr', [
    'module' => 'audio_dicts',
    'methods' => ['POST'],
    'permission' => 'audio_dicts.edit',
    'handler' => static function (): void {
        require base_path('modules/audio_dicts/pages/dicts_suggest_descr.php');
    },
]);

ModuleManager::registerRoute('/audio/keys/longjob', [
    'module' => 'audio_dicts',
    'methods' => ['POST'],
    'permission' => 'audio_dicts.edit',
    'handler' => static function (): void {
        require base_path('modules/audio_dicts/pages/keys_longjob.php');
    },
]);

ModuleManager::registerRoute('/audio/keys/replace-options', [
    'module' => 'audio_dicts',
    'methods' => ['GET'],
    'permission' => 'audio_dicts.edit',
    'handler' => static function (): void {
        require base_path('modules/audio_dicts/pages/keys_replace_options.php');
    },
]);

ModuleManager::registerRoute('/audio/dicts/longjob', [
    'module' => 'audio_dicts',
    'methods' => ['POST'],
    'permission' => 'audio_dicts.edit',
    'handler' => static function (): void {
        require base_path('modules/audio_dicts/pages/longjob.php');
    },
]);

ModuleManager::registerRoute('/audio/keys/check-overlap', [
    'module' => 'audio_dicts',
    'methods' => ['GET', 'POST'],
    'permission' => 'audio_dicts.edit',
    'handler' => static function (): void {
        require base_path('modules/audio_dicts/pages/keys_check_overlap.php');
    },
]);

ModuleManager::registerRoute('/audio/scripts', [
    'module' => 'audio_dicts',
    'methods' => ['GET', 'POST'],
    'permission' => 'audio_dicts.view',
    'handler' => static function (): void {
        require base_path('modules/audio_dicts/pages/scripts_list.php');
    },
]);
ModuleManager::registerRoute('/audio/scripts/new', [
    'module' => 'audio_dicts',
    'methods' => ['GET', 'POST'],
    'permission' => 'audio_dicts.edit',
    'handler' => static function (): void {
        require base_path('modules/audio_dicts/pages/scripts_new.php');
    },
]);
ModuleManager::registerRoute('/audio/scripts/import', [
    'module' => 'audio_dicts',
    'methods' => ['POST'],
    'permission' => 'audio_dicts.edit',
    'handler' => static function (): void {
        require base_path('modules/audio_dicts/pages/scripts_import.php');
    },
]);
ModuleManager::registerRoute('/audio/scripts/{id}/parts', [
    'module' => 'audio_dicts',
    'methods' => ['POST'],
    'permission' => 'audio_dicts.edit',
    'handler' => static function (): void {
        require base_path('modules/audio_dicts/pages/scripts_parts.php');
    },
]);
ModuleManager::registerRoute('/audio/scripts/{id}/download', [
    'module' => 'audio_dicts',
    'methods' => ['GET'],
    'permission' => 'audio_dicts.view',
    'handler' => static function (): void {
        require base_path('modules/audio_dicts/pages/scripts_download.php');
    },
]);
ModuleManager::registerRoute('/audio/scripts/{id}/copy', [
    'module' => 'audio_dicts',
    'methods' => ['GET', 'POST'],
    'permission' => 'audio_dicts.edit',
    'handler' => static function (): void {
        require base_path('modules/audio_dicts/pages/scripts_copy.php');
    },
]);
ModuleManager::registerRoute('/audio/scripts/{id}', [
    'module' => 'audio_dicts',
    'methods' => ['GET', 'POST'],
    'permission' => 'audio_dicts.view',
    'handler' => static function (): void {
        require base_path('modules/audio_dicts/pages/scripts_edit.php');
    },
]);

BulkActions::register('audio_scripts', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'audio_dicts.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RecordsScriptsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
BulkActions::register('audio_scripts', 'copy', [
    'label' => 'audio_dicts.scripts.bulk_copy',
    'permission' => 'audio_dicts.edit',
    'run' => static function (array $ids, array $params): array {
        $done = 0;
        $skipped = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id < 1) {
                continue;
            }
            try {
                RecordsScriptsRepository::copy($id);
                $done++;
            } catch (Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return ['done' => $done, 'skipped' => $skipped];
    },
]);
