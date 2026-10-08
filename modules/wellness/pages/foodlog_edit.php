<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$ctx = WellnessUi::handleEdit(
    static fn (int $id): ?array => FitFoodlogsRepository::findOwned($id),
    static fn (array $post): array => FitFoodlogsRepository::dataFromPost($post),
    static fn (array $data): int => FitFoodlogsRepository::create($data),
    static function (int $id, array $data): void {
        FitFoodlogsRepository::update($id, $data);
    },
    '/wellness/food-diary',
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
    } else {
        $row['dt'] = time();
    }
}

$foods = FitFoodsRepository::list(['limit' => 500, 'offset' => 0, 'order' => 'NAME ASC']);

View::render('foodlog_edit', [
    'title' => $isNew ? t('core.common.add') : t('wellness.food_diary.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.food_diary'), 'href' => '/wellness/food-diary'],
        ['label' => $isNew ? t('core.common.add') : ('#' . $ctx['id']), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'foods' => $foods['items'],
    'message' => $ctx['message'],
    'error' => $ctx['error'],
], 'layout', 'wellness');
