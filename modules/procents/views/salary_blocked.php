<?php
declare(strict_types=1);
/** @var string $gateHtml */
?>
<section class="page-head">
  <p class="lede"><?= te('payments.salary_gate.lede') ?></p>
</section>
<?= $gateHtml /* trusted HTML from SalaryVisibility::inviteHtml */ ?>
