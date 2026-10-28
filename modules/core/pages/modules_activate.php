<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/ModulesActivate.php');
require_once base_path('modules/core/lib/StatusesRepository.php');
require_once base_path('modules/core/lib/UsersRepository.php');

$moduleId = trim((string)Router::param('id'));
$meta = ModuleManager::get($moduleId);
if ($meta === null || $moduleId === 'core') {
    http_response_code(404);
    echo t('core.modules.err.no_module');
    return;
}

$actorId = (int)Auth::userId();
$error = '';
$flash = trim((string)($_GET['msg'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['activate_action'] ?? ''));
    $opts = [
        'actor_user_id' => $actorId,
        'accept_risk' => !empty($_POST['accept_risk']),
        'access' => (string)($_POST['access'] ?? 'full'),
    ];
    if ($action === 'grant' || $action === 'grant_skip') {
        $statusIds = [];
        if ($action === 'grant' && isset($_POST['status_ids']) && is_array($_POST['status_ids'])) {
            foreach ($_POST['status_ids'] as $sid) {
                $statusIds[] = (int)$sid;
            }
        }
        $userIds = [];
        if ($action === 'grant') {
            $rawUsers = trim((string)($_POST['user_ids'] ?? ''));
            if ($rawUsers !== '') {
                foreach (preg_split('/[\s,;]+/', $rawUsers) ?: [] as $p) {
                    $uid = (int)$p;
                    if ($uid > 0) {
                        $userIds[] = $uid;
                    }
                }
            }
        }
        $opts['status_ids'] = $statusIds;
        $opts['user_ids'] = $userIds;
        if ($action === 'grant_skip') {
            $opts['status_ids'] = [];
            $opts['user_ids'] = [];
        }
        $action = 'grant';
    }
    if ($action === 'setup_step') {
        $opts['step_id'] = (string)($_POST['step_id'] ?? '');
        $opts['choice'] = (string)($_POST['choice'] ?? 'defer');
    }
    try {
        $result = ModulesActivate::apply($moduleId, $action, $opts);
        if (empty($result['ok'])) {
            $error = (string)($result['error'] ?? t('core.modules.err.unknown_action'));
            if (!empty($result['accept_risk_required'])) {
                $error = t('core.modules.err.risk_required', [
                    'stage' => t('core.modules.maturity.' . (string)($result['maturity'] ?? 'beta')),
                ]);
            }
        } elseif ($action === 'leave_disabled' || $action === 'skip_enable') {
            redirect('/modules?msg=' . rawurlencode(t('core.modules.msg.disabled', ['module' => $moduleId])));
        } elseif ($action === 'finish' || ($action === 'setup_defer_all')
            || ($action === 'setup_step' && (int)($result['setup_pending'] ?? 1) === 0)
            || ($action === 'grant' && (int)($result['setup_pending'] ?? 0) === 0
                && ModulesActivate::declaredSetupSteps($moduleId) === [])
        ) {
            redirect('/modules/' . rawurlencode($moduleId) . '?msg=' . rawurlencode(
                t('core.modules.msg.enabled', ['module' => $moduleId])
            ));
        } else {
            // Stay on wizard with updated snapshot
            $flash = t('core.modules.msg.enabled', ['module' => $moduleId]);
            if ($action === 'grant') {
                $flash = t('core.modules.activate.grant_continue');
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$snap = ModulesActivate::snapshot($moduleId, $actorId);
$phase = (string)($snap['phase'] ?? 'enable');
// After enable in same request, force grant phase once
if ($flash !== '' && !empty($snap['enabled']) && $phase === 'setup'
    && ($_POST['activate_action'] ?? '') === 'enable'
) {
    $phase = 'grant';
}
if (!empty($snap['enabled']) && ($_POST['activate_action'] ?? '') === 'enable') {
    $phase = 'grant';
}

$name = (string)($snap['name'] ?? $moduleId);
$title = t('core.modules.activate.title', ['module' => $name !== '' ? $name : $moduleId]);

View::render('modules_activate', [
    'title' => $title,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.modules'), 'href' => '/modules'],
        ['label' => $name !== '' ? $name : $moduleId, 'href' => '/modules/' . rawurlencode($moduleId)],
        ['label' => t('core.modules.btn_activate'), 'current' => true],
    ]),
    'moduleId' => $moduleId,
    'snap' => $snap,
    'phase' => $phase,
    'error' => $error,
    'flash' => $flash,
    'staff' => UsersRepository::listEnabledStaff(),
], 'layout', 'core');
