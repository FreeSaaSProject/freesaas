<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $cards */
/** @var list<array{id:string,cards:list<array<string,mixed>>}> $sections */
/** @var list<array<string,mixed>> $installable */
/** @var bool $updatesConfigured */
/** @var bool $canInstall */
/** @var array{q:string,category:string,tag:string,status:string,maturity:string} $filters */
/** @var list<string> $categories */
/** @var list<string> $tags */
/** @var list<string> $maturities */
/** @var string $listPath */
/** @var string $message */
/** @var string $error */

$installable = is_array($installable ?? null) ? $installable : [];
/** @var list<array<string,mixed>> $dismissedInstallable */
$dismissedInstallable = is_array($dismissedInstallable ?? null) ? $dismissedInstallable : [];
$updatesConfigured = !empty($updatesConfigured);
$canInstall = !empty($canInstall);

$modLabel = static function (string $modId): string {
    $meta = ModuleManager::get($modId);
    if ($meta === null) {
        return $modId;
    }
    return t((string)($meta['name'] ?? $modId));
};

$showNav = count($sections) > 1 || $installable !== [];
?>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if ($canInstall && $updatesConfigured): ?>
  <div class="modules-catalog-bar">
    <form method="post" action="/modules" class="inline-form">
      <input type="hidden" name="action" value="refresh_catalog">
      <input type="hidden" name="ret" value="<?= e($listPath) ?>">
      <button class="btn-secondary btn-sm" type="submit"><?= te('core.modules.btn_refresh_catalog') ?></button>
    </form>
  </div>
<?php elseif ($canInstall && !$updatesConfigured): ?>
  <p class="muted modules-catalog-hint">
    <?= te('core.modules.catalog_configure') ?>
    — <a href="/updates/settings"><?= te('core.modules.catalog_configure_link') ?></a>
  </p>
<?php endif; ?>

<form method="get" action="/modules" class="modules-filters" data-modules-filters>
  <label class="modules-filter-q">
    <span class="muted"><?= te('core.modules.filter_q') ?></span>
    <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= te('core.modules.filter_q_ph') ?>" data-modules-filter-q>
  </label>
  <label>
    <span class="muted"><?= te('core.modules.filter_category') ?></span>
    <select name="category" data-modules-filter-auto>
      <option value=""><?= te('core.modules.filter_any') ?></option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= e($cat) ?>"<?= $filters['category'] === $cat ? ' selected' : '' ?>><?= e(ModuleCatalog::categoryLabel($cat)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    <span class="muted"><?= te('core.modules.filter_tag') ?></span>
    <select name="tag" data-modules-filter-auto>
      <option value=""><?= te('core.modules.filter_any') ?></option>
      <?php foreach ($tags as $tag): ?>
        <option value="<?= e($tag) ?>"<?= $filters['tag'] === $tag ? ' selected' : '' ?>><?= e($tag) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    <span class="muted"><?= te('core.modules.filter_status') ?></span>
    <select name="status" data-modules-filter-auto>
      <option value=""><?= te('core.modules.filter_any') ?></option>
      <option value="enabled"<?= $filters['status'] === 'enabled' ? ' selected' : '' ?>><?= te('core.modules.state_enabled') ?></option>
      <option value="disabled"<?= $filters['status'] === 'disabled' ? ' selected' : '' ?>><?= te('core.modules.state_disabled') ?></option>
      <option value="blocked"<?= $filters['status'] === 'blocked' ? ' selected' : '' ?>><?= te('core.modules.state_blocked') ?></option>
      <option value="available"<?= $filters['status'] === 'available' ? ' selected' : '' ?>><?= te('core.modules.state_available') ?></option>
    </select>
  </label>
  <label>
    <span class="muted"><?= te('core.modules.filter_maturity') ?></span>
    <select name="maturity" data-modules-filter-auto>
      <option value=""><?= te('core.modules.filter_any') ?></option>
      <?php foreach ($maturities as $mat): ?>
        <option value="<?= e($mat) ?>"<?= $filters['maturity'] === $mat ? ' selected' : '' ?>><?= te('core.modules.maturity.' . $mat) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="modules-filters-actions">
    <a class="btn-secondary btn-sm" href="/modules"><?= te('core.modules.filter_reset') ?></a>
  </div>
</form>

<p class="muted modules-count">
  <?= te('core.modules.count', ['n' => count($cards)]) ?>
  <?php if ($installable !== []): ?>
    · <?= te('core.modules.count_available', ['n' => count($installable)]) ?>
  <?php endif; ?>
</p>

