<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $position */
/** @var array<int,string> $positionOptions */
/** @var array<int,string> $positionFilterOptions */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
/** @var string $path */
/** @var string $listId */
/** @var string $error */
/** @var array<string,string> $roleLabels */
/** @var list<string> $existingScriptNames */
$showAll = !empty($showAll);
$existingScriptNames = is_array($existingScriptNames ?? null) ? $existingScriptNames : [];
$existingNamesJson = json_encode(array_values($existingScriptNames), JSON_UNESCAPED_UNICODE) ?: '[]';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 6 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
$roleLabels = is_array($roleLabels ?? null) ? $roleLabels : [];
$position = (int)($position ?? 0);
$positionOptions = is_array($positionOptions ?? null) ? $positionOptions : [];
$positionFilterOptions = is_array($positionFilterOptions ?? null) ? $positionFilterOptions : [];
$q = (string)($q ?? '');
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions">
    <a class="btn-primary" href="<?= e($path) ?>/new"><?= te('audio_dicts.scripts.btn_new') ?></a>
    <form class="scripts-upload-form" id="scripts-upload-form" method="post" action="<?= e($path) ?>/import" enctype="multipart/form-data">
      <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
      <input type="hidden" name="name" id="scripts-upload-name" value="">
      <label class="btn-secondary scripts-upload-label">
        <?= te('audio_dicts.scripts.btn_upload') ?>
        <input type="file" name="pack" id="scripts-upload-file" accept="application/json,.json" required hidden>
      </label>
    </form>
  </div></div>
  <dialog class="scripts-import-dialog" id="scripts-import-dialog"
          data-existing-names="<?= e($existingNamesJson) ?>"
          data-i18n-parts="<?= e(t('audio_dicts.scripts.pack.preview_parts')) ?>"
          data-i18n-dicts="<?= e(t('audio_dicts.scripts.pack.preview_dicts')) ?>"
          data-i18n-positions="<?= e(t('audio_dicts.scripts.pack.preview_positions')) ?>"
          data-i18n-keys-yes="<?= e(t('audio_dicts.scripts.pack.preview_keys_yes')) ?>"
          data-i18n-keys-no="<?= e(t('audio_dicts.scripts.pack.preview_keys_no')) ?>"
          data-i18n-invalid="<?= e(t('audio_dicts.scripts.pack.err_invalid_json')) ?>"
          data-i18n-name-required="<?= e(t('audio_dicts.err.name_required')) ?>"
          data-i18n-name-taken="<?= e(t('audio_dicts.scripts.err.name_taken', ['name' => '{name}'])) ?>">
    <h2><?= te('audio_dicts.scripts.pack.import_title') ?></h2>
    <label class="scripts-import-name-label"><?= te('audio_dicts.col.name') ?>
      <input type="text" id="scripts-import-name" maxlength="40" required>
    </label>
    <p class="flash error scripts-import-name-err" id="scripts-import-name-err" hidden></p>
    <div class="scripts-import-preview muted" id="scripts-import-preview"></div>
    <div class="form-actions">
      <button type="button" class="btn-secondary" id="scripts-import-cancel"><?= te('core.common.cancel') ?></button>
      <button type="button" class="btn-primary" id="scripts-import-ok"><?= te('audio_dicts.scripts.btn_upload') ?></button>
    </div>
  </dialog>
  <?php endif; ?>
