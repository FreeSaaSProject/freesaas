<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/AmocrmConfig.php';
require_once __DIR__ . '/lib/AmocrmJobsAuth.php';
require_once __DIR__ . '/lib/AmocrmPhone.php';
require_once __DIR__ . '/lib/AmocrmIdMapRepository.php';
require_once __DIR__ . '/lib/AmocrmSyncStateRepository.php';
require_once __DIR__ . '/lib/AmocrmOutboxRepository.php';
require_once __DIR__ . '/lib/AmocrmWebhookEventsRepository.php';
require_once __DIR__ . '/lib/AmocrmApiClient.php';
require_once __DIR__ . '/lib/AmocrmImporter.php';
require_once __DIR__ . '/lib/AmocrmOutboxPusher.php';
require_once __DIR__ . '/lib/AmocrmWebhookIngest.php';
require_once __DIR__ . '/lib/AmocrmOutboxListener.php';

ModuleManager::registerMenuGroup('amocrm.menu.group', 1235, '/amocrm');

$pages = [
    '/amocrm' => ['permission' => 'amocrm.view', 'methods' => ['GET', 'POST'], 'file' => 'status.php'],
    '/amocrm/import' => ['permission' => 'amocrm.import', 'methods' => ['GET', 'POST'], 'file' => 'import.php'],
    '/amocrm/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    '/amocrm/webhook' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'webhook.php'],
    '/amocrm/jobs/ping' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_ping.php'],
    '/amocrm/jobs/import' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_import.php'],
    '/amocrm/jobs/outbox' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_outbox.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'amocrm',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/amocrm/pages/' . $file);
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
        return AmocrmOutboxListener::onClientsChanged(is_array($payload) ? $payload : []);
    });
}
foreach (['crm.company.created', 'crm.company.updated'] as $ev) {
    EventBus::on($ev, static function ($payload) {
        return AmocrmOutboxListener::onCompanyChanged(is_array($payload) ? $payload : []);
    });
}
EventBus::on('crm.company.deleted', static function ($payload) {
    if (!is_array($payload) || !AmocrmConfig::pushEnabled() || (string)($payload['sync_origin'] ?? '') === 'amocrm') {
        return is_array($payload) ? $payload : [];
    }
    $id = (int)($payload['id'] ?? 0);
    if ($id > 0) {
        AmocrmOutboxRepository::enqueue('company', $id, 'delete', []);
    }
    return $payload;
});
foreach (['crm.deal.created', 'crm.deal.updated', 'crm.deal.stage_changed'] as $ev) {
    EventBus::on($ev, static function ($payload) {
        return AmocrmOutboxListener::onDealChanged(is_array($payload) ? $payload : []);
    });
}
EventBus::on('crm.deal.deleted', static function ($payload) {
    if (!is_array($payload) || !AmocrmConfig::pushEnabled() || (string)($payload['sync_origin'] ?? '') === 'amocrm') {
        return is_array($payload) ? $payload : [];
    }
    $id = (int)($payload['id'] ?? 0);
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : null;
    if ($id > 0 && (string)($row['pipeline_kind'] ?? 'sale') !== 'lead') {
        AmocrmOutboxRepository::enqueue('lead', $id, 'delete', []);
    }
    return $payload;
});

Cron::register('amocrm.import_contacts', [
    'module' => 'amocrm',
    'label' => 'amocrm.cron.import_contacts',
    'every' => 60,
    'handler' => static function (): array {
        if (!AmocrmConfig::pullEnabled()) {
            return ['ok' => true, 'skipped' => 1, 'message' => 'pull disabled'];
        }
        $r = AmocrmImporter::run('contact', false);
        return [
            'ok' => !empty($r['ok']),
            'processed' => (int)($r['created'] ?? 0) + (int)($r['updated'] ?? 0),
            'message' => (string)($r['error'] ?? ('fetched=' . (int)($r['fetched'] ?? 0))),
        ];
    },
]);

Cron::register('amocrm.outbox', [
    'module' => 'amocrm',
    'label' => 'amocrm.cron.outbox',
    'every' => 5,
    'handler' => static function (): array {
        $r = AmocrmOutboxPusher::flush(25);
        return [
            'ok' => !empty($r['ok']),
            'processed' => (int)($r['processed'] ?? 0),
            'message' => (string)($r['message'] ?? ('errors=' . (int)($r['errors'] ?? 0))),
        ];
    },
]);
