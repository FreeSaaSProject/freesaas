<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (!Auth::capability('SUPERADMIN_ACCESS')) {
    if ($wantsJson) {
        AgentHttp::error(t('vacations.err.superadmin'), 403);
        return;
    }
    http_response_code(403);
    echo t('vacations.err.superadmin');
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (class_exists('Csrf', false) && !Csrf::verify()) {
            Csrf::deny('csrf');
            return;
        }
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'test_remind') {
            VacationsConfig::saveFromPost($_POST);
            $r = VacationsGroupNotify::sendTest();
            if (!empty($r['ok'])) {
                $message = t('vacations.flash.remind_test_ok', ['id' => (string)($r['queued_id'] ?? 0)]);
            } else {
                $error = (string)($r['error'] ?? t('vacations.remind.err.enqueue'));
            }
            if ($wantsJson) {
                AgentHttp::emit(array_merge(['message' => $message, 'error' => $error], $r));
                return;
            }
        } else {
            VacationsConfig::saveFromPost($_POST);
            $message = t('vacations.flash.settings_saved');
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'saved' => true,
                    'settings' => VacationsConfig::formValues(),
                    'message' => $message,
                ]);
                return;
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

if ($wantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    AgentHttp::emit([
        'ok' => true,
        'settings' => VacationsConfig::formValues(),
        'bots_enabled' => ModuleManager::isEnabled('bots'),
        'known_chats' => VacationsConfig::knownChatOptions(),
    ]);
    return;
}

View::render('settings', [
    'title' => t('vacations.settings.title'),
    'message' => $message,
    'error' => $error,
    'cfg' => VacationsConfig::formValues(),
    'bots_enabled' => ModuleManager::isEnabled('bots'),
    'known_chats' => VacationsConfig::knownChatOptions(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('vacations.menu.list'), 'href' => '/vacations'],
        ['label' => t('vacations.menu.settings'), 'current' => true],
    ]),
], 'layout', 'vacations');