</section>
<?php if ($error !== ''): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('audio_dicts.search_placeholder') ?>" data-filter-debounce>
  <?php if ($positionFilterOptions !== []): ?>
  <label><?= te('audio_dicts.scripts.field.positions') ?>
    <select name="position" data-filter-change>
      <option value="0"><?= te('audio_dicts.filter.all_positions') ?></option>
      <?php foreach ($positionFilterOptions as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= $position === (int)$sid ? ' selected' : '' ?>><?= e((string)$sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>
</form>
<?= $pager ?>
<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data scripts-list-table">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('audio_dicts.col.name'), 'name', $sort, $dir, $path, $filters) ?>
  <th><?= te('audio_dicts.scripts.col.positions') ?></th>
  <th><?= te('audio_dicts.scripts.col.parts') ?></th>
  <th><?= te('audio_dicts.scripts.col.enabled') ?></th>
</tr></thead>
<tbody>
<?php foreach ($items as $c):
    $enabled = empty($c['disabled']);
    $parts = is_array($c['parts'] ?? null) ? $c['parts'] : [];
    $posIds = is_array($c['position_ids'] ?? null) ? $c['position_ids'] : [];
    $ratingOn = !empty($c['rating_enabled']);
    $dayOn = !empty($c['day_bonus']);
    $daySum = (int)($c['day_bonus_sum'] ?? 0);
?>
<tr<?= !$enabled ? ' class="is-disabled"' : '' ?>>
  <?= BulkActions::td($listId, (int)$c['id']) ?>
  <td class="muted"><a href="<?= e($path) ?>/<?= (int)$c['id'] ?>"><?= (int)$c['id'] ?></a></td>
  <td>
    <a href="<?= e($path) ?>/<?= (int)$c['id'] ?>"><?= e((string)$c['name']) ?></a>
    <div class="scripts-list-flags">
      <span class="scripts-badge <?= $ratingOn ? 'scripts-badge--rating' : 'scripts-badge--off' ?>"
            title="<?= te('audio_dicts.scripts.field.rating_enabled') ?>">
        <?= te('audio_dicts.scripts.badge.rating') ?><?= $ratingOn ? '' : '—' ?>
      </span>
      <span class="scripts-badge <?= $dayOn ? 'scripts-badge--day-bonus' : 'scripts-badge--off' ?>"
            title="<?= te('audio_dicts.scripts.field.day_bonus') ?>">
        <?php if ($dayOn): ?>
          <?= te('audio_dicts.scripts.badge.day_bonus') ?><?= $daySum > 0 ? ' ' . $daySum : '' ?>
        <?php else: ?>
          <?= te('audio_dicts.scripts.badge.day_bonus_off') ?>
        <?php endif; ?>
      </span>
    </div>
  </td>
  <td class="scripts-list-positions">
    <?php if ($posIds === []): ?>
      <span class="muted"><?= te('audio_dicts.scripts.positions_all') ?></span>
    <?php else: ?>
      <span class="scripts-pos-chips">
        <?php foreach ($posIds as $pid):
            $pid = (int)$pid;
            $pname = $positionOptions[$pid] ?? ('#' . $pid);
        ?>
          <a class="scripts-pos-chip" href="<?= e(ListQuery::url($path, array_merge($filters, ['position' => (string)$pid]), ['page' => null])) ?>"><?= e((string)$pname) ?></a>
        <?php endforeach; ?>
      </span>
    <?php endif; ?>
  </td>
  <td class="scripts-list-parts">
    <?php if ($parts === []): ?>
      <span class="muted"><?= te('audio_dicts.scripts.parts_empty') ?></span>
    <?php else: ?>
      <div class="scripts-parts-tiles">
        <?php foreach ($parts as $i => $part):
            $roles = is_array($part['roles'] ?? null) ? $part['roles'] : ['ordinary'];
            $timeout = (int)($part['timeout'] ?? 0);
            $extendTimeout = !array_key_exists('extend_timeout', $part) || !empty($part['extend_timeout']);
            $kpi = (int)($part['kpi'] ?? 0);
            $gateRed = (float)($part['gate_red_max'] ?? 0);
            $gateYellow = (float)($part['gate_yellow_max'] ?? 0);
            $showGate = $dayOn && ($gateRed > 0 || $gateYellow > 0);
        ?>
          <?php if ($i > 0): ?><span class="scripts-parts-arrow" aria-hidden="true"></span><?php endif; ?>
          <a class="scripts-part-tile" href="<?= e($path) ?>/<?= (int)$c['id'] ?>">
            <span class="scripts-part-tile-num"><?= $i + 1 ?></span>
            <span class="scripts-part-tile-body">
              <span class="scripts-part-tile-name"><?= e((string)$part['name']) ?></span>
              <span class="scripts-part-tile-meta">
                <?php foreach ($roles as $role):
                    if ($role === 'ordinary') {
                        continue;
                    }
                ?>
                  <span class="scripts-badge scripts-badge--<?= e((string)$role) ?>"><?= e((string)($roleLabels[$role] ?? $role)) ?></span>
                <?php endforeach; ?>
                <?php if ($timeout > 0):
                    $toTitle = $extendTimeout
                        ? t('audio_dicts.scripts.field.extend_timeout')
                        : t('audio_dicts.scripts.flow.timeout_no_extend');
                ?>
                  <span class="scripts-badge scripts-badge--timeout" title="<?= e($toTitle) ?>">
                    <?= e(t('audio_dicts.scripts.flow.timeout', ['n' => (string)$timeout])) ?>
                    <span class="scripts-timeout-mark <?= $extendTimeout ? 'scripts-timeout-mark--extend' : 'scripts-timeout-mark--dot' ?>" aria-hidden="true"><?= $extendTimeout ? '↗' : '●' ?></span>
                  </span>
                <?php endif; ?>
                <?php if ($kpi > 0): ?>
                  <span class="scripts-badge scripts-badge--kpi"><?= e(t('audio_dicts.scripts.flow.kpi', ['n' => (string)$kpi])) ?></span>
                <?php endif; ?>
                <?php if ($showGate): ?>
                  <span class="scripts-badge scripts-badge--gate-red" title="<?= e(t('audio_dicts.scripts.field.gate_red_max')) ?>">
                    <span class="scripts-gate-dot" aria-hidden="true">●</span>
                    <?= e(t('audio_dicts.scripts.flow.gate_red', ['n' => rtrim(rtrim(number_format($gateRed, 1, '.', ''), '0'), '.')])) ?>
                  </span>
                <?php endif; ?>
                <?php
                  $scorePts = (int)($part['score'] ?? 0);
                  $ratePts = (int)($part['score_rate'] ?? 0);
                ?>
                <?php if ($scorePts !== 0): ?>
                  <span class="scripts-badge<?= $scorePts < 0 ? ' scripts-badge--penalty' : '' ?>"><?= ($scorePts > 0 ? '+' : '') . $scorePts ?></span>
                <?php endif; ?>
                <?php if ($ratePts !== 0): ?>
                  <span class="scripts-badge <?= $ratePts < 0 ? 'scripts-badge--penalty' : 'scripts-badge--rating' ?>"><?= ($ratePts > 0 ? '+' : '') . $ratePts ?> ®</span>
                <?php endif; ?>
              </span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($canEdit): ?>
    <label class="switch scripts-enabled-switch" title="<?= $enabled ? te('audio_dicts.scripts.enabled_on') : te('audio_dicts.scripts.enabled_off') ?>">
      <input type="checkbox" form="script-toggle-<?= (int)$c['id'] ?>" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?> onchange="document.getElementById('script-toggle-<?= (int)$c['id'] ?>').submit()">
      <span class="switch-slider" aria-hidden="true"></span>
      <span class="visually-hidden"><?= te('audio_dicts.scripts.col.enabled') ?></span>
    </label>
    <span class="scripts-enabled-label muted"><?= $enabled ? te('audio_dicts.scripts.enabled_on') : te('audio_dicts.scripts.enabled_off') ?></span>
    <?php else: ?>
      <span class="muted"><?= $enabled ? te('audio_dicts.scripts.enabled_on') : te('audio_dicts.scripts.enabled_off') ?></span>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('audio_dicts.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?php if ($canEdit): ?>
  <?php foreach ($items as $c): ?>
  <form id="script-toggle-<?= (int)$c['id'] ?>" method="post" action="<?= e($actionUrl) ?>" class="visually-hidden" aria-hidden="true">
    <input type="hidden" name="action" value="toggle_disabled">
    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  </form>
  <?php endforeach; ?>
<?php endif; ?>
<?= $pager ?>
