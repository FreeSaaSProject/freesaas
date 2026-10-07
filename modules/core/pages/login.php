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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $login = (string)($_POST['login'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if (!Database::tableExists('base_users')) {
        $error = t('core.login.error.no_table');
    } elseif (Auth::attempt($login, $password)) {
        $afterLogin();
    } else {
        $error = t('core.login.error.bad_credentials');
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
