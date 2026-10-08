<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$ctx = WellnessUi::handleEdit(
    static fn (int $id): ?array => HealthLogsRepository::findOwned($id),
    static fn (array $post): array => HealthLogsRepository::dataFromPost($post),
    static fn (array $data): int => HealthLogsRepository::create($data),
    static function (int $id, array $data): void {
        HealthLogsRepository::update($id, $data);
    },
    '/wellness/health-diary',
    $wantsJson
);
if ($ctx['done']) {
    return;
}

$row = $ctx['row'];
$isNew = $ctx['isNew'];
if ($isNew && empty($row['dt'])) {
    $prefDate = trim((string)($_GET['date'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefDate)) {
        $row['dt'] = strtotime($prefDate . ' ' . date('H:i:s')) ?: time();
    }
}

$symptoms = HealthSymptomsRepository::list(['limit' => 500, 'offset' => 0]);
$medicines = HealthMedicinesRepository::list(['limit' => 500, 'offset' => 0]);

View::render('health_log_edit', [
    'title' => $isNew ? t('wellness.health_diary.edit_new') : t('wellness.health_diary.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.health_diary'), 'href' => '/wellness/health-diary'],
        ['label' => $isNew ? t('wellness.health_diary.edit_new') : ('#' . $ctx['id']), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'symptoms' => $symptoms['items'],
    'medicines' => $medicines['items'],
    'message' => $ctx['message'],
    'error' => $ctx['error'],
], 'layout', 'wellness');
