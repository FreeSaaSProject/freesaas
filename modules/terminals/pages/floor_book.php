<?php
declare(strict_types=1);

/**
 * Staff/agent procedure-mode book (Surface B). Not the public /t drop-in.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = $_POST;
    if (AgentHttp::wantsJson()) {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
    }
    $action = (string)($body['action'] ?? 'book');
    if ($action === 'cancel') {
        $res = TerminalBooking::cancel(
            (int)($body['procedure_id'] ?? 0),
            !isset($body['unsale']) || !empty($body['unsale']),
            !empty($body['disable_1c'])
        );
        if (AgentHttp::wantsJson()) {
            if (empty($res['ok'])) {
                AgentHttp::error((string)($res['error'] ?? 'cancel failed'), 400);
            }
            AgentHttp::emit(['ok' => true, 'cancel' => $res]);
        }
        flash('ok', t('terminals.t.cancelled'));
        redirect('/terminals/floor');
    }

    $bookIn = [
        'product_id' => (int)($body['product_id'] ?? 0),
        'operator_id' => (int)($body['operator_id'] ?? 0),
        'admin_id' => (int)($body['admin_id'] ?? $body['operator_id'] ?? 0),
        'terminal_id' => (int)($body['terminal_id'] ?? 0),
        'dt_start' => (int)($body['dt_start'] ?? 0),
        'client_id' => (int)($body['client_id'] ?? 0),
        'onec_client_id' => (string)($body['onec_client_id'] ?? ''),
        'onec_bracelet' => (string)($body['onec_bracelet'] ?? ''),
        'token' => (string)($body['token'] ?? ''),
        'order_id' => (int)($body['order_id'] ?? 0),
        'disable_1c' => !empty($body['disable_1c']),
        'notice' => (string)($body['notice'] ?? ''),
        'lead_sec' => TerminalsConfig::leadSecApi(),
    ];
    if (isset($body['price']) && $body['price'] !== '') {
        $bookIn['price'] = (float)$body['price'];
    }
    $res = TerminalBooking::book($bookIn);
    if (empty($res['ok'])) {
        if (AgentHttp::wantsJson()) {
            AgentHttp::error((string)($res['error'] ?? 'book failed'), 400);
        }
        flash('err', (string)($res['error'] ?? t('terminals.t.err.book')));
        redirect('/terminals/floor');
    }
    if (AgentHttp::wantsJson()) {
        AgentHttp::emit(['ok' => true, 'booking' => $res]);
    }
    flash('ok', t('terminals.t.booked'));
    redirect('/procedures/' . (int)$res['procedure_id']);
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'hint' => 'POST action=book|cancel with product_id, operator_id, dt_start, terminal_id',
        'diagnose' => TerminalFloor::diagnose(null),
    ]);
}

View::render('floor_book', [
    'title' => t('terminals.floor.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('terminals.menu.list'), 'href' => '/terminals'],
        ['label' => t('terminals.floor.title'), 'current' => true],
    ]),
], 'layout', 'terminals');
