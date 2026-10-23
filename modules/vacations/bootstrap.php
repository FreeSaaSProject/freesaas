<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/VacationsRepository.php';
require_once __DIR__ . '/lib/VacationsBiContrib.php';

EventBus::on('bi.datasets', [VacationsBiContrib::class, 'onDatasets']);

EventBus::on('assistant.write.preview', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $opId = (string)($payload['op_id'] ?? '');
    if ($opId !== 'vacations.update' && $opId !== 'vacations.create') {
        return $payload;
    }
    $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
    $row = $opId === 'vacations.update' ? VacationsRepository::find((int)($args['id'] ?? 0)) : null;
    $fmt = static function ($raw): string {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return '';
        }
        $ts = ctype_digit($raw) ? (int)$raw : strtotime($raw);
        return $ts ? date('d.m.Y', $ts) : $raw;
    };
    $userId = (int)($args['user_id'] ?? ($row['user_id'] ?? 0));
    $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
    if ($userId > 0) {
        $lines[] = t('vacations.assistant.preview.user', ['v' => UsersRepository::nameById($userId)]);
    }
    $newStart = $fmt($args['dt_start'] ?? ($row['dt_start'] ?? ''));
    $newEnd = $fmt($args['dt_end'] ?? ($row['dt_end'] ?? ''));
    if ($row !== null) {
        $lines[] = t('vacations.assistant.preview.was', [
            'v' => $fmt($row['dt_start']) . ' – ' . $fmt($row['dt_end']),
        ]);
    }
    if ($newStart !== '' || $newEnd !== '') {
        $lines[] = t('vacations.assistant.preview.will', ['v' => $newStart . ' – ' . $newEnd]);
    }
    $payload['lines'] = $lines;
    $payload['handled'] = true;
    return $payload;
});

$pages = [
    '/vacations' => ['permission' => 'vacations.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/vacations/new' => ['permission' => 'vacations.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/vacations/{id}/edit' => ['permission' => 'vacations.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'vacations',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/vacations/pages/' . $file);
        },
    ]);
}

BulkActions::register('vacations', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'vacations.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => VacationsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('vacations', 'sign', [
    'label' => 'vacations.bulk.sign',
    'permission' => 'vacations.edit',
    'run' => static function (array $ids, array $params): array {
        return ['done' => VacationsRepository::signByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check()) {
        return $notices;
    }
    foreach (VacationsRepository::noticesForUser(Auth::userId()) as $n) {
        $notices[] = $n;
    }
    return $notices;
});
