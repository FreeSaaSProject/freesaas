<?php
declare(strict_types=1);
/**
 * @var int $clientId
 * @var int $total
 * @var array<string,mixed>|null $profile
 * @var bool $canMedical
 * @var bool $canPrint
 */
?>
<p class="docs-client-profile">
  <a href="/documents?client=<?= (int)$clientId ?>"><?= te('docs.client.docs', ['n' => $total]) ?></a>
  <?php if ($canMedical): ?>
    · <a href="/documents/clients/<?= (int)$clientId ?>/profile"><?= te('docs.client.profile') ?></a>
    <?php if ($profile !== null): ?>
      <span class="docs-status docs-status--<?= e((string)$profile['status']) ?>"><?= te('docs.status.' . $profile['status']) ?></span>
    <?php endif; ?>
    <?php if ($canPrint): ?>
      · <a href="/documents/clients/<?= (int)$clientId ?>/medcard"><?= te('docs.client.dossier') ?></a>
    <?php endif; ?>
  <?php endif; ?>
</p>
