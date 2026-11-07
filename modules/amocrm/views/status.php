<?php
declare(strict_types=1);
?>
<section class="page-head"><h1><?= te('amocrm.menu.status') ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<p><?= te('amocrm.status.api') ?>: <?= $apiReady ? te('core.common.yes') : te('core.common.no') ?></p>
<p><?= te('amocrm.status.outbox') ?>: <?= (int)$pending ?></p>
<ul>
<?php foreach ($counts as $ent => $n): ?>
  <li><?= e((string)$ent) ?>: <?= (int)$n ?></li>
<?php endforeach; ?>
</ul>
<form method="post">
  <input type="hidden" name="action" value="ping">
  <button class="btn-primary" type="submit"><?= te('amocrm.action.ping') ?></button>
</form>
<p><a href="/amocrm/import"><?= te('amocrm.menu.import') ?></a> · <a href="/amocrm/settings"><?= te('amocrm.menu.settings') ?></a></p>
