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
require_once __DIR__ . '/Assets.php';
require_once __DIR__ . '/ListQuery.php';
require_once __DIR__ . '/NavContext.php';
require_once __DIR__ . '/BulkActions.php';
require_once __DIR__ . '/SchemaMigrator.php';
require_once __DIR__ . '/DbTools.php';
require_once __DIR__ . '/DataMigrator.php';
require_once __DIR__ . '/StageTiming.php';
require_once __DIR__ . '/Cli.php';
require_once __DIR__ . '/Health.php';
require_once __DIR__ . '/ModuleValidator.php';
require_once __DIR__ . '/ModuleCatalog.php';
require_once __DIR__ . '/AgentOps.php';
require_once __DIR__ . '/AgentAuth.php';
require_once __DIR__ . '/AgentHttpCaptureSignal.php';
require_once __DIR__ . '/AgentHttp.php';
require_once __DIR__ . '/UpdateEngine.php';

// Status flags registry used by product modules during boot (before core routes).
require_once dirname(__DIR__) . '/modules/core/lib/StatusesRepository.php';
require_once dirname(__DIR__) . '/modules/core/lib/UsersRepository.php';

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
