<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CabinetsRepository.php';

$pages = [
    '/cabinets' => ['permission' => 'cabinets.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/cabinets/new' => ['permission' => 'cabinets.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/cabinets/{id}/edit' => ['permission' => 'cabinets.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'cabinets',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/cabinets/pages/' . $file);
        },
    ]);
}

BulkActions::register('cabinets', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'cabinets.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => CabinetsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('core.user_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    $cabinets = [];
    $listed = CabinetsRepository::list(['limit' => 500]);
    foreach ($listed['items'] as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $label = trim((string)($r['name'] ?? ''));
        $descr = trim((string)($r['descr'] ?? ''));
        if ($descr !== '') {
            $label .= ' — ' . $descr;
        }
        $cabinets[$id] = $label !== '' ? $label : ('#' . $id);
    }
    $skinRaw = $user['skin'] ?? '';
    if (is_array($skinRaw)) {
        $skinRaw = UsersRepository::encodeHashIds(array_map('intval', $skinRaw));
    }
    $cabSet = [];
    foreach (UsersRepository::parseHashIds((string)$skinRaw) as $cid) {
        $cabSet[(int)$cid] = true;
    }
    ob_start();
    require __DIR__ . '/views/_user_edit_access.php';
    $payload['html_access'] = ($payload['html_access'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.user_edit.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    $data['skin'] = UsersRepository::encodeHashIds(array_map('intval', (array)($post['skin'] ?? [])));
    $skinIds = UsersRepository::parseHashIds((string)$data['skin']);
    if (count($skinIds) === 1) {
        $data['skin'] = (string)$skinIds[0];
    }
    $payload['data'] = $data;
    return $payload;
});

EventBus::on('core.user_edit.profile', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    ob_start();
    require __DIR__ . '/views/_user_profile_site.php';
    $payload['html_site'] = ($payload['html_site'] ?? '') . (string)ob_get_clean();
    return $payload;
});
