<?php
/** @var string $content */
/** @var string $title */
$brand = (string)Config::get('app', 'name', 'FreeSaaS');
$instance = (string)Config::get('app', 'instance', '');
$menuGrouped = ModuleManager::menuGrouped();
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
  <title><?= e($title ?? t('core.menu.home')) ?> · <?= e($brand) ?></title>
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
  <link rel="stylesheet" href="/assets/css/crm.css?v=<?= (int)@filemtime(base_path('assets/css/crm.css')) ?>">
</head>
<body class="app-shell" data-rail="ready">
  <header class="topbar" id="topbar">
    <button type="button" class="topbar-menu" id="nav-open" aria-label="<?= te('core.layout.open_menu') ?>" aria-controls="rail" aria-expanded="false">
      <span class="topbar-menu-icon" aria-hidden="true"></span>
    </button>
    <a class="topbar-brand" href="/"><?= e($brand) ?></a>
    <?php if ($instance !== ''): ?>
      <span class="topbar-instance"><?= e($instance) ?></span>
    <?php endif; ?>
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
      <a class="rail-brand" href="/"><?= e($brand) ?></a>
      <button type="button" class="rail-toggle" id="rail-toggle" title="<?= te('core.layout.hide_menu') ?>" aria-label="<?= te('core.layout.hide_menu') ?>">‹</button>
      <button type="button" class="rail-close" id="nav-close" title="<?= te('core.layout.close_menu') ?>" aria-label="<?= te('core.layout.close_menu') ?>">×</button>
    </div>
    <?php if ($instance !== ''): ?>
      <div class="rail-instance"><?= e($instance) ?></div>
    <?php endif; ?>
    <nav class="rail-nav">
      <?php foreach ($menuGrouped as $group => $items): ?>
        <div class="rail-group">
          <div class="rail-group-title"><?= e($group) ?></div>
          <?php foreach ($items as $item): ?>
            <?php
              $itemPath = parse_url((string)$item['path'], PHP_URL_PATH);
              $itemPath = is_string($itemPath) && $itemPath !== '' ? $itemPath : (string)$item['path'];
              $active = ($path === $itemPath)
                || ($itemPath !== '/' && strpos($path, rtrim($itemPath, '/') . '/') === 0);
            ?>
            <a href="<?= e($item['path']) ?>"<?= $active ? ' class="is-active"' : '' ?> title="<?= e($item['title']) ?>">
              <span class="rail-link-text"><?= e($item['title']) ?></span>
            </a>
          <?php endforeach; ?>
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
  <script src="/assets/js/crm.js?v=<?= (int)@filemtime(base_path('assets/js/crm.js')) ?>"></script>
</body>
</html>
