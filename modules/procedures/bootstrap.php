<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ProcedureTypesRepository.php';
require_once __DIR__ . '/lib/ProceduresRepository.php';

$pages = [
    '/procedures' => ['permission' => 'procedures.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/procedures/new' => ['permission' => 'procedures.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/procedures/{id}' => ['permission' => 'procedures.edit', 'methods' => ['GET'], 'file' => 'edit_redirect.php'],
    '/procedures/{id}/edit' => ['permission' => 'procedures.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/procedure-types' => ['permission' => 'procedures.types', 'methods' => ['GET', 'POST'], 'file' => 'types.php'],
    '/procedure-types/new' => ['permission' => 'procedures.types', 'methods' => ['GET', 'POST'], 'file' => 'type_edit.php'],
    '/procedure-types/{id}/edit' => ['permission' => 'procedures.types', 'methods' => ['GET', 'POST'], 'file' => 'type_edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'procedures',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/procedures/pages/' . $file);
        },
    ]);
}

BulkActions::register('procedures', 'delete', [
    'label' => 'core.bulk.delete',
    'capability' => 'SUPERADMIN_ACCESS',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => ProceduresRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('procedures', 'set_admin', [
    'label' => 'procedures.bulk.set_admin',
    'capability' => 'SUPERADMIN_ACCESS',
    'fields' => [
        [
            'name' => 'admin_id',
            'type' => 'select',
            'label' => 'procedures.bulk.admin',
            'options' => static function (): array {
                $out = [];
                foreach (UsersRepository::listForCopy() as $u) {
                    $label = trim((string)$u['name']);
                    if ($label === '') {
                        $label = (string)$u['login'];
                    }
                    $out[(int)$u['id']] = $label;
                }
                return $out;
            },
        ],
    ],
    'run' => static function (array $ids, array $params): array {
        $adminId = (int)($params['admin_id'] ?? 0);
        if ($adminId < 1) {
            $skipped = [];
            foreach ($ids as $id) {
                $skipped[] = ['id' => (int)$id, 'reason' => t('procedures.bulk.err.admin_required')];
            }
            return ['done' => 0, 'skipped' => $skipped];
        }
        return ['done' => ProceduresRepository::setAdminByIds($ids, $adminId), 'skipped' => []];
    },
]);

BulkActions::register('procedure_types', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'procedures.types',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => ProcedureTypesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('core.bulk.check_item', static function (array $payload): array {
    $listId = (string)($payload['list_id'] ?? '');
    $action = (string)($payload['action'] ?? '');
    if (!empty($payload['blocked'])) {
        return $payload;
    }
    $id = (int)($payload['id'] ?? 0);

    if ($listId === 'procedure_types' && $action === 'delete') {
        $n = ProceduresRepository::countByTypeId($id);
        if ($n > 0) {
            $payload['blocked'] = true;
            $payload['reason'] = t('procedures.bulk.type_in_use', ['n' => $n]);
        }
        return $payload;
    }

    if ($listId === 'clients' && $action === 'delete') {
        $n = ProceduresRepository::countByClientId($id);
        if ($n > 0) {
            $payload['blocked'] = true;
            $payload['reason'] = t('procedures.bulk.client_in_use', ['n' => $n]);
        }
    }
    return $payload;
});
