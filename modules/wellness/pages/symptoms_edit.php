<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$ctx = WellnessUi::handleEdit(
    static fn (int $id): ?array => HealthSymptomsRepository::findOwned($id),
    static fn (array $post): array => HealthSymptomsRepository::dataFromPost($post),
    static fn (array $data): int => HealthSymptomsRepository::create($data),
    static function (int $id, array $data): void {
        HealthSymptomsRepository::update($id, $data);
    },
    '/wellness/symptoms',
    $wantsJson
);
if ($ctx['done']) {
    return;
}

$row = $ctx['row'];
$isNew = $ctx['isNew'];

View::render('catalog_edit', [
    'title' => $isNew ? t('wellness.symptoms.edit_new') : t('wellness.symptoms.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.symptoms'), 'href' => '/wellness/symptoms'],
        ['label' => $isNew ? t('wellness.symptoms.edit_new') : (string)($row['name'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $ctx['message'],
    'error' => $ctx['error'],
    'listPath' => '/wellness/symptoms',
    'headingNew' => t('wellness.symptoms.edit_new'),
], 'layout', 'wellness');
