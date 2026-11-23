<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/MediaConfig.php';
require_once __DIR__ . '/lib/MediaImage.php';
require_once __DIR__ . '/lib/MediaStore.php';
require_once __DIR__ . '/lib/MediaAttachmentsRepository.php';
require_once __DIR__ . '/lib/MediaRequirementsRepository.php';
require_once __DIR__ . '/lib/MediaAcl.php';
require_once __DIR__ . '/lib/MediaGate.php';
require_once __DIR__ . '/lib/MediaUpload.php';

Assets::register('media', [
    'css' => ['media.css'],
]);

BulkActions::register('media_attachments', 'delete', [
    'label' => 'media.bulk.delete',
    'permission' => 'media.manage',
    'capability' => 'SUPERADMIN_ACCESS',
    'confirm' => 'media.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        unset($params);
        return ['done' => MediaAttachmentsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

$super = [
    'module' => 'media',
    'permission' => 'media.manage',
    'capability' => 'SUPERADMIN_ACCESS',
];

ModuleManager::registerRoute('/media', array_merge($super, [
    'methods' => ['GET', 'POST'],
    'handler' => static function (): void {
        require base_path('modules/media/pages/list.php');
    },
]));

ModuleManager::registerRoute('/media/settings', array_merge($super, [
    'methods' => ['GET', 'POST'],
    'handler' => static function (): void {
        require base_path('modules/media/pages/settings.php');
    },
]));

// Scope editors (procedure types / catalog) may POST; page enforces ACL.
// Auth required (no permission key → any logged-in staff); ACL inside page.
ModuleManager::registerRoute('/media/requirements', [
    'module' => 'media',
    'methods' => ['GET', 'POST'],
    'handler' => static function (): void {
        require base_path('modules/media/pages/requirements.php');
    },
]);

ModuleManager::registerRoute('/media/upload', [
    'module' => 'media',
    'methods' => ['POST'],
    'handler' => static function (): void {
        require base_path('modules/media/pages/upload.php');
    },
]);

ModuleManager::registerRoute('/media/file/{id}', [
    'module' => 'media',
    'methods' => ['GET'],
    'handler' => static function (): void {
        require base_path('modules/media/pages/file.php');
    },
]);

ModuleManager::registerRoute('/media/thumb/{id}', [
    'module' => 'media',
    'methods' => ['GET'],
    'handler' => static function (): void {
        require base_path('modules/media/pages/thumb.php');
    },
]);

EventBus::on('media.owner.acl', static function (array $payload): array {
    $table = (string)($payload['owner_table'] ?? '');
    $id = (int)($payload['owner_id'] ?? 0);
    if ($table !== 'base_procedures' || $id < 1) {
        return $payload;
    }
    if (!class_exists('ProceduresRepository', false)
        && is_file(base_path('modules/procedures/lib/ProceduresRepository.php'))
    ) {
        require_once base_path('modules/procedures/lib/ProceduresRepository.php');
    }
    if (!class_exists('ProceduresRepository', false)) {
        return $payload;
    }
    $row = ProceduresRepository::find($id);
    if ($row === null) {
        $payload['allowed'] = false;
        return $payload;
    }
    $payload['allowed'] = ProceduresRepository::canViewRow($row);
    return $payload;
});

EventBus::on('procedures.edit.sections', static function (array $payload): array {
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
    $id = (int)($payload['id'] ?? $row['id'] ?? 0);
    if ($id < 1) {
        return $payload;
    }
    Assets::need('media');
    $attachments = MediaAttachmentsRepository::listForOwner('base_procedures', $id);
    $unmet = MediaGate::unmetForProcedure($row);
    ob_start();
    $attachments = $attachments;
    $unmet = $unmet;
    require base_path('modules/media/views/_panel.php');
    $html = (string)ob_get_clean();
    $payload['html'] = (string)($payload['html'] ?? '') . $html;
    return $payload;
});

EventBus::on('procedures.type_edit.sections', static function (array $payload): array {
    $id = (int)($payload['id'] ?? 0);
    $isNew = !empty($payload['is_new']);
    if ($isNew || $id < 1) {
        return $payload;
    }
    if (!Auth::capability('SUPERADMIN_ACCESS') && !Auth::can('procedures.types')) {
        return $payload;
    }
    // Requirements CRUD is director-oriented; types editors with procedures.types may set them.
    Assets::need('media');
    $items = MediaRequirementsRepository::listForScope('base_procedure_types', $id, false);
    $scope_table = 'base_procedure_types';
    $scope_id = $id;
    $ret = '/procedure-types/' . $id . '/edit';
    ob_start();
    echo '<fieldset><legend>' . te('media.req.title') . '</legend>';
    require base_path('modules/media/views/_requirements_form.php');
    echo '</fieldset>';
    $html = (string)ob_get_clean();
    $payload['html'] = (string)($payload['html'] ?? '') . $html;
    return $payload;
});

EventBus::on('catalog.product_edit.sections', static function (array $payload): array {
    $id = (int)($payload['product_id'] ?? 0);
    if ($id < 1 || !empty($payload['is_new'])) {
        return $payload;
    }
    if (!Auth::capability('SUPERADMIN_ACCESS') && !Auth::can('catalog.edit')) {
        return $payload;
    }
    Assets::need('media');
    $items = MediaRequirementsRepository::listForScope('base_products', $id, false);
    $scope_table = 'base_products';
    $scope_id = $id;
    $ret = '/products/' . $id . '/edit';
    ob_start();
    echo '<fieldset><legend>' . te('media.req.title') . '</legend>';
    require base_path('modules/media/views/_requirements_form.php');
    echo '</fieldset>';
    $html = (string)ob_get_clean();
    $payload['html'] = (string)($payload['html'] ?? '') . $html;
    return $payload;
});
