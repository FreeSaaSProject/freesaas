<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ContractsRepository.php';

ModuleManager::registerRoute('/contracts', [
    'module' => 'contracts',
    'methods' => ['GET'],
    'permission' => 'contracts.list',
    'handler' => static function (): void {
        require base_path('modules/contracts/pages/list.php');
    },
]);

ModuleManager::registerRoute('/contracts/action', [
    'module' => 'contracts',
    'methods' => ['POST'],
    'permission' => 'contracts.manage',
    'handler' => static function (): void {
        require base_path('modules/contracts/pages/action.php');
    },
]);

EventBus::on('comments.form.offer_fields', static function ($payload) {
    if (!is_array($payload)) {
        $payload = [];
    }
    if (empty($payload['is_ticket'])) {
        return $payload;
    }
    $dl = e((string)($payload['offer_default_dl'] ?? ''));
    $lblPrice = te('contracts.offer.price');
    $phOptional = te('contracts.offer.optional');
    $lblDeadline = te('contracts.offer.deadline');
    $payload['html'] = <<<HTML
<div class="offer-fields">
  <label>{$lblPrice}
    <input type="number" name="price_offer" min="0" step="1" placeholder="{$phOptional}">
  </label>
  <label>{$lblDeadline}
    <input type="date" name="offer_deadline" value="{$dl}">
  </label>
</div>
HTML;
    return $payload;
});

EventBus::on('comments.prepare_add', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $row = $payload['row'] ?? null;
    $post = $payload['post'] ?? null;
    if (!is_array($row) || !is_array($post) || (int)($row['TYPE'] ?? 0) !== 2) {
        return $payload;
    }

    $extra = is_array($payload['extra'] ?? null) ? $payload['extra'] : [];
    $text = (string)($payload['text'] ?? '');
    $priceOffer = (int)($post['price_offer'] ?? 0);
    $offerDl = trim((string)($post['offer_deadline'] ?? ''));
    if ($priceOffer < 1) {
        $payload['extra'] = $extra;
        return $payload;
    }

    $extra['PRICE_OFFER'] = $priceOffer;
    if ($offerDl === '') {
        $payload['error'] = t('contracts.err.deadline_required');
        return $payload;
    }
    $ots = strtotime($offerDl . ' 23:59:59');
    if (!$ots || $ots <= time()) {
        $payload['error'] = t('contracts.err.deadline_future');
        return $payload;
    }
    $extra['DT_DEADLINE'] = $ots;
    if (trim($text) === '') {
        $text = t('contracts.offer.auto_text', ['price' => $priceOffer, 'date' => I18n::formatDate($ots, 'date')]);
    }
    $payload['extra'] = $extra;
    $payload['text'] = $text;
    return $payload;
});

EventBus::on('comments.render_actions', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $comment = $payload['comment'] ?? null;
    if (!is_array($comment)) {
        return $payload;
    }
    $newsId = (int)($payload['news_id'] ?? 0);
    $uid = (int)($payload['uid'] ?? 0);
    $html = ContractsRepository::renderCommentActionsHtml($comment, $newsId, $uid);
    $payload['html'] = ($payload['html'] ?? '') . $html;
    return $payload;
});

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check()) {
        return $notices;
    }
    foreach (ContractsRepository::noticesForUser(Auth::userId()) as $n) {
        $notices[] = $n;
    }
    return $notices;
});

EventBus::on('core.dashboard.widgets', static function ($widgets) {
    if (!is_array($widgets)) {
        $widgets = [];
    }
    $uid = Auth::userId();
    if ($uid < 1) {
        return $widgets;
    }
    $open = ContractsRepository::countRaw(
        'PRICE_OFFER <> 0 AND DT_OFFER_ACCEPT > 0 AND DT_WORK_ACCEPT = 0 AND DT_FAIL = 0
         AND (ID_USER = ? OR ID_ACCEPTOR = ?)',
        [$uid, $uid]
    );
    $newCount = ContractsRepository::countRaw(
        'PRICE_OFFER <> 0 AND DT_OFFER_ACCEPT = 0 AND DT_DEADLINE > ? AND (ID_USER = ? OR ID_ACCEPTOR = ?)',
        [time(), $uid, $uid]
    );
    if (ContractsRepository::isAcceptor($uid) || Auth::capability('CONTROL_ACCESS')) {
        $newCount = ContractsRepository::countRaw(
            'PRICE_OFFER <> 0 AND DT_OFFER_ACCEPT = 0 AND DT_DEADLINE > ?',
            [time()]
        );
    }
    if ($open > 0 || $newCount > 0) {
        $widgets[] = [
            'id' => 'contracts.mine',
            'title' => t('contracts.menu.list'),
            'body' => t('contracts.widget.body', ['open' => $open, 'new' => $newCount]),
            'tone' => $open > 0 ? 'warn' : 'ok',
            'href' => '/contracts',
        ];
    }
    return $widgets;
});
