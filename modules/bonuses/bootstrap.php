<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/BonusMovesRepository.php';
require_once __DIR__ . '/lib/EventBonusRepository.php';
require_once __DIR__ . '/lib/BonusProcessor.php';

Assets::register('bonuses', [
    'css' => ['bonuses.css'],
]);

$pages = [
    '/bonuses' => ['permission' => 'bonuses.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/bonuses/new' => ['permission' => 'bonuses.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/bonuses/{id}/edit' => ['permission' => 'bonuses.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/bonuses/clients/{id}' => ['permission' => 'bonuses.view', 'methods' => ['GET'], 'file' => 'client.php'],
    '/bonuses/events/{id}/quote' => ['permission' => 'bonuses.view', 'methods' => ['GET'], 'file' => 'event_quote.php'],
    '/bonuses/events/{id}/apply' => ['permission' => 'bonuses.apply', 'methods' => ['POST'], 'file' => 'event_apply.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'bonuses',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/bonuses/pages/' . $file);
        },
    ]);
}

BulkActions::register('bonuses', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'bonuses.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => BonusMovesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('calendar.event.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (class_exists('CalendarConfig', false) && !CalendarConfig::isVisitsMode()) {
        return $payload;
    }
    if (!Auth::can('bonuses.view') && !Auth::can('bonuses.apply')) {
        return $payload;
    }
    if (!Auth::capability('EVT_BONUS') && !Auth::capability('SUPERADMIN_ACCESS')) {
        return $payload;
    }
    if (!BonusMovesRepository::tableReady()) {
        return $payload;
    }

    $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
    $isNew = !empty($payload['is_new']);
    $canModify = !empty($payload['can_modify']);
    $eventId = (int)($payload['id'] ?? 0);
    if ($eventId > 0) {
        $intent = EventBonusRepository::get($eventId);
        $row['bonus_use'] = $intent['bonus_use'];
        $row['bonus_discount'] = $intent['bonus_discount'];
    }
    $clientId = (int)($row['id_user'] ?? 0);
    $balance = $clientId > 0 ? BonusMovesRepository::balance($clientId) : 0.0;
    $already = $eventId > 0 && BonusMovesRepository::eventHasSettlement($eventId);
    $canApply = !$isNew
        && $eventId > 0
        && !$already
        && Auth::can('bonuses.apply')
        && (Auth::capability('EVT_BONUS') || Auth::capability('SUPERADMIN_ACCESS'));

    $quote = null;
    if (!$isNew && $eventId > 0 && $clientId > 0) {
        $quote = BonusProcessor::quote($eventId, $row);
    }

    Assets::need('bonuses');
    ob_start();
    $bonusSection = [
        'row' => $row,
        'isNew' => $isNew,
        'canModify' => $canModify,
        'eventId' => $eventId,
        'clientId' => $clientId,
        'balance' => $balance,
        'alreadyApplied' => $already,
        'canApply' => $canApply,
        'quote' => $quote,
        'returnUrl' => (string)($payload['return_url'] ?? '/calendar'),
        'applyFlash' => (string)($payload['bonus_flash'] ?? ''),
        'applyError' => (string)($payload['bonus_error'] ?? ''),
    ];
    extract($bonusSection, EXTR_SKIP);
    require __DIR__ . '/views/_event_bonus.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('calendar.event.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (class_exists('CalendarConfig', false) && !CalendarConfig::isVisitsMode()) {
        return $payload;
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    $eventId = (int)($payload['id'] ?? 0);

    $canBonusFields = Auth::capability('EVT_BONUS') || Auth::capability('SUPERADMIN_ACCESS');
    $bonusUse = 0;
    $bonusDiscount = 0.0;
    if ($eventId > 0 && AgentHttp::wantsJson() && !array_key_exists('bonus_use', $post)) {
        // Agent patch (e.g. reassign staff) without bonus fields keeps the stored settlement intent.
        $stored = EventBonusRepository::get($eventId);
        $bonusUse = (int)$stored['bonus_use'];
        $bonusDiscount = (float)$stored['bonus_discount'];
    } elseif ($canBonusFields && Auth::can('bonuses.view')) {
        $bonusUse = !empty($post['bonus_use']) ? 1 : 0;
        $bonusDiscount = max(0.0, (float)($post['bonus_discount'] ?? 0));
        if ($bonusUse < 1) {
            $bonusDiscount = 0.0;
        }
    }
    // Keep keys for quote UI on same request; persist after save via event id.
    $data['bonus_use'] = $bonusUse;
    $data['bonus_discount'] = $bonusDiscount;
    $payload['data'] = $data;
    $payload['_bonus_persist'] = ['use' => $bonusUse, 'discount' => $bonusDiscount, 'event_id' => $eventId];
    return $payload;
});
