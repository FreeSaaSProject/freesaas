<?php
declare(strict_types=1);
/** @var list<array{id:string,title:string,html:string,href:string}> $cards */
?>
<h1><?= te('client_portal.home.title') ?></h1>
<?php if ($cards === []): ?>
  <p class="muted"><?= te('client_portal.home.empty') ?></p>
<?php else: ?>
  <div class="client-portal-cards">
    <?php foreach ($cards as $card): ?>
      <section class="client-portal-card">
        <h2><a href="<?= e($card['href']) ?>"><?= e($card['title']) ?></a></h2>
        <?= $card['html'] ?>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
