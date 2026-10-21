<?php
declare(strict_types=1);

/**
 * Agent/JSON skill diagnose + grant (rates SoT).
 * GET  /rates/skill?operator_id=&type_id=|&good_id=
 * POST /rates/skill  grant (dry_run=1 for draft text)
 */
$wantsJson = AgentHttp::wantsJson();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$operatorId = (int)($_GET['operator_id'] ?? $_POST['operator_id'] ?? 0);
$typeId = (int)($_GET['type_id'] ?? $_POST['type_id'] ?? 0);
$goodId = (int)($_GET['good_id'] ?? $_POST['good_id'] ?? $_GET['product_id'] ?? $_POST['product_id'] ?? 0);

if ($method === 'GET') {
    $report = RatesSkill::diagnose($operatorId, $typeId, $goodId);
    if ($wantsJson) {
        AgentHttp::emit(array_merge(['ok' => true], $report));
        return;
    }
    http_response_code(400);
    View::render('error', [
        'title' => t('rates.diagnose.title', ['id' => $operatorId]),
        'message' => t('rates.module.summary'),
    ]);
    return;
}

try {
    $result = RatesSkill::grant([
        'operator_id' => $operatorId,
        'type_id' => $typeId,
        'good_id' => $goodId,
        'percent' => array_key_exists('percent', $_POST) ? $_POST['percent'] : null,
        'dry_run' => !empty($_POST['dry_run']) || (string)($_POST['dry_run'] ?? '') === '1',
        'clear_online_ban' => !isset($_POST['clear_online_ban']) || !empty($_POST['clear_online_ban']),
    ]);
    if ($wantsJson) {
        if (empty($result['ok'])) {
            AgentHttp::error((string)($result['message'] ?? 'grant failed'), 400, $result);
            return;
        }
        AgentHttp::emit($result);
        return;
    }
    $opId = (int)($result['operator_id'] ?? $operatorId);
    redirect('/rates?operator_id=' . $opId . '&granted=1');
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
