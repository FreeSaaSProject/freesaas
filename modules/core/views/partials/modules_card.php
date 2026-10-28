<?php
declare(strict_types=1);
/**
 * One module catalog card.
 *
 * @var array<string,mixed> $card
 * @var string $listPath
 * @var string|null $sectionCategory  when set, this category pill is omitted (already the section)
 * @var callable(string):string $modLabel
 */
$id = (string)$card['id'];
$st = (string)$card['status'];
$pending = (int)$card['migrations_pending'];
$name = ModuleCatalog::display($card['name'], $id);
$desc = ModuleCatalog::display($card['description']);
$cardCats = $card['catalog']['categories'] ?? [];
$cardTags = $card['catalog']['tags'] ?? [];
$maturity = (string)($card['maturity'] ?? 'beta');
$requiresRiskAck = !empty($card['requires_risk_ack']);
$sectionCategory = isset($sectionCategory) ? (string)$sectionCategory : '';
$isInstallable = !empty($card['installable']) || $st === 'available';
$canInstallBtn = !empty($canInstall) && !empty($card['can_install']);
?>
<article class="modules-card<?= $isInstallable ? ' modules-card--installable' : '' ?>" data-module="<?= e($id) ?>">
  <header class="modules-card-head">
    <div>
      <h3 class="modules-card-title">
        <?php if ($isInstallable): ?>
          <?= e($name) ?>
        <?php else: ?>
          <a href="/modules/<?= e(rawurlencode($id)) ?>"><?= e($name) ?></a>
        <?php endif; ?>
      </h3>
      <div class="muted"><code><?= e($id) ?></code> · v<?= e((string)$card['version']) ?></div>
    </div>
    <div class="modules-card-status">
      <span class="pill maturity-<?= e($maturity) ?>"><?= te('core.modules.maturity.' . $maturity) ?></span>
      <?php if ($st === 'available'): ?>
        <span class="pill avail"><?= te('core.modules.state_available') ?></span>
      <?php elseif ($st === 'enabled'): ?>
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
    <?php
      // Always show primary category; omit duplicates of the section heading only.
      $primaryCat = ModuleCatalog::primaryCategory($card);
      if ($primaryCat !== '' && ($sectionCategory === '' || $primaryCat !== $sectionCategory)):
    ?>
      <span class="pill"><?= e(ModuleCatalog::categoryLabel($primaryCat)) ?></span>
    <?php endif; ?>
  </div>

  <div class="modules-deps">
    <?php if ($isInstallable): ?>
      <?php
        $reqIds = [];
        foreach ($card['requires'] ?? [] as $depId => $constraint) {
            $depId = is_string($depId) ? $depId : (string)$constraint;
            if ($depId === '' || $depId === 'core') {
                continue;
            }
            $reqIds[] = $depId;
        }
      ?>
      <?php if ($reqIds !== []): ?>
        <span class="muted"><?= te('core.modules.col_deps') ?>: <?= e(implode(', ', $reqIds)) ?></span>
      <?php endif; ?>
    <?php else: ?>
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
    <?php if ($depsVisible !== [] && !empty($card['deps_ok'])): ?>
      <?php
        $okNames = [];
        foreach ($depsVisible as $dep) {
            $okNames[] = $modLabel((string)$dep['id']);
        }
      ?>
      <span class="muted"><?= te('core.modules.col_deps') ?>: <?= e(implode(', ', $okNames)) ?></span>
    <?php elseif ($depsVisible !== []): ?>
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
    <?php endif; ?>
    <div class="modules-hint bad-text" data-modules-hint hidden></div>
  </div>

  <div class="modules-actions">
    <?php if ($isInstallable): ?>
      <?php if ($canInstallBtn): ?>
        <form method="post" class="inline-form" action="/modules"
          onsubmit="return confirm(<?= tj('core.modules.confirm_install', ['module' => $name !== '' ? $name : $id]) ?>);">
          <input type="hidden" name="module" value="<?= e($id) ?>">
          <input type="hidden" name="action" value="install">
          <input type="hidden" name="ret" value="<?= e($listPath) ?>">
          <button class="btn-primary btn-sm" type="submit"><?= te('core.modules.btn_install') ?></button>
        </form>
      <?php endif; ?>
      <?php if (!empty($canInstall)):
          $isDismissed = !empty($card['dismissed']);
          ?>
        <form method="post" class="inline-form" action="/modules">
          <input type="hidden" name="module" value="<?= e($id) ?>">
          <input type="hidden" name="action" value="<?= $isDismissed ? 'restore_available' : 'dismiss_available' ?>">
          <input type="hidden" name="ret" value="<?= e($listPath) ?>">
          <button class="btn-secondary btn-sm" type="submit"
                  title="<?= $isDismissed ? te('core.modules.btn_restore_hint') : te('core.modules.btn_dismiss_hint') ?>">
            <?= $isDismissed ? te('core.modules.btn_restore') : te('core.modules.btn_dismiss') ?>
          </button>
        </form>
      <?php endif; ?>
    <?php else: ?>
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

    <?php if ($pending > 0 && !empty($card['config_enabled'])): ?>
      <form method="post" class="inline-form" action="/modules">
        <input type="hidden" name="module" value="<?= e($id) ?>">
        <input type="hidden" name="action" value="migrate">
        <input type="hidden" name="ret" value="<?= e($listPath) ?>">
        <button class="btn-secondary btn-sm" type="submit">
          <?= te('core.modules.btn_migrate') ?> (<?= $pending ?>)
        </button>
      </form>
    <?php endif; ?>

    <?php if (!empty($card['can_uninstall']) && Auth::capability('SUPERADMIN_ACCESS')): ?>
      <form method="post" class="inline-form" action="/modules"
        onsubmit="return confirm(<?= tj('core.modules.confirm_uninstall', ['module' => $name !== '' ? $name : $id]) ?>);">
        <input type="hidden" name="module" value="<?= e($id) ?>">
        <input type="hidden" name="action" value="uninstall">
        <input type="hidden" name="confirm_uninstall" value="1">
        <input type="hidden" name="ret" value="<?= e($listPath) ?>">
        <button class="btn-danger btn-sm" type="submit"><?= te('core.modules.btn_uninstall') ?></button>
      </form>
    <?php endif; ?>

    <a class="btn-secondary btn-sm" href="/modules/<?= e(rawurlencode($id)) ?>"><?= te('core.modules.btn_details') ?></a>
    <?php endif; ?>
  </div>
</article>
