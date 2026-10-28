<?php
declare(strict_types=1);
/** @var string $moduleId */
/** @var array<string,mixed> $snap */
/** @var string $phase */
/** @var string $error */
/** @var string $flash */
/** @var list<array{id:int,login:string,name:string,status_id:int,status_name:string}> $staff */
$enabled = !empty($snap['enabled']);
$needsRisk = !empty($snap['accept_risk_required']);
$maturity = (string)($snap['maturity'] ?? 'release');
$steps = is_array($snap['setup_steps'] ?? null) ? $snap['setup_steps'] : [];
$targets = is_array($snap['grant_targets'] ?? null) ? $snap['grant_targets'] : [];
?>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($flash !== '' && $error === ''): ?>
  <div class="flash flash-ok"><?= e($flash) ?></div>
<?php endif; ?>

<nav class="modules-activate-steps" aria-label="<?= te('core.modules.activate.title', ['module' => (string)($snap['name'] ?? $moduleId)]) ?>">
  <span class="<?= $phase === 'enable' ? 'is-current' : ($enabled ? 'is-done' : '') ?>"><?= te('core.modules.activate.step_enable') ?></span>
  <span class="<?= $phase === 'grant' ? 'is-current' : '' ?>"><?= te('core.modules.activate.step_grant') ?></span>
  <span class="<?= $phase === 'setup' || $phase === 'done' ? 'is-current' : '' ?>"><?= te('core.modules.activate.step_setup') ?></span>
</nav>

<?php if ($phase === 'enable' && !$enabled): ?>
  <section class="modules-activate-panel">
    <p><?= te('core.modules.activate.enable_help') ?></p>
    <?php if ($needsRisk): ?>
      <p class="flash flash-warn"><?= te('core.modules.risk_body', ['stage' => t('core.modules.maturity.' . $maturity)]) ?></p>
    <?php endif; ?>
    <form method="post" class="inline-form">
      <input type="hidden" name="activate_action" value="enable">
      <?php if ($needsRisk): ?>
        <input type="hidden" name="accept_risk" value="1">
      <?php endif; ?>
      <button type="submit" class="btn-primary"><?= $needsRisk ? te('core.modules.risk_accept') : te('core.modules.activate.enable_now') ?></button>
    </form>
    <form method="post" class="inline-form">
      <input type="hidden" name="activate_action" value="leave_disabled">
      <button type="submit" class="btn-secondary"><?= te('core.modules.activate.leave_off') ?></button>
    </form>
  </section>
<?php elseif ($phase === 'grant'): ?>
  <section class="modules-activate-panel">
    <?php if ($enabled): ?>
      <p class="muted"><?= te('core.modules.activate.already_on') ?></p>
    <?php endif; ?>
    <p><?= te('core.modules.activate.grant_help') ?></p>
    <form method="post">
      <input type="hidden" name="activate_action" value="grant">
      <fieldset>
        <legend><?= te('core.modules.activate.access_full') ?> / <?= te('core.modules.activate.access_view') ?></legend>
        <label><input type="radio" name="access" value="full" checked> <?= te('core.modules.activate.access_full') ?>
          <span class="pill"><?= te('core.modules.activate.recommended') ?></span></label>
        <label><input type="radio" name="access" value="view"> <?= te('core.modules.activate.access_view') ?></label>
      </fieldset>
      <fieldset>
        <legend><?= te('core.modules.activate.statuses') ?></legend>
        <?php if ($targets === []): ?>
          <p class="muted">—</p>
        <?php else: ?>
          <?php foreach ($targets as $t): ?>
            <label>
              <input type="checkbox" name="status_ids[]" value="<?= (int)$t['id'] ?>"<?= !empty($t['recommended']) ? ' checked' : '' ?>>
              <?= e((string)$t['name']) ?>
              <?php if (!empty($t['recommended'])): ?>
                <span class="pill"><?= te('core.modules.activate.recommended') ?></span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </fieldset>
      <fieldset>
        <legend><?= te('core.modules.activate.users') ?></legend>
        <p class="muted"><?= te('core.modules.activate.users_hint') ?></p>
        <input type="text" name="user_ids" class="input" placeholder="1, 2, 3" list="activate-staff">
        <datalist id="activate-staff">
          <?php foreach ($staff as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= e((string)$u['name'] . ' (' . (string)$u['login'] . ')') ?></option>
          <?php endforeach; ?>
        </datalist>
      </fieldset>
      <button type="submit" class="btn-primary"><?= te('core.modules.activate.grant_continue') ?></button>
    </form>
    <form method="post" class="inline-form">
      <input type="hidden" name="activate_action" value="grant_skip">
      <button type="submit" class="btn-secondary"><?= te('core.modules.activate.grant_skip') ?></button>
    </form>
  </section>
<?php else: ?>
  <section class="modules-activate-panel">
    <p><?= te('core.modules.activate.setup_help') ?></p>
    <?php if ($steps === []): ?>
      <p class="muted"><?= te('core.modules.none') ?></p>
      <form method="post">
        <input type="hidden" name="activate_action" value="finish">
        <button type="submit" class="btn-primary"><?= te('core.modules.activate.finish') ?></button>
      </form>
    <?php else: ?>
      <ul class="modules-activate-setup">
        <?php foreach ($steps as $step):
            $st = (string)($step['status'] ?? 'pending');
            ?>
          <li class="modules-activate-setup-item status-<?= e($st) ?>">
            <strong><?= e((string)($step['title'] ?? $step['id'])) ?></strong>
            <?php if (($step['description'] ?? '') !== ''): ?>
              <p class="muted"><?= e((string)$step['description']) ?></p>
            <?php endif; ?>
            <?php if ($st === 'pending'): ?>
              <div class="modules-activate-setup-actions">
                <?php if (($step['href'] ?? '') !== ''): ?>
                  <a class="btn-secondary btn-sm" href="<?= e((string)$step['href']) ?>" target="_blank" rel="noopener"><?= te('core.modules.activate.setup_open') ?></a>
                <?php endif; ?>
                <form method="post" class="inline-form">
                  <input type="hidden" name="activate_action" value="setup_step">
                  <input type="hidden" name="step_id" value="<?= e((string)$step['id']) ?>">
                  <input type="hidden" name="choice" value="open">
                  <button type="submit" class="btn-primary btn-sm"><?= te('core.modules.activate.setup_done') ?></button>
                </form>
                <form method="post" class="inline-form">
                  <input type="hidden" name="activate_action" value="setup_step">
                  <input type="hidden" name="step_id" value="<?= e((string)$step['id']) ?>">
                  <input type="hidden" name="choice" value="defer">
                  <button type="submit" class="btn-secondary btn-sm"><?= te('core.modules.activate.setup_defer') ?></button>
                </form>
              </div>
            <?php else: ?>
              <span class="pill"><?= e($st) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="post" class="inline-form">
        <input type="hidden" name="activate_action" value="setup_defer_all">
        <button type="submit" class="btn-secondary"><?= te('core.modules.activate.setup_defer_all') ?></button>
      </form>
      <form method="post" class="inline-form">
        <input type="hidden" name="activate_action" value="finish">
        <button type="submit" class="btn-primary"><?= te('core.modules.activate.finish') ?></button>
      </form>
    <?php endif; ?>
  </section>
<?php endif; ?>
