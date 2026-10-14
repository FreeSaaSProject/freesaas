<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $status */
/** @var string $audience */
/** @var string $q */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var string $path */
/** @var int $pending */
$path = (string)($path ?? '/bots/sends');
$showAll = !empty($showAll);
$filters = is_array($filters ?? null) ? $filters : [];
$perPage = (int)($perPage ?? 50);
$pager = ListQuery::pager((int)$total, (int)$page, $perPage, $path, $filters, $showAll);
?>
<section class="page-head">
  <p class="lede"><?= te('bots.sends.title') ?> · <?= te('bots.health.queue', ['n' => $pending]) ?></p>
  <form method="get" class="filters-row" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end" data-auto-filter>
    <?= ListQuery::sortHiddenInputs((string)($filters['sort'] ?? 'id'), (string)($filters['dir'] ?? 'desc'), $perPage) ?>
    <label><?= te('bots.sends.filter_status') ?>
      <select name="status" data-auto-submit>
        <option value=""<?= $status < 0 ? ' selected' : '' ?>><?= te('bots.sends.status_all') ?></option>
        <?php foreach ([1, 2, 3, 100] as $st): ?>
          <option value="<?= $st ?>"<?= $status === $st ? ' selected' : '' ?>><?= te('bots.sends.status.' . $st) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('bots.sends.filter_audience') ?>
      <select name="audience" data-auto-submit>
        <option value=""<?= $audience === '' ? ' selected' : '' ?>><?= te('bots.sends.audience_all') ?></option>
        <option value="staff"<?= $audience === 'staff' ? ' selected' : '' ?>><?= te('bots.settings.audience.staff') ?></option>
        <option value="client"<?= $audience === 'client' ? ' selected' : '' ?>><?= te('bots.settings.audience.client') ?></option>
      </select>
    </label>
    <label><?= te('bots.sends.col.name') ?>
      <input name="q" value="<?= e($q) ?>" placeholder="q" data-filter-debounce>
    </label>
  </form>
  <form method="post" action="/bots/sends/work" style="margin-top:.5rem">
    <input type="hidden" name="limit" value="20">
    <?php if ($audience !== ''): ?><input type="hidden" name="audience" value="<?= e($audience) ?>"><?php endif; ?>
    <button type="submit" class="btn-primary"><?= te('bots.sends.drain') ?></button>
  </form>
</section>

<?= $pager ?>

<?php if ($items === []): ?>
  <p class="muted"><?= te('bots.sends.empty') ?></p>
<?php else: ?>
<table class="data-table">
  <thead>
    <tr>
      <th><?= te('bots.sends.col.id') ?></th>
      <th><?= te('bots.sends.col.user') ?></th>
      <th><?= te('bots.sends.col.audience') ?></th>
      <th><?= te('bots.sends.col.status') ?></th>
      <th><?= te('bots.sends.col.name') ?></th>
      <th><?= te('bots.sends.col.text') ?></th>
      <th><?= te('bots.sends.col.dtsend') ?></th>
      <th><?= te('bots.sends.col.error') ?></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($items as $row): ?>
    <?php
      $st = (int)($row['STATUS'] ?? 0);
      $src = (int)($row['BOT_SOURCE'] ?? 1);
      $aud = $src === 2 ? t('bots.settings.audience.staff') : t('bots.settings.audience.client');
      $stLabel = te('bots.sends.status.' . $st);
      $text = (string)($row['TEXT'] ?? '');
      if (strlen($text) > 120) {
          $text = substr($text, 0, 120) . '…';
      }
    ?>
    <tr>
      <td><?= (int)$row['ID'] ?></td>
      <td><?= (int)$row['ID_USER'] ?></td>
      <td><?= e($aud) ?></td>
      <td><?= e($stLabel) ?></td>
      <td><?= e((string)($row['NAME'] ?? '')) ?></td>
      <td><?= e($text) ?></td>
      <td><?= ((int)($row['DTSEND'] ?? 0) > 0) ? e(I18n::formatDate((int)$row['DTSEND'], 'datetime')) : '—' ?></td>
      <td><?= e((string)($row['ERROR'] ?? $row['STATUS_DESCR'] ?? '')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?= $pager ?>
<?php endif; ?>
