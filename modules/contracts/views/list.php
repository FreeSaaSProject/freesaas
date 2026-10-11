<?php
declare(strict_types=1);
/** @var list<array> $items */
/** @var int $total */
/** @var bool $scoped */
/** @var int $status */
/** @var int $executor */
/** @var int $acceptor */
/** @var string $party */
/** @var list<array> $staff */
/** @var bool $super */
/** @var int $uid */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */

$path = '/contracts';
$sort = $sort ?? 'id';
$dir = $dir ?? 'desc';
$page = $page ?? 1;
$perPage = $perPage ?? 40;
$showAll = !empty($showAll);
$filters = $filters ?? [];
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);

$statusLabels = [
    0 => t('contracts.filter.current'),
    10 => t('contracts.filter.new'),
    11 => t('contracts.filter.in_work'),
    4 => t('contracts.filter.in_work_ok'),
    3 => t('contracts.filter.in_work_late'),
    5 => t('contracts.filter.done'),
    6 => t('contracts.filter.to_pay'),
    9 => t('contracts.filter.failed'),
    2 => t('contracts.filter.overdue_new'),
    1 => t('core.common.all'),
];

$sortLabels = [
    'id' => 'ID',
    'price' => t('contracts.sort.price'),
    'deadline' => t('contracts.sort.deadline'),
    'executor' => t('contracts.sort.executor'),
    'acceptor' => t('contracts.sort.acceptor'),
    'news' => t('contracts.sort.ticket'),
];
?>
<section class="page-head">
  <p class="lede">
    <?= te('contracts.list.count', ['n' => (int)$total]) ?>
    <?php if ($scoped): ?>
      · <?= te('contracts.list.scoped') ?>
    <?php endif; ?>
  </p>
</section>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
  <select name="status" data-auto-submit aria-label="<?= te('contracts.filter.status_aria') ?>">
    <?php foreach ($statusLabels as $sid => $label): ?>
      <option value="<?= (int)$sid ?>"<?= $status === (int)$sid ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($super): ?>
    <select name="executor" data-auto-submit aria-label="<?= te('contracts.filter.executor_aria') ?>">
      <option value="0"><?= te('contracts.filter.executor_all') ?></option>
      <?php foreach ($staff as $u): ?>
        <option value="<?= (int)$u['id'] ?>"<?= $executor === (int)$u['id'] ? ' selected' : '' ?>>
          <?= te('contracts.filter.executor_prefix') ?> <?= e($u['name'] ?: $u['login']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="acceptor" data-auto-submit aria-label="<?= te('contracts.filter.acceptor_aria') ?>">
      <option value="0"><?= te('contracts.filter.acceptor_all') ?></option>
      <?php foreach ($staff as $u): ?>
        <option value="<?= (int)$u['id'] ?>"<?= $acceptor === (int)$u['id'] ? ' selected' : '' ?>>
          <?= te('contracts.filter.acceptor_prefix') ?> <?= e($u['name'] ?: $u['login']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  <?php else: ?>
    <select name="party" data-auto-submit aria-label="<?= te('contracts.filter.party_aria') ?>">
      <option value=""<?= $party === '' ? ' selected' : '' ?>><?= te('contracts.filter.party_all') ?></option>
      <option value="executor"<?= $party === 'executor' ? ' selected' : '' ?>><?= te('contracts.filter.party_executor') ?></option>
      <option value="acceptor"<?= $party === 'acceptor' ? ' selected' : '' ?>><?= te('contracts.filter.party_acceptor') ?></option>
    </select>
  <?php endif; ?>
  <select name="sort" data-auto-submit aria-label="<?= te('core.news.sort_aria') ?>">
    <?php foreach ($sortLabels as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= te('core.news.sort_prefix') ?> <?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="dir" data-auto-submit aria-label="<?= te('core.news.dir_aria') ?>">
    <option value="desc"<?= $dir === 'desc' ? ' selected' : '' ?>>↓ <?= te('core.news.dir_desc') ?></option>
    <option value="asc"<?= $dir === 'asc' ? ' selected' : '' ?>>↑ <?= te('core.news.dir_asc') ?></option>
  </select>
</form>

<?= $pager ?>

<?php if (!$items): ?>
  <div class="muted"><?= te('contracts.list.empty') ?></div>
<?php else: ?>
  <div class="contract-list">
    <?php foreach ($items as $row): ?>
      <?php
        $info = ContractsRepository::statusInfo($row);
        $nid = (int)$row['ID_OBJECT'];
        $cid = (int)$row['ID'];
      ?>
      <article class="contract-row widget">
        <div class="contract-row-head">
          <a href="/news/<?= $nid ?>#c<?= $cid ?>">
            <?= e((string)($row['NEWS_NAME'] ?? t('contracts.list.ticket_n', ['id' => $nid]))) ?>
          </a>
          <span class="pill tone-type"><?= (int)$row['PRICE_OFFER'] ?> ₽</span>
          <?php if ($info['label'] !== ''): ?>
            <span class="contract-status is-<?= e($info['tone']) ?>"><?= e($info['label']) ?></span>
          <?php endif; ?>
        </div>
        <div class="contract-row-meta muted">
          #<?= $cid ?>
          · <?= te('contracts.list.executor_short') ?> <?= UsersRepository::badgeHtml((int)$row['ID_USER'], false) ?>
          <?php if ((int)$row['ID_ACCEPTOR'] > 0): ?>
            · <?= te('contracts.list.acceptor_short') ?> <?= UsersRepository::badgeHtml((int)$row['ID_ACCEPTOR'], false) ?>
          <?php endif; ?>
          <?php if ((int)$row['DT_DEADLINE'] > 0): ?>
            · <?= te('contracts.list.deadline_short') ?> <?= e(I18n::formatDate((int)$row['DT_DEADLINE'], 'date')) ?>
          <?php endif; ?>
        </div>
        <?php
          $payCta = ContractsRepository::paymentCtaHtml($row);
          if ($payCta !== ''):
        ?>
          <div class="contract-actions"><?= $payCta ?></div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?= $pager ?>
