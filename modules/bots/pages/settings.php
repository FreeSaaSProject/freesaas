<?php
declare(strict_types=1);

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'subscribe_webhook') {
            if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
                Csrf::deny('csrf');
            }
            $audience = strtolower(trim((string)($_POST['audience'] ?? '')));
            $platform = strtolower(trim((string)($_POST['platform'] ?? '')));
            if (!in_array($audience, BotsConfig::AUDIENCES, true) || !in_array($platform, BotsConfig::PLATFORMS, true)) {
                throw new InvalidArgumentException(t('bots.settings.subscribe_bad_args'));
            }
            $origin = '';
            if (class_exists('AgentOps', false)) {
                $origin = AgentOps::publicOrigin();
            }
            if ($origin === '') {
                $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                $origin = ($https ? 'https://' : 'http://') . $host;
            }
            $url = rtrim($origin, '/') . '/bots/webhook/' . $platform . '/' . $audience;
            $res = BotClient::subscribeWebhook($audience, $platform, $url);
            if (empty($res['ok'])) {
                throw new RuntimeException((string)($res['error'] ?? t('bots.settings.subscribe_fail')));
            }
            $extra = '';
            if (!empty($res['ip_address'])) {
                $extra = ' · IP ' . (string)$res['ip_address'];
            }
            $message = t('bots.settings.subscribe_ok', [
                'channel' => t('bots.settings.audience.' . $audience) . ' / ' . t('bots.settings.platform.' . $platform),
                'url' => $url,
            ]) . $extra;
        } else {
            BotsConfig::saveFromPost($_POST);
            $message = t('core.common.saved');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$channels = BotsConfig::listForUi();
$maxSsl = BotsConfig::maxSslInsecure();
$telegramWebhooks = [
    'staff' => BotClient::telegramGetWebhookInfo('staff'),
    'client' => BotClient::telegramGetWebhookInfo('client'),
];
$telegramMe = [
    'staff' => BotClient::telegramGetMe('staff'),
    'client' => BotClient::telegramGetMe('client'),
];

if (AgentHttp::wantsJson()) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $error !== '') {
        AgentHttp::error($error, 400);
    }
    AgentHttp::emit([
        'ok' => true,
        'message' => $message,
        'max_ssl_insecure' => $maxSsl,
        'channels' => $channels,
        'client_telegram_username' => BotsConfig::clientTelegramUsername(),
        'client_max_bot_url' => BotsConfig::clientMaxBotUrl(),
        'telegram_webhooks' => $telegramWebhooks,
        'telegram_me' => $telegramMe,
    ]);
}

View::render('settings', [
    'title' => t('bots.settings.title'),
    'message' => $message,
    'error' => $error,
    'channels' => $channels,
    'maxSsl' => $maxSsl,
    'clientTelegramUsername' => BotsConfig::clientTelegramUsername(),
    'clientMaxBotUrl' => BotsConfig::clientMaxBotUrl(),
    'telegramWebhooks' => $telegramWebhooks,
    'telegramMe' => $telegramMe,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bots.menu.settings'), 'current' => true],
    ]),
], 'layout', 'bots');
