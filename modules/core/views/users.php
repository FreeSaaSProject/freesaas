<?php
/** @var array $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var array $statuses */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/users';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
?>
<section class="page-head">
  <p class="lede"><?= t('core.users.lede', ['total' => (int)$total]) ?></p>
</section>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('core.users.search_placeholder') ?>" data-filter-debounce>
  <select name="enabled" data-auto-submit>
    <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('core.users.filter_active') ?></option>
    <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('core.users.filter_disabled') ?></option>
    <option value=""<?= $enabled === '' ? ' selected' : '' ?>><?= te('core.common.all') ?></option>
  </select>
</form>

<?= $pager ?>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.users.col_name'), 'name', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.users.col_login'), 'login', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.users.col_role'), 'role', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.users.col_status'), 'enabled', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $u): ?>
      <tr>
        <td class="muted"><a href="/users/<?= (int)$u['id'] ?>/edit"><?= (int)$u['id'] ?></a></td>
        <td>
          <a href="/users/<?= (int)$u['id'] ?>">
            <?= UsersRepository::badgeHtml((int)$u['id'], false) ?>
            <span class="user-name-full"><?= e((string)($u['name'] ?: '—')) ?></span>
          </a>
        </td>
        <td><code><?= e((string)$u['login']) ?></code></td>
        <td><?= e($statuses[(int)$u['ID_STATUS']] ?? ('#' . $u['ID_STATUS'])) ?></td>
        <td>
          <?php if ((string)$u['enabled'] === '1'): ?>
            <span class="pill ok"><?= te('core.users.pill_active') ?></span>
          <?php else: ?>
            <span class="pill off"><?= te('core.common.off') ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="5" class="muted"><?= te('core.users.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?= $pager ?>
