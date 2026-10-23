<?php
declare(strict_types=1);

/**
 * Agent/JSON skill diagnose + search + grant/revoke (rates SoT).
 * GET  /rates/skill?operator_id=&type_id=|&good_id=   diagnose
 * GET  /rates/skill?q=средиземноморский               search types/products
 * POST /rates/skill  action=grant|revoke (default grant); dry_run=1 for draft text
 */
$wantsJson = AgentHttp::wantsJson();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$operatorId = (int)($_GET['operator_id'] ?? $_POST['operator_id'] ?? 0);
$typeId = (int)($_GET['type_id'] ?? $_POST['type_id'] ?? 0);
$goodId = (int)($_GET['good_id'] ?? $_POST['good_id'] ?? $_GET['product_id'] ?? $_POST['product_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));

if ($method === 'GET') {
    if ($q !== '' && $operatorId < 1 && $typeId < 1 && $goodId < 1) {
        $report = RatesSkill::search($q);
        if ($wantsJson) {
            AgentHttp::emit($report);
            return;
        }
        http_response_code(400);
        View::render('error', [
            'title' => t('rates.skill.search_title'),
            'message' => t('rates.module.summary'),
        ]);
        return;
    }
    $date = trim((string)($_GET['date'] ?? ''));
    $dayStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? (int)strtotime($date . ' 00:00:00') : 0;
    $report = RatesSkill::diagnose($operatorId, $typeId, $goodId, $dayStart);
    if ($wantsJson) {
        // Envelope ok = request succeeded; the skill verdict lives in can_perform*.
        $verdict = !empty($report['ok']);
        $verdictOnline = !empty($report['ok_online']);
        unset($report['ok'], $report['ok_online']);
        AgentHttp::emit(array_merge($report, [
            'ok' => true,
            'can_perform' => $verdict,
            'can_perform_online' => $verdictOnline,
        ]));
        return;
    }
    http_response_code(400);
    View::render('error', [
        'title' => t('rates.diagnose.title', ['id' => $operatorId]),
        'message' => t('rates.module.summary'),
    ]);
    return;
}

$action = strtolower(trim((string)($_POST['action'] ?? 'grant')));
if ($action === '' || $action === 'enable' || $action === 'on') {
    $action = 'grant';
}
if ($action === 'disable' || $action === 'off' || $action === 'remove') {
    $action = 'revoke';
}

try {
    if ($action === 'revoke') {
        $result = RatesSkill::revoke([
            'operator_id' => $operatorId,
            'type_id' => $typeId,
            'good_id' => $goodId,
            'dry_run' => !empty($_POST['dry_run']) || (string)($_POST['dry_run'] ?? '') === '1',
            'ban_online' => !isset($_POST['ban_online']) || !empty($_POST['ban_online']),
        ]);
    } else {
        $result = RatesSkill::grant([
            'operator_id' => $operatorId,
            'type_id' => $typeId,
            'good_id' => $goodId,
            'percent' => array_key_exists('percent', $_POST) ? $_POST['percent'] : null,
            'dry_run' => !empty($_POST['dry_run']) || (string)($_POST['dry_run'] ?? '') === '1',
            'clear_online_ban' => !isset($_POST['clear_online_ban']) || !empty($_POST['clear_online_ban']),
        ]);
    }
    if ($wantsJson) {
        if (empty($result['ok'])) {
            AgentHttp::error((string)($result['message'] ?? $action . ' failed'), 400, $result);
            return;
        }
        AgentHttp::emit($result);
        return;
    }
    $opId = (int)($result['operator_id'] ?? $operatorId);
    redirect('/rates?operator_id=' . $opId . '&' . $action . '=1');
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    http_response_code(400);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => $e->getMessage(),
    ]);
}
