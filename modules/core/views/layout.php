<?php
/** @var string $content */
/** @var string $title */
$brand = (string)Config::get('app', 'name', 'FreeSaaS');
$instance = SystemRepository::companyName();
$titleSuffix = $instance !== '' ? $instance : $brand;
$menuItemsFlat = ModuleManager::menuItems();
$path = request_path();
$userName = Auth::check() ? Auth::displayName() : '';
$userId = Auth::check() ? Auth::userId() : 0;
$htmlLang = I18n::locale();

$notices = [];
if (Auth::check()) {
    $tNotices = microtime(true);
    $notices = EventBus::emit('core.notices.collect', []);
    if (!is_array($notices)) {
        $notices = [];
    }
    if (class_exists('StageTiming', false)) {
        StageTiming::mark('Notices', microtime(true) - $tNotices);
    }
}
$timingTotal = class_exists('StageTiming', false) ? StageTiming::total() : 0;
$timingHtml = class_exists('StageTiming', false) ? StageTiming::reportHtml() : '';

$permFooter = null;
if ($userId > 0) {
    if (!Auth::hasNamespacedPermissions()) {
        $permFooter = 'setup';
    } elseif (Auth::isSuperAdmin()) {
        $routePerm = Router::matchedPermission();
        if ($routePerm !== null && !Auth::hasPersonalPermission($routePerm)) {
            $permFooter = 'bypass';
        }
    }
}

