<?php
declare(strict_types=1);
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
/** @var list<array{key:string,label:string}> $extraColumns */
$path = '/users';
$showAll = !empty($showAll);
$extraColumns = is_array($extraColumns ?? null) ? $extraColumns : [];
$hasNum = Database::columnExists('base_users', 'num');
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 5 + ($hasNum ? 1 : 0) + count($extraColumns);
?>
<section class="page-head">
  <p class="lede"><?= t('core.users.lede', ['total' => (int)$total]) ?></p>
  <?php if (Auth::can('core.users')): ?>
  <p><a class="btn" href="<?= e(NavContext::withReturn('/users/new')) ?>"><?= te('core.users.add') ?></a></p>
  <?php endif; ?>
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
        <?php if ($hasNum): ?>
          <?= ListQuery::sortTh(t('core.users.col_num'), 'num', $sort, $dir, $path, $filters) ?>
        <?php endif; ?>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.users.col_name'), 'name', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.users.col_login'), 'login', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.users.col_role'), 'role', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.users.col_status'), 'enabled', $sort, $dir, $path, $filters) ?>
        <?php foreach ($extraColumns as $col): ?>
          <th><?= e((string)($col['label'] ?? $col['key'] ?? '')) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $u): ?>
      <?php
        $uid = (int)$u['id'];
        $isOn = (string)($u['enabled'] ?? '') === '1';
        $canToggle = UsersRepository::canToggleEnabled($uid, $u);
      ?>
      <tr<?= !$isOn ? ' class="is-disabled"' : '' ?>>
        <?php if ($hasNum): ?>
          <?php $ord = (int)($u['num'] ?? 0); ?>
          <td class="muted"><?= $ord > 0 ? $ord : '—' ?></td>
        <?php endif; ?>
        <td class="muted"><a href="/users/<?= $uid ?>/edit"><?= $uid ?></a></td>
        <td>
          <a href="/users/<?= $uid ?>">
            <?= UsersRepository::badgeHtml($uid, false) ?>
            <span class="user-name-full"><?= e((string)($u['name'] ?: '—')) ?></span>
          </a>
        </td>
        <td><code><?= e((string)$u['login']) ?></code></td>
        <td><?= e($statuses[(int)$u['ID_STATUS']] ?? ('#' . $u['ID_STATUS'])) ?></td>
        <td>
          <?php if ($canToggle): ?>
            <form method="post" class="inline-form">
              <input type="hidden" name="action" value="toggle_user">
              <input type="hidden" name="id" value="<?= $uid ?>">
              <label class="switch" title="<?= te('core.users.col_status') ?>">
                <input type="checkbox" name="enabled" value="1"<?= $isOn ? ' checked' : '' ?> onchange="this.form.submit()">
                <span class="switch-slider" aria-hidden="true"></span>
                <span class="visually-hidden"><?= te('core.users.col_status') ?></span>
              </label>
            </form>
          <?php elseif ($isOn): ?>
            <span class="pill ok"><?= te('core.users.pill_active') ?></span>
          <?php else: ?>
            <span class="pill off"><?= te('core.common.off') ?></span>
          <?php endif; ?>
        </td>
        <?php foreach ($extraColumns as $col): ?>
          <?php
            $key = (string)($col['key'] ?? '');
            $val = $key !== '' ? trim((string)($u[$key] ?? '')) : '';
          ?>
          <td class="muted"><?= $val !== '' ? '<code>' . e($val) . '</code>' : '—' ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('core.users.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?= $pager ?>
