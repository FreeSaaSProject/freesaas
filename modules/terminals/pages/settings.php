<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        TerminalsConfig::saveFromPost($_POST);
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$cfg = TerminalsConfig::formValues();
$terminalOptions = TerminalsRepository::names();
$adminId = (int)($cfg['booking_admin_id'] ?? 0);
$staffOptions = class_exists(UsersRepository::class, false)
    ? UsersRepository::staffNamesIncluding($adminId > 0 ? [$adminId] : [])
    : [];

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'settings' => $cfg,
        'terminal_options' => $terminalOptions,
        'staff_options' => $staffOptions,
        'hint' => 'ignore_staff_shift_windows=1 (default): /t and floor book ignore staff PRICE_COL end '
            . '(still need day roster + salon hours). Site, MCP, legacy API always clip. '
            . 'Set 0 to match public booking.',
    ]);
    return;
}

View::render('settings', [
    'title' => t('terminals.settings.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('terminals.menu.list'), 'href' => '/terminals'],
        ['label' => t('terminals.menu.settings'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
    'terminalOptions' => $terminalOptions,
    'staffOptions' => $staffOptions,
], 'layout', 'terminals');
