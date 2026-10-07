<?php
/** @var array<string,mixed> $client */
/** @var string $statusName */
/** @var string $staffName */
/** @var list<string> $tagNames */
/** @var bool $canEdit */
/** @var string $extraHtml */
$cid = (int)$client['id'];
$tagNames = $tagNames ?? [];
$extraHtml = (string)($extraHtml ?? '');
$gender = $client['gender'] ?? null;
$genderLabel = $gender === null || $gender === ''
    ? '—'
    : ((int)$gender === 1 ? t('clients.gender.m') : t('clients.gender.f'));
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1><?= e((string)($client['name'] ?: ('#' . $cid))) ?></h1>
      <p class="lede"><code><?= e((string)($client['tel'] ?: '—')) ?></code> · <?= e($statusName) ?></p>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-primary" href="/clients/<?= $cid ?>/edit"><?= te('core.common.edit') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>

<div class="detail-grid">
  <div class="widget">
    <h2><?= te('clients.view.profile') ?></h2>
    <dl class="kv">
      <dt>ID</dt><dd><?= $cid ?></dd>
      <dt><?= te('clients.view.phone') ?></dt><dd><?= e((string)($client['tel'] ?: '—')) ?></dd>
      <dt><?= te('clients.view.email') ?></dt><dd><?= e((string)($client['mail'] ?: '—')) ?></dd>
      <dt><?= te('clients.view.gender') ?></dt><dd><?= e($genderLabel) ?></dd>
      <dt><?= te('clients.view.birthday') ?></dt><dd><?php
        $bd = (string)($client['birthday'] ?? '');
        echo $bd !== '' ? e(I18n::formatDate((int)strtotime($bd), 'date')) : '—';
      ?></dd>
      <dt><?= te('clients.view.active') ?></dt><dd><?= ((int)$client['enabled'] === 1) ? te('core.common.yes') : te('core.common.no') ?></dd>
      <dt><?= te('clients.view.status') ?></dt><dd><?= e($statusName) ?></dd>
      <dt><?= te('clients.view.tags') ?></dt>
      <dd>
        <?php if (!$tagNames): ?>
          —
        <?php else: ?>
          <?php foreach ($tagNames as $tn): ?>
            <span class="pill"><?= e($tn) ?></span>
          <?php endforeach; ?>
        <?php endif; ?>
      </dd>
      <dt><?= te('clients.view.registered') ?></dt><dd><?=
        ((int)($client['registered_at'] ?? 0) > 0)
          ? e(I18n::formatDate((int)$client['registered_at'], 'datetime'))
          : '—'
      ?></dd>
      <dt><?= te('clients.view.subscribe') ?></dt><dd><?= !empty($client['subscribe']) ? te('core.common.yes') : te('core.common.no') ?></dd>
      <dt><?= te('clients.view.staff') ?></dt><dd><?=
        $staffName !== ''
          ? e($staffName) . ' <span class="muted">(#' . (int)$client['staff_user_id'] . ')</span>'
          : '—'
      ?></dd>
      <?= $extraHtml ?>
    </dl>
  </div>

  <div class="widget">
    <h2><?= te('clients.view.passport') ?></h2>
    <dl class="kv">
      <dt><?= te('clients.view.fio') ?></dt><dd><?=
        e(trim(implode(' ', array_filter([
            (string)($client['surname'] ?? ''),
            (string)($client['pname'] ?? ''),
            (string)($client['patronymic'] ?? ''),
        ]))) ?: '—')
      ?></dd>
      <dt><?= te('clients.view.passport_no') ?></dt><dd><?=
        e(trim(((string)($client['passport_serie'] ?? '')) . ' ' . ((string)($client['passport_number'] ?? ''))) ?: '—')
      ?></dd>
      <dt><?= te('clients.view.issued_by') ?></dt><dd><?= e((string)($client['passport_issued_by'] ?: '—')) ?></dd>
      <dt><?= te('clients.view.issued_at') ?></dt><dd><?= e((string)($client['passport_issued_at'] ?: '—')) ?></dd>
      <dt><?= te('clients.view.passport_birthday') ?></dt><dd><?= e((string)($client['passport_birthday'] ?: '—')) ?></dd>
      <dt><?= te('clients.view.address_passport') ?></dt><dd><?= e((string)($client['address_passport'] ?: '—')) ?></dd>
      <dt><?= te('clients.view.address_fact') ?></dt><dd><?= e((string)($client['address_fact'] ?: '—')) ?></dd>
    </dl>
  </div>

  <div class="widget">
    <h2><?= te('clients.view.extra') ?></h2>
    <?php
      $notes = trim((string)($client['notes'] ?? ''));
      if ($notes === ''):
    ?>
      <p class="muted">—</p>
    <?php else: ?>
      <p><?= e_nl($notes) ?></p>
    <?php endif; ?>
  </div>
</div>
