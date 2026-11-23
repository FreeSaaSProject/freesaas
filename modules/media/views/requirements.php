<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var string $scope_table */
/** @var int $scope_id */
?>
<section class="page-head">
  <h1><?= te('media.req.title') ?></h1>
  <p class="muted"><code><?= e((string)$scope_table) ?></code> #<?= (int)$scope_id ?></p>
</section>
<?php if (($message ?? '') !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php require __DIR__ . '/_requirements_form.php'; ?>
