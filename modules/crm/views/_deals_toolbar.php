<?php
declare(strict_types=1);
/** @var string $view list|kanban */
/** @var array<string,mixed> $filters */
/** @var array<int,string> $pipelines */
/** @var array<int,string> $staff */
$isKanban = $view === 'kanban';
$base = $filters;
unset($base['page'], $base['sort'], $base['dir']);
?>
<section class="page-head">
  <div class="page-head-row">
    <div class="crm-view-switch" role="tablist" aria-label="<?= te('crm.deals.view_aria') ?>">
      <a class="<?= $isKanban ? '' : 'is-active' ?>" href="<?= e(ListQuery::url('/crm/deals', $base, ['view' => ''])) ?>"
         data-nav-no-ret><?= te('crm.deals.view_list') ?></a>
      <a class="<?= $isKanban ? 'is-active' : '' ?>" href="<?= e(ListQuery::url('/crm/deals', $base, ['view' => 'kanban'])) ?>"
         data-nav-no-ret><?= te('crm.deals.view_kanban') ?></a>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-primary" href="/crm/deals/quick"><?= te('crm.quick.button') ?></a>
      <a class="btn-secondary" href="/crm/deals/new"><?= te('crm.deals.new') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>
<form class="filters" method="get" action="/crm/deals" data-auto-filter>
  <?php if ($isKanban): ?><input type="hidden" name="view" value="kanban"><?php endif; ?>
  <?php if (!$isKanban): ?><?= ListQuery::sortHiddenInputs((string)$sort, (string)$dir, (int)$perPage) ?><?php endif; ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('crm.deals.search') ?>" data-filter-debounce>
  <select name="pipeline_id" aria-label="<?= te('crm.filter.pipeline') ?>">
    <?php if (!$isKanban): ?><option value=""><?= te('crm.filter.pipeline') ?></option><?php endif; ?>
    <?php foreach ($pipelines as $pid => $pname): ?>
      <option value="<?= (int)$pid ?>"<?= (int)$pipelineId === (int)$pid ? ' selected' : '' ?>><?= e((string)$pname) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="responsible" aria-label="<?= te('crm.col.responsible') ?>">
    <option value=""><?= te('crm.filter.responsible_any') ?></option>
    <option value="me"<?= $responsibleRaw === 'me' ? ' selected' : '' ?>><?= te('crm.filter.responsible_me') ?></option>
    <?php foreach ($staff as $uid => $uname): ?>
      <option value="<?= (int)$uid ?>"<?= $responsibleRaw === (string)$uid ? ' selected' : '' ?>><?= e((string)$uname) ?></option>
    <?php endforeach; ?>
  </select>
</form>
