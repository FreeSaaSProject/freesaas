<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$ctx = WellnessUi::handleEdit(
    static fn (int $id): ?array => FitWeightlogsRepository::findOwned($id),
    static fn (array $post): array => FitWeightlogsRepository::dataFromPost($post),
    static fn (array $data): int => FitWeightlogsRepository::create($data),
    static function (int $id, array $data): void {
        FitWeightlogsRepository::update($id, $data);
    },
    '/wellness/weight',
    $wantsJson
);
if ($ctx['done']) {
    return;
}

$row = $ctx['row'];
$isNew = $ctx['isNew'];

View::render('weight_edit', [
    'title' => $isNew ? t('wellness.weight.edit_new') : t('wellness.weight.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.weight'), 'href' => '/wellness/weight'],
        ['label' => $isNew ? t('wellness.weight.edit_new') : ('#' . $ctx['id']), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $ctx['message'],
    'error' => $ctx['error'],
], 'layout', 'wellness');
