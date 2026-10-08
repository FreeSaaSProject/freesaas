<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$ctx = WellnessUi::handleEdit(
    static fn (int $id): ?array => FitFoodsRepository::findOwned($id),
    static fn (array $post): array => FitFoodsRepository::dataFromPost($post),
    static fn (array $data): int => FitFoodsRepository::create($data),
    static function (int $id, array $data): void {
        FitFoodsRepository::update($id, $data);
    },
    '/wellness/foods',
    $wantsJson
);
if ($ctx['done']) {
    return;
}

$row = $ctx['row'];
$isNew = $ctx['isNew'];

View::render('foods_edit', [
    'title' => $isNew
        ? t('wellness.foods.edit_new')
        : t('wellness.foods.edit', ['name' => (string)($row['name'] ?? '')]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.foods'), 'href' => '/wellness/foods'],
        ['label' => $isNew ? t('wellness.foods.edit_new') : (string)($row['name'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $ctx['message'],
    'error' => $ctx['error'],
], 'layout', 'wellness');
