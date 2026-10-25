<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = $isNew ? [
    'id' => 0,
    'name' => '',
    'enabled' => 1,
    'supplier_id' => 0,
    'account_id' => 0,
    'type_id' => 0,
    'fee_percent' => 2.0,
    'fee_posting' => 'none',
    'fee_move_type_id' => 0,
    'fee_account_id' => 0,
    'parser_preset' => RemittanceParser::PRESET_VOLGA_TERMY_V1,
    'pattern_period_from' => '',
    'pattern_period_to' => '',
    'pattern_gross' => '',
    'pattern_fee' => '',
    'expected_channels' => 'spa_ops',
    'auto_set_supplier' => 1,
    'supplier_name' => '',
    'supplier_inn' => '',
] : RemittanceAgreementsRepository::find($id);

if (!$isNew && $row === null) {
    if ($wantsJson) {
        AgentHttp::error(t('remittances.err.not_found', ['id' => (string)$id]), 404);
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('remittances.err.not_found', ['id' => (string)$id]),
    ], 'layout', 'core');
    return;
}

$message = '';
$error = '';
$dryRun = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Auth::can('remittances.manage')) {
        if ($wantsJson) {
            AgentHttp::error(t('remittances.err.forbidden'), 403);
        }
        $error = t('remittances.err.forbidden');
    } else {
        $action = (string)($_POST['action'] ?? 'save');
        try {
            if ($action === 'delete') {
                if (!Auth::can('remittances.delete') || $isNew) {
                    throw new RuntimeException(t('remittances.err.forbidden'));
                }
                RemittanceAgreementsRepository::deleteByIds([$id]);
                RemittanceMatcher::clearCache();
                if ($wantsJson) {
                    AgentHttp::emit(['ok' => true, 'deleted' => $id]);
                    return;
                }
                flash_set('ok', t('remittances.flash.deleted'));
                redirect('/remittances');
            }

            if ($action === 'apply_preset') {
                $preset = trim((string)($_POST['parser_preset'] ?? ''));
                $slots = RemittanceParser::slotsForPreset($preset);
                $_POST['pattern_period_from'] = $slots['period_from'];
                $_POST['pattern_period_to'] = $slots['period_to'];
                $_POST['pattern_gross'] = $slots['gross'];
                $_POST['pattern_fee'] = $slots['fee'];
                $_POST['parser_preset'] = $preset;
            }

            $supplierId = (int)($_POST['supplier_id'] ?? 0);
            if ($supplierId < 1) {
                throw new InvalidArgumentException(t('remittances.err.supplier_required'));
            }
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $supName = '';
                if (class_exists('SuppliersRepository', false)) {
                    $s = SuppliersRepository::find($supplierId);
                    $supName = (string)($s['name'] ?? '');
                }
                $name = $supName !== '' ? $supName : ('#' . $supplierId);
            }

            $data = [
                'name' => $name,
                'enabled' => !empty($_POST['enabled']) ? 1 : 0,
                'supplier_id' => $supplierId,
                'account_id' => (int)($_POST['account_id'] ?? 0),
                'type_id' => (int)($_POST['type_id'] ?? 0),
                'fee_percent' => (float)str_replace(',', '.', (string)($_POST['fee_percent'] ?? '2')),
                'fee_posting' => (string)($_POST['fee_posting'] ?? 'none'),
                'fee_move_type_id' => (int)($_POST['fee_move_type_id'] ?? 0),
                'fee_account_id' => (int)($_POST['fee_account_id'] ?? 0),
                'parser_preset' => trim((string)($_POST['parser_preset'] ?? '')),
                'pattern_period_from' => trim((string)($_POST['pattern_period_from'] ?? '')),
                'pattern_period_to' => trim((string)($_POST['pattern_period_to'] ?? '')),
                'pattern_gross' => trim((string)($_POST['pattern_gross'] ?? '')),
                'pattern_fee' => trim((string)($_POST['pattern_fee'] ?? '')),
                'expected_channels' => trim((string)($_POST['expected_channels'] ?? 'spa_ops')),
                'auto_set_supplier' => !empty($_POST['auto_set_supplier']) ? 1 : 0,
            ];

            if ($action === 'dry_run' || $action === 'apply_preset') {
                $probe = $data;
                if (class_exists('SuppliersRepository', false)) {
                    $s = SuppliersRepository::find($supplierId);
                    if ($s) {
                        $probe['supplier_inn'] = (string)($s['inn'] ?? '');
                        $probe['supplier_name'] = (string)($s['name'] ?? '');
                        $probe['supplier_ur_name'] = (string)($s['ur_name'] ?? '');
                    }
                }
                $dryRun = RemittanceReconcile::dryRun($probe, 5);
                $row = array_merge($row ?? [], $data);
                $message = t('remittances.flash.dry_run');
            } else {
                $newId = RemittanceAgreementsRepository::save($isNew ? null : $id, $data);
                RemittanceMatcher::clearCache();
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'id' => $newId,
                        'row' => RemittanceAgreementsRepository::find($newId),
                    ]);
                    return;
                }
                flash_set('ok', t('remittances.flash.saved'));
                redirect('/remittances/' . $newId . '/edit');
            }
        } catch (Throwable $e) {
            if ($wantsJson) {
                AgentHttp::error($e->getMessage(), 400);
            }
            $error = $e->getMessage();
            $row = array_merge($row ?? [], [
                'name' => (string)($_POST['name'] ?? ''),
                'enabled' => !empty($_POST['enabled']) ? 1 : 0,
                'supplier_id' => (int)($_POST['supplier_id'] ?? 0),
                'account_id' => (int)($_POST['account_id'] ?? 0),
                'type_id' => (int)($_POST['type_id'] ?? 0),
                'fee_percent' => (float)str_replace(',', '.', (string)($_POST['fee_percent'] ?? '2')),
                'fee_posting' => (string)($_POST['fee_posting'] ?? 'none'),
                'fee_move_type_id' => (int)($_POST['fee_move_type_id'] ?? 0),
                'fee_account_id' => (int)($_POST['fee_account_id'] ?? 0),
                'parser_preset' => trim((string)($_POST['parser_preset'] ?? '')),
                'pattern_period_from' => trim((string)($_POST['pattern_period_from'] ?? '')),
                'pattern_period_to' => trim((string)($_POST['pattern_period_to'] ?? '')),
                'pattern_gross' => trim((string)($_POST['pattern_gross'] ?? '')),
                'pattern_fee' => trim((string)($_POST['pattern_fee'] ?? '')),
                'expected_channels' => trim((string)($_POST['expected_channels'] ?? 'spa_ops')),
                'auto_set_supplier' => !empty($_POST['auto_set_supplier']) ? 1 : 0,
            ]);
        }
    }
}

