<?php
declare(strict_types=1);

$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'toggle') {
            NotifyRulesRepository::setEnabled($id, !empty($_POST['enabled']));
            $message = t('core.common.saved');
        } elseif ($action === 'delete') {
            if (!NotifyRulesRepository::delete($id)) {
                throw new InvalidArgumentException(t('notify.err.rule_not_found'));
            }
            $message = t('notify.rules.deleted');
        } elseif ($action === 'apply_preset') {
            $pack = trim((string)($_POST['pack'] ?? ''));
            $opts = [
                'chat_id' => trim((string)($_POST['chat_id'] ?? '')),
                'platform' => trim((string)($_POST['platform'] ?? '')),
                'staff_ids' => trim((string)($_POST['staff_ids'] ?? '')),
                'bot_chat_id' => (int)($_POST['bot_chat_id'] ?? 0),
                'enabled' => !empty($_POST['enabled']),
            ];
            if ($pack === 'all_satisfied') {
                $r = NotifyPresets::applySatisfied($opts);
                if (!$r['ok']) {
                    throw new InvalidArgumentException($r['error'] !== '' ? $r['error'] : t('notify.presets.err.unknown'));
                }
                $created = 0;
                $skipped = 0;
                foreach ($r['results'] as $one) {
                    $created += (int)($one['created'] ?? 0);
                    $skipped += (int)($one['skipped'] ?? 0);
                }
                $message = t('notify.presets.applied', ['created' => (string)$created, 'skipped' => (string)$skipped]);
            } else {
                $r = NotifyPresets::apply($pack, $opts);
                if (!$r['ok']) {
                    throw new InvalidArgumentException($r['error'] !== '' ? $r['error'] : t('notify.presets.err.unknown'));
                }
                $message = t('notify.presets.applied', [
                    'created' => (string)$r['created'],
                    'skipped' => (string)$r['skipped'],
                ]);
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$rules = NotifyRulesRepository::listAll();
$presets = NotifyPresets::catalog();
$chats = [];
if (class_exists('BotChatsRepository', false) && BotChatsRepository::tableReady()) {
    $chats = BotChatsRepository::list(['enabled' => 1, 'limit' => 100])['items'];
}

if (AgentHttp::wantsJson()) {
    if ($error !== '') {
        AgentHttp::error($error, 400);
    }
    AgentHttp::emit([
        'ok' => true,
        'message' => $message,
        'items' => $rules,
        'total' => count($rules),
        'presets' => $presets,
    ]);
}

View::render('rules', [
    'title' => t('notify.rules.title'),
    'message' => $message,
    'error' => $error,
    'rules' => $rules,
    'events' => NotifyCatalog::labels(),
    'presets' => $presets,
    'chats' => $chats,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('notify.menu.rules'), 'current' => true],
    ]),
], 'layout', 'notify');
