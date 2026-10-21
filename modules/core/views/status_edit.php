<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array $row */
/** @var list<array{title:string,when:string,fields:list<array{key:string,label:string,type:string}>}> $groups */
/** @var array<int,string> $audioOptions */
/** @var array<int,string> $shiftOptions */
/** @var array<string,array{name:string,keys:list}> $permGroups */
/** @var array<string,int> $statusPerms */
/** @var string $message */
/** @var string $error */
$sid = (int)($row['ID'] ?? 0);
$permGroups = $permGroups ?? [];
$statusPerms = $statusPerms ?? [];
$hasPermCol = Database::columnExists('base_admin_statuses', 'PERMISSIONS');
?>
<section class="page-head">
  <h1><?= $isNew ? te('core.status_edit.title_new') : e((string)($row['NAME'] ?? '')) ?></h1>
  <p class="lede"><?= te('core.status_edit.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <?php foreach ($groups as $g): ?>
    <h2 class="form-section-title"><?= e(t((string)$g['title'])) ?></h2>
    <?php foreach ($g['fields'] as $f):
      $key = (string)$f['key'];
      $type = (string)$f['type'];
      $val = $row[$key] ?? ($type === 'checkbox' ? 0 : '');
      ?>
      <?php if ($type === 'checkbox'): ?>
        <label class="check">
          <input type="checkbox" name="<?= e($key) ?>" value="1"<?= !empty($val) ? ' checked' : '' ?>>
          <?= e(t((string)$f['label'])) ?>
        </label>
      <?php elseif ($type === 'select_status'): ?>
        <label><?= e(t((string)$f['label'])) ?>
          <select name="<?= e($key) ?>">
            <?php foreach ($audioOptions as $oid => $olabel): ?>
              <option value="<?= (int)$oid ?>"<?= (int)$val === (int)$oid ? ' selected' : '' ?>><?= e($olabel) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php elseif ($type === 'select_shift'): ?>
        <label><?= e(t((string)$f['label'])) ?>
          <select name="<?= e($key) ?>">
            <?php foreach (($shiftOptions ?? []) as $oid => $olabel): ?>
              <option value="<?= (int)$oid ?>"<?= (int)$val === (int)$oid ? ' selected' : '' ?>><?= e($olabel) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php else: ?>
        <label><?= e(t((string)$f['label'])) ?>
          <input name="<?= e($key) ?>" value="<?= e((string)$val) ?>"<?= $key === 'NAME' ? ' required' : '' ?>>
        </label>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <?php if ($hasPermCol && $permGroups): ?>
  <h2 class="form-section-title"><?= te('core.status_edit.sec_perms') ?></h2>
  <p class="form-hint"><?= te('core.status_edit.perms_hint') ?></p>
  <label><?= te('core.user_edit.perms_search') ?>
    <input type="search" id="perm-search" placeholder="<?= te('core.user_edit.perms_search') ?>" autocomplete="off">
  </label>
  <div class="perm-groups" id="perm-groups">
  <?php foreach ($permGroups as $modId => $group):
    $onCount = 0;
    $totalKeys = 0;
    foreach ($group['keys'] as $key) {
      $totalKeys++;
      $pid = is_array($key) ? (string)$key['id'] : (string)$key;
      if (!empty($statusPerms[$pid])) {
        $onCount++;
      }
    }
    ?>
    <details class="check-fieldset perm-group" data-perm-mod="<?= e((string)$modId) ?>" open>
      <summary class="perm-group-summary">
        <span class="perm-group-name"><?= e(t((string)$group['name'])) ?></span>
        <span class="perm-group-count<?= $onCount > 0 ? ' is-on' : '' ?>"><?= (int)$onCount ?>/<?= (int)$totalKeys ?></span>
      </summary>
      <div class="check-grid">
        <?php foreach ($group['keys'] as $key):
          $pid = is_array($key) ? (string)$key['id'] : (string)$key;
          $plabel = is_array($key) ? (string)$key['label'] : (string)$key;
          ?>
          <label class="check" title="<?= e($pid) ?>" data-perm-id="<?= e($pid) ?>" data-perm-label="<?= e(t($plabel)) ?>">
            <input type="hidden" name="permissions[<?= e($pid) ?>]" value="0">
            <input type="checkbox" name="permissions[<?= e($pid) ?>]" value="1"<?= !empty($statusPerms[$pid]) ? ' checked' : '' ?>>
            <span><?= e(t($plabel)) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; ?>
  </div>
  <script>
  (function () {
    var input = document.getElementById('perm-search');
    var root = document.getElementById('perm-groups');
    if (!input || !root) return;
    input.addEventListener('input', function () {
      var q = (input.value || '').toLowerCase().trim();
      root.querySelectorAll('details.perm-group').forEach(function (d) {
        var mod = (d.getAttribute('data-perm-mod') || '').toLowerCase();
        var name = (d.querySelector('.perm-group-name') || {}).textContent || '';
        name = name.toLowerCase();
        var any = false;
        d.querySelectorAll('[data-perm-id]').forEach(function (lab) {
          var id = (lab.getAttribute('data-perm-id') || '').toLowerCase();
          var labTxt = (lab.getAttribute('data-perm-label') || '').toLowerCase();
          var hit = !q || id.indexOf(q) >= 0 || labTxt.indexOf(q) >= 0 || mod.indexOf(q) >= 0 || name.indexOf(q) >= 0;
          lab.style.display = hit ? '' : 'none';
          if (hit) any = true;
        });
        d.style.display = any ? '' : 'none';
        if (q && any) d.open = true;
      });
    });
  })();
  </script>
  <?php endif; ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/statuses"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('core_statuses', (int)($id ?? $row['ID'] ?? 0)) ?>
<?php endif; ?>

