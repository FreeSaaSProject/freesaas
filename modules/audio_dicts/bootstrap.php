<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/RecordsTrashesRepository.php';
require_once __DIR__ . '/lib/RecordsDictsRepository.php';
require_once __DIR__ . '/lib/RecordsKeysRepository.php';
require_once __DIR__ . '/lib/RecordsTagsRepository.php';
require_once __DIR__ . '/lib/RecordsScriptsRepository.php';
require_once __DIR__ . '/lib/ScriptPack.php';
require_once __DIR__ . '/lib/DictDescrSuggest.php';
require_once __DIR__ . '/lib/AudioDictsAgentOps.php';

AudioDictsAgentOps::register();

Assets::register('audio_dicts', [
    'css' => ['audio_dicts.css'],
    'js' => ['audio_dicts.js'],
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
    BulkActions::register($listId, 'delete', [
        'label' => 'core.bulk.delete',
        'permission' => 'audio_dicts.delete',
        'confirm' => 'core.bulk.confirm_delete',
        'run' => static function (array $ids, array $params) use ($repo): array {
            return ['done' => $repo::deleteByIds($ids), 'skipped' => []];
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
    'run' => static function (array $ids, array $params): array {
        unset($params);

        return ['done' => RecordsKeysRepository::setDisabledByIds($ids, true), 'skipped' => []];
    },
]);

// Soft: only when llm is enabled in config (profile check happens at run time).
if (ModuleManager::isEnabled('llm')) {
    // LLM per row is slow — UI runs progressive overlay (one id JSON call).
    // Sync POST must not call LLM (timeout / Internal Server Error / hung workers).
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
            // Safety net if the progressive JS overlay did not intercept the form.
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
