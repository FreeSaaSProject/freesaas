#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Public route allow-list lock (CI / pre-PR).
 *
 * Runtime Health::platform.route_auth only catches public+permission/capability and /db.
 * This check fails when a new public route appears without an allow-list entry, or when
 * named locks (/db not public; bot webhooks POST-only) are broken.
 *
 * Enable all modules in cfg/config.ini before running (CI does this).
 *
 * Exit: 0 ok, 1 fail
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

/**
 * Allow-list of public routes. Key = path pattern as registered.
 * value: methods (uppercase) + short why (for humans / PR review).
 *
 * @var array<string, array{methods: list<string>, why: string}>
 */
$allowed = [
    // Admin auth
    '/login' => ['methods' => ['GET', 'POST'], 'why' => 'staff login form'],
    '/login/t/{token}' => ['methods' => ['GET'], 'why' => 'one-time login link'],
    '/locale' => ['methods' => ['GET', 'POST'], 'why' => 'locale switch + redirect'],
    '/health' => ['methods' => ['GET'], 'why' => 'liveness probe (no secrets)'],

    // Platform agent HTTP (token / setup.key gated in handler)
    '/agent' => ['methods' => ['GET'], 'why' => 'agent catalog'],
    '/agent/bootstrap' => ['methods' => ['POST'], 'why' => 'virgin bootstrap'],
    '/agent/rescue' => ['methods' => ['POST'], 'why' => 'break-glass rescue'],
    '/agent/system/status' => ['methods' => ['GET'], 'why' => 'agent system status'],
    '/agent/doctor' => ['methods' => ['GET'], 'why' => 'agent doctor'],
    '/agent/health' => ['methods' => ['GET'], 'why' => 'agent health'],
    '/agent/validate' => ['methods' => ['GET'], 'why' => 'agent validate all'],
    '/agent/i18n' => ['methods' => ['GET'], 'why' => 'agent i18n'],
    '/agent/locale' => ['methods' => ['GET'], 'why' => 'agent locale list'],
    '/agent/locale/validate' => ['methods' => ['GET'], 'why' => 'agent locale validate'],
    '/agent/modules' => ['methods' => ['GET'], 'why' => 'agent modules list'],
    '/agent/modules/{id}' => ['methods' => ['GET'], 'why' => 'agent module inspect'],
    '/agent/modules/{id}/enable' => ['methods' => ['POST'], 'why' => 'agent module enable'],
    '/agent/modules/{id}/disable' => ['methods' => ['POST'], 'why' => 'agent module disable'],
    '/agent/modules/{id}/install' => ['methods' => ['POST'], 'why' => 'agent module install'],
    '/agent/modules/{id}/uninstall' => ['methods' => ['POST'], 'why' => 'agent module uninstall'],
    '/agent/modules/{id}/validate' => ['methods' => ['GET'], 'why' => 'agent module validate'],
    '/agent/db' => ['methods' => ['GET'], 'why' => 'agent db status'],
    '/agent/db/migrate' => ['methods' => ['GET'], 'why' => 'agent migrate status'],
    '/agent/db/migrate/run' => ['methods' => ['POST'], 'why' => 'agent migrate run'],
    '/agent/db/data-migrate' => ['methods' => ['GET'], 'why' => 'agent data migrator catalog'],
    '/agent/db/data-migrate/run' => ['methods' => ['POST'], 'why' => 'agent data migrator run one'],
    '/agent/db/dumps' => ['methods' => ['GET'], 'why' => 'agent dump list'],
    '/agent/db/dump' => ['methods' => ['POST'], 'why' => 'agent dump create'],
    '/agent/db/dump/file' => ['methods' => ['GET'], 'why' => 'agent dump download (header or signed)'],
    '/agent/db/dump/delete' => ['methods' => ['POST'], 'why' => 'agent dump delete'],
    '/agent/db/restore' => ['methods' => ['POST'], 'why' => 'agent restore'],
    '/agent/db/check' => ['methods' => ['POST'], 'why' => 'agent check'],
    '/agent/db/repair' => ['methods' => ['POST'], 'why' => 'agent repair'],
    '/agent/db/optimize' => ['methods' => ['POST'], 'why' => 'agent optimize'],
    '/agent/update' => ['methods' => ['GET'], 'why' => 'agent update status'],
    '/agent/update/check' => ['methods' => ['GET'], 'why' => 'agent update check'],
    '/agent/update/apply' => ['methods' => ['POST'], 'why' => 'agent update apply'],
    '/agent/update/conflicts' => ['methods' => ['GET'], 'why' => 'agent update local-edit conflicts'],
    '/agent/update/resolve' => ['methods' => ['POST'], 'why' => 'agent update resolve conflict'],

    // Cron / hard ticks (agent_token in handler)
    '/cron/tick' => ['methods' => ['POST', 'GET'], 'why' => 'platform cron tick'],
    '/cron/status' => ['methods' => ['GET'], 'why' => 'cron registry status'],
    '/core/jobs/habits-reminders' => ['methods' => ['POST', 'GET'], 'why' => 'habits reminder worker'],

    // Storefront (sites)
    '/shop' => ['methods' => ['GET', 'POST'], 'why' => 'storefront catalog'],
    '/shop/{id}' => ['methods' => ['GET', 'POST'], 'why' => 'storefront product'],
    '/shop/c/{id}' => ['methods' => ['GET', 'POST'], 'why' => 'storefront category'],
    '/cart' => ['methods' => ['GET', 'POST'], 'why' => 'storefront cart'],
    '/cart/availability' => ['methods' => ['GET'], 'why' => 'cart slot availability'],
    '/cart/checkout' => ['methods' => ['POST'], 'why' => 'cart checkout'],
    '/certificates/cart/apply' => ['methods' => ['POST'], 'why' => 'storefront certificate CODE apply'],
    '/certificates/cart/reset' => ['methods' => ['POST'], 'why' => 'storefront certificate CODE clear'],
    '/promo/{code}' => ['methods' => ['GET'], 'why' => 'promo redirect'],

    // Floor NFC shell (terminals); frozen query terminalid/braceletid/token
    '/t' => ['methods' => ['GET', 'POST'], 'why' => 'NFC terminal web shell'],

    // KPI live share links (token in path; audience/TTL/revoke enforced in handler)
    '/bi/s/{token}' => ['methods' => ['GET', 'POST'], 'why' => 'BI report family share link (guest or staff)'],

    // Warehouse sticker QR (printed labels → mothersite); guest→/, staff GOOD_USE in handler
    '/qr2.php' => ['methods' => ['GET', 'POST'], 'why' => 'legacy sticker QR scan gate'],
    // Optional anonymous A4 print (handler 404 unless stickers.public_print_enabled)
    '/stickers/printstickers' => ['methods' => ['GET'], 'why' => 'optional anonymous sticker A4 print sheet'],
    // Document archive QR (handler 404 unless docs.qr_archive; no PII; POST sign needs session + docs.sign)
    '/documents/qr/{hash}' => ['methods' => ['GET', 'POST'], 'why' => 'printed document archive check / archivist sign'],

    // LikeSpa legacy drop-in APIs (handlers 503 unless legacy_api_enabled)
    '/api_termy.php' => ['methods' => ['GET'], 'why' => 'Termy order-mode booking drop-in'],
    '/api.php' => ['methods' => ['GET'], 'why' => 'legacy booking/terminal API drop-in'],
    '/api1c.php' => ['methods' => ['GET', 'POST', 'PUT', 'PATCH'], 'why' => '1C reverse proxy drop-in'],
    '/api1c.php/{*path}' => ['methods' => ['GET', 'POST', 'PUT', 'PATCH'], 'why' => '1C proxy PATH_INFO (client_by_card, …)'],
    '/robots.txt' => ['methods' => ['GET'], 'why' => 'SEO robots'],
    '/sitemap.xml' => ['methods' => ['GET'], 'why' => 'SEO sitemap'],

    // Bots webhooks (secret in handler; POST-only lock below)
    '/bots/webhook/telegram/{channel}' => ['methods' => ['POST'], 'why' => 'Telegram webhook'],
    '/bots/webhook/max/{channel}' => ['methods' => ['POST'], 'why' => 'Max webhook'],
    '/assistant-groups/webhook/max' => ['methods' => ['POST'], 'why' => 'Max group assistant webhook'],

    // Surface A module repair (agent_token gated in agent_api.php)
    '/agent/bots/settings' => ['methods' => ['GET', 'POST'], 'why' => 'agent_token bots settings'],
    '/agent/bots/webhook/subscribe' => ['methods' => ['POST'], 'why' => 'agent_token bots subscribe'],
    '/agent/bots/link-code' => ['methods' => ['POST'], 'why' => 'agent_token bots link-code'],
    '/agent/bots/unlink' => ['methods' => ['POST'], 'why' => 'agent_token bots unlink'],
    '/agent/gcal/status' => ['methods' => ['GET'], 'why' => 'agent_token gcal status'],
    '/agent/gcal/settings' => ['methods' => ['GET', 'POST'], 'why' => 'agent_token gcal settings'],
    '/agent/gcal/queue/drain' => ['methods' => ['POST'], 'why' => 'agent_token gcal drain'],
    '/agent/llm/status' => ['methods' => ['GET'], 'why' => 'agent_token llm status'],
    '/agent/llm/tokens' => ['methods' => ['GET', 'POST'], 'why' => 'agent_token llm tokens'],
    '/agent/llm/tokens/delete' => ['methods' => ['POST'], 'why' => 'agent_token llm token delete'],
    '/agent/llm/ping' => ['methods' => ['POST'], 'why' => 'agent_token llm ping'],

    // OAuth / MCP (llm)
    '/oauth/authorize' => ['methods' => ['GET', 'POST'], 'why' => 'OAuth authorize'],
    '/oauth/token' => ['methods' => ['POST', 'OPTIONS'], 'why' => 'OAuth token'],
    '/oauth/register' => ['methods' => ['POST', 'OPTIONS'], 'why' => 'OAuth dynamic client'],
    '/oauth/revoke' => ['methods' => ['POST', 'OPTIONS'], 'why' => 'OAuth revoke'],
    '/llm/mcp' => ['methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'], 'why' => 'MCP endpoint'],
    '/llm/openapi.json' => ['methods' => ['GET', 'OPTIONS'], 'why' => 'OpenAPI for MCP tools'],
    '/llm/api/tools' => ['methods' => ['GET', 'OPTIONS'], 'why' => 'tools list'],
    '/llm/api/tools/call' => ['methods' => ['POST', 'OPTIONS'], 'why' => 'tools call'],
    '/.well-known/oauth-authorization-server' => ['methods' => ['GET', 'OPTIONS'], 'why' => 'OAuth metadata'],
    '/.well-known/openid-configuration' => ['methods' => ['GET', 'OPTIONS'], 'why' => 'OIDC metadata'],
    '/.well-known/oauth-protected-resource' => ['methods' => ['GET', 'OPTIONS'], 'why' => 'OAuth resource metadata'],
    '/.well-known/oauth-protected-resource/llm/mcp' => ['methods' => ['GET', 'OPTIONS'], 'why' => 'MCP resource metadata'],

    // Public client MCP (client_mcp); anonymous by design, 404/503 unless enabled for the request host
    '/mcp' => ['methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'], 'why' => 'client MCP endpoint (per storefront host)'],
    '/mcp/server-card' => ['methods' => ['GET', 'OPTIONS'], 'why' => 'MCP server card (per storefront host)'],
    // Domain AI catalog (sites aggregator; entries via EventBus sites.ai_catalog, 404 when empty)
    '/.well-known/ai-catalog.json' => ['methods' => ['GET', 'OPTIONS'], 'why' => 'AI catalog (agent discovery)'],
    '/llms.txt' => ['methods' => ['GET'], 'why' => 'site card for LLM readers (llmstxt.org)'],

    // ASR / audio pipeline workers (worker_token in handler)
    '/asr/voice-transcribe/worker' => ['methods' => ['GET', 'POST'], 'why' => 'ASR worker claim'],
    '/asr/voice-transcribe/file' => ['methods' => ['GET', 'POST'], 'why' => 'ASR worker file'],
    '/audio/voice-transcribe/worker' => ['methods' => ['GET', 'POST'], 'why' => 'legacy ASR worker alias'],
    '/audio/voice-transcribe/file' => ['methods' => ['GET', 'POST'], 'why' => 'legacy ASR file alias'],
    '/audio/jobs/ingest' => ['methods' => ['GET', 'POST'], 'why' => 'audio ingest enqueue'],
    '/audio/jobs/ingest/run' => ['methods' => ['GET', 'POST'], 'why' => 'audio ingest sync processor'],
    '/audio/jobs/stem' => ['methods' => ['GET', 'POST'], 'why' => 'audio stem enqueue'],
    '/audio/jobs/stem/run' => ['methods' => ['GET', 'POST'], 'why' => 'audio stem sync processor'],
    '/audio/jobs/collect' => ['methods' => ['GET', 'POST'], 'why' => 'audio collect worker'],
    '/audio/asr/claim' => ['methods' => ['GET', 'POST'], 'why' => 'audio ASR claim'],
    '/audio/asr/result' => ['methods' => ['GET', 'POST'], 'why' => 'audio ASR result'],
    '/audio/asr/chunk-claim' => ['methods' => ['GET', 'POST'], 'why' => 'audio ASR chunk claim'],
    '/audio/asr/chunk-result' => ['methods' => ['GET', 'POST'], 'why' => 'audio ASR chunk result'],

    // Video Mac worker (X-Video-Token)
    '/video/worker/hello' => ['methods' => ['GET', 'POST'], 'why' => 'video worker heartbeat'],
    '/video/worker/claim' => ['methods' => ['GET', 'POST'], 'why' => 'video worker claim'],
    '/video/worker/result' => ['methods' => ['GET', 'POST'], 'why' => 'video worker result'],
    '/video/worker/file' => ['methods' => ['GET', 'POST'], 'why' => 'video worker media download'],
    '/video/worker/frame' => ['methods' => ['GET', 'POST'], 'why' => 'video worker live frame push'],

    // Integration workers / OAuth callbacks
    '/banks/jobs/sync' => ['methods' => ['POST', 'GET'], 'why' => 'banks sync worker'],
    '/terminals/jobs/load' => ['methods' => ['POST', 'GET'], 'why' => 'terminals load worker'],
    '/terminals/jobs/tickets' => ['methods' => ['POST', 'GET'], 'why' => 'terminals tickets worker'],
    '/clientics/jobs/import-clients' => ['methods' => ['POST', 'GET'], 'why' => 'clientics import'],
    '/clientics/jobs/ping' => ['methods' => ['POST', 'GET'], 'why' => 'clientics ping'],
    '/clientics/webhook' => ['methods' => ['POST', 'GET'], 'why' => 'clientics webhook'],
    '/yclients/jobs/import-clients' => ['methods' => ['POST', 'GET'], 'why' => 'yclients import'],
    '/yclients/jobs/ping' => ['methods' => ['POST', 'GET'], 'why' => 'yclients ping'],
    '/gcal/oauth' => ['methods' => ['GET'], 'why' => 'Google Calendar OAuth callback'],
];

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $failed++;
    }
};

