<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/DashboardWidgets.php');
require_once base_path('modules/core/lib/UserPrefsRepository.php');
require_once base_path('modules/core/lib/HomeSplash.php');

$brand = (string)Config::get('app', 'name', 'FreeSaaS');
$instance = SystemRepository::companyName();
$uid = Auth::userId();

if ($uid > 0 && !empty($_GET['splash_reset'])) {
    HomeSplash::reset($uid);
    redirect('/');
}

$showSplash = HomeSplash::shouldShow($uid);
$splashHasAsk = $showSplash && HomeSplash::hasAsk();
if ($showSplash) {
    Assets::need('core');
    if ($splashHasAsk) {
        Assets::need('assistant');
    }
}

StageTiming::mark('Home: widgets');
$catalog = DashboardWidgets::catalog();
$layout = DashboardWidgets::resolveLayout($uid, $catalog);
DashboardWidgets::setContext($layout);
try {
    $widgets = EventBus::emit('core.dashboard.widgets', []);
    if (!is_array($widgets)) {
        $widgets = [];
    }
    $widgets = DashboardWidgets::filterAndSort($widgets, $layout);
} finally {
    DashboardWidgets::clearContext();
}

View::render('home', [
    'title' => t('core.menu.home'),
    'brand' => $brand,
    'instance' => $instance,
    'userName' => Auth::displayName(),
    'widgets' => $widgets,
    'show_splash' => $showSplash,
    'splash_version' => HomeSplash::currentVersion(),
    'splash_has_ask' => $splashHasAsk,
]);
