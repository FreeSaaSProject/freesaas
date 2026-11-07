<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$items = CrmCustomFieldsRepository::listFields('', false);

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'items' => $items]);
    return;
}

View::render('fields_list', [
    'title' => t('crm.fields.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('crm.fields.title'), 'current' => true],
    ]),
    'items' => $items,
    'canEdit' => Auth::can('crm.fields'),
], 'layout', 'crm');
