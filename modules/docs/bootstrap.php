<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/DocsConfig.php';
require_once __DIR__ . '/lib/DocsSchema.php';
require_once __DIR__ . '/lib/DocsSeed.php';
require_once __DIR__ . '/lib/DocsDefsRepository.php';
require_once __DIR__ . '/lib/DocsInstancesRepository.php';
require_once __DIR__ . '/lib/DocsHtml.php';
require_once __DIR__ . '/lib/DocsQr.php';
require_once __DIR__ . '/lib/DocsRender.php';
require_once __DIR__ . '/lib/DocsSpawner.php';
require_once __DIR__ . '/lib/DocsProposals.php';
require_once __DIR__ . '/lib/DocsLegacy.php';
require_once __DIR__ . '/lib/DocsMoney.php';
require_once __DIR__ . '/lib/DocsSample.php';
require_once __DIR__ . '/lib/DocsLibrary.php';

Assets::register('docs', [
    'css' => ['docs.css'],
    'js' => ['docs.js'],
]);
ModuleManager::registerMenuGroup('docs.menu.group', 420, '/documents');

$pages = [
    '/documents' => ['permission' => 'docs.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/documents/new' => ['permission' => 'docs.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/documents/{id}/edit' => ['permission' => 'docs.view', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/documents/{id}/print' => ['permission' => 'docs.print', 'methods' => ['GET', 'POST'], 'file' => 'print.php'],
    '/documents/defs' => ['permission' => 'docs.manage', 'methods' => ['GET'], 'file' => 'defs.php'],
    '/documents/defs/new' => ['permission' => 'docs.manage', 'methods' => ['GET', 'POST'], 'file' => 'def_edit.php'],
    '/documents/defs/import' => ['permission' => 'docs.manage', 'methods' => ['GET', 'POST'], 'file' => 'def_import.php'],
    '/documents/defs/import/sample' => ['permission' => 'docs.manage', 'methods' => ['GET'], 'file' => 'def_sample.php'],
    '/documents/defs/{id}/export' => ['permission' => 'docs.manage', 'methods' => ['GET'], 'file' => 'def_export.php'],
    '/documents/defs/{id}/sample' => ['permission' => 'docs.print', 'methods' => ['GET'], 'file' => 'def_sample.php'],
    '/documents/defs/{id}/edit' => ['permission' => 'docs.manage', 'methods' => ['GET', 'POST'], 'file' => 'def_edit.php'],
    '/documents/clients/{id}/profile' => ['permission' => 'docs.medical', 'methods' => ['GET'], 'file' => 'client_profile.php'],
    '/documents/clients/{id}/medcard' => ['permission' => 'docs.medical', 'methods' => ['GET'], 'file' => 'client_dossier.php'],
    '/documents/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
];
foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'docs',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/docs/pages/' . $file);
        },
    ];
    if (!empty($def['permission'])) {
        $route['permission'] = (string)$def['permission'];
    }
    if (!empty($def['capability'])) {
        $route['capability'] = (string)$def['capability'];
    }
    ModuleManager::registerRoute($path, $route);
}

// Public archive gate: 404 unless qr_archive=1; staff sign requires docs.sign in handler.
ModuleManager::registerRoute('/documents/qr/{hash}', [
    'module' => 'docs',
    'methods' => ['GET', 'POST'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/docs/pages/qr.php');
    },
]);

BulkActions::register('docs_instances', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'docs.edit',
    'confirm' => 'docs.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return DocsInstancesRepository::deleteByIds($ids);
    },
]);

// Producers (audio, imports, agents) hand values to staff review instead of writing payloads.
EventBus::on('docs.instance.propose', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $payload['result'] = DocsProposals::propose($payload);
    return $payload;
});

EventBus::on('audio_llm.study.result', static function ($payload) {
    if (!is_array($payload) || !DocsProposals::tableReady()) {
        return $payload;
    }
    $payload['docs_proposed'] = DocsProposals::fromStudyResult($payload);
    return $payload;
});

