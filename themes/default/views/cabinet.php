<?php
declare(strict_types=1);
/** @var string $cabinet_body */
/** @var list<array{id:string,label:string,href:string,current?:bool}> $cabinet_nav */
/** @var array<string,mixed>|null $client */
$cabinet_nav = $cabinet_nav ?? [];
$cabinet_body = $cabinet_body ?? '';
?>
<section class="site-section client-portal">
  <div class="client-portal-toolbar">
    <div>
      <?php if (is_array($client) && !empty($client['name'])): ?>
        <strong><?= e((string)$client['name']) ?></strong>
      <?php endif; ?>
    </div>
    <a href="/cabinet/logout"><?= e(t('client_portal.logout')) ?></a>
  </div>
  <?php if ($cabinet_nav !== []): ?>
    <ul class="client-portal-nav">
      <?php foreach ($cabinet_nav as $item): ?>
        <li>
          <a href="<?= e($item['href']) ?>"<?= !empty($item['current']) ? ' aria-current="page"' : '' ?>>
            <?= e($item['label']) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <div class="client-portal-body">
    <?= $cabinet_body ?>
  </div>
</section>
<link rel="stylesheet" href="/modules/client_portal/assets/css/client_portal.css">
