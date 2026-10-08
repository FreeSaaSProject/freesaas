<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$ctx = WellnessUi::handleEdit(
    static fn (int $id): ?array => HealthMedicinesRepository::findOwned($id),
    static fn (array $post): array => HealthMedicinesRepository::dataFromPost($post),
    static fn (array $data): int => HealthMedicinesRepository::create($data),
    static function (int $id, array $data): void {
        HealthMedicinesRepository::update($id, $data);
    },
    '/wellness/medicines',
    $wantsJson
);
if ($ctx['done']) {
    return;
}

$row = $ctx['row'];
$isNew = $ctx['isNew'];

View::render('catalog_edit', [
    'title' => $isNew ? t('wellness.medicines.edit_new') : t('wellness.medicines.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.medicines'), 'href' => '/wellness/medicines'],
        ['label' => $isNew ? t('wellness.medicines.edit_new') : (string)($row['name'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $ctx['message'],
    'error' => $ctx['error'],
    'listPath' => '/wellness/medicines',
    'headingNew' => t('wellness.medicines.edit_new'),
], 'layout', 'wellness');
