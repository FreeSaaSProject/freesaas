<?php
declare(strict_types=1);
/** @var array<string,mixed> $card */
/** @var string $detailPath */
/** @var string $message */
/** @var string $error */

$id = (string)$card['id'];
$name = ModuleCatalog::display($card['name'], $id);
$desc = ModuleCatalog::display($card['description']);
$summary = ModuleCatalog::display($card['summary']);
$st = (string)$card['status'];
$pending = (int)$card['migrations_pending'];
$cardCats = $card['catalog']['categories'] ?? [];
$cardTags = $card['catalog']['tags'] ?? [];
$maturity = (string)($card['maturity'] ?? 'beta');
$requiresRiskAck = !empty($card['requires_risk_ack']);
$screenshots = $card['screenshots'] ?? [];

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

<section class="modules-detail">
  <header class="modules-detail-head">
    <div>
      <h1><?= e($name) ?></h1>
      <div class="muted"><code><?= e($id) ?></code> · v<?= e((string)$card['version']) ?> · <?= e((string)$card['type']) ?></div>
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

  <div class="modules-card-meta">
    <?php foreach ($cardCats as $c): ?>
      <span class="pill"><?= e(ModuleCatalog::categoryLabel((string)$c)) ?></span>
    <?php endforeach; ?>
    <?php foreach ($cardTags as $tg): ?>
      <span class="pill"><?= e((string)$tg) ?></span>
    <?php endforeach; ?>
  </div>

  <?php if ($requiresRiskAck): ?>
    <div class="modules-risk flash flash-warn modules-risk-static">
      <strong class="modules-risk-title"><?= te('core.modules.risk_title') ?></strong>
      <p class="modules-risk-body"><?= te('core.modules.risk_body', ['stage' => t('core.modules.maturity.' . $maturity)]) ?></p>
      <p class="modules-risk-agent"><?= te('core.modules.risk_agent', ['note' => ModuleCatalog::display($card['agent_note'] ?? [])]) ?></p>
    </div>
  <?php endif; ?>

  <?php if ($desc !== ''): ?>
    <p class="lede"><?= e($desc) ?></p>
  <?php endif; ?>

  <?php if ($summary !== ''): ?>
    <div class="modules-summary">
      <h2><?= te('core.modules.section_summary') ?></h2>
      <p><?= e($summary) ?></p>
    </div>
  <?php endif; ?>

  <div class="modules-actions modules-detail-actions">
    <?php if (!empty($card['can_enable'])): ?>
      <?php
        $moduleId = $id;
        $retPath = $detailPath;
        $formAction = '';
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
      <form method="post" class="inline-form" onsubmit="return confirm(<?= tj('core.modules.confirm_disable', ['module' => $name]) ?>);">
        <input type="hidden" name="module" value="<?= e($id) ?>">
        <input type="hidden" name="action" value="disable">
        <input type="hidden" name="ret" value="<?= e($detailPath) ?>">
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
      <form method="post" class="inline-form">
        <input type="hidden" name="module" value="<?= e($id) ?>">
        <input type="hidden" name="action" value="migrate">
        <input type="hidden" name="ret" value="<?= e($detailPath) ?>">
        <button class="btn-secondary btn-sm" type="submit">
          <?= te('core.modules.btn_migrate') ?> (<?= $pending ?>)
        </button>
      </form>
    <?php endif; ?>

    <?php if (!empty($card['can_uninstall']) && Auth::capability('SUPERADMIN_ACCESS')): ?>
      <form method="post" class="inline-form"
        onsubmit="return confirm(<?= tj('core.modules.confirm_uninstall', ['module' => $name]) ?>);">
        <input type="hidden" name="module" value="<?= e($id) ?>">
        <input type="hidden" name="action" value="uninstall">
        <input type="hidden" name="confirm_uninstall" value="1">
        <input type="hidden" name="ret" value="/modules">
        <button class="btn-danger btn-sm" type="submit"><?= te('core.modules.btn_uninstall') ?></button>
      </form>
    <?php elseif (($card['uninstall_strategy'] ?? '') === 'leave_tables' && $id !== 'core'): ?>
      <span class="muted" title="<?= te('core.modules.uninstall_leave_hint') ?>"><?= te('core.modules.uninstall_leave_badge') ?></span>
    <?php endif; ?>
  </div>
  <div class="modules-hint bad-text" data-modules-hint hidden></div>

  <?php if (!empty($card['block_reason'])): ?>
    <p class="muted"><?= e((string)$card['block_reason']) ?></p>
  <?php endif; ?>

  <section class="modules-section">
    <h2><?= te('core.modules.section_deps') ?></h2>
    <h3><?= te('core.modules.deps_hard') ?></h3>
    <?php
      $hard = [];
      foreach ($card['status_requires'] as $dep) {
          if (!is_array($dep) || !empty($dep['optional'])) {
              continue;
          }
          $hard[] = $dep;
      }
    ?>
    <?php if ($hard === []): ?>
      <p class="muted"><?= te('core.modules.none') ?></p>
    <?php else: ?>
      <ul class="dep-list">
        <?php foreach ($hard as $dep):
            $depId = (string)$dep['id'];
            $ok = !empty($dep['satisfied']);
            ?>
          <li class="<?= $ok ? 'dep-ok' : 'dep-bad' ?>">
            <?php if ($depId !== 'core'): ?>
              <a href="/modules/<?= e(rawurlencode($depId)) ?>"><?= e($modLabel($depId)) ?></a>
            <?php else: ?>
              <?= e($modLabel($depId)) ?>
            <?php endif; ?>
            <?php if (($dep['constraint'] ?? '') !== ''): ?>
              <span class="dep-constraint"><?= e((string)$dep['constraint']) ?></span>
            <?php endif; ?>
            <?php if (!$ok): ?>
              <span class="muted">— <?= e((string)($dep['detail'] ?? '')) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h3><?= te('core.modules.deps_optional') ?></h3>
    <?php
      $soft = [];
      foreach ($card['status_requires'] as $dep) {
          if (!is_array($dep) || empty($dep['optional'])) {
              continue;
          }
          $soft[] = $dep;
      }
    ?>
    <?php if ($soft === []): ?>
      <p class="muted"><?= te('core.modules.none') ?></p>
    <?php else: ?>
      <ul class="dep-list">
        <?php foreach ($soft as $dep):
            $depId = (string)$dep['id'];
            $ok = !empty($dep['satisfied']);
            ?>
          <li class="<?= $ok ? 'dep-ok' : 'dep-bad' ?>">
            <a href="/modules/<?= e(rawurlencode($depId)) ?>"><?= e($modLabel($depId)) ?></a>
            <?php if (($dep['constraint'] ?? '') !== ''): ?>
              <span class="dep-constraint"><?= e((string)$dep['constraint']) ?></span>
            <?php endif; ?>
            <span class="pill"><?= te('core.modules.optional') ?></span>
            <?php if (!$ok): ?>
              <span class="muted">— <?= e((string)($dep['detail'] ?? '')) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h3><?= te('core.modules.required_by') ?></h3>
    <?php if (empty($card['required_by'])): ?>
      <p class="muted"><?= te('core.modules.none') ?></p>
    <?php else: ?>
      <ul class="dep-list">
        <?php foreach ($card['required_by'] as $depId): ?>
          <li>
            <a href="/modules/<?= e(rawurlencode((string)$depId)) ?>"><?= e($modLabel((string)$depId)) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if (!empty($card['provides'])): ?>
    <section class="modules-section">
      <h2><?= te('core.modules.section_provides') ?></h2>
      <ul class="modules-provides">
        <?php foreach ($card['provides'] as $cap): ?>
          <li><code><?= e((string)$cap) ?></code></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php if (is_array($screenshots) && $screenshots !== []): ?>
    <section class="modules-section">
      <h2><?= te('core.modules.section_screenshots') ?></h2>
      <div class="modules-shots">
        <?php foreach ($screenshots as $shot):
            $cap = ModuleCatalog::display($shot['caption'] ?? []);
            ?>
          <figure class="modules-shot">
            <img src="<?= e((string)$shot['url']) ?>" alt="<?= e($cap !== '' ? $cap : $name) ?>" loading="lazy">
            <?php if ($cap !== ''): ?>
              <figcaption class="muted"><?= e($cap) ?></figcaption>
            <?php endif; ?>
          </figure>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (!empty($card['permissions'])): ?>
    <section class="modules-section">
      <h2><?= te('core.modules.section_permissions') ?></h2>
      <ul class="modules-provides">
        <?php foreach ($card['permissions'] as $p): ?>
          <li>
            <code><?= e((string)$p['id']) ?></code>
            — <?= e(ModuleCatalog::display($p['label'] ?? [], (string)$p['id'])) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php if (!empty($card['routes_pages'])): ?>
    <section class="modules-section">
      <h2><?= te('core.modules.section_routes') ?></h2>
      <ul class="modules-provides">
        <?php foreach ($card['routes_pages'] as $path): ?>
          <li><code><?= e((string)$path) ?></code></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
</section>

<script>
(function () {
  document.querySelectorAll('[data-modules-hint-show]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var root = btn.closest('.modules-detail') || document;
      var hint = root.querySelector('[data-modules-hint]');
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
