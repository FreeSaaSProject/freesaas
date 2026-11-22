<?php
declare(strict_types=1);

Auth::requireCapability('SUPERADMIN_ACCESS');

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $action = trim((string)($_POST['action'] ?? 'save'));
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && BotChatsRepository::delete($id)) {
            $message = t('bots.chats.flash.deleted');
        } else {
            $error = t('bots.chats.err.delete');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $enabled = !empty($_POST['enabled']);
        if ($id > 0 && BotChatsRepository::setEnabled($id, $enabled)) {
            $message = t('bots.chats.flash.saved');
        } else {
            $error = t('bots.chats.err.save');
        }
    } else {
        $chatId = trim((string)($_POST['chat_id'] ?? ''));
        $platform = BotChatsRepository::normalizePlatform((string)($_POST['platform'] ?? 'max'));
        $title = trim((string)($_POST['title'] ?? ''));
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $enabled = !empty($_POST['enabled']);
        $id = (int)($_POST['id'] ?? 0);
        if ($chatId === '') {
            $error = t('bots.chats.err.chat_id');
        } else {
            $saved = BotChatsRepository::upsert([
                'platform' => $platform,
                'chat_id' => $chatId,
                'title' => $title,
                'purpose' => $purpose,
                'description' => $description,
                'enabled' => $enabled,
            ]);
            if ($saved > 0) {
                $message = t('bots.chats.flash.saved');
            } else {
                $error = t('bots.chats.err.save');
            }
        }
        unset($id);
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$list = BotChatsRepository::list(['q' => $q, 'limit' => 200]);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $list['items'],
        'total' => $list['total'],
        'q' => $q,
    ]);
}

View::render('chats', [
    'title' => t('bots.chats.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bots.menu.chats'), 'current' => true],
    ]),
    'items' => $list['items'],
    'total' => $list['total'],
    'q' => $q,
    'message' => $message,
    'error' => $error,
], 'layout', 'bots');