EventBus::on('calendar.event.saved', static function ($payload) {
    if (!is_array($payload) || !DocsInstancesRepository::tableReady()) {
        return $payload;
    }
    $id = (int)($payload['id'] ?? 0);
    if ($id > 0) {
        DocsSpawner::ensureForEvent($id);
    }
    return $payload;
});

EventBus::on('calendar.event.sections', static function ($payload) {
    if (!is_array($payload) || !DocsInstancesRepository::tableReady() || !DocsConfig::canSeeEventDocs()) {
        return $payload;
    }
    $eventId = (int)($payload['id'] ?? 0);
    if ($eventId < 1 || !empty($payload['is_new'])) {
        return $payload;
    }
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
    if (!empty($row['is_block'])) {
        return $payload;
    }
    DocsSpawner::ensureForEvent($eventId);
    $instances = array_values(array_filter(
        DocsInstancesRepository::listForEvent($eventId),
        static fn(array $i): bool => DocsConfig::canSeeKind((string)$i['kind'])
    ));
    $manualDefs = [];
    if (Auth::can('docs.edit')) {
        foreach (DocsDefsRepository::listAll(true) as $def) {
            if (in_array($def['kind'], ['medcard_profile', 'medcard_dossier'], true) || !DocsConfig::canSeeKind($def['kind'])) {
                continue;
            }
            $manualDefs[] = $def;
        }
    }
    Assets::need('docs');
    $payload['html'] = ($payload['html'] ?? '') . View::capture('_event_docs', [
        'eventId' => $eventId,
        'clientId' => (int)($row['id_user'] ?? 0),
        'instances' => $instances,
        'manualDefs' => $manualDefs,
        'blocking' => DocsSpawner::eventMedcardBlocking($eventId),
    ], 'docs');
    return $payload;
});

EventBus::on('clients.view.profile', static function ($payload) {
    if (!is_array($payload) || !DocsInstancesRepository::tableReady() || !Auth::can('docs.view')) {
        return $payload;
    }
    $client = is_array($payload['client'] ?? null) ? $payload['client'] : [];
    $clientId = (int)($client['id'] ?? $payload['id'] ?? 0);
    if ($clientId < 1) {
        return $payload;
    }
    $counts = DocsInstancesRepository::list(['client' => $clientId, 'limit' => 1]);
    $profile = Auth::can('docs.medical') ? DocsInstancesRepository::latestForClient($clientId, 'medcard_profile') : null;
    Assets::need('docs');
    $payload['html'] = ($payload['html'] ?? '') . View::capture('_client_profile', [
        'clientId' => $clientId,
        'total' => (int)$counts['total'],
        'profile' => $profile,
        'canMedical' => Auth::can('docs.medical'),
        'canPrint' => Auth::can('docs.print'),
    ], 'docs');
    return $payload;
});

EventBus::on('procedures.edit.sections', static function ($payload) {
    if (!is_array($payload) || !DocsInstancesRepository::tableReady() || !Auth::can('docs.medical')) {
        return $payload;
    }
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
    $procId = (int)($payload['id'] ?? 0);
    if ($procId < 1) {
        return $payload;
    }
    $eventId = (int)($row['id_event_parent'] ?? 0);
    $sheets = DocsInstancesRepository::list(['procedure' => $procId, 'kind' => 'medcard_visit', 'limit' => 10])['items'];
    if ($sheets === [] && $eventId > 0) {
        foreach (DocsInstancesRepository::listForEvent($eventId) as $inst) {
            if ($inst['kind'] === 'medcard_visit') {
                $sheets[] = $inst;
            }
        }
    }
    $isMedical = DocsSpawner::isMedicalType((int)($row['type'] ?? 0));
    if ($sheets === [] && !$isMedical) {
        return $payload;
    }
    $visitDef = DocsDefsRepository::firstOfKind('medcard_visit');
    $payload['html'] = ($payload['html'] ?? '') . View::capture('_procedure_docs', [
        'procId' => $procId,
        'eventId' => $eventId,
        'clientId' => (int)($row['id_user'] ?? 0),
        'sheets' => $sheets,
        'visitDef' => $visitDef,
        'canEdit' => Auth::can('docs.edit'),
    ], 'docs');
    return $payload;
});
