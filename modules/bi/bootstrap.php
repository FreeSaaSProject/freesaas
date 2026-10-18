<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ReportProgress.php';
require_once __DIR__ . '/lib/ReportParams.php';
require_once __DIR__ . '/lib/ReportFamily.php';
require_once __DIR__ . '/lib/ReportRenderer.php';
require_once __DIR__ . '/lib/BiSharesRepository.php';
require_once __DIR__ . '/lib/BiDatasetCatalog.php';
require_once __DIR__ . '/lib/BiExpr.php';
require_once __DIR__ . '/lib/BiSafeHtml.php';
require_once __DIR__ . '/lib/BiQueryBuilder.php';
require_once __DIR__ . '/lib/BiText.php';
require_once __DIR__ . '/lib/BiRecipeEngine.php';
require_once __DIR__ . '/lib/BiRecipeValidator.php';
require_once __DIR__ . '/lib/RecipeFamily.php';
require_once __DIR__ . '/lib/BiFamilyDefsRepository.php';
require_once __DIR__ . '/lib/BiShippedRecipes.php';
require_once __DIR__ . '/lib/FamilyValidator.php';
require_once __DIR__ . '/lib/ReportRegistry.php';
require_once __DIR__ . '/lib/BiShiftLanes.php';
require_once __DIR__ . '/lib/BiFilterOptions.php';

EventBus::on('assistant.system.hints', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    $ids = is_array($payload['op_ids'] ?? null) ? $payload['op_ids'] : [];
    $hints = is_array($payload['hints'] ?? null) ? $payload['hints'] : [];
    if (isset($ids['bi.families.list']) || isset($ids['bi.report.run'])) {
        $hints[] = t('bi.assistant.hint');
        $hasShare = false;
        foreach ($ids as $id => $_true) {
            if (strpos((string)$id, 'bi.share') === 0) {
                $hasShare = true;
                break;
            }
        }
        if (!$hasShare) {
            $hints[] = t('bi.assistant.hint_no_shares');
        }
    }
    $payload['hints'] = $hints;
    return $payload;
});

Assets::register('bi', [
    'css' => ['bi.css'],
    'js' => ['bi.js'],
]);

ModuleManager::registerMenuGroup('bi.menu.group', 600);

// /bi /bi/run /bi/work: any logged-in staff (shared reports); manage tools stay bi.manage.
$pages = [
    '/bi' => ['methods' => ['GET'], 'file' => 'index.php'],
    '/bi/run' => ['methods' => ['GET', 'POST'], 'file' => 'run.php'],
    '/bi/work' => ['methods' => ['GET', 'POST'], 'file' => 'work.php'],
    '/bi/shares' => ['permission' => 'bi.manage', 'methods' => ['GET', 'POST'], 'file' => 'shares.php'],
    '/bi/legend' => ['permission' => 'bi.manage', 'methods' => ['GET'], 'file' => 'legend.php'],
    '/bi/families/new' => ['permission' => 'bi.manage', 'methods' => ['GET', 'POST'], 'file' => 'family_edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'bi',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/bi/pages/' . $file);
        },
    ];
    if (isset($def['permission'])) {
        $route['permission'] = $def['permission'];
    }
    ModuleManager::registerRoute($path, $route);
}

ModuleManager::registerRoute('/bi/families/{id}', [
    'module' => 'bi',
    'methods' => ['GET', 'POST'],
    'permission' => 'bi.manage',
    'handler' => static function (): void {
        require base_path('modules/bi/pages/family_edit.php');
    },
]);

ModuleManager::registerRoute('/bi/s/{token}', [
    'module' => 'bi',
    'methods' => ['GET', 'POST'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/bi/pages/share_view.php');
    },
]);

Cron::register('bi.shares_expire', [
    'module' => 'bi',
    'label' => 'bi.cron.expire',
    'every' => 60,
    'handler' => static function (): array {
        $n = BiSharesRepository::expireDue();
        return ['ok' => true, 'processed' => $n];
    },
]);
