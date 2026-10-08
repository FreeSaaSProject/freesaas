<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$ctx = WellnessUi::handleEdit(
    static fn (int $id): ?array => HealthDoctorRxRepository::findOwned($id),
    static fn (array $post): array => HealthDoctorRxRepository::dataFromPost($post),
    static fn (array $data): int => HealthDoctorRxRepository::create($data),
    static function (int $id, array $data): void {
        HealthDoctorRxRepository::update($id, $data);
    },
    '/wellness/doctor-rx',
    $wantsJson
);
if ($ctx['done']) {
    return;
}

$row = $ctx['row'];
$isNew = $ctx['isNew'];

View::render('doctor_rx_edit', [
    'title' => $isNew ? t('wellness.doctor_rx.edit_new') : t('wellness.doctor_rx.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('wellness.menu.group'), 'href' => '/wellness'],
        ['label' => t('wellness.menu.doctor_rx'), 'href' => '/wellness/doctor-rx'],
        ['label' => $isNew ? t('wellness.doctor_rx.edit_new') : ('#' . $ctx['id']), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $ctx['message'],
    'error' => $ctx['error'],
], 'layout', 'wellness');
