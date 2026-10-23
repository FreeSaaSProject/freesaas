<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$returnUrl = EventsRepository::sanitizeReturnUrl(
    (string)($_POST['ret'] ?? $_GET['ret'] ?? '')
);

$row = [];
if (!$isNew) {
    $found = EventsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('calendar.event.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('calendar.event.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
    // GET/JSON: same ownership gate as POST (EVT_MY_ONLY / EVT_MODIFY).
    if (!EventsRepository::canModify($row)) {
        if ($wantsJson) {
            AgentHttp::error(t('calendar.event.err.no_modify'), 403);
            return;
        }
        http_response_code(403);
        View::render('error', [
            'title' => t('calendar.event.err.no_modify'),
            'message' => t('calendar.event.err.no_modify'),
        ]);
        return;
    }
} else {
    if (!EventsRepository::canCreate()) {
        if ($wantsJson) {
            AgentHttp::error(t('calendar.event.err.no_create'), 403);
            return;
        }
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
    $forceBlock = CalendarConfig::isBlocksMode() || !empty($_GET['block']);
    $row = [
        'dtstart' => $startTs,
        'dtend' => $startTs + 3600,
        'id_operator' => $staffHint > 0 ? $staffHint : Auth::userId(),
        'id_user' => 0,
        'content_ids' => [],
        'content_price_map' => [],
        'content_qty_map' => [],
        'items' => [],
        'is_block' => $forceBlock ? 1 : 0,
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
$visitsMode = CalendarConfig::isVisitsMode();
$canModify = $isNew ? EventsRepository::canCreate() : EventsRepository::canModify($row);
$canDelete = !$isNew && EventsRepository::canDelete($row);
$canMakeProcedures = !$isNew
    && $visitsMode
    && Auth::can('procedures.edit')
    && Auth::can('calendar.edit')
    && empty($row['is_block']);

if (!$isNew && EntityDelete::tryHandle('calendar_events', $id, $returnUrl !== '' ? $returnUrl : '/calendar', [
    'stay_path' => '/calendar/events/' . $id . '/edit',
    'confirm' => 'calendar.event.confirm_delete',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'create_procedures') {
            if (!$canMakeProcedures) {
                throw new RuntimeException(t('calendar.event.err.no_procedures'));
            }
            $result = ProceduresRepository::createFromEvent($id);
            $n = count($result['created']);
            $s = count($result['skipped']);
            if ($wantsJson) {
                $row = EventsRepository::find($id) ?: $row;
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $id,
                    'procedures_created' => $n,
                    'procedures_skipped' => $s,
                    'item' => EventsRepository::forAgent($row),
                ]);
                return;
            }
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
            if (!$isNew && $wantsJson) {
                $_POST = EventsRepository::patchPost($_POST, $row);
            }
            $data = EventsRepository::dataFromPost($_POST);
            $saved = EventBus::emit('calendar.event.save', [
                'post' => $_POST,
                'row' => $row,
                'data' => $data,
                'id' => $id,
                'is_new' => $isNew,
            ]);
            if (is_array($saved) && isset($saved['data']) && is_array($saved['data'])) {
                $data = $saved['data'];
            }
            if (!empty(Auth::statusFlags()['EVT_MY_ONLY'])) {
                $data['id_operator'] = Auth::userId();
            }
            if ($isNew) {
                $newId = EventsRepository::create($data);
                if (is_array($saved) && isset($saved['_bonus_persist']) && class_exists('EventBonusRepository', false)) {
                    $bp = $saved['_bonus_persist'];
                    EventBonusRepository::upsert(
                        $newId,
                        (int)($bp['use'] ?? 0),
                        (float)($bp['discount'] ?? 0)
                    );
                }
                EventBus::emit('calendar.event.saved', ['id' => $newId, 'is_new' => true, 'data' => $data]);
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'id' => $newId,
                        'item' => EventsRepository::forAgent(EventsRepository::find($newId)),
                    ]);
                    return;
                }
                redirect(ListQuery::url('/calendar/events/' . $newId . '/edit', [
                    'saved' => 1,
                    'ret' => $returnUrl !== '/calendar' ? $returnUrl : EventsRepository::weekUrl((int)$data['dtstart']),
                ]));
                return;
            }
            EventsRepository::update($id, $data);
            if (is_array($saved) && isset($saved['_bonus_persist']) && class_exists('EventBonusRepository', false)) {
                $bp = $saved['_bonus_persist'];
                EventBonusRepository::upsert(
                    $id,
                    (int)($bp['use'] ?? 0),
                    (float)($bp['discount'] ?? 0)
                );
            }
            EventBus::emit('calendar.event.saved', ['id' => $id, 'is_new' => false, 'data' => $data]);
            $row = EventsRepository::find($id) ?: array_merge($row, $data);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $id,
                    'item' => EventsRepository::forAgent($row),
                ]);
                return;
            }
            $message = t('core.common.saved');
            $canModify = EventsRepository::canModify($row);
            $canDelete = EventsRepository::canDelete($row);
            $canMakeProcedures = $visitsMode
                && Auth::can('procedures.edit')
                && Auth::can('calendar.edit')
                && empty($row['is_block']);
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
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
            if (isset($_POST['content_qty']) && is_array($_POST['content_qty'])) {
                $qmap = [];
                foreach ($_POST['content_qty'] as $cid => $qty) {
                    $qmap[(int)$cid] = max(1, (int)$qty);
                }
                $row['content_qty_map'] = $qmap;
            }
        }
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$bonusFlash = '';
$bonusError = '';
if (ModuleManager::isEnabled('bonuses')) {
    if (isset($_GET['bonus_ok'])) {
        $bonusFlash = t('bonuses.flash.applied', [
            'spend' => (string)($_GET['spend'] ?? '0'),
            'accrue' => (string)($_GET['accrue'] ?? '0'),
        ]);
    }
    if (isset($_GET['bonus_err'])) {
        $bonusError = trim((string)($_GET['msg'] ?? ''));
        if ($bonusError === '') {
            $bonusError = t('bonuses.err.apply_failed');
        }
    }
}

