<?php
declare(strict_types=1);
/** @var list<array{id:string,module:string,title:string,show:bool,weight:int}> $rows */
?>
<h1><?= te('client_portal.settings.title') ?></h1>
<p class="page-actions">
  <a href="/client-portal/status"><?= te('client_portal.menu.status') ?></a>
  <?php if (ModuleManager::isEnabled('bots') && Auth::can('bots.view')): ?>
    · <a href="/bots/settings"><?= te('client_portal.status.link_bots_settings') ?></a>
  <?php endif; ?>
</p>
<?php if ($rows === []): ?>
  <p class="muted"><?= te('client_portal.settings.empty') ?></p>
<?php else: ?>
<form method="post" class="form">
  <?= Csrf::field() ?>
  <table class="table">
    <thead>
      <tr>
        <th><?= te('client_portal.settings.section') ?></th>
        <th><?= te('client_portal.settings.module') ?></th>
        <th><?= te('client_portal.settings.show') ?></th>
        <th><?= te('client_portal.settings.weight') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= e($row['title']) ?> <code><?= e($row['id']) ?></code></td>
        <td><code><?= e($row['module']) ?></code></td>
        <td>
          <label class="check">
            <input type="checkbox" name="show[<?= e($row['id']) ?>]" value="1"<?= $row['show'] ? ' checked' : '' ?>>
          </label>
        </td>
        <td>
          <input type="number" name="weight[<?= e($row['id']) ?>]" value="<?= (int)$row['weight'] ?>" style="width:6rem">
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p><button type="submit" class="btn"><?= te('client_portal.settings.save') ?></button></p>
</form>
<?php endif; ?>
