<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EventBus.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/ModuleManager.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/View.php';
require_once __DIR__ . '/ListQuery.php';
require_once __DIR__ . '/BulkActions.php';
require_once __DIR__ . '/SchemaMigrator.php';
require_once __DIR__ . '/DataMigrator.php';
require_once __DIR__ . '/StageTiming.php';

StageTiming::reset(microtime(true));

Config::load(base_path('cfg/config.ini'));
StageTiming::mark('Config');

$tz = (string)Config::get('app', 'timezone', 'UTC');
@date_default_timezone_set($tz);

Auth::startSession();
I18n::bootFromRequest();
ModuleManager::discover();
ModuleManager::bootEnabled();
StageTiming::mark('Bootstrap & modules');

try {
    if (Database::tableExists('base_modules')) {
        ModuleManager::syncRegistry();
    }
} catch (Throwable $e) {
    // DB may not be ready before first schema migrate
    error_log('[bootstrap] ' . $e->getMessage());
}
