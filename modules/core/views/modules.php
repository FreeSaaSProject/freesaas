<?php
/** @var list<array<string,mixed>> $cards */
/** @var array{q:string,category:string,tag:string,status:string,maturity:string} $filters */
/** @var list<string> $categories */
/** @var list<string> $tags */
/** @var list<string> $maturities */
/** @var string $listPath */
/** @var string $message */
/** @var string $error */

$modLabel = static function (string $modId): string {
    $meta = ModuleManager::get($modId);
    if ($meta === null) {
        return $modId;
    }
    return t((string)($meta['name'] ?? $modId));
};
?>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

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

<p class="muted modules-count"><?= te('core.modules.count', ['n' => count($cards)]) ?></p>

<div class="modules-grid">
<?php foreach ($cards as $card):
    $id = (string)$card['id'];
    $st = (string)$card['status'];
    $pending = (int)$card['migrations_pending'];
    $name = ModuleCatalog::display($card['name'], $id);
    $desc = ModuleCatalog::display($card['description']);
    $cardCats = $card['catalog']['categories'] ?? [];
    $cardTags = $card['catalog']['tags'] ?? [];
    $maturity = (string)($card['maturity'] ?? 'beta');
    $requiresRiskAck = !empty($card['requires_risk_ack']);
    ?>
  <article class="modules-card" data-module="<?= e($id) ?>">
    <header class="modules-card-head">
      <div>
        <h2 class="modules-card-title"><a href="/modules/<?= e(rawurlencode($id)) ?>"><?= e($name) ?></a></h2>
        <div class="muted"><code><?= e($id) ?></code> · v<?= e((string)$card['version']) ?></div>
      </div>
      <div class="modules-card-status">
        <span class="pill maturity-<?= e($maturity) ?>"><?= te('core.modules.maturity.' . $maturity) ?></span>
        <?php if ($st === 'enabled'): ?>
          <span class="pill ok"><?= te('core.modules.state_enabled') ?></span>
        <?php elseif ($st === 'blocked'): ?>
          <span class="pill off"><?= te('core.modules.state_blocked') ?></span>
        <?php else: ?>
          <span class="pill off"><?= te('core.modules.state_disabled') ?></span>
        <?php endif; ?>
      </div>
    </header>

    <?php if ($desc !== ''): ?>
      <p class="modules-card-desc"><?= e($desc) ?></p>
    <?php endif; ?>

    <div class="modules-card-meta">
      <?php foreach ($cardCats as $c): ?>
        <span class="pill"><?= e(ModuleCatalog::categoryLabel((string)$c)) ?></span>
      <?php endforeach; ?>
      <?php foreach ($cardTags as $tg): ?>
        <span class="pill"><?= e((string)$tg) ?></span>
      <?php endforeach; ?>
    </div>

    <div class="modules-deps">
      <?php
        $depsVisible = [];
        foreach ($card['status_requires'] as $dep) {
            if (!is_array($dep)) {
                continue;
            }
            $depId = (string)($dep['id'] ?? '');
            if ($depId === 'core' && !empty($dep['satisfied'])) {
                continue;
            }
            if ($depId === '') {
                continue;
            }
            $depsVisible[] = $dep;
        }
      ?>
      <?php if ($depsVisible === []): ?>
        <span class="muted"><?= te('core.modules.col_deps') ?>: —</span>
      <?php elseif (!empty($card['deps_ok'])): ?>
        <?php
          $okNames = [];
          foreach ($depsVisible as $dep) {
              $okNames[] = $modLabel((string)$dep['id']);
          }
        ?>
        <span class="muted"><?= te('core.modules.col_deps') ?>: <?= e(implode(', ', $okNames)) ?></span>
      <?php else: ?>
        <ul class="dep-list">
          <?php foreach ($depsVisible as $dep): ?>
            <?php if (!empty($dep['satisfied'])): ?>
              <li class="dep-ok"><?= e($modLabel((string)$dep['id'])) ?></li>
              <?php continue; ?>
            <?php endif; ?>
            <li class="dep-bad">
              <?= e($modLabel((string)$dep['id'])) ?>
              <?php if (($dep['constraint'] ?? '') !== ''): ?>
                <span class="dep-constraint"><?= e((string)$dep['constraint']) ?></span>
              <?php endif; ?>
              <?php if (!empty($dep['optional'])): ?>
                <span class="pill"><?= te('core.modules.optional') ?></span>
              <?php endif; ?>
              <span class="muted">— <?= e((string)($dep['detail'] ?? '')) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if (!empty($card['block_reason'])): ?>
        <div class="muted"><?= e((string)$card['block_reason']) ?></div>
      <?php endif; ?>
      <?php if ($pending > 0): ?>
        <div class="muted bad-text"><?= te('core.modules.pending', ['n' => $pending]) ?></div>
      <?php endif; ?>
      <div class="modules-hint bad-text" data-modules-hint hidden></div>
    </div>

    <div class="modules-actions">
      <?php if (!empty($card['can_enable'])): ?>
        <?php
          $moduleId = $id;
          $retPath = $listPath;
          $formAction = '/modules';
          $agentNote = $card['agent_note'] ?? null;
          require base_path('modules/core/views/partials/modules_enable.php');
        ?>
      <?php elseif (!empty($card['can_toggle']) && empty($card['config_enabled']) && empty($card['deps_ok'])): ?>
        <?php
          $missing = [];
          foreach ($card['status_requires'] as $dep) {
              if (!is_array($dep) || !empty($dep['satisfied']) || !empty($dep['optional'])) {
                  continue;
              }
              $missing[] = $modLabel((string)$dep['id']);
          }
          $enableHint = t('core.modules.enable_blocked_hint', [
              'modules' => $missing !== []
                  ? implode(', ', $missing)
                  : (string)($card['block_reason'] ?? t('core.modules.deps_bad')),
          ]);
        ?>
        <button class="btn-secondary btn-sm" type="button"
          data-modules-hint-show
          data-hint="<?= e($enableHint) ?>">
          <?= te('core.modules.btn_enable') ?>
        </button>
      <?php endif; ?>

      <?php if (!empty($card['can_disable'])): ?>
        <form method="post" class="inline-form" action="/modules" onsubmit="return confirm(<?= tj('core.modules.confirm_disable', ['module' => $name]) ?>);">
          <input type="hidden" name="module" value="<?= e($id) ?>">
          <input type="hidden" name="action" value="disable">
          <input type="hidden" name="ret" value="<?= e($listPath) ?>">
          <button class="btn-danger btn-sm" type="submit"><?= te('core.modules.btn_disable') ?></button>
        </form>
      <?php elseif (!empty($card['can_toggle']) && !empty($card['config_enabled']) && !empty($card['required_by'])): ?>
        <?php
          $blockers = [];
          foreach ($card['required_by'] as $depId) {
              $blockers[] = $modLabel((string)$depId);
          }
          $disableHint = t('core.modules.disable_blocked_hint', [
              'modules' => implode(', ', $blockers),
          ]);
        ?>
        <button class="btn-secondary btn-sm" type="button"
          data-modules-hint-show
          data-hint="<?= e($disableHint) ?>">
          <?= te('core.modules.btn_disable') ?>
        </button>
      <?php endif; ?>

      <?php if ($pending > 0): ?>
        <form method="post" class="inline-form" action="/modules">
          <input type="hidden" name="module" value="<?= e($id) ?>">
          <input type="hidden" name="action" value="migrate">
          <input type="hidden" name="ret" value="<?= e($listPath) ?>">
          <button class="btn-secondary btn-sm" type="submit">
            <?= te('core.modules.btn_migrate') ?> (<?= $pending ?>)
          </button>
        </form>
      <?php endif; ?>

      <a class="btn-secondary btn-sm" href="/modules/<?= e(rawurlencode($id)) ?>"><?= te('core.modules.btn_details') ?></a>
    </div>
  </article>
<?php endforeach; ?>
</div>

<?php if ($cards === []): ?>
  <p class="muted"><?= te('core.modules.empty') ?></p>
<?php endif; ?>

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
