<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$row = [
    'enabled' => 1,
    'can_use_oldclients' => 1,
    'can_use_newclient' => 1,
    'can_use_clubers' => 1,
    'can_pay_online_only' => 1,
    'can_pay_offline_only' => 1,
    'can_use_dco' => 1,
    'can_use_bonus' => 1,
    'can_schedule' => 1,
    'can_use_today_only' => 0,
    'special_menu' => 0,
    'types' => [],
    'cosm_limit' => [],
    'modules' => [],
    'good_prices' => '',
    'is_clone' => false,
    'has_clones' => false,
];
if (!$isNew) {
    $found = PromopagesRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('sites.promopages.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
    $row['has_clones'] = PromopagesRepository::cloneCount($id) > 0;
}

$message = '';
$error = '';
$isClone = !empty($row['is_clone']);
$modulesFlash = '';


if (!$isNew && EntityDelete::tryHandle('sites_promopages', $id, '/sites/promopages', [
    'stay_path' => '/sites/promopages/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $parentId = (int)($_POST['id_parent'] ?? 0);
        $isClone = $parentId > 0 || $isClone;
        $data = PromopagesRepository::dataFromPost($_POST, $isClone);
        if (!$isClone) {
            $mods = $data['modules'] ?? [];
            $mods = PromopageModules::applyFixPrices(
                is_array($mods) ? $mods : [],
                (string)($row['good_prices'] ?? '')
            );
            $data['modules'] = $mods;
            $data['modules_raw'] = PromopageModules::encode($mods);
        }
        if ($isNew) {
            $newId = PromopagesRepository::create($data);
            redirect('/sites/promopages/' . $newId . '/edit?saved=1');
            return;
        }
        PromopagesRepository::update($id, $data, $isClone);
        $message = t('core.common.saved');
        $row = PromopagesRepository::find($id) ?: $row;
        $row['has_clones'] = PromopagesRepository::cloneCount($id) > 0;
        $isClone = !empty($row['is_clone']);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, [
            'descr' => (string)($_POST['descr'] ?? ''),
            'code' => (string)($_POST['code'] ?? ''),
            'skin' => (string)($_POST['skin'] ?? ''),
            'tpl' => (string)($_POST['tpl'] ?? ''),
            'phone' => (string)($_POST['phone'] ?? ''),
            'id_parent' => (int)($_POST['id_parent'] ?? 0),
            'thanks_url' => (string)($_POST['thanks_url'] ?? ''),
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
            'types' => is_array($_POST['types'] ?? null) ? array_map('intval', $_POST['types']) : [],
            'cosm_limit' => is_array($_POST['cosm_limit'] ?? null) ? array_map('intval', $_POST['cosm_limit']) : [],
            'dt_expire_local' => (string)($_POST['dt_expire_local'] ?? ''),
            'dt_use_only_local' => (string)($_POST['dt_use_only_local'] ?? ''),
            'modules' => PromopageModules::fromPost(
                is_array($_POST['modules'] ?? null) ? $_POST['modules'] : []
            ),
        ]);
        $isClone = ((int)($row['id_parent'] ?? 0)) > 0;
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$copyFrom = (int)($_GET['copyFrom'] ?? 0);
if ($copyFrom > 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $src = PromopagesRepository::find($copyFrom);
    if ($src && empty($src['is_clone'])) {
        $row['modules'] = is_array($src['modules'] ?? null) ? $src['modules'] : [];
        $modulesFlash = t('sites.promopages.mod.copied', [
            'code' => (string)($src['code'] ?? $copyFrom),
        ]);
    }
}

$modules = is_array($row['modules'] ?? null) ? $row['modules'] : [];
if (!$isClone) {
    $modules = PromopageModules::applyFixPrices($modules, (string)($row['good_prices'] ?? ''));
}

$typeNames = [];
if (class_exists('ProcedureTypesRepository', false) || ModuleManager::isEnabled('procedures')) {
    if (!class_exists('ProcedureTypesRepository', false)
        && is_file(base_path('modules/procedures/lib/ProcedureTypesRepository.php'))
    ) {
        require_once base_path('modules/procedures/lib/ProcedureTypesRepository.php');
    }
    if (class_exists('ProcedureTypesRepository', false)) {
        $typeNames = ProcedureTypesRepository::names();
    }
}

$staffNames = UsersRepository::staffNamesIncluding($row['cosm_limit'] ?? []);
$publicUrl = PromopagesRepository::publicUrl((string)($row['code'] ?? ''));

$copyFromOptions = [];
if (!$isNew && !$isClone) {
    $base = '/sites/promopages/' . $id . '/edit';
    foreach (PromopagesRepository::parentOptions($id) as $pid => $label) {
        $copyFromOptions[] = [
            'href' => $base . '?copyFrom=' . (int)$pid,
            'label' => $label,
        ];
    }
}

View::render('promopage_edit', [
    'title' => $isNew
        ? t('sites.promopages.edit.title_new')
        : t('sites.promopages.edit.title', ['id' => $id]),
    'isNew' => $isNew,
    'isClone' => $isClone,
    'row' => $row,
    'modules' => $modules,
    'copyFromOptions' => $copyFromOptions,
    'modulesFlash' => $modulesFlash,
    'message' => $message,
    'error' => $error,
    'parentOptions' => PromopagesRepository::parentOptions($isNew ? null : $id),
    'typeNames' => $typeNames,
    'staffNames' => $staffNames,
    'publicUrl' => $publicUrl,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('sites.menu.promopages'), 'href' => '/sites/promopages'],
        [
            'label' => $isNew
                ? t('sites.promopages.edit.title_new')
                : (string)($row['descr'] ?? ('#' . $id)),
            'current' => true,
        ],
    ]),
], 'layout', 'sites');
