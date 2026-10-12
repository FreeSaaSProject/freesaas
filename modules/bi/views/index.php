<?php
declare(strict_types=1);
/** @var list<array{id:string,title:string,ready:bool,ready_note:string,custom?:bool,shipped?:bool,def_id?:int}> $families */
/** @var string $flash */
?>
<?php if ($flash !== ''): ?><p class="flash-ok"><?= e($flash) ?></p><?php endif; ?>
<p class="bi-family-actions">
  <a class="btn-primary" href="/bi/families/new"><?= te('bi.index.new_family') ?></a>
  <a class="btn-secondary" href="/bi/shares"><?= te('bi.index.shares') ?></a>
</p>

<?php if ($families === []): ?>
  <p class="muted"><?= te('bi.empty.families') ?></p>
<?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th><?= te('bi.shares.family') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($families as $f): ?>
      <?php $ready = !empty($f['ready']); ?>
      <tr>
        <td>
          <?php if ($ready): ?>
            <a href="/bi/run?family=<?= e(rawurlencode($f['id'])) ?>"><strong><?= e($f['title']) ?></strong></a>
          <?php else: ?>
            <strong><?= e($f['title']) ?></strong>
          <?php endif; ?>
          <div class="muted mono"><?= e($f['id']) ?></div>
          <?php if (!$ready && $f['ready_note'] !== ''): ?>
            <div class="muted"><?= e($f['ready_note']) ?></div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
