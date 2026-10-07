<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$flash = '';
$canEdit = Auth::can('audio.badges');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'sync') {
        $n = BadgesRepository::syncFromRecords();
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'action' => 'sync', 'synced' => $n, 'items' => BadgesRepository::listAll(false)]);
            return;
        }
        $flash = t('audio.badges.synced', ['n' => $n]);
    } elseif ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $enabled = !empty($_POST['enabled']);
        if ($id > 0 && $name !== '') {
            BadgesRepository::updateMeta($id, $name, $enabled);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'action' => 'save', 'id' => $id, 'items' => BadgesRepository::listAll(false)]);
                return;
            }
            $flash = t('audio.badges.saved');
        } elseif ($wantsJson) {
            AgentHttp::error(t('audio.api.unknown_action'), 400);
            return;
        }
    } elseif ($wantsJson) {
        AgentHttp::error(t('audio.api.unknown_action'), 400);
        return;
    }
}

$items = BadgesRepository::listAll(false);

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'items' => $items, 'can_edit' => $canEdit]);
    return;
}

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
