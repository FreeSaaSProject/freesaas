#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Bots webhook / link security regressions (no HTTP server).
 * Exit 0 ok, 1 fail.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$fail = 0;
$check = static function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'OK  ' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

if (!ModuleManager::isEnabled('bots')) {
    echo "SKIP bots module disabled\n";
    exit(0);
}

require_once base_path('modules/bots/lib/BotsConfig.php');
require_once base_path('modules/bots/lib/BotWebhook.php');
require_once base_path('modules/bots/lib/BotWebhookUpdatesRepository.php');
require_once base_path('modules/bots/lib/BotLinkCodesRepository.php');
require_once base_path('modules/bots/lib/BotLinksRepository.php');
require_once base_path('modules/core/lib/LoginLinksRepository.php');

$ref = new ReflectionClass('BotWebhook');
$m = $ref->getMethod('verifySecret');
$m->setAccessible(true);

if (BotsConfig::allowUnsignedWebhooks()) {
    echo "SKIP empty_secret_denied (allow_unsigned_webhooks=1 in live ini)\n";
} else {
    $check('empty_secret_denied', $m->invoke(null, 'telegram', '') === false);
}

$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = 'deadbeef';
$check('wrong_secret_denied', $m->invoke(null, 'telegram', 'cafebabe') === false);
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = 'cafebabe';
$check('right_secret_ok', $m->invoke(null, 'telegram', 'cafebabe') === true);
unset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']);

$routes = ModuleManager::routes();
$tg = $routes['/bots/webhook/telegram/{channel}'] ?? null;
$check('webhook_post_only', is_array($tg) && ($tg['methods'] ?? []) === ['POST']);

// Secret / route checks above are DB-free. Dedup + link-code need MySQL.
try {
    Database::pdo()->query('SELECT 1');
} catch (Throwable $e) {
    echo 'SKIP no database: ' . $e->getMessage() . PHP_EOL;
    exit($fail > 0 ? 1 : 0);
}

if (BotWebhookUpdatesRepository::tableReady()) {
    $uid = (int)(microtime(true) * 1000) + random_int(1, 999);
    $check('dedup_first', BotWebhookUpdatesRepository::claim('telegram', 'staff', $uid) === true);
    $check('dedup_replay', BotWebhookUpdatesRepository::claim('telegram', 'staff', $uid) === false);
    Database::pdo()->prepare(
        'DELETE FROM base_bot_webhook_updates WHERE platform = ? AND channel = ? AND update_id = ?'
    )->execute(['telegram', 'staff', $uid]);
} else {
    echo "SKIP dedup (migrate bots)\n";
}

// Login link URL: without Host → relative (config host is preferred when set in live ini).
$hadHost = array_key_exists('HTTP_HOST', $_SERVER);
unset($_SERVER['HTTP_HOST']);
$adminHost = class_exists('Config', false) ? trim((string)Config::get('app', 'admin_host', '')) : '';
$publicHost = class_exists('Config', false) ? trim((string)Config::get('app', 'public_host', '')) : '';
$token64 = str_repeat('a', 64);
$url = LoginLinksRepository::absoluteUrl($token64);
if ($adminHost !== '' || $publicHost !== '') {
    $expectHost = preg_replace('#^https?://#i', '', $adminHost !== '' ? $adminHost : $publicHost);
    $expectHost = rtrim((string)$expectHost, '/');
    $check('login_link_uses_config_host', strpos($url, '://' . $expectHost . '/login/t/') !== false);
} else {
    $check('login_link_relative_without_host', $url === '/login/t/' . $token64);
}
if ($hadHost) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}

if (!BotLinkCodesRepository::tableReady() || !Database::tableExists('base_users')) {
    echo "SKIP link_code suite (migrate bots)\n";
    exit($fail > 0 ? 1 : 0);
}

$st = Database::pdo()->query("SELECT id FROM base_users WHERE enabled = '1' ORDER BY id ASC LIMIT 1");
$eid = $st ? (int)$st->fetchColumn() : 0;
if ($eid < 1) {
    echo "SKIP link_code suite (no enabled users)\n";
    exit($fail > 0 ? 1 : 0);
}

$created = BotLinkCodesRepository::create(BotLinksRepository::SOURCE_STAFF, $eid, 120);
$code = $created['code'];
$check(
    'link_code_entropy',
    strlen($code) === BotLinkCodesRepository::CODE_LEN
    && BotLinkCodesRepository::looksLikeCode($code)
);

$ok = BotLinkCodesRepository::consume($code, BotLinksRepository::SOURCE_STAFF);
$check('link_code_consume_ok', !empty($ok['ok']) && (int)$ok['id_entity'] === $eid);
$again = BotLinkCodesRepository::consume($code, BotLinksRepository::SOURCE_STAFF);
$check('link_code_once', empty($again['ok']));

// Wrong audience does not burn.
$c2 = BotLinkCodesRepository::create(BotLinksRepository::SOURCE_STAFF, $eid, 120);
$wa = BotLinkCodesRepository::consume($c2['code'], BotLinksRepository::SOURCE_CLIENT);
$check('link_code_wrong_audience', empty($wa['ok']) && ($wa['reason'] ?? '') === 'wrong_audience');
$still = BotLinkCodesRepository::consume($c2['code'], BotLinksRepository::SOURCE_STAFF);
$check('link_code_not_burned_on_wrong_audience', !empty($still['ok']));

// Per-code fail burn.
$c3 = BotLinkCodesRepository::create(BotLinksRepository::SOURCE_STAFF, $eid, 120);
$fake = BotLinkCodesRepository::generateCode();
// Ensure fake differs
while ($fake === $c3['code']) {
    $fake = BotLinkCodesRepository::generateCode();
}
// Misses against wrong hash don't burn c3 — registerCodeMiss on c3's code with wrong... 
// Burn by calling registerCodeMiss with the real code 3 times without consume.
$burn1 = BotLinkCodesRepository::registerCodeMiss($c3['code']);
$burn2 = BotLinkCodesRepository::registerCodeMiss($c3['code']);
$burn3 = BotLinkCodesRepository::registerCodeMiss($c3['code']);
$check('link_code_burn_on_fails', $burn3 === 'burned' || ($burn1 === 'hit' && $burn2 === 'hit' && $burn3 === 'burned'));
$afterBurn = BotLinkCodesRepository::consume($c3['code'], BotLinksRepository::SOURCE_STAFF);
$check('link_code_burned_unusable', empty($afterBurn['ok']));

// Chat throttle lock.
$chat = 'check-throttle-' . bin2hex(random_bytes(4));
if (BotLinkCodesRepository::throttleTableReady()) {
    for ($i = 0; $i < BotLinkCodesRepository::CHAT_FAIL_MAX; $i++) {
        BotLinkCodesRepository::recordChatFail('telegram', 'staff', $chat);
    }
    $check('link_chat_locked', BotLinkCodesRepository::isChatLocked('telegram', 'staff', $chat) === true);
    BotLinkCodesRepository::clearChatFails('telegram', 'staff', $chat);
    $check('link_chat_cleared', BotLinkCodesRepository::isChatLocked('telegram', 'staff', $chat) === false);
} else {
    echo "SKIP chat throttle (migrate 005)\n";
}

exit($fail > 0 ? 1 : 0);