<?php if ($showNav): ?>
  <nav class="modules-sections-nav" aria-label="<?= te('core.modules.sections_nav') ?>">
    <?php if ($installable !== []): ?>
      <a href="#mod-installable"><?= te('core.modules.section_installable') ?> <span class="muted"><?= count($installable) ?></span></a>
    <?php endif; ?>
    <?php foreach ($sections as $sec):
        $secId = (string)$sec['id'];
        $anchor = $secId !== '' ? 'mod-cat-' . $secId : 'mod-cat-other';
        $label = $secId !== ''
            ? ModuleCatalog::categoryLabel($secId)
            : t('core.modules.category_uncategorized');
        $n = count($sec['cards']);
        ?>
      <a href="#<?= e($anchor) ?>"><?= e($label) ?> <span class="muted"><?= (int)$n ?></span></a>
    <?php endforeach; ?>
  </nav>
<?php endif; ?>

<?php if ($installable !== []): ?>
  <section class="modules-section modules-section--installable" id="mod-installable">
    <header class="modules-section-head">
      <h2 class="modules-section-title"><?= te('core.modules.section_installable') ?></h2>
      <span class="muted"><?= te('core.modules.section_count', ['n' => count($installable)]) ?></span>
    </header>
    <p class="muted modules-section-lede"><?= te('core.modules.section_installable_help') ?></p>
    <div class="modules-grid">
      <?php foreach ($installable as $card):
          $sectionCategory = '';
          require base_path('modules/core/views/partials/modules_card.php');
      endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($dismissedInstallable !== []): ?>
  <details class="modules-section modules-section--dismissed" id="mod-dismissed">
    <summary class="modules-section-head">
      <span class="modules-section-title"><?= te('core.modules.section_dismissed', ['n' => count($dismissedInstallable)]) ?></span>
    </summary>
    <p class="muted modules-section-lede"><?= te('core.modules.section_dismissed_help') ?></p>
    <div class="modules-grid">
      <?php foreach ($dismissedInstallable as $card):
          $sectionCategory = '';
          require base_path('modules/core/views/partials/modules_card.php');
      endforeach; ?>
    </div>
  </details>
<?php endif; ?>

<?php if ($sections === [] && $installable === []): ?>
  <p class="muted"><?= te('core.modules.empty') ?></p>
<?php endif; ?>

<?php foreach ($sections as $sec):
    $secId = (string)$sec['id'];
    $anchor = $secId !== '' ? 'mod-cat-' . $secId : 'mod-cat-other';
    $label = $secId !== ''
        ? ModuleCatalog::categoryLabel($secId)
        : t('core.modules.category_uncategorized');
    $secCards = $sec['cards'];
    ?>
  <section class="modules-section" id="<?= e($anchor) ?>">
    <header class="modules-section-head">
      <h2 class="modules-section-title"><?= e($label) ?></h2>
      <span class="muted"><?= te('core.modules.section_count', ['n' => count($secCards)]) ?></span>
    </header>
    <div class="modules-grid">
      <?php foreach ($secCards as $card):
          $sectionCategory = $secId;
          require base_path('modules/core/views/partials/modules_card.php');
      endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>

<script>
(function () {
  var form = document.querySelector('[data-modules-filters]');
  if (form) {
    form.querySelectorAll('[data-modules-filter-auto]').forEach(function (el) {
      el.addEventListener('change', function () { form.submit(); });
    });
    var q = form.querySelector('[data-modules-filter-q]');
    if (q) {
      var qTimer = null;
      var submitQ = function () {
        qTimer = null;
        form.submit();
      };
      q.addEventListener('input', function () {
        if (qTimer) clearTimeout(qTimer);
        qTimer = setTimeout(submitQ, 350);
      });
      q.addEventListener('search', function () {
        if (qTimer) clearTimeout(qTimer);
        form.submit();
      });
    }
  }
  document.querySelectorAll('[data-modules-hint-show]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var card = btn.closest('.modules-card');
      if (!card) return;
      var hint = card.querySelector('[data-modules-hint]');
      if (!hint) return;
      hint.textContent = btn.getAttribute('data-hint') || '';
      hint.hidden = false;
    });
  });
  document.querySelectorAll('[data-modules-risk-show]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var wrap = btn.closest('.modules-risk-wrap');
      if (!wrap) return;
      var panel = wrap.querySelector('[data-modules-risk]');
      if (!panel) return;
      panel.hidden = false;
      btn.hidden = true;
    });
  });
  document.querySelectorAll('[data-modules-risk-cancel]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var wrap = btn.closest('.modules-risk-wrap');
      if (!wrap) return;
      var panel = wrap.querySelector('[data-modules-risk]');
      var show = wrap.querySelector('[data-modules-risk-show]');
      if (panel) panel.hidden = true;
      if (show) show.hidden = false;
    });
  });
})();
</script>
