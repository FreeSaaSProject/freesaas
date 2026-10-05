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

$jsI18n = I18n::jsDict([
    'crm.reply_hint',
    'crm.link_prompt',
    'core.layout.nav_loading',
    'core.layout.close_menu',
    'core.layout.menu_expand_close',
]);

$layoutGate = ['blocked' => false, 'html' => '', 'chrome' => '', 'js_i18n' => []];
if (Auth::check()) {
    $gatePayload = EventBus::emit('core.layout.gate', $layoutGate);
    if (is_array($gatePayload)) {
        $layoutGate = array_merge($layoutGate, $gatePayload);
    }
    if (!empty($layoutGate['js_i18n']) && is_array($layoutGate['js_i18n'])) {
        foreach ($layoutGate['js_i18n'] as $k => $v) {
            $jsI18n[(string)$k] = (string)$v;
        }
    }
}
$gateBlocked = !empty($layoutGate['blocked']);
$gateHtml = (string)($layoutGate['html'] ?? '');
// Prefer generic `chrome`; accept legacy `topbar` from early listeners.
$gateChrome = (string)($layoutGate['chrome'] ?? '');
if ($gateChrome === '') {
    $gateChrome = (string)($layoutGate['topbar'] ?? '');
}
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
<?php
  $railPeek = false;
  if (Auth::check() && class_exists('UserPrefsRepository', false)) {
      $railPeek = UserPrefsRepository::get(Auth::userId(), 'rail.peek') === '1';
  }
?>
<body class="app-shell<?= $gateBlocked ? ' is-layout-gate' : '' ?>" data-rail="ready" data-rail-peek="<?= $railPeek ? '1' : '0' ?>">
  <header class="topbar" id="topbar">
    <button type="button" class="topbar-menu" id="nav-open" aria-label="<?= te('core.layout.open_menu') ?>" aria-controls="rail" aria-expanded="false">
      <span class="topbar-menu-icon" aria-hidden="true"></span>
    </button>
    <?php $brandClass = 'topbar-brand'; require __DIR__ . '/_brand.php'; ?>
    <div class="topbar-spacer"></div>
    <?php if ($gateChrome !== ''): ?>
      <div class="topbar-extra"><?= $gateChrome ?></div>
    <?php endif; ?>
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

<?php if ($gateBlocked): ?>
  <div class="workspace workspace-gate">
    <main class="main main-flush">
      <?= $gateHtml ?>
    </main>
  </div>
<?php else: ?>
  <aside class="rail" id="rail">
    <div class="rail-top">
      <?php $brandClass = 'rail-brand'; require __DIR__ . '/_brand.php'; ?>
      <button type="button" class="rail-toggle" id="rail-toggle" title="<?= te('core.layout.hide_menu') ?>" aria-label="<?= te('core.layout.hide_menu') ?>">‹</button>
      <button type="button" class="rail-close" id="nav-close" title="<?= te('core.layout.close_menu') ?>" aria-label="<?= te('core.layout.close_menu') ?>">×</button>
    </div>
    <nav class="rail-nav" id="rail-nav">
      <?php
        // Merge by translated label so duplicate section titles never appear.
        $railGroups = [];
        $groupIndexByLabel = [];
        foreach ($menuItemsFlat as $item) {
            $groupLabel = (string)$item['group'];
            $groupKey = (string)($item['group_key'] ?? $groupLabel);
            $itemPath = parse_url((string)$item['path'], PHP_URL_PATH);
            $itemPath = is_string($itemPath) && $itemPath !== '' ? $itemPath : (string)$item['path'];
            $itemPath = rtrim($itemPath, '/') ?: '/';
            $matches = ($path === $itemPath)
                || ($itemPath !== '/' && strpos($path, $itemPath . '/') === 0);
            if (!isset($groupIndexByLabel[$groupLabel])) {
                $groupIndexByLabel[$groupLabel] = count($railGroups);
                $railGroups[] = [
                    'key' => $groupKey,
                    'label' => $groupLabel,
                    'has_active' => false,
                    'items' => [],
                ];
            }
            $idx = $groupIndexByLabel[$groupLabel];
            $railGroups[$idx]['items'][] = [
                'path' => (string)$item['path'],
                'path_key' => $itemPath,
                'title' => (string)$item['title'],
                'matches' => $matches,
                'active' => false,
            ];
        }
        // Only the most specific matching item in a group is highlighted
        // (so /calendar/settings does not also light up /calendar).
        foreach ($railGroups as &$g) {
            $bestIdx = null;
            $bestLen = -1;
            foreach ($g['items'] as $i => $link) {
                if (empty($link['matches'])) {
                    continue;
                }
                $g['has_active'] = true;
                $len = strlen((string)$link['path_key']);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $bestIdx = $i;
                }
            }
            if ($bestIdx !== null) {
                $g['items'][$bestIdx]['active'] = true;
            }
        }
        unset($g);
        $openIndex = null;
        foreach ($railGroups as $i => $g) {
            if ($g['has_active']) {
                $openIndex = $i;
                break;
            }
        }
        foreach ($railGroups as $i => $g):
            $isOpen = ($openIndex !== null && $i === $openIndex);
            $homeHref = null;
            $homeActive = false;
            $homeCandidate = ModuleManager::menuGroupHome((string)$g['key']);
            if ($homeCandidate !== null && $homeCandidate !== '') {
                $homePath = parse_url($homeCandidate, PHP_URL_PATH);
                $homePath = is_string($homePath) && $homePath !== '' ? $homePath : $homeCandidate;
                $homePath = rtrim($homePath, '/') ?: '/';
                foreach ($g['items'] as $link) {
                    if ((string)$link['path_key'] === $homePath) {
                        $homeHref = (string)$link['path'];
                        // Section title is active only on the home screen itself.
                        $homeActive = !empty($link['active']);
                        break;
                    }
                }
            }
      ?>
        <div class="rail-group<?= $isOpen ? ' is-open' : '' ?>" data-group-key="<?= e($g['key']) ?>">
          <div class="rail-group-bar">
            <?php if ($homeHref !== null): ?>
              <a class="rail-group-home<?= $homeActive ? ' is-active' : '' ?>"
                 href="<?= e($homeHref) ?>"
                 title="<?= e($g['label']) ?>">
                <span class="rail-group-title"><?= e($g['label']) ?></span>
              </a>
              <button type="button"
                      class="rail-group-toggle"
                      aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
                      aria-label="<?= te('core.layout.menu_group_toggle', ['group' => $g['label']]) ?>">
                <span class="rail-group-chevron" aria-hidden="true"></span>
              </button>
            <?php else: ?>
              <button type="button"
                      class="rail-group-toggle rail-group-toggle-full"
                      aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
                      aria-label="<?= te('core.layout.menu_group_toggle', ['group' => $g['label']]) ?>">
                <span class="rail-group-title"><?= e($g['label']) ?></span>
                <span class="rail-group-chevron" aria-hidden="true"></span>
              </button>
            <?php endif; ?>
          </div>
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
    <button type="button" class="rail-expand-all" id="rail-expand-all">
      <?= te('core.layout.menu_expand_all') ?>
    </button>
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
          <?php if ($gateChrome !== ''): ?>
            <div class="rail-extra"><?= $gateChrome ?></div>
          <?php endif; ?>
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
<?php endif; ?>
  <script src="/assets/js/core.js?v=<?= (int)@filemtime(base_path('assets/js/core.js')) ?>"></script>
  <?= Assets::jsTags() ?>
</body>
</html>
