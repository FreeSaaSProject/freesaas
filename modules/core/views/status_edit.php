<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array $row */
/** @var list<array{title:string,when:string,fields:list<array{key:string,label:string,type:string}>}> $groups */
/** @var array<int,string> $audioOptions */
/** @var string $message */
/** @var string $error */
$sid = (int)($row['ID'] ?? 0);
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
      <?php else: ?>
        <label><?= e(t((string)$f['label'])) ?>
          <input name="<?= e($key) ?>" value="<?= e((string)$val) ?>"<?= $key === 'NAME' ? ' required' : '' ?>>
        </label>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/statuses"><?= te('core.common.cancel') ?></a>
  </div>
</form>
