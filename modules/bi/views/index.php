<?php
declare(strict_types=1);
/** @var list<array{id:string,title:string,ready:bool,ready_note:string,custom?:bool,shipped?:bool,def_id?:int,shared?:bool,share_token?:string,share_label?:string,share_expires?:int}> $families */
/** @var bool $canManage */
/** @var string $flash */
$canManage = !empty($canManage);
?>
<?php if ($flash !== ''): ?><p class="flash-ok"><?= e($flash) ?></p><?php endif; ?>
<?php if ($canManage): ?>
  <p class="bi-family-actions">
    <a class="btn-primary" href="/bi/families/new"><?= te('bi.index.new_family') ?></a>
    <a class="btn-secondary" href="/bi/shares"><?= te('bi.index.shares') ?></a>
  </p>
<?php else: ?>
  <p class="lede"><?= te('bi.index.shared_lede') ?></p>
<?php endif; ?>

<?php if ($families === []): ?>
  <p class="muted"><?= te($canManage ? 'bi.empty.families' : 'bi.empty.shared') ?></p>
<?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th><?= te('bi.shares.family') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($families as $f): ?>
      <?php
        $ready = !empty($f['ready']);
        $shareToken = trim((string)($f['share_token'] ?? ''));
        $href = '/bi/run?family=' . rawurlencode($f['id']);
        if ($shareToken !== '') {
            $href .= '&share=' . rawurlencode($shareToken);
        }
      ?>
      <tr>
        <td>
          <?php if ($ready): ?>
            <a href="<?= e($href) ?>"><strong><?= e($f['title']) ?></strong></a>
          <?php else: ?>
            <strong><?= e($f['title']) ?></strong>
          <?php endif; ?>
          <?php if ($canManage): ?>
            <div class="muted mono"><?= e($f['id']) ?></div>
          <?php endif; ?>
          <?php if (!empty($f['share_label'])): ?>
            <div class="muted"><?= e((string)$f['share_label']) ?></div>
          <?php endif; ?>
          <?php if (!$canManage && (int)($f['share_expires'] ?? 0) > 0): ?>
            <div class="muted"><?= te('bi.share.expires') ?>: <?= e(date('Y-m-d H:i', (int)$f['share_expires'])) ?></div>
          <?php endif; ?>
          <?php if (!$ready && $f['ready_note'] !== ''): ?>
            <div class="muted"><?= e($f['ready_note']) ?></div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
