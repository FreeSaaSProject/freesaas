<?php
declare(strict_types=1);

$flash = '';
$canEdit = Auth::can('audio.badges');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'sync') {
        $n = BadgesRepository::syncFromRecords();
        $flash = t('audio.badges.synced', ['n' => $n]);
    } elseif ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $enabled = !empty($_POST['enabled']);
        if ($id > 0 && $name !== '') {
            BadgesRepository::updateMeta($id, $name, $enabled);
            $flash = t('audio.badges.saved');
        }
    }
}

$items = BadgesRepository::listAll(false);

View::render('badges', [
    'title' => t('audio.badges.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.list'), 'href' => '/audio'],
        ['label' => t('audio.badges.title'), 'current' => true],
    ]),
    'items' => $items,
    'canEdit' => $canEdit,
    'flash' => $flash,
], 'layout', 'audio');