$routes = ModuleManager::routes();

// Named lock: /db must never be public
$dbRoute = $routes['/db'] ?? null;
$assert(!(is_array($dbRoute) && !empty($dbRoute['public'])), '/db is not public');

// Named lock: bot webhooks POST-only when public
foreach (['/bots/webhook/telegram/{channel}', '/bots/webhook/max/{channel}', '/assistant-groups/webhook/max'] as $wh) {
    $r = $routes[$wh] ?? null;
    if (!is_array($r) || empty($r['public'])) {
        continue;
    }
    $methods = array_map('strtoupper', $r['methods'] ?? ['GET']);
    $assert($methods === ['POST'], $wh . ' public methods must be POST-only (got ' . implode(',', $methods) . ')');
}

$public = [];
foreach ($routes as $path => $route) {
    if (!is_array($route) || empty($route['public'])) {
        continue;
    }
    $methods = array_map('strtoupper', $route['methods'] ?? ['GET']);
    sort($methods);
    $public[$path] = $methods;
}

ksort($public);
$violations = [];

foreach ($public as $path => $methods) {
    if (!isset($allowed[$path])) {
        $violations[] = $path . ' public but not in allow-list (methods=' . implode(',', $methods) . ')';
        continue;
    }
    $want = array_map('strtoupper', $allowed[$path]['methods']);
    sort($want);
    foreach ($methods as $m) {
        if (!in_array($m, $want, true)) {
            $violations[] = $path . ' public method ' . $m . ' not allowed (allow=' . implode(',', $want) . ')';
        }
    }
}

foreach ($violations as $v) {
    $assert(false, $v);
}

$stale = [];
foreach ($allowed as $path => $meta) {
    if (!isset($public[$path])) {
        $stale[] = $path;
    }
}

echo 'public routes: ' . count($public) . "\n";
echo 'allow-list: ' . count($allowed) . "\n";
echo 'violations: ' . count($violations) . "\n";
if ($stale !== []) {
    echo 'allow-list entries not currently public (module off or removed): ' . count($stale) . "\n";
    foreach (array_slice($stale, 0, 20) as $p) {
        echo '  stale: ' . $p . "\n";
    }
}

if ($failed > 0) {
    echo "FAILED ($failed)\n";
    exit(1);
}
echo "OK\n";
exit(0);
