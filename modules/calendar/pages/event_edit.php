<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$returnUrl = EventsRepository::sanitizeReturnUrl(
    (string)($_POST['ret'] ?? $_GET['ret'] ?? '')
);

$row = [];
if (!$isNew) {
    $found = EventsRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('calendar.event.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
} else {
    if (!EventsRepository::canCreate()) {
        http_response_code(403);
        View::render('error', [
            'title' => t('calendar.event.err.no_create'),
            'message' => t('calendar.event.err.no_create'),
        ]);
        return;
    }
    $dateHint = trim((string)($_GET['date'] ?? ''));
    $hourHint = (int)($_GET['hour'] ?? -1);
    $startTs = time();
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateHint)) {
        $timePart = '10:00:00';
        if ($hourHint >= 0 && $hourHint <= 23) {
            $timePart = sprintf('%02d:00:00', $hourHint);
        }
        $day = strtotime($dateHint . ' ' . $timePart);
        if ($day !== false) {
            $startTs = $day;
        }
    }
    $staffHint = (int)($_GET['staff'] ?? 0);
    if (!empty(Auth::statusFlags()['EVT_MY_ONLY'])) {
        $staffHint = Auth::userId();
    }
    $row = [
        'dtstart' => $startTs,
        'dtend' => $startTs + 3600,
        'id_operator' => $staffHint > 0 ? $staffHint : Auth::userId(),
        'id_user' => 0,
        'content_ids' => [],
        'content_price_map' => [],
        'is_block' => 0,
        'fix_move' => 0,
        'real_summ' => 0,
        'phone' => '',
        'name' => '',
        'descr' => '',
    ];
    if ($returnUrl === '/calendar' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateHint)) {
        $returnUrl = EventsRepository::weekUrl($startTs, $staffHint);
    }
}

$message = '';
$error = '';
$canModify = $isNew ? EventsRepository::canCreate() : EventsRepository::canModify($row);
$canDelete = !$isNew && EventsRepository::canDelete($row);
$canMakeProcedures = !$isNew
    && Auth::can('procedures.edit')
    && Auth::can('calendar.edit')
    && empty($row['is_block']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'delete') {
            if (!$canDelete) {
                throw new RuntimeException(t('calendar.event.err.no_delete'));
            }
            $backUrl = EventsRepository::weekUrl((int)($row['dtstart'] ?? 0));
            EventsRepository::delete($id);
            redirect($returnUrl !== '/calendar' ? $returnUrl : $backUrl);
            return;
        }
        if ($action === 'create_procedures') {
            if (!$canMakeProcedures) {
                throw new RuntimeException(t('calendar.event.err.no_procedures'));
            }
            $result = ProceduresRepository::createFromEvent($id);
            $n = count($result['created']);
            $s = count($result['skipped']);
            if ($n < 1 && $s > 0) {
                $message = t('calendar.event.msg.procedures_exist', ['n' => $s]);
            } elseif ($n > 0) {
                $message = t('calendar.event.msg.procedures_created', ['n' => $n, 's' => $s]);
            } else {
                $message = t('calendar.event.msg.procedures_none');
            }
            $row = EventsRepository::find($id) ?: $row;
        } else {
            if (!$canModify) {
                throw new RuntimeException(t('calendar.event.err.no_modify'));
            }
            $data = EventsRepository::dataFromPost($_POST);
            if (!empty(Auth::statusFlags()['EVT_MY_ONLY'])) {
                $data['id_operator'] = Auth::userId();
            }
            if ($isNew) {
                $newId = EventsRepository::create($data);
                redirect(ListQuery::url('/calendar/events/' . $newId . '/edit', [
                    'saved' => 1,
                    'ret' => $returnUrl !== '/calendar' ? $returnUrl : EventsRepository::weekUrl((int)$data['dtstart']),
                ]));
                return;
            }
            EventsRepository::update($id, $data);
            $message = t('core.common.saved');
            $row = EventsRepository::find($id) ?: array_merge($row, $data);
            $canModify = EventsRepository::canModify($row);
            $canDelete = EventsRepository::canDelete($row);
            $canMakeProcedures = Auth::can('procedures.edit')
                && Auth::can('calendar.edit')
                && empty($row['is_block']);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($action !== 'create_procedures' && $action !== 'delete') {
            $row = array_merge($row, $_POST);
            if (isset($_POST['content']) && is_array($_POST['content'])) {
                $row['content_ids'] = array_map('intval', $_POST['content']);
            }
            if (isset($_POST['content_price']) && is_array($_POST['content_price'])) {
                $map = [];
                foreach ($_POST['content_price'] as $cid => $price) {
                    $map[(int)$cid] = (float)$price;
                }
                $row['content_price_map'] = $map;
            }
        }
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$staffNames = [];
foreach (UsersRepository::listForCopy() as $u) {
    $label = trim((string)$u['name']);
    if ($label === '') {
        $label = (string)$u['login'];
    }
    $staffNames[(int)$u['id']] = $label;
}

$productNames = ProductsRepository::names(true);
$productPrices = ProductsRepository::priceMap(true);

$clientLabel = '';
$clientId = (int)($row['id_user'] ?? 0);
if ($clientId > 0) {
    $client = ClientsRepository::find($clientId);
    if ($client) {
        $clientLabel = trim((string)($client['name'] ?: $client['tel'] ?: ''));
        if (($row['phone'] ?? '') === '' && !empty($client['tel'])) {
            $row['phone'] = (string)$client['tel'];
        }
        if (($row['name'] ?? '') === '' && !empty($client['name'])) {
            $row['name'] = (string)$client['name'];
        }
    }
}

$dayBack = $returnUrl;
$dtForBack = $row['dtstart'] ?? 0;
if (is_string($dtForBack) && strpos($dtForBack, 'T') !== false) {
    $dtForBack = EventsRepository::localToTs($dtForBack);
} else {
    $dtForBack = (int)$dtForBack;
}
if ($dayBack === '/calendar' && $dtForBack > 0) {
    $dayBack = EventsRepository::weekUrl($dtForBack);
}

$linkedProcedures = !$isNew ? ProceduresRepository::listByEventParent($id) : [];

View::render('event_edit', [
    'title' => $isNew
        ? t('calendar.event.title_new')
        : t('calendar.event.title', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'returnUrl' => $dayBack,
    'staffNames' => $staffNames,
    'productNames' => $productNames,
    'productPrices' => $productPrices,
    'clientLabel' => $clientLabel,
    'canModify' => $canModify,
    'canDelete' => $canDelete,
    'canMakeProcedures' => $canMakeProcedures,
    'linkedProcedures' => $linkedProcedures,
    'staffLocked' => !empty(Auth::statusFlags()['EVT_MY_ONLY']),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('calendar.menu.week'), 'href' => '/calendar'],
        [
            'label' => $isNew ? t('calendar.event.heading_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
], 'layout', 'calendar');
