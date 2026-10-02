<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ClientsRepository.php';
require_once __DIR__ . '/lib/ClientStatusesRepository.php';
require_once __DIR__ . '/lib/ClientTagsRepository.php';

$pages = [
    '/clients' => ['permission' => 'clients.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/clients/new' => ['permission' => 'clients.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/clients/{id}' => ['permission' => 'clients.view', 'methods' => ['GET'], 'file' => 'view.php'],
    '/clients/{id}/edit' => ['permission' => 'clients.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/client-statuses' => ['permission' => 'clients.statuses', 'methods' => ['GET'], 'file' => 'statuses.php'],
    '/client-statuses/new' => ['permission' => 'clients.statuses', 'methods' => ['GET', 'POST'], 'file' => 'status_edit.php'],
    '/client-statuses/{id}/edit' => ['permission' => 'clients.statuses', 'methods' => ['GET', 'POST'], 'file' => 'status_edit.php'],
    '/client-tags' => ['permission' => 'clients.tags', 'methods' => ['GET', 'POST'], 'file' => 'tags.php'],
    '/client-tags/new' => ['permission' => 'clients.tags', 'methods' => ['GET', 'POST'], 'file' => 'tag_edit.php'],
    '/client-tags/{id}/edit' => ['permission' => 'clients.tags', 'methods' => ['GET', 'POST'], 'file' => 'tag_edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'clients',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/clients/pages/' . $file);
        },
    ]);
}

BulkActions::register('clients', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'clients.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => ClientsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
BulkActions::register('clients', 'enable', [
    'label' => 'core.bulk.enable',
    'permission' => 'clients.edit',
    'run' => static function (array $ids, array $params): array {
        return ['done' => ClientsRepository::setEnabledByIds($ids, true), 'skipped' => []];
    },
]);
BulkActions::register('clients', 'disable', [
    'label' => 'core.bulk.disable',
    'permission' => 'clients.edit',
    'run' => static function (array $ids, array $params): array {
        return ['done' => ClientsRepository::setEnabledByIds($ids, false), 'skipped' => []];
    },
]);
BulkActions::register('clients', 'assign_tag', [
    'label' => 'clients.bulk.assign_tag',
    'permission' => 'clients.edit',
    'fields' => [
        [
            'name' => 'tag_id',
            'type' => 'select',
            'label' => 'clients.bulk.tag',
            'options' => static function (): array {
                return ClientTagsRepository::names(true);
            },
        ],
    ],
    'run' => static function (array $ids, array $params): array {
        $tagId = (int)($params['tag_id'] ?? 0);
        if ($tagId < 1) {
            $skipped = [];
            foreach ($ids as $id) {
                $skipped[] = ['id' => (int)$id, 'reason' => t('clients.bulk.err.tag_required')];
            }
            return ['done' => 0, 'skipped' => $skipped];
        }
        return ['done' => ClientsRepository::addTagToClients($ids, $tagId), 'skipped' => []];
    },
]);
BulkActions::register('clients', 'unassign_tag', [
    'label' => 'clients.bulk.unassign_tag',
    'permission' => 'clients.edit',
    'fields' => [
        [
            'name' => 'tag_id',
            'type' => 'select',
            'label' => 'clients.bulk.tag',
            'options' => static function (): array {
                return ClientTagsRepository::names(false);
            },
        ],
    ],
    'run' => static function (array $ids, array $params): array {
        $tagId = (int)($params['tag_id'] ?? 0);
        if ($tagId < 1) {
            $skipped = [];
            foreach ($ids as $id) {
                $skipped[] = ['id' => (int)$id, 'reason' => t('clients.bulk.err.tag_required')];
            }
            return ['done' => 0, 'skipped' => $skipped];
        }
        return ['done' => ClientsRepository::removeTagFromClients($ids, $tagId), 'skipped' => []];
    },
]);

BulkActions::register('client_tags', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'clients.tags',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => ClientTagsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('core.dashboard.catalog', static function ($catalog) {
    if (!is_array($catalog)) {
        $catalog = [];
    }
    if (!Database::tableExists('base_clients')) {
        return $catalog;
    }
    $catalog[] = [
        'id' => 'clients.count',
        'title' => t('clients.widget.title'),
        'module' => 'clients',
        'default_order' => 80,
        'default_on' => false,
    ];
    return $catalog;
});

EventBus::on('core.dashboard.widgets', static function ($widgets) {
    if (!is_array($widgets)) {
        $widgets = [];
    }
    if (!Database::tableExists('base_clients') || !DashboardWidgets::isEnabled('clients.count')) {
        return $widgets;
    }
    $active = 0;
    try {
        $active = (int)Database::pdo()->query(
            'SELECT COUNT(*) FROM base_clients WHERE enabled = 1'
        )->fetchColumn();
    } catch (Throwable $e) {
        $active = 0;
    }
    $widgets[] = [
        'id' => 'clients.count',
        'title' => t('clients.widget.title'),
        'body' => t('clients.widget.body', ['n' => $active]),
        'tone' => 'ok',
        'href' => '/clients',
        'size' => 'sm',
    ];
    return $widgets;
});
