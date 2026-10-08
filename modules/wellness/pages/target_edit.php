<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$ctx = WellnessUi::handleEdit(
    static fn (int $id): ?array => FitTargetsRepository::findOwned($id),
    static fn (array $post): array => FitTargetsRepository::dataFromPost($post),
    static fn (array $data): int => FitTargetsRepository::create($data),
    static function (int $id, array $data): void {
        FitTargetsRepository::update($id, $data);
    },
    '/wellness/targets',
    $wantsJson
);
if ($ctx['done']) {
    return;
}

$row = $ctx['row'];
$isNew = $ctx['isNew'];

View::render('target_edit', [
    'title' => $isNew ? t('wellness.targets.edit_new') : t('wellness.targets.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.targets'), 'href' => '/wellness/targets'],
        ['label' => $isNew ? t('wellness.targets.edit_new') : (string)($row['name'] ?? '#' . $ctx['id']), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $ctx['message'],
    'error' => $ctx['error'],
], 'layout', 'wellness');
