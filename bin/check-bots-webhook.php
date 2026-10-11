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

$ref = new ReflectionClass('BotWebhook');
$m = $ref->getMethod('verifySecret');
$m->setAccessible(true);

if (BotsConfig::allowUnsignedWebhooks()) {
    echo "SKIP empty_secret_denied (allow_unsigned_webhooks=1 in live ini)\n";
} else {
    $check('empty_secret_denied', $m->invoke(null, 'telegram', '') === false);
}

// Wrong / right token
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = 'deadbeef';
$check('wrong_secret_denied', $m->invoke(null, 'telegram', 'cafebabe') === false);
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = 'cafebabe';
$check('right_secret_ok', $m->invoke(null, 'telegram', 'cafebabe') === true);
unset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']);

$routes = ModuleManager::routes();
$tg = $routes['/bots/webhook/telegram/{channel}'] ?? null;
$check('webhook_post_only', is_array($tg) && ($tg['methods'] ?? []) === ['POST']);

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

if (BotLinkCodesRepository::tableReady() && Database::tableExists('base_users')) {
    $st = Database::pdo()->query("SELECT id FROM base_users WHERE enabled = '1' ORDER BY id ASC LIMIT 1");
    $eid = $st ? (int)$st->fetchColumn() : 0;
    if ($eid > 0) {
        $created = BotLinkCodesRepository::create(BotLinksRepository::SOURCE_STAFF, $eid, 120);
        $check('link_code_created', preg_match('/^\d{6}$/', $created['code']) === 1);
        $again = BotLinkCodesRepository::consume($created['code']);
        $check('link_code_consume', is_array($again) && (int)$again['id_entity'] === $eid);
        $check('link_code_once', BotLinkCodesRepository::consume($created['code']) === null);
    } else {
        echo "SKIP link_code (no enabled users)\n";
    }
} else {
    echo "SKIP link_code (migrate bots)\n";
}

exit($fail > 0 ? 1 : 0);
