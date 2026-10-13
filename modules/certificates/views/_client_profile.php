<?php
declare(strict_types=1);
/**
 * @var int $clientId
 * @var int $bought
 * @var int $active
 */
?>
<p class="certificates-client-profile">
  <a href="/certificates?id_buyer=<?= (int)$clientId ?>"><?= te('certificates.client.link') ?></a>:
  <?= te('certificates.client.bought', ['n' => $bought]) ?>,
  <?= te('certificates.client.active', ['n' => $active]) ?>
  · <a href="/certificates?id_holder=<?= (int)$clientId ?>&expired=2&money_left=1"><?= te('certificates.list.money_left') ?></a>
</p>
