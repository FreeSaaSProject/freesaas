<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

$procentsOn = ModuleManager::isEnabled('procents');
$contractsOn = ModuleManager::isEnabled('contracts');
$ledgerOn = ModuleManager::isEnabled('ledger') && class_exists('LedgerAccountsRepository', false);

/**
 * @return array<string,mixed>
 */
$buildSettings = static function () use ($procentsOn, $contractsOn): array {
    $dp = DayPayroll::settings();
    $out = [
        'auto_procent_accrual' => (string)Config::get('payments', 'auto_procent_accrual', '1') === '1' ? '1' : '0',
        'day_pay_min_pay' => (int)$dp['min_pay'],
        'day_pay_payout_account_id' => (int)$dp['account_id'],
        'day_pay_issuer_status2' => (int)$dp['issuer_status2'],
        'procents_enabled' => $procentsOn,
        'contracts_enabled' => $contractsOn,
    ];
    if ($contractsOn && class_exists('ContractsRepository', false)) {
        $out['deadline_penalty'] = ContractsRepository::deadlinePenaltyEnabled() ? '1' : '0';
    }
    return $out;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (array_key_exists('auto_procent_accrual', $_POST)) {
            Config::set('payments', 'auto_procent_accrual', !empty($_POST['auto_procent_accrual']) ? '1' : '0');
        }
        if (array_key_exists('day_pay_min_pay', $_POST)) {
            Config::set('payments', 'day_pay_min_pay', (string)max(0, (int)$_POST['day_pay_min_pay']));
        }
        if (array_key_exists('day_pay_payout_account_id', $_POST)) {
            Config::set('payments', 'day_pay_payout_account_id', (string)max(0, (int)$_POST['day_pay_payout_account_id']));
        }
        if (array_key_exists('day_pay_issuer_status2', $_POST)) {
            Config::set('payments', 'day_pay_issuer_status2', (string)max(0, (int)$_POST['day_pay_issuer_status2']));
        }
        if ($contractsOn && array_key_exists('deadline_penalty', $_POST)) {
            Config::set('contracts', 'deadline_penalty', !empty($_POST['deadline_penalty']) ? '1' : '0');
        }

        $message = t('core.common.saved');
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
                'settings' => $buildSettings(),
            ]);
            return;
        }
    } catch (Throwable $e) {
        AgentHttp::rethrowIfCapture($e);
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$settings = $buildSettings();

$statusOptions = [0 => '—'];
foreach (StatusesRepository::listAll() as $st) {
    $sid = (int)($st['ID'] ?? 0);
    if ($sid < 1) {
        continue;
    }
    $statusOptions[$sid] = trim((string)($st['NAME'] ?? '')) !== ''
        ? (string)$st['NAME']
        : ('#' . $sid);
}

$accountOptions = [0 => '—'];
if ($ledgerOn) {
    foreach (LedgerAccountsRepository::names(true) as $aid => $aname) {
        $accountOptions[(int)$aid] = $aname;
    }
    $curAcc = (int)($settings['day_pay_payout_account_id'] ?? 0);
    if ($curAcc > 0 && !isset($accountOptions[$curAcc])) {
        $accountOptions[$curAcc] = '#' . $curAcc;
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'settings' => $settings,
        'status_options' => $statusOptions,
        'account_options' => $accountOptions,
    ]);
    return;
}

View::render('settings', [
    'title' => t('payments.settings.title'),
    'message' => $message,
    'error' => $error,
    'settings' => $settings,
    'procentsOn' => $procentsOn,
    'contractsOn' => $contractsOn,
    'ledgerOn' => $ledgerOn,
    'statusOptions' => $statusOptions,
    'accountOptions' => $accountOptions,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('payments.menu.list'), 'href' => '/payments'],
        ['label' => t('payments.settings.title'), 'current' => true],
    ]),
], 'layout', 'payments');
