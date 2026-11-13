<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$pipelines = CrmPipelinesRepository::listPipelines(true);
$defaultPipe = (int)($pipelines[0]['id'] ?? 0);
$form = [
    'company_id' => '',
    'company_name' => '',
    'company_inn' => '',
    'company_phone' => '',
    'client_id' => '',
    'contact_name' => '',
    'contact_tel' => '',
    'contact_mail' => '',
    'contact_telegram' => '',
    'contact_max' => '',
    'contact_whatsapp' => '',
    'contact_role' => '',
    'title' => '',
    'pipeline_id' => (string)$defaultPipe,
    'amount' => '',
    'source' => '',
    'responsible_user_id' => (string)(int)Auth::userId(),
    'notes' => '',
];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_merge($form, array_intersect_key(array_map(static function ($v) {
        return is_scalar($v) ? trim((string)$v) : '';
    }, $_POST), $form));
    $pdo = Database::pdo();
    try {
        $pdo->beginTransaction();
        $created = ['company' => false, 'client' => false];
        $matched = ['company' => '', 'client' => ''];

        $companyId = (int)$form['company_id'];
        if ($companyId > 0) {
            if (CrmCompaniesRepository::find($companyId) === null) {
                throw new InvalidArgumentException(t('crm.err.company_not_found'));
            }
            $matched['company'] = 'id';
        } elseif ($form['company_inn'] !== '' && ($found = CrmCompaniesRepository::findByInn($form['company_inn'])) !== null) {
            $companyId = (int)$found['id'];
            $matched['company'] = 'inn';
        } elseif ($form['company_name'] !== '' && ($found = CrmCompaniesRepository::findByName($form['company_name'])) !== null) {
            $companyId = (int)$found['id'];
            $matched['company'] = 'name';
        } elseif ($form['company_name'] !== '') {
            $companyId = CrmCompaniesRepository::create([
                'name' => $form['company_name'],
                'inn' => $form['company_inn'],
                'phone' => $form['company_phone'],
                'enabled' => 1,
                'responsible_user_id' => (int)$form['responsible_user_id'],
            ]);
            $created['company'] = true;
        }

        $clientId = 0;
        $contactIn = [
            'client_id' => (int)$form['client_id'],
            'tel' => $form['contact_tel'],
            'name' => $form['contact_name'],
            'mail' => $form['contact_mail'],
            'telegram' => $form['contact_telegram'],
            'max' => $form['contact_max'],
            'whatsapp' => $form['contact_whatsapp'],
            'channels' => $_POST['contact_channels'] ?? [],
        ];
        if (CrmContactResolver::hasAnyChannel($contactIn)) {
            $res = CrmContactResolver::resolve($contactIn);
            $clientId = $res['client_id'];
            $created['client'] = $res['created'];
            $matched['client'] = $res['matched'];
            if ($companyId > 0) {
                CrmCompaniesRepository::addContact($companyId, $clientId, $form['contact_role']);
            }
        }
        if ($companyId < 1 && $clientId < 1) {
            throw new InvalidArgumentException(t('crm.err.quick_need_party'));
        }

        $title = $form['title'];
        if ($title === '') {
            $title = $form['company_name'] !== '' ? $form['company_name']
                : ($companyId > 0 ? (string)(CrmCompaniesRepository::find($companyId)['name'] ?? '') : $form['contact_name']);
        }
        if ($title === '') {
            $title = $form['contact_tel'] !== '' ? $form['contact_tel']
                : ($form['contact_telegram'] !== '' ? $form['contact_telegram'] : $form['contact_mail']);
        }
        $dealId = CrmDealsRepository::create([
            'title' => $title,
            'pipeline_id' => (int)$form['pipeline_id'],
            'stage_id' => (int)($_POST['stage_id'] ?? 0),
            'company_id' => $companyId,
            'amount' => (float)str_replace([' ', ','], ['', '.'], $form['amount']),
            'currency' => 'RUB',
            'source' => $form['source'],
            'responsible_user_id' => (int)$form['responsible_user_id'],
            'notes' => $form['notes'],
        ], $clientId > 0 ? [$clientId] : [], $clientId);
        $pdo->commit();

        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'deal_id' => $dealId,
                'company_id' => $companyId,
                'client_id' => $clientId,
                'created' => $created + ['deal' => true],
                'matched' => $matched,
                'item' => CrmDealsRepository::find($dealId),
            ]);
            return;
        }
        flash_set('ok', t('crm.quick.done'));
        redirect('/crm/deals/' . $dealId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

$stagesByPipe = [];
foreach ($pipelines as $p) {
    $stagesByPipe[(int)$p['id']] = CrmPipelinesRepository::listStages((int)$p['id'], true);
}
$staff = CrmUi::staffOptions([(int)$form['responsible_user_id']]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'defaults' => $form,
        'pipelines' => $pipelines,
        'stages_by_pipeline' => $stagesByPipe,
        'staff' => $staff,
        'can_create_client' => Auth::can('clients.edit'),
    ]);
    return;
}

View::render('deal_quick', [
    'title' => t('crm.quick.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('crm.deals.title'), 'href' => '/crm/deals'],
        ['label' => t('crm.quick.title'), 'current' => true],
    ]),
    'form' => $form,
    'pipelines' => $pipelines,
    'stagesByPipe' => $stagesByPipe,
    'staff' => $staff,
    'companies' => CrmCompaniesRepository::names(true),
    'canCreateClient' => Auth::can('clients.edit'),
    'error' => $error,
], 'layout', 'crm');
