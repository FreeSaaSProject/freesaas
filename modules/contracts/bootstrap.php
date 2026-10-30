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

// Auth only: each action is gated by ContractsRepository::availableActions
// (IS_ACCEPTOR / ticket acceptor / CONTROL — not contracts.manage).
ModuleManager::registerRoute('/contracts/action', [
    'module' => 'contracts',
    'methods' => ['POST'],
    'handler' => static function (): void {
        require base_path('modules/contracts/pages/action.php');
    },
]);

// Settings UI lives under payroll: /payments/settings (deadline_penalty + settlements).
ModuleManager::registerRoute('/contracts/settings', [
    'module' => 'contracts',
    'methods' => ['GET', 'POST'],
    'capability' => 'SUPERADMIN_ACCESS',
    'handler' => static function (): void {
        if (ModuleManager::isEnabled('payments')) {
            redirect('/payments/settings');
        }
        redirect('/contracts');
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
    $lblToggle = te('contracts.offer.toggle');
    // Hidden by default under <details>; inputs stay in #comment-form so POST is unchanged.
    // Empty price ⇒ prepare_add skips offer even if deadline is posted.
    $payload['html'] = <<<HTML
<details class="offer-fields-wrap">
  <summary class="offer-fields-toggle">{$lblToggle}</summary>
  <div class="offer-fields">
    <label>{$lblPrice}
      <input type="number" name="price_offer" min="0" step="1" placeholder="{$phOptional}">
    </label>
    <label>{$lblDeadline}
      <input type="date" name="offer_deadline" value="{$dl}" data-offer-default-dl="{$dl}">
    </label>
  </div>
</details>
HTML;
    return $payload;
});

EventBus::on('comments.form.edit_fields', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (empty($payload['is_ticket']) || empty($payload['is_superadmin'])) {
        return $payload;
    }
    $lblPrice = te('contracts.offer.price');
    $phOptional = te('contracts.offer.optional');
    $lblDeadline = te('contracts.offer.deadline');
    $lblToggle = te('contracts.offer.toggle');
    $payload['html'] = <<<HTML
<details class="offer-fields-wrap" open>
  <summary class="offer-fields-toggle">{$lblToggle}</summary>
  <div class="offer-fields">
    <label>{$lblPrice}
      <input type="number" name="price_offer" min="0" step="1" placeholder="{$phOptional}">
    </label>
    <label>{$lblDeadline}
      <input type="date" name="offer_deadline" value="">
    </label>
  </div>
</details>
HTML;
    return $payload;
});

EventBus::on('comments.prepare_edit', static function ($payload) {
    if (!is_array($payload) || !empty($payload['error'])) {
        return $payload;
    }
    if (!Auth::isSuperAdmin()) {
        return $payload;
    }
    $row = $payload['row'] ?? null;
    $comment = $payload['comment'] ?? null;
    $post = $payload['post'] ?? null;
    if (!is_array($row) || !is_array($comment) || !is_array($post) || (int)($row['TYPE'] ?? 0) !== 2) {
        return $payload;
    }
    if (!array_key_exists('price_offer', $post) && !array_key_exists('offer_deadline', $post)) {
        return $payload;
    }
    $extra = is_array($payload['extra'] ?? null) ? $payload['extra'] : [];
    $priceOffer = isset($post['price_offer']) ? (int)$post['price_offer'] : (int)($comment['PRICE_OFFER'] ?? 0);
    $offerDl = trim((string)($post['offer_deadline'] ?? ''));
    if ($priceOffer < 1 && (int)($comment['PRICE_OFFER'] ?? 0) < 1) {
        return $payload;
    }
    if ($priceOffer >= 1) {
        $extra['PRICE_OFFER'] = $priceOffer;
        if ($offerDl === '') {
            $payload['error'] = t('contracts.err.deadline_required');
            return $payload;
        }
        $ots = strtotime($offerDl . ' 23:59:59');
        if (!$ots) {
            $payload['error'] = t('contracts.err.deadline_future');
            return $payload;
        }
        $extra['DT_DEADLINE'] = $ots;
    } elseif (array_key_exists('price_offer', $post) && $priceOffer < 1) {
        $extra['PRICE_OFFER'] = 0;
        $extra['DT_DEADLINE'] = 0;
    } elseif ($offerDl !== '') {
        $ots = strtotime($offerDl . ' 23:59:59');
        if (!$ots) {
            $payload['error'] = t('contracts.err.deadline_future');
            return $payload;
        }
        $extra['DT_DEADLINE'] = $ots;
    }
    $payload['extra'] = $extra;
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

EventBus::on('comments.visible', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $comments = $payload['comments'] ?? null;
    if (!is_array($comments)) {
        return $payload;
    }
    ContractsRepository::warmPaymentMismatch((int)($payload['news_id'] ?? 0), $comments);

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
    $ui = ContractsRepository::renderCommentUi($comment, $newsId, $uid);
    $payload['html'] = ($payload['html'] ?? '') . (string)($ui['html'] ?? '');
    $payload['meta_html'] = ($payload['meta_html'] ?? '') . (string)($ui['meta_html'] ?? '');
    return $payload;
});

EventBus::on('comments.can_delete', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $comment = $payload['comment'] ?? null;
    if (!is_array($comment) || !ContractsRepository::offerHasDps($comment)) {
        return $payload;
    }
    $payload['allowed'] = false;
    $payload['error'] = t('contracts.err.delete_has_payment');
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

EventBus::on('core.dashboard.catalog', static function ($catalog) {
    if (!is_array($catalog)) {
        $catalog = [];
    }
    if (Auth::userId() < 1) {
        return $catalog;
    }
    $catalog[] = [
        'id' => 'contracts.mine',
        'title' => t('contracts.menu.list'),
        'module' => 'contracts',
        'default_order' => 20,
        'default_on' => true,
    ];
    return $catalog;
});

EventBus::on('core.dashboard.widgets', static function ($widgets) {
    if (!is_array($widgets)) {
        $widgets = [];
    }
    $uid = Auth::userId();
    if ($uid < 1 || !DashboardWidgets::isEnabled('contracts.mine')) {
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
    $widgets[] = [
        'id' => 'contracts.mine',
        'title' => t('contracts.menu.list'),
        'body' => t('contracts.widget.body', ['open' => $open, 'new' => $newCount]),
        'tone' => $open > 0 ? 'warn' : 'ok',
        'href' => '/contracts',
        'size' => 'sm',
    ];
    return $widgets;
});

EventBus::on('core.user_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    ob_start();
    require __DIR__ . '/views/_user_edit_access.php';
    $payload['html_access'] = ($payload['html_access'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.user_edit.save', static function ($payload) {
    if (!is_array($payload) || !Database::columnExists('base_users', 'IS_ACCEPTOR')) {
        return $payload;
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    $data['IS_ACCEPTOR'] = !empty($post['IS_ACCEPTOR']) ? 1 : 0;
    $payload['data'] = $data;
    return $payload;
});

EventBus::on('core.user_edit.profile', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (empty($payload['can_see_sensitive'])) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    ob_start();
    require __DIR__ . '/views/_user_profile_flags.php';
    $payload['html_flags'] = ($payload['html_flags'] ?? '') . (string)ob_get_clean();
    return $payload;
});
