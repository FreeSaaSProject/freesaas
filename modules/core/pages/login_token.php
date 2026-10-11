<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/LoginLinksRepository.php');

$error = '';
$token = trim((string)(Router::param('token') ?? ''));

if (Auth::check()) {
    redirect('/');
}

if (!Database::tableExists('base_user_login_links')) {
    $error = t('core.login.error.no_table');
} else {
    try {
        $user = LoginLinksRepository::consume($token);
        if ($user === null) {
            $error = t('core.login.error.link_invalid');
        } else {
            Auth::unlockUser((int)$user['id']);
            Auth::establishSession($user, true);
            redirect('/');
        }
    } catch (Throwable $e) {
        error_log('[login_token] ' . $e->getMessage());
        $error = t('core.login.error.link_invalid');
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
