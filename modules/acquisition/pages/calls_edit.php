<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = $isNew ? [
    'id' => 0,
    'phone' => trim((string)($_GET['phone'] ?? '')),
    'direction' => CallsRepository::TYPE_IN,
    'client_kind' => CallsRepository::KIND_PRIMARY,
    'result_id' => CallsRepository::RESULT_UNMARKED,
    'request' => '',
    'result_text' => '',
    'channel' => 'manual',
    'client_id' => max(0, (int)($_GET['client_id'] ?? 0)),
    'admin_id' => Auth::userId(),
    'dt_call' => time(),
    'event_id' => 0,
    'order_id' => 0,
] : CallsRepository::find($id);

if (!$isNew && $row === null) {
    if ($wantsJson) {
        AgentHttp::error(t('acquisition.calls.err.not_found'), 404);
        return;
    }
    flash_set('error', t('acquisition.calls.err.not_found'));
    redirect('/acquisition/calls');
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $post = [
        'phone' => (string)($_POST['phone'] ?? ''),
        'direction' => (int)($_POST['direction'] ?? CallsRepository::TYPE_IN),
        'client_kind' => (int)($_POST['client_kind'] ?? 0),
        'result_id' => (int)($_POST['result_id'] ?? 0),
        'request' => (string)($_POST['request'] ?? ''),
        'result_text' => (string)($_POST['result_text'] ?? ''),
        'channel' => (string)($_POST['channel'] ?? 'manual'),
        'client_id' => (int)($_POST['client_id'] ?? 0),
        'admin_id' => (int)($_POST['admin_id'] ?? Auth::userId()),
        'dt_call' => (string)($_POST['dt_call'] ?? ''),
        'event_id' => (int)($_POST['event_id'] ?? 0),
        'order_id' => (int)($_POST['order_id'] ?? 0),
    ];
    try {
        if ($isNew) {
            $id = CallsRepository::create($post);
            $isNew = false;
        } else {
            CallsRepository::update($id, $post);
        }
        $row = CallsRepository::find($id);
        $message = t('acquisition.calls.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
        if (!empty($_POST['book_after']) && (int)$post['result_id'] === CallsRepository::RESULT_BOOKED) {
            $phone = CallsRepository::normalizePhone((string)$post['phone']);
            redirect('/calendar/day?phone=' . rawurlencode($phone) . '&call_id=' . $id);
        }
        if (!empty($_POST['save_stay'])) {
            // stay on edit
        } else {
            redirect('/acquisition/calls/' . $id . '/edit');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge(is_array($row) ? $row : [], $post);
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'results' => CallsRepository::resultLabels(),
        'kinds' => CallsRepository::kindLabels(),
    ]);
    return;
}

View::render('calls_edit', [
    'title' => $isNew ? t('acquisition.calls.new') : t('acquisition.calls.title') . ' #' . $id,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('acquisition.calls.title'), 'href' => '/acquisition/calls'],
        ['label' => $isNew ? t('acquisition.calls.new') : ('#' . $id), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => is_array($row) ? $row : [],
    'message' => $message,
    'error' => $error,
    'resultLabels' => CallsRepository::resultLabels(),
    'kindLabels' => CallsRepository::kindLabels(),
], 'layout', 'acquisition');
