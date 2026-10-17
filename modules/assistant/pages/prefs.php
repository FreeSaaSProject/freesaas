<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$userId = Auth::userId();
if ($userId < 1) {
    if ($wantsJson) {
        AssistantAccess::denyJson(401);
    }
    redirect('/login');
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $fabOff = !empty($_POST['fab_off']);
        AssistantPrefs::setFabOff($userId, $fabOff);
        $unhide = $_POST['unhide'] ?? [];
        if (is_array($unhide)) {
            $paths = [];
            foreach ($unhide as $p) {
                $paths[] = (string)$p;
            }
            AssistantPrefs::unhidePaths($userId, $paths);
        }
        $message = t('core.common.saved');
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
                'fab_off' => AssistantPrefs::isFabOff($userId),
                'hidden_paths' => AssistantPrefs::listHiddenPaths($userId),
            ]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$fabOff = AssistantPrefs::isFabOff($userId);
$hidden = AssistantPrefs::listHiddenPaths($userId);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'fab_off' => $fabOff,
        'hidden_paths' => $hidden,
    ]);
    return;
}

View::render('prefs', [
    'title' => t('assistant.prefs.title'),
    'message' => $message,
    'error' => $error,
    'fab_off' => $fabOff,
    'hidden_paths' => $hidden,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => AssistantConfig::displayName(), 'href' => '/assistant'],
        ['label' => t('assistant.prefs.title'), 'current' => true],
    ]),
], 'layout', 'assistant');
