<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/BitrixConfig.php';
require_once __DIR__ . '/lib/BitrixJobsAuth.php';
require_once __DIR__ . '/lib/BitrixPhone.php';
require_once __DIR__ . '/lib/BitrixIdMapRepository.php';
require_once __DIR__ . '/lib/BitrixSyncStateRepository.php';
require_once __DIR__ . '/lib/BitrixOutboxRepository.php';
require_once __DIR__ . '/lib/BitrixWebhookEventsRepository.php';
require_once __DIR__ . '/lib/BitrixApiClient.php';
require_once __DIR__ . '/lib/BitrixImporter.php';
require_once __DIR__ . '/lib/BitrixLongJob.php';
require_once __DIR__ . '/lib/BitrixOutboxPusher.php';
require_once __DIR__ . '/lib/BitrixWebhookApply.php';
require_once __DIR__ . '/lib/BitrixWebhookIngest.php';
require_once __DIR__ . '/lib/BitrixOutboxListener.php';

ModuleManager::registerMenuGroup('bitrix.menu.group', 1230, '/bitrix');

LongJob::register(BitrixLongJob::TYPE_IMPORT, [
    'module' => 'bitrix',
    'permission' => 'bitrix.import',
    'defaults' => ['tick_seconds' => 45],
    'contexts' => [
        'ui' => ['tick_seconds' => 45],
        'cli' => ['tick_seconds' => 90],
    ],
    'agent' => ['mode' => 'tick', 'assistant' => false],
    'on_start' => [BitrixLongJob::class, 'onStart'],
    'tick' => [BitrixLongJob::class, 'tick'],
    'on_cancel' => [BitrixLongJob::class, 'onCancel'],
]);

$pages = [
    '/bitrix' => ['permission' => 'bitrix.view', 'methods' => ['GET', 'POST'], 'file' => 'status.php'],
    '/bitrix/import' => ['permission' => 'bitrix.import', 'methods' => ['GET', 'POST'], 'file' => 'import.php'],
    '/bitrix/import/longjob' => ['permission' => 'bitrix.import', 'methods' => ['POST'], 'file' => 'longjob.php'],
    '/bitrix/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    '/bitrix/webhook' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'webhook.php'],
    '/bitrix/jobs/ping' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_ping.php'],
    '/bitrix/jobs/import' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_import.php'],
    '/bitrix/jobs/outbox' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_outbox.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'bitrix',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/bitrix/pages/' . $file);
        },
    ];
    if (!empty($def['public'])) {
        $route['public'] = true;
    }
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    if (!empty($def['permission'])) {
        $route['permission'] = $def['permission'];
    }
    ModuleManager::registerRoute($path, $route);
}

foreach (['clients.created', 'clients.updated'] as $ev) {
    EventBus::on($ev, static function ($payload) {
        return BitrixOutboxListener::onClientsChanged(is_array($payload) ? $payload : []);
    });
}
foreach (['crm.company.created', 'crm.company.updated'] as $ev) {
    EventBus::on($ev, static function ($payload) {
        return BitrixOutboxListener::onCompanyChanged(is_array($payload) ? $payload : []);
    });
}
EventBus::on('crm.company.deleted', static function ($payload) {
    if (!is_array($payload) || !BitrixConfig::pushEnabled()) {
        return is_array($payload) ? $payload : [];
    }
    if ((string)($payload['sync_origin'] ?? '') === 'bitrix') {
        return $payload;
    }
    $id = (int)($payload['id'] ?? 0);
    if ($id > 0) {
        BitrixOutboxRepository::enqueue('company', $id, 'delete', []);
    }
    return $payload;
});
foreach (['crm.deal.created', 'crm.deal.updated', 'crm.deal.stage_changed'] as $ev) {
    EventBus::on($ev, static function ($payload) {
        return BitrixOutboxListener::onDealChanged(is_array($payload) ? $payload : []);
    });
}
EventBus::on('crm.deal.deleted', static function ($payload) {
    if (!is_array($payload) || !BitrixConfig::pushEnabled()) {
        return is_array($payload) ? $payload : [];
    }
    if ((string)($payload['sync_origin'] ?? '') === 'bitrix') {
        return $payload;
    }
    $id = (int)($payload['id'] ?? 0);
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : null;
    $entity = ((string)($row['pipeline_kind'] ?? 'sale') === 'lead') ? 'lead' : 'deal';
    if ($id > 0) {
        BitrixOutboxRepository::enqueue($entity, $id, 'delete', []);
    }
    return $payload;
});

Cron::register('bitrix.import_contacts', [
    'module' => 'bitrix',
    'label' => 'bitrix.cron.import_contacts',
    'every' => 60,
    'handler' => static function (): array {
        if (!BitrixConfig::pullEnabled()) {
            return ['ok' => true, 'skipped' => 1, 'message' => 'pull disabled'];
        }
        $r = BitrixImporter::run('contact', false);
        return [
            'ok' => !empty($r['ok']),
            'processed' => (int)($r['created'] ?? 0) + (int)($r['updated'] ?? 0),
            'message' => (string)($r['error'] ?? ('fetched=' . (int)($r['fetched'] ?? 0))),
        ];
    },
]);

Cron::register('bitrix.outbox', [
    'module' => 'bitrix',
    'label' => 'bitrix.cron.outbox',
    'every' => 5,
    'handler' => static function (): array {
        $r = BitrixOutboxPusher::flush(25);
        return [
            'ok' => !empty($r['ok']),
            'processed' => (int)($r['processed'] ?? 0),
            'message' => (string)($r['message'] ?? ('errors=' . (int)($r['errors'] ?? 0))),
        ];
    },
]);
