<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $status */
/** @var string $q */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var string $path */
/** @var int $pending */
$path = (string)($path ?? '/mail/sends');
$showAll = !empty($showAll);
$filters = is_array($filters ?? null) ? $filters : [];
$perPage = (int)($perPage ?? 50);
$pager = ListQuery::pager((int)$total, (int)$page, $perPage, $path, $filters, $showAll);
?>
<section class="page-head">
  <p class="lede"><?= te('mail.sends.title') ?> · <?= te('mail.health.queue', ['n' => (string)(int)$pending]) ?></p>
  <form method="get" class="filters-row" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end" data-auto-filter>
    <?= ListQuery::sortHiddenInputs((string)($filters['sort'] ?? 'id'), (string)($filters['dir'] ?? 'desc'), $perPage) ?>
    <label><?= te('mail.sends.filter_status') ?>
      <select name="status" data-auto-submit>
        <option value=""<?= $status < 0 ? ' selected' : '' ?>><?= te('mail.sends.status_all') ?></option>
        <?php foreach ([1, 2, 3, 100] as $st): ?>
          <option value="<?= $st ?>"<?= $status === $st ? ' selected' : '' ?>><?= te('mail.sends.status.' . $st) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('mail.sends.filter_q') ?>
      <input name="q" value="<?= e($q) ?>" placeholder="<?= te('mail.sends.filter_q_ph') ?>" data-filter-debounce>
    </label>
  </form>
  <?php if (Auth::capability('SUPERADMIN_ACCESS')): ?>
    <p><a href="/mail/settings"><?= te('mail.menu.settings') ?></a></p>
  <?php endif; ?>
</section>

<?= $pager ?>

<?php if ($items === []): ?>
  <p class="muted"><?= te('mail.sends.empty') ?></p>
<?php else: ?>
<table class="data-table">
  <thead>
    <tr>
      <th><?= te('mail.sends.col.id') ?></th>
      <th><?= te('mail.sends.col.to') ?></th>
      <th><?= te('mail.sends.col.subject') ?></th>
      <th><?= te('mail.sends.col.status') ?></th>
      <th><?= te('mail.sends.col.dtcreate') ?></th>
      <th><?= te('mail.sends.col.dtsend') ?></th>
      <th><?= te('mail.sends.col.error') ?></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($items as $row): ?>
    <?php
      $st = (int)($row['STATUS'] ?? 0);
      $subject = (string)($row['SUBJECT'] ?? '');
      if (mb_strlen($subject) > 100) {
          $subject = mb_substr($subject, 0, 100) . '…';
      }
      $toLabel = (string)($row['TO_ADDR'] ?? '');
      if ((string)($row['TO_NAME'] ?? '') !== '') {
          $toLabel = (string)$row['TO_NAME'] . ' <' . $toLabel . '>';
      }
    ?>
    <tr>
      <td><?= (int)$row['ID'] ?></td>
      <td><?= e($toLabel) ?></td>
      <td><?= e($subject) ?></td>
      <td><?= te('mail.sends.status.' . $st) ?></td>
      <td><?= ((int)($row['DTCREATE'] ?? 0) > 0) ? e(I18n::formatDate((int)$row['DTCREATE'], 'datetime')) : '—' ?></td>
      <td><?= ((int)($row['DTSEND'] ?? 0) > 0) ? e(I18n::formatDate((int)$row['DTSEND'], 'datetime')) : '—' ?></td>
      <td><?= e((string)($row['ERROR'] ?? $row['STATUS_DESCR'] ?? '')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?= $pager ?>
<?php endif; ?>