$suppliers = class_exists('SuppliersRepository', false) ? SuppliersRepository::names(true) : [];
$accounts = class_exists('LedgerAccountsRepository', false) ? LedgerAccountsRepository::names(true) : [];
$types = class_exists('AccountMoveTypesRepository', false) ? AccountMoveTypesRepository::names(true) : [];
$presets = RemittanceParser::presets();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'row' => $row,
        'suppliers' => $suppliers,
        'accounts' => $accounts,
        'types' => $types,
        'presets' => array_keys($presets),
        'dry_run' => $dryRun,
    ]);
    return;
}

$flash = flash_take();
if ($flash && $message === '' && $error === '') {
    if (($flash['type'] ?? '') === 'ok') {
        $message = (string)$flash['message'];
    } else {
        $error = (string)$flash['message'];
    }
}

View::render('edit', [
    'title' => $isNew ? t('remittances.edit.title_new') : t('remittances.edit.title', ['id' => (string)$id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('remittances.menu.list'), 'href' => '/remittances'],
        [
            'label' => $isNew ? t('remittances.edit.title_new') : ('#' . $id),
            'current' => true,
        ],
    ]),
    'isNew' => $isNew,
    'row' => $row,
    'suppliers' => $suppliers,
    'accounts' => $accounts,
    'types' => $types,
    'presets' => $presets,
    'dryRun' => $dryRun,
    'message' => $message,
    'error' => $error,
    'canDelete' => !$isNew && Auth::can('remittances.delete'),
], 'layout', 'remittances');