$dayForStaff = (int)($row['dtstart'] ?? 0);
if ($dayForStaff <= 0) {
    $dayForStaff = time();
}
$keepOp = (int)($row['id_operator'] ?? 0);
$staffNames = EventsRepository::staffChoicesForDay($dayForStaff, $keepOp);

$productNames = ProductsRepository::names(true);
$productPrices = ProductsRepository::priceMap(true);
$staffForGoods = (int)($row['id_operator'] ?? 0);
$dayForGoods = (int)($row['dtstart'] ?? 0);
if ($dayForGoods <= 0) {
    $dayForGoods = time();
}
$keepGoodIds = [];
foreach (is_array($row['content_ids'] ?? null) ? $row['content_ids'] : [] as $gid) {
    $gid = (int)$gid;
    if ($gid > 0) {
        $keepGoodIds[] = $gid;
    }
}
$frequentGoods = EventItemsRepository::frequentGoods(12, $staffForGoods, $dayForGoods, $keepGoodIds);

$selectedItems = [];
$contentIds = is_array($row['content_ids'] ?? null) ? $row['content_ids'] : [];
$priceMap = is_array($row['content_price_map'] ?? null) ? $row['content_price_map'] : [];
$qtyMap = is_array($row['content_qty_map'] ?? null) ? $row['content_qty_map'] : [];
foreach ($contentIds as $gid) {
    $gid = (int)$gid;
    if ($gid < 1) {
        continue;
    }
    $selectedItems[] = [
        'id' => $gid,
        'name' => (string)($productNames[$gid] ?? ('#' . $gid)),
        'price' => (float)($priceMap[$gid] ?? $productPrices[$gid] ?? 0),
        'qty' => max(1, (int)($qtyMap[$gid] ?? 1)),
    ];
}

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
$row['phone'] = EventsRepository::normalizeEventPhone((string)($row['phone'] ?? ''));

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

$bonusSections = EventBus::emit('calendar.event.sections', [
    'html' => '',
    'row' => $row,
    'id' => $isNew ? 0 : $id,
    'is_new' => $isNew,
    'can_modify' => $canModify,
    'return_url' => $dayBack,
    'bonus_flash' => $bonusFlash,
    'bonus_error' => $bonusError,
]);
$bonusExtraHtml = is_array($bonusSections) ? (string)($bonusSections['html'] ?? '') : '';

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'id' => $isNew ? 0 : $id,
        'item' => EventsRepository::forAgent($row),
        'can_modify' => $canModify,
        'can_delete' => $canDelete,
        'can_make_procedures' => $canMakeProcedures,
        'linked_procedures' => $linkedProcedures,
        'staff_names' => $staffNames,
        'product_names' => $productNames,
        'product_prices' => $productPrices,
        'selected_items' => $selectedItems,
        'frequent_goods' => $frequentGoods,
        'client_label' => $clientLabel,
        'staff_locked' => !empty(Auth::statusFlags()['EVT_MY_ONLY']),
        'event_mode' => CalendarConfig::eventMode(),
        'visits_mode' => $visitsMode,
    ]);
    return;
}

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
    'selectedItems' => $selectedItems,
    'frequentGoods' => $frequentGoods,
    'clientLabel' => $clientLabel,
    'canModify' => $canModify,
    'canDelete' => $canDelete,
    'canMakeProcedures' => $canMakeProcedures,
    'linkedProcedures' => $linkedProcedures,
    'staffLocked' => !empty(Auth::statusFlags()['EVT_MY_ONLY']),
    'visitsMode' => $visitsMode,
    'bonusExtraHtml' => $bonusExtraHtml,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('calendar.menu.week'), 'href' => '/calendar'],
        [
            'label' => $isNew ? t('calendar.event.heading_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
], 'layout', 'calendar');
