<?php
/** @var array<string, array{meta:array,status:array}> $rows */
/** @var string $message */
/** @var string $error */
/** @var array $schemaReport */

$modName = static function (string $modId) use ($rows): string {
    $meta = $rows[$modId]['meta'] ?? null;
    if (is_array($meta)) {
        return t((string)($meta['name'] ?? $modId));
    }
    return $modId;
};
?>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if ($schemaReport): ?>
  <ul class="report">
    <?php foreach ($schemaReport as $r): ?>
      <li class="<?= e($r['status']) ?>">
        <?= e($r['module'] . '/' . $r['id'] . ': ' . $r['status']) ?>
        <?= !empty($r['message']) ? ' — ' . e($r['message']) : '' ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<div class="table-wrap">
  <table class="data modules-table">
    <thead>
      <tr>
        <th><?= te('core.modules.col_module') ?></th>
        <th><?= te('core.modules.col_status') ?></th>
        <th><?= te('core.modules.col_deps') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $id => $pack):
        $m = $pack['meta'];
        $st = $pack['status'];
        $pending = (int)($st['migrations_pending'] ?? 0);
        ?>
      <tr>
        <td>
          <strong><?= e($modName($id)) ?></strong>
          <div class="muted"><code><?= e($id) ?></code> · v<?= e((string)($m['version'] ?? '')) ?></div>
        </td>
        <td>
          <?php if (!empty($st['enabled'])): ?>
            <span class="pill ok"><?= te('core.modules.state_enabled') ?></span>
          <?php elseif (!empty($st['config_enabled'])): ?>
            <span class="pill off"><?= te('core.modules.state_blocked') ?></span>
          <?php else: ?>
            <span class="pill off"><?= te('core.modules.state_disabled') ?></span>
          <?php endif; ?>
          <?php if (!empty($st['block_reason'])): ?>
            <div class="muted"><?= e((string)$st['block_reason']) ?></div>
          <?php endif; ?>
          <?php if ($pending > 0): ?>
            <div class="muted bad-text"><?= te('core.modules.pending', ['n' => $pending]) ?></div>
          <?php endif; ?>
        </td>
        <td class="modules-deps">
          <?php
            $depsVisible = [];
            foreach ($st['requires'] as $dep) {
                $depId = (string)$dep['id'];
                // core is implied — show only when version/constraint fails
                if ($depId === 'core' && !empty($dep['satisfied'])) {
                    continue;
                }
                $depsVisible[] = $dep;
            }
          ?>
          <?php if ($depsVisible === []): ?>
            <span class="muted">—</span>
          <?php elseif (!empty($st['deps_ok'])): ?>
            <?php
              $okNames = [];
              foreach ($depsVisible as $dep) {
                  $okNames[] = $modName((string)$dep['id']);
              }
            ?>
            <span class="muted"><?= e(implode(', ', $okNames)) ?></span>
          <?php else: ?>
            <ul class="dep-list">
              <?php foreach ($depsVisible as $dep): ?>
                <?php if (!empty($dep['satisfied'])): ?>
                  <li class="dep-ok"><?= e($modName((string)$dep['id'])) ?></li>
                  <?php continue; ?>
                <?php endif; ?>
                <li class="dep-bad">
                  <?= e($modName((string)$dep['id'])) ?>
                  <?php if ($dep['constraint'] !== ''): ?>
                    <span class="dep-constraint"><?= e($dep['constraint']) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($dep['optional'])): ?>
                    <span class="pill"><?= te('core.modules.optional') ?></span>
                  <?php endif; ?>
                  <span class="muted">— <?= e($dep['detail']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <div class="modules-hint bad-text" data-modules-hint hidden></div>
        </td>
        <td class="modules-actions">
          <?php if (!empty($st['can_enable'])): ?>
            <form method="post" class="inline-form">
              <input type="hidden" name="module" value="<?= e($id) ?>">
              <input type="hidden" name="action" value="enable">
              <button class="btn-primary btn-sm" type="submit"><?= te('core.modules.btn_enable') ?></button>
            </form>
          <?php elseif (!empty($st['can_toggle']) && empty($st['config_enabled']) && empty($st['deps_ok'])): ?>
            <?php
              $missing = [];
              foreach ($st['requires'] as $dep) {
                  if (!empty($dep['satisfied']) || !empty($dep['optional'])) {
                      continue;
                  }
                  $missing[] = $modName((string)$dep['id']);
              }
              $enableHint = t('core.modules.enable_blocked_hint', [
                  'modules' => $missing !== []
                      ? implode(', ', $missing)
                      : (string)($st['block_reason'] ?? t('core.modules.deps_bad')),
              ]);
            ?>
            <button class="btn-secondary btn-sm" type="button"
              data-modules-hint-show
              data-hint="<?= e($enableHint) ?>">
              <?= te('core.modules.btn_enable') ?>
            </button>
          <?php endif; ?>

          <?php if (!empty($st['can_disable'])): ?>
            <form method="post" class="inline-form" onsubmit="return confirm(<?= tj('core.modules.confirm_disable', ['module' => $modName($id)]) ?>);">
              <input type="hidden" name="module" value="<?= e($id) ?>">
              <input type="hidden" name="action" value="disable">
              <button class="btn-danger btn-sm" type="submit"><?= te('core.modules.btn_disable') ?></button>
            </form>
          <?php elseif (!empty($st['can_toggle']) && !empty($st['config_enabled']) && !empty($st['required_by'])): ?>
            <?php
              $blockers = [];
              foreach ($st['required_by'] as $depId) {
                  $blockers[] = $modName((string)$depId);
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
              <button class="btn-secondary btn-sm" type="submit">
                <?= te('core.modules.btn_migrate') ?> (<?= $pending ?>)
              </button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
(function () {
  document.querySelectorAll('[data-modules-hint-show]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tr = btn.closest('tr');
      if (!tr) return;
      var hint = tr.querySelector('[data-modules-hint]');
      if (!hint) return;
      hint.textContent = btn.getAttribute('data-hint') || '';
      hint.hidden = false;
    });
  });
})();
</script>
