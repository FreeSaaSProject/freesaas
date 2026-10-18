<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$accounts = LaundryAccountsRepository::names(true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $ticketId = max(0, (int)($_POST['ticket_news_id'] ?? 0));
        $termsId = max(0, (int)($_POST['terms_account_id'] ?? 0));
        $laundryId = max(0, (int)($_POST['laundry_account_id'] ?? 0));
        if ($termsId > 0 && $laundryId > 0 && $termsId === $laundryId) {
            throw new InvalidArgumentException(t('laundry.err.accounts_same'));
        }
        if ($termsId > 0 && !isset($accounts[$termsId])) {
            throw new InvalidArgumentException(t('laundry.err.account_required'));
        }
        if ($laundryId > 0 && !isset($accounts[$laundryId])) {
            throw new InvalidArgumentException(t('laundry.err.account_required'));
        }
        if ($ticketId > 0 && Database::tableExists('base_news')) {
            $st = Database::pdo()->prepare('SELECT ID FROM base_news WHERE ID = ? LIMIT 1');
            $st->execute([$ticketId]);
            if (!$st->fetchColumn()) {
                throw new InvalidArgumentException(t('laundry.err.ticket_not_found', ['id' => $ticketId]));
            }
        }
        Config::set('laundry', 'ticket_news_id', (string)$ticketId);
        Config::set('laundry', 'terms_account_id', (string)$termsId);
        Config::set('laundry', 'laundry_account_id', (string)$laundryId);
        $message = t('core.common.saved');
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'ticket_news_id' => $ticketId,
                'terms_account_id' => $termsId,
                'laundry_account_id' => $laundryId,
            ]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$ticketId = LaundryConfig::ticketNewsId();
$termsId = LaundryConfig::termsAccountId();
$laundryId = LaundryConfig::laundryAccountId();
$ticketTitle = '';
if ($ticketId > 0 && Database::tableExists('base_news')) {
    $st = Database::pdo()->prepare('SELECT NAME FROM base_news WHERE ID = ? LIMIT 1');
    $st->execute([$ticketId]);
    $ticketTitle = (string)($st->fetchColumn() ?: '');
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'ticket_news_id' => $ticketId,
        'ticket_title' => $ticketTitle,
        'terms_account_id' => $termsId,
        'laundry_account_id' => $laundryId,
        'accounts' => $accounts,
    ]);
    return;
}

View::render('settings', [
    'title' => t('laundry.menu.settings'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('laundry.menu.home'), 'href' => '/laundry'],
        ['label' => t('laundry.menu.settings'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'ticketId' => $ticketId,
    'ticketTitle' => $ticketTitle,
    'termsId' => $termsId,
    'laundryId' => $laundryId,
    'accounts' => $accounts,
], 'layout', 'laundry');
