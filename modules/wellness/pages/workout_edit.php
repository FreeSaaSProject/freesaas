<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$ctx = WellnessUi::handleEdit(
    static fn (int $id): ?array => FitWorkoutsRepository::findOwned($id),
    static fn (array $post): array => FitWorkoutsRepository::dataFromPost($post),
    static fn (array $data): int => FitWorkoutsRepository::create($data),
    static function (int $id, array $data): void {
        FitWorkoutsRepository::update($id, $data);
    },
    '/wellness/workouts',
    $wantsJson
);
if ($ctx['done']) {
    return;
}

$row = $ctx['row'];
$isNew = $ctx['isNew'];
if ($isNew && empty($row['dt_start'])) {
    $prefDate = trim((string)($_GET['date'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefDate)) {
        $ts = strtotime($prefDate . ' ' . date('H:i:s')) ?: time();
        $row['dt_start'] = $ts;
        $row['dt_end'] = $ts;
    }
}

View::render('workout_edit', [
    'title' => $isNew ? t('wellness.workouts.edit_new') : t('wellness.workouts.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.workouts'), 'href' => '/wellness/workouts'],
        ['label' => $isNew ? t('wellness.workouts.edit_new') : ('#' . $ctx['id']), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $ctx['message'],
    'error' => $ctx['error'],
], 'layout', 'wellness');
