<?php
declare(strict_types=1);

$error = '';
$afterLogin = static function (): void {
    $ret = NavContext::returnUrl();
    redirect($ret !== '' ? $ret : '/');
};

if (Auth::check()) {
    $afterLogin();
}

// Session ended (idle / auth_epoch / …) — show why, then clear (GET only; POST has own errors).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $reason = (string)($_SESSION['auth_redirect_reason'] ?? '');
    unset($_SESSION['auth_redirect_reason']);
    $reasonMap = [
        'idle' => 'core.login.session.idle',
        'auth_epoch' => 'core.login.session.auth_epoch',
        'disabled' => 'core.login.session.disabled',
        'expired' => 'core.login.session.expired',
        'user_missing' => 'core.login.session.user_missing',
        'session_cleared' => 'core.login.session.cleared',
    ];
    if ($reason !== '' && isset($reasonMap[$reason])) {
        $error = t($reasonMap[$reason]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    unset($_SESSION['auth_redirect_reason']);
    $login = (string)($_POST['login'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    // Default: long session. Checkbox "ephemeral" = shared device / do not remember.
    $remember = !(isset($_POST['ephemeral']) && (string)$_POST['ephemeral'] !== '0');
    if (!Database::tableExists('base_users')) {
        $error = t('core.login.error.no_table');
    } else {
        $status = Auth::attemptStatus($login, $password, $remember);
        if ($status === 'ok') {
            $afterLogin();
        }
        $map = [
            'locked' => 'core.login.error.locked',
            'expired' => 'core.login.error.expired',
            'disabled' => 'core.login.error.disabled',
            'bad_credentials' => 'core.login.error.bad_credentials',
        ];
        $error = t($map[$status] ?? 'core.login.error.bad_credentials');
    }
}

$brand = (string)Config::get('app', 'name', 'FreeSaaS');
$instance = SystemRepository::companyName();
View::render('login', [
    'title' => t('core.login.title'),
    'error' => $error,
    'brand' => $brand,
    'instance' => $instance,
], 'layout_auth');
