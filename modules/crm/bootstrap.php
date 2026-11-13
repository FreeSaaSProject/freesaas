<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CrmSyncContext.php';
require_once __DIR__ . '/lib/CrmPipelinesRepository.php';
require_once __DIR__ . '/lib/CrmCompaniesRepository.php';
require_once __DIR__ . '/lib/CrmDealsRepository.php';
require_once __DIR__ . '/lib/CrmCustomFieldsRepository.php';
require_once __DIR__ . '/lib/CrmNotesRepository.php';
require_once __DIR__ . '/lib/CrmUi.php';
require_once __DIR__ . '/lib/CrmTimeline.php';
require_once __DIR__ . '/lib/CrmContactResolver.php';
require_once __DIR__ . '/lib/CrmLinksRepository.php';
require_once __DIR__ . '/lib/CrmTickets.php';
require_once __DIR__ . '/lib/CrmPolls.php';

Assets::register('crm', [
    'css' => ['crm.css'],
    'js' => ['crm.js'],
]);

ModuleManager::registerMenuGroup('crm.menu.group', 410, '/crm/deals');

$pages = [
    '/crm' => ['permission' => 'crm.view', 'methods' => ['GET'], 'file' => 'home.php'],
    '/crm/companies' => ['permission' => 'crm.view', 'methods' => ['GET', 'POST'], 'file' => 'companies_list.php'],
    '/crm/companies/new' => ['permission' => 'crm.edit', 'methods' => ['GET', 'POST'], 'file' => 'company_edit.php'],
    '/crm/companies/{id}' => ['permission' => 'crm.view', 'methods' => ['GET', 'POST'], 'file' => 'company_view.php'],
    '/crm/companies/{id}/edit' => ['permission' => 'crm.edit', 'methods' => ['GET', 'POST'], 'file' => 'company_edit.php'],
    '/crm/deals' => ['permission' => 'crm.view', 'methods' => ['GET', 'POST'], 'file' => 'deals_list.php'],
    '/crm/deals/new' => ['permission' => 'crm.edit', 'methods' => ['GET', 'POST'], 'file' => 'deal_edit.php'],
    '/crm/deals/quick' => ['permission' => 'crm.edit', 'methods' => ['GET', 'POST'], 'file' => 'deal_quick.php'],
    '/crm/deals/{id}' => ['permission' => 'crm.view', 'methods' => ['GET', 'POST'], 'file' => 'deal_view.php'],
    '/crm/deals/{id}/edit' => ['permission' => 'crm.edit', 'methods' => ['GET', 'POST'], 'file' => 'deal_edit.php'],
    '/crm/pipelines' => ['permission' => 'crm.pipelines', 'methods' => ['GET'], 'file' => 'pipelines_list.php'],
    '/crm/pipelines/new' => ['permission' => 'crm.pipelines', 'methods' => ['GET', 'POST'], 'file' => 'pipeline_edit.php'],
    '/crm/pipelines/{id}/edit' => ['permission' => 'crm.pipelines', 'methods' => ['GET', 'POST'], 'file' => 'pipeline_edit.php'],
    '/crm/stages/new' => ['permission' => 'crm.pipelines', 'methods' => ['GET', 'POST'], 'file' => 'stage_edit.php'],
    '/crm/stages/{id}/edit' => ['permission' => 'crm.pipelines', 'methods' => ['GET', 'POST'], 'file' => 'stage_edit.php'],
    '/crm/fields' => ['permission' => 'crm.fields', 'methods' => ['GET'], 'file' => 'fields_list.php'],
    '/crm/fields/new' => ['permission' => 'crm.fields', 'methods' => ['GET', 'POST'], 'file' => 'field_edit.php'],
    '/crm/fields/{id}/edit' => ['permission' => 'crm.fields', 'methods' => ['GET', 'POST'], 'file' => 'field_edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'crm',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/crm/pages/' . $file);
        },
    ]);
}

BulkActions::register('crm_companies', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'crm.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => CrmCompaniesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
BulkActions::register('crm_deals', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'crm.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => CrmDealsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('core.news_item.section', static function ($payload) {
    if (!is_array($payload) || !empty($payload['is_folder']) || !Auth::can('crm.view')) {
        return $payload;
    }
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
    if ((int)($row['TYPE'] ?? 0) !== 2) {
        return $payload;
    }
    $deals = CrmTickets::dealsForTicket((int)($row['ID'] ?? 0));
    if ($deals === []) {
        return $payload;
    }
    $html = '<section class="crm-news-deals crm-panel"><strong>' . te('crm.tickets.on_ticket') . '</strong><ul>';
    foreach ($deals as $d) {
        $html .= '<li><a href="' . e($d['url']) . '">' . e($d['title']) . '</a>'
            . ' <span class="crm-stage-pill crm-sem-' . e(strtolower($d['stage_semantic'])) . '">' . e($d['stage_name']) . '</span>'
            . ($d['company_name'] !== '' ? ' <span class="muted">' . e($d['company_name']) . '</span>' : '')
            . '</li>';
    }
    $html .= '</ul></section>';
    Assets::need('crm');
    $payload['html'] = (string)($payload['html'] ?? '') . $html;
    return $payload;
});

EventBus::on('core.news_item.agent', static function ($payload) {
    if (!is_array($payload) || !Auth::can('crm.view')) {
        return $payload;
    }
    $deals = CrmTickets::dealsForTicket((int)($payload['id'] ?? 0));
    if ($deals !== []) {
        $payload['extensions']['crm'] = ['deals' => $deals];
    }
    return $payload;
});

EventBus::on('poll_invites.completed', static function ($payload) {
    return is_array($payload) ? CrmPolls::onCompleted($payload) : $payload;
});
EventBus::on('poll_invites.refilled', static function ($payload) {
    return is_array($payload) ? CrmPolls::onRefilled($payload) : $payload;
});
EventBus::on('poll_invites.public.context', static function ($payload) {
    return is_array($payload) ? CrmPolls::onPublicContext($payload) : $payload;
});

EventBus::on('clients.view.profile', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    $clientId = (int)($payload['id'] ?? 0);
    if ($clientId < 1) {
        return $payload;
    }
    $companies = CrmCompaniesRepository::forClient($clientId);
    $deals = CrmDealsRepository::list(['client_id' => $clientId, 'limit' => 20, 'offset' => 0]);
    $html = '<section class="crm-client-profile"><h3>' . te('crm.profile.companies') . '</h3>';
    if ($companies === []) {
        $html .= '<p class="muted">' . te('crm.profile.none') . '</p>';
    } else {
        $html .= '<ul>';
        foreach ($companies as $c) {
            $html .= '<li><a href="/crm/companies/' . (int)$c['id'] . '">' . e((string)$c['name']) . '</a></li>';
        }
        $html .= '</ul>';
    }
    $html .= '<h3>' . te('crm.profile.deals') . '</h3>';
    if ($deals['items'] === []) {
        $html .= '<p class="muted">' . te('crm.profile.none') . '</p>';
    } else {
        $html .= '<ul>';
        foreach ($deals['items'] as $d) {
            $html .= '<li><a href="/crm/deals/' . (int)$d['id'] . '">' . e((string)$d['title']) . '</a>'
                . ' <span class="muted">' . e((string)($d['stage_name'] ?? '')) . '</span></li>';
        }
        $html .= '</ul>';
    }
    $html .= '</section>';
    $payload['html'] = (string)($payload['html'] ?? '') . $html;
    return $payload;
});