$jsI18n = I18n::jsDict(['crm.reply_hint', 'crm.link_prompt']);
?>
<!DOCTYPE html>
<html lang="<?= e($htmlLang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? t('core.menu.home')) ?> · <?= e($titleSuffix) ?></title>
  <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
  <link rel="icon" href="/assets/favicon-32.png" type="image/png" sizes="32x32">
  <link rel="shortcut icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png" sizes="180x180">
  <script>
  (function () {
    try {
      var k = 'freesaas.theme';
      var m = localStorage.getItem(k) || 'system';
      var dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      var t = m === 'system' ? (dark ? 'dark' : 'light') : m;
      document.documentElement.setAttribute('data-theme', t);
      document.documentElement.setAttribute('data-theme-mode', m);
    } catch (e) {
      document.documentElement.setAttribute('data-theme', 'dark');
      document.documentElement.setAttribute('data-theme-mode', 'system');
    }
  })();
  window.FREESAAS_I18N = <?= json_encode($jsI18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,700;1,9..40,400&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/core.css?v=<?= (int)@filemtime(base_path('assets/css/core.css')) ?>">
  <?= Assets::cssTags() ?>
</head>
<body class="app-shell" data-rail="ready">
  <header class="topbar" id="topbar">
    <button type="button" class="topbar-menu" id="nav-open" aria-label="<?= te('core.layout.open_menu') ?>" aria-controls="rail" aria-expanded="false">
      <span class="topbar-menu-icon" aria-hidden="true"></span>
    </button>
    <?php $brandClass = 'topbar-brand'; require __DIR__ . '/_brand.php'; ?>
    <div class="topbar-spacer"></div>
    <?php require __DIR__ . '/_lang_switch.php'; ?>
    <div class="theme-switch" data-theme-switch role="group" aria-label="<?= te('core.layout.theme') ?>">
      <button type="button" data-theme-set="light" title="<?= te('core.layout.theme_light') ?>" aria-label="<?= te('core.layout.theme_light_aria') ?>">☀</button>
      <button type="button" data-theme-set="dark" title="<?= te('core.layout.theme_dark') ?>" aria-label="<?= te('core.layout.theme_dark_aria') ?>">☾</button>
      <button type="button" data-theme-set="system" title="<?= te('core.layout.theme_system') ?>" aria-label="<?= te('core.layout.theme_system_aria') ?>">◐</button>
    </div>
    <?php if ($userId > 0): ?>
      <?= UsersRepository::badgeHtml($userId, true) ?>
      <a class="topbar-logout" href="/logout"><?= te('core.layout.logout') ?></a>
    <?php endif; ?>
  </header>

  <div class="nav-backdrop" id="nav-backdrop" hidden></div>

  <aside class="rail" id="rail">
    <div class="rail-top">
      <?php $brandClass = 'rail-brand'; require __DIR__ . '/_brand.php'; ?>
      <button type="button" class="rail-toggle" id="rail-toggle" title="<?= te('core.layout.hide_menu') ?>" aria-label="<?= te('core.layout.hide_menu') ?>">‹</button>
      <button type="button" class="rail-close" id="nav-close" title="<?= te('core.layout.close_menu') ?>" aria-label="<?= te('core.layout.close_menu') ?>">×</button>
    </div>
    <nav class="rail-nav" id="rail-nav">
      <?php
        $railGroups = [];
        foreach ($menuItemsFlat as $item) {
            $groupLabel = (string)$item['group'];
            $groupKey = (string)($item['group_key'] ?? $groupLabel);
            $itemPath = parse_url((string)$item['path'], PHP_URL_PATH);
            $itemPath = is_string($itemPath) && $itemPath !== '' ? $itemPath : (string)$item['path'];
            $active = ($path === $itemPath)
                || ($itemPath !== '/' && strpos($path, rtrim($itemPath, '/') . '/') === 0);
            $last = count($railGroups) - 1;
            if ($last < 0 || $railGroups[$last]['label'] !== $groupLabel) {
                $railGroups[] = [
                    'key' => $groupKey,
                    'label' => $groupLabel,
                    'has_active' => false,
                    'items' => [],
                ];
                $last = count($railGroups) - 1;
            }
            if ($active) {
                $railGroups[$last]['has_active'] = true;
            }
            $railGroups[$last]['items'][] = [
                'path' => (string)$item['path'],
                'title' => (string)$item['title'],
                'active' => $active,
            ];
        }
        $openIndex = null;
        foreach ($railGroups as $i => $g) {
            if ($g['has_active']) {
                $openIndex = $i;
                break;
            }
        }
        foreach ($railGroups as $i => $g):
            $isOpen = ($openIndex !== null && $i === $openIndex);
      ?>
        <div class="rail-group<?= $isOpen ? ' is-open' : '' ?>" data-group-key="<?= e($g['key']) ?>">
          <button type="button"
                  class="rail-group-toggle"
                  aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
                  aria-label="<?= te('core.layout.menu_group_toggle', ['group' => $g['label']]) ?>">
            <span class="rail-group-title"><?= e($g['label']) ?></span>
            <span class="rail-group-chevron" aria-hidden="true"></span>
          </button>
          <div class="rail-group-body">
            <?php foreach ($g['items'] as $link): ?>
              <a href="<?= e($link['path']) ?>"<?= $link['active'] ? ' class="is-active"' : '' ?> title="<?= e($link['title']) ?>">
                <span class="rail-link-text"><?= e($link['title']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="rail-foot">
      <div class="rail-foot-switches">
        <?php
          $langSwitchClass = 'lang-switch lang-switch-rail';
          require __DIR__ . '/_lang_switch.php';
        ?>
        <div class="theme-switch theme-switch-rail" data-theme-switch role="group" aria-label="<?= te('core.layout.theme') ?>">
          <button type="button" data-theme-set="light" title="<?= te('core.layout.theme_light') ?>" aria-label="<?= te('core.layout.theme_light_aria') ?>">☀</button>
          <button type="button" data-theme-set="dark" title="<?= te('core.layout.theme_dark') ?>" aria-label="<?= te('core.layout.theme_dark_aria') ?>">☾</button>
          <button type="button" data-theme-set="system" title="<?= te('core.layout.theme_system') ?>" aria-label="<?= te('core.layout.theme_system_aria') ?>">◐</button>
        </div>
      </div>
      <?php if ($userId > 0): ?>
        <div class="rail-user">
          <?= UsersRepository::badgeHtml($userId, true) ?>
          <a class="rail-logout" href="/logout"><?= te('core.layout.logout') ?></a>
        </div>
      <?php endif; ?>
    </div>
  </aside>
  <button type="button" class="rail-expand" id="rail-expand" title="<?= te('core.layout.show_menu') ?>" aria-label="<?= te('core.layout.show_menu') ?>" hidden>›</button>
  <div class="workspace">
    <?php if ($notices): ?>
      <nav class="notices-bar" aria-label="<?= te('core.layout.notices_aria') ?>">
        <?php foreach ($notices as $n): ?>
          <a class="notice-chip tone-<?= e((string)($n['tone'] ?? 'info')) ?>"
             href="<?= e((string)($n['href'] ?? '#')) ?>"
             title="<?= e((string)($n['title'] ?? '')) ?>"><?= e((string)($n['short'] ?? '')) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <main class="main<?= !empty($main_flush) ? ' main-flush' : '' ?>">
      <?php Breadcrumbs::renderInLayout(isset($title) && is_string($title) ? $title : ''); ?>
      <?= $content ?>
    </main>
    <footer class="app-footer">
      <?php if ($permFooter === 'setup'): ?>
        <div class="perm-setup-warn" role="status">
          <?= te('core.layout.perms_empty') ?>
          <a href="/users/<?= (int)$userId ?>/edit"><?= te('core.layout.perms_empty_link') ?></a><?php if (Auth::isSuperAdmin()): ?>
          <?= te('core.layout.perms_empty_hint_sa') ?><?php endif; ?>
        </div>
      <?php elseif ($permFooter === 'bypass'): ?>
        <div class="perm-setup-warn" role="status">
          <?= te('core.layout.sa_bypass') ?>
          <code><?= e((string)Router::matchedPermission()) ?></code> <?= te('core.layout.sa_bypass_off') ?>
          <a href="/users/<?= (int)$userId ?>/edit"><?= te('core.layout.sa_bypass_link') ?></a>.
        </div>
      <?php endif; ?>
      <details class="timing-panel">
        <summary><?= e(t('core.layout.timings', ['sec' => sprintf('%.3f', $timingTotal)])) ?></summary>
        <?= $timingHtml ?>
      </details>
    </footer>
  </div>
  <script src="/assets/js/core.js?v=<?= (int)@filemtime(base_path('assets/js/core.js')) ?>"></script>
  <?= Assets::jsTags() ?>
</body>
</html>
