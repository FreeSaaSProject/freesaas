<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/NewsRepository.php');
require_once base_path('modules/core/lib/UsersRepository.php');

$brand = (string)Config::get('app', 'name', 'FreeSaaS');
$instance = (string)Config::get('app', 'instance', '');
$uid = Auth::userId();

StageTiming::mark('Home: widgets');
$widgets = EventBus::emit('core.dashboard.widgets', []);
if (!is_array($widgets)) {
    $widgets = [];
}

$commands = [];
foreach (ModuleManager::menuItems() as $item) {
    $commands[] = [
        'path' => $item['path'],
        'title' => $item['title'],
        'group' => $item['group'],
    ];
}

$statuses = NewsRepository::statuses();
$counts = NewsRepository::countsByType($uid);
$columns = [];
$allIds = [];
foreach (NewsRepository::typeTabs() as $tab) {
    $tid = (int)$tab['id'];
    if ((int)($counts[$tid] ?? 0) < 1) {
        continue;
    }
    StageTiming::mark('Home: feed type ' . $tid);
    $result = NewsRepository::list([
        'type' => $tid,
        'limit' => 8,
        'user_id' => $uid,
    ]);
    foreach ($result['items'] as $n) {
        $allIds[] = (int)$n['ID'];
    }
    $columns[] = [
        'type' => $tid,
        'label' => (string)$tab['label'],
        'add' => $tab['add'] ?? null,
        'addType' => $tab['addType'] ?? null,
        'items' => $result['items'],
        'total' => $result['total'],
    ];
}

View::render('home', [
    'title' => t('core.menu.home'),
    'brand' => $brand,
    'instance' => $instance,
    'userName' => Auth::displayName(),
    'widgets' => $widgets,
    'commands' => $commands,
    'columns' => $columns,
    'statuses' => $statuses,
    'commentCounts' => NewsRepository::commentCounts($allIds),
    'viewerCounts' => NewsRepository::viewerCounts($allIds),
]);
