<?php
declare(strict_types=1);
/**
 * @var array<string,mixed> $coverage
 * @var list<array{id:int,name:string}> $statuses
 * @var list<array{id:int,name:string}> $staff
 * @var list<array<string,mixed>> $profiles
 * @var array<string,mixed>|null $editPosition
 * @var array<string,mixed>|null $editAdmin
 * @var int $editPositionId
 * @var int $editAdminId
 * @var bool $editPositionFlagOk
 * @var bool $editAdminFlagOk
 */
$coverage = is_array($coverage ?? null) ? $coverage : AudioCoverageConfig::defaults();
$statuses = is_array($statuses ?? null) ? $statuses : [];
$staff = is_array($staff ?? null) ? $staff : [];
$profiles = is_array($profiles ?? null) ? $profiles : [];
$editPosition = is_array($editPosition ?? null) ? $editPosition : null;
$editAdmin = is_array($editAdmin ?? null) ? $editAdmin : null;
$editPositionId = (int)($editPositionId ?? 0);
$editAdminId = (int)($editAdminId ?? 0);
$editPositionFlagOk = !empty($editPositionFlagOk);
$editAdminFlagOk = !empty($editAdminFlagOk);
$posCfg = is_array($editPosition['config'] ?? null) ? $editPosition['config'] : AudioCoverageProfiles::normalizeConfig([]);
$admCfg = is_array($editAdmin['config'] ?? null) ? $editAdmin['config'] : AudioCoverageProfiles::normalizeConfig([]);
$settingsBase = '/audio/settings';
$coverageTabQ = static function (array $extra = []) use ($settingsBase): string {
    $q = array_merge(['tab' => 'coverage'], $extra);
    $q = array_filter($q, static function ($v) {
        return $v !== '' && $v !== null && $v !== 0 && $v !== '0';
    });

    return $settingsBase . '?' . http_build_query($q);
};

/** @var array<int,array<string,mixed>> $posProfiles */
$posProfiles = [];
/** @var list<array<string,mixed>> $adminProfiles */
$adminProfiles = [];
foreach ($profiles as $pr) {
    if (!is_array($pr)) {
        continue;
    }
    if ((string)($pr['kind'] ?? '') === 'admin' || (int)($pr['admin_id'] ?? 0) > 0) {
        $adminProfiles[] = $pr;
    } else {
        $posProfiles[(int)($pr['position_id'] ?? 0)] = $pr;
    }
}

$mark = static function (bool $on): string {
    if ($on) {
        return '<span class="cov-mark cov-mark--on" aria-hidden="true">✓</span>';
    }

    return '<span class="cov-mark cov-mark--off" aria-hidden="true">○</span>';
};
?>
<fieldset class="form-fieldset">
  <legend><?= te('audio_pipeline.settings.coverage') ?></legend>
  <p class="muted"><?= te('audio_pipeline.settings.coverage_hint') ?></p>
  <input type="hidden" name="audio_coverage_save" value="1">
  <label class="checkbox">
    <input type="checkbox" name="disable_outside_coverage" value="1"<?= !empty($coverage['disable_outside_coverage']) ? ' checked' : '' ?>>
    <?= te('audio_pipeline.settings.disable_outside') ?>
  </label>
  <p class="muted small"><?= te('audio_pipeline.settings.disable_outside_hint') ?></p>
  <label class="checkbox">
    <input type="checkbox" name="match_procedures" value="1"<?= !empty($coverage['match_procedures']) ? ' checked' : '' ?>>
    <?= te('audio_pipeline.settings.match_procedures') ?>
  </label>
  <p class="muted small"><?= te('audio_pipeline.settings.match_procedures_hint') ?></p>
  <div class="form-row-inline">
    <label><?= te('audio_pipeline.settings.procedure_buffer') ?>
      <input type="number" min="0" name="procedure_buffer_sec" value="<?= (int)($coverage['procedure_buffer_sec'] ?? 1600) ?>">
    </label>
    <label><?= te('audio_pipeline.settings.min_procedure') ?>
      <input type="number" min="0" name="min_procedure_sec" value="<?= (int)($coverage['min_procedure_sec'] ?? 360) ?>">
    </label>
  </div>
</fieldset>

<?php if ($statuses !== []): ?>
<fieldset class="form-fieldset">
  <legend><?= te('audio_pipeline.settings.coverage_profiles') ?></legend>
  <p class="muted"><?= te('audio_pipeline.settings.coverage_profiles_hint') ?></p>
  <div class="table-wrap">
    <table class="data-table cov-profiles-table">
      <thead>
        <tr>
          <th><?= te('audio_pipeline.settings.coverage_col_role') ?></th>
          <th class="cov-col-mark" title="<?= e(t('audio_pipeline.settings.coverage_col_enabled')) ?>"><?= te('audio_pipeline.settings.coverage_col_enabled_short') ?></th>
          <th class="cov-col-mark" title="<?= e(t('audio_pipeline.settings.coverage_col_disable')) ?>"><?= te('audio_pipeline.settings.coverage_col_disable_short') ?></th>
          <th class="cov-col-mark" title="<?= e(t('audio_pipeline.settings.coverage_col_match')) ?>"><?= te('audio_pipeline.settings.coverage_col_match_short') ?></th>
          <th class="cov-col-mark" title="<?= e(t('audio_pipeline.settings.coverage_col_flag')) ?>"><?= te('audio_pipeline.settings.coverage_col_flag_short') ?></th>
          <th class="cov-col-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($statuses as $s): ?>
          <?php
            $sid = (int)$s['id'];
            $pr = $posProfiles[$sid] ?? null;
            $hasProfile = is_array($pr);
            $profileId = $hasProfile ? (int)($pr['id'] ?? 0) : 0;
            $cfg = $hasProfile && is_array($pr['config'] ?? null) ? $pr['config'] : [];
            $enabled = $hasProfile && !empty($pr['enabled']);
            $disableOn = $enabled && !empty($cfg['disable_outside_coverage']);
            $matchOn = $enabled && !empty($cfg['match_procedures']);
            $flagOk = AudioCoverageProfiles::positionHasDisableFlag($sid);
            $editHref = $coverageTabQ(['cov_position_id' => $sid]);
            $isEditing = $editPositionId === $sid;
          ?>
          <tr class="<?= $isEditing ? 'cov-row--editing' : '' ?><?= $enabled ? '' : ' cov-row--off' ?>">
            <td>
              <a class="cov-scope-link" href="<?= e($editHref) ?>">
                <strong><?= e((string)$s['name']) ?></strong>
                <span class="muted">#<?= $sid ?></span>
              </a>
              <?php if (!$enabled): ?>
                <span class="muted small"><?= te('audio_pipeline.settings.coverage_not_enabled') ?></span>
              <?php endif; ?>
            </td>
            <td class="cov-col-mark"><?= $mark($enabled) ?></td>
            <td class="cov-col-mark"><?= $mark($disableOn) ?></td>
            <td class="cov-col-mark"><?= $mark($matchOn) ?></td>
            <td class="cov-col-mark"><?= $mark($flagOk) ?></td>
            <td class="cov-col-actions">
              <a class="cov-icon-btn" href="<?= e($editHref) ?>"
                 title="<?= e(t('core.common.edit')) ?>"
                 aria-label="<?= e(t('core.common.edit')) ?>">✎</a>
              <?php if ($profileId > 0): ?>
                <button type="submit" class="cov-icon-btn cov-icon-btn--danger"
                        name="audio_coverage_delete_id" value="<?= $profileId ?>"
                        title="<?= e(t('core.common.delete')) ?>"
                        aria-label="<?= e(t('core.common.delete')) ?>"
                        onclick="return confirm(<?= tj('audio_pipeline.settings.coverage_delete_confirm') ?>)">✕</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($editPositionId > 0): ?>
    <?php
      $editPosName = '#' . $editPositionId;
      foreach ($statuses as $s) {
          if ((int)$s['id'] === $editPositionId) {
              $editPosName = (string)$s['name'];
              break;
          }
      }
    ?>
    <div class="cov-editor">
      <h3 class="subhead"><?= te('audio_pipeline.settings.coverage_edit_role', [
          'name' => $editPosName,
          'id' => (string)$editPositionId,
      ]) ?></h3>
      <p class="muted"><?= te('audio_pipeline.settings.coverage_by_position_hint') ?></p>
      <input type="hidden" name="audio_coverage_position_save" value="1">
      <input type="hidden" name="cov_position_id" value="<?= $editPositionId ?>">
      <label class="checkbox">
        <input type="checkbox" name="cov_pos_enabled" value="1"<?= !empty($editPosition['enabled']) ? ' checked' : '' ?>>
        <?= te('audio_pipeline.settings.profile_enabled') ?>
      </label>
      <label class="checkbox">
        <input type="checkbox" name="cov_pos_match_procedures" value="1"<?= !empty($posCfg['match_procedures']) ? ' checked' : '' ?>>
        <?= te('audio_pipeline.settings.match_procedures') ?>
      </label>
      <label class="checkbox">
        <input type="checkbox" name="cov_pos_disable_outside" value="1"<?= !empty($posCfg['disable_outside_coverage']) ? ' checked' : '' ?>>
        <?= te('audio_pipeline.settings.disable_outside') ?>
      </label>
      <label class="checkbox cov-flag-readonly">
        <input type="checkbox" disabled<?= $editPositionFlagOk ? ' checked' : '' ?>>
        <?= te('audio_pipeline.settings.flag_checkbox') ?>
      </label>
      <?php if (!$editPositionFlagOk): ?>
        <p class="flash flash-warn"><?= te('audio_pipeline.settings.flag_warn_position') ?></p>
      <?php else: ?>
        <p class="muted small"><?= te('audio_pipeline.settings.flag_ok_hint') ?></p>
      <?php endif; ?>
      <p class="muted small"><?= te('audio_pipeline.settings.flag_edit_elsewhere') ?></p>
      <div class="form-row-inline">
        <label><?= te('audio_pipeline.settings.procedure_buffer') ?>
          <input type="number" min="0" name="cov_pos_buffer" value="<?= (int)($posCfg['procedure_buffer_sec'] ?? 1600) ?>">
        </label>
        <label><?= te('audio_pipeline.settings.min_procedure') ?>
          <input type="number" min="0" name="cov_pos_min" value="<?= (int)($posCfg['min_procedure_sec'] ?? 360) ?>">
        </label>
      </div>
    </div>
  <?php endif; ?>
</fieldset>
<?php endif; ?>

<?php if ($adminProfiles !== [] || $staff !== []): ?>
<fieldset class="form-fieldset">
  <legend><?= te('audio_pipeline.settings.coverage_by_admin') ?></legend>
  <p class="muted"><?= te('audio_pipeline.settings.coverage_by_admin_hint') ?></p>

  <?php if ($adminProfiles !== []): ?>
    <div class="table-wrap">
      <table class="data-table cov-profiles-table">
        <thead>
          <tr>
            <th><?= te('audio_pipeline.settings.coverage_col_staff') ?></th>
            <th class="cov-col-mark"><?= te('audio_pipeline.settings.coverage_col_enabled_short') ?></th>
            <th class="cov-col-mark"><?= te('audio_pipeline.settings.coverage_col_disable_short') ?></th>
            <th class="cov-col-mark"><?= te('audio_pipeline.settings.coverage_col_match_short') ?></th>
            <th class="cov-col-mark"><?= te('audio_pipeline.settings.coverage_col_flag_short') ?></th>
            <th class="cov-col-actions"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($adminProfiles as $pr): ?>
            <?php
              $aid = (int)($pr['admin_id'] ?? 0);
              $profileId = (int)($pr['id'] ?? 0);
              $cfg = is_array($pr['config'] ?? null) ? $pr['config'] : [];
              $enabled = !empty($pr['enabled']);
              $editHref = $coverageTabQ(['cov_admin_id' => $aid]);
              $isEditing = $editAdminId === $aid;
            ?>
            <tr class="<?= $isEditing ? 'cov-row--editing' : '' ?><?= $enabled ? '' : ' cov-row--off' ?>">
              <td>
                <a class="cov-scope-link" href="<?= e($editHref) ?>">
                  <strong><?= e((string)($pr['label'] ?? ('#' . $aid))) ?></strong>
                  <span class="muted">#<?= $aid ?></span>
                </a>
              </td>
              <td class="cov-col-mark"><?= $mark($enabled) ?></td>
              <td class="cov-col-mark"><?= $mark($enabled && !empty($cfg['disable_outside_coverage'])) ?></td>
              <td class="cov-col-mark"><?= $mark($enabled && !empty($cfg['match_procedures'])) ?></td>
              <td class="cov-col-mark"><?= $mark(!empty($pr['flag_ok'])) ?></td>
              <td class="cov-col-actions">
                <a class="cov-icon-btn" href="<?= e($editHref) ?>"
                   title="<?= e(t('core.common.edit')) ?>"
                   aria-label="<?= e(t('core.common.edit')) ?>">✎</a>
                <?php if ($profileId > 0): ?>
                  <button type="submit" class="cov-icon-btn cov-icon-btn--danger"
                          name="audio_coverage_delete_id" value="<?= $profileId ?>"
                          title="<?= e(t('core.common.delete')) ?>"
                          aria-label="<?= e(t('core.common.delete')) ?>"
                          onclick="return confirm(<?= tj('audio_pipeline.settings.coverage_delete_confirm') ?>)">✕</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($staff !== []): ?>
    <label><?= te('audio_pipeline.settings.admin') ?>
      <select onchange="location.href='<?= e($settingsBase) ?>?tab=coverage' + (parseInt(this.value, 10) > 0 ? ('&cov_admin_id=' + encodeURIComponent(this.value)) : '')">
        <option value="0"><?= te('audio_pipeline.settings.coverage_pick_staff') ?></option>
        <?php foreach ($staff as $u): ?>
          <option value="<?= (int)$u['id'] ?>"<?= $editAdminId === (int)$u['id'] ? ' selected' : '' ?>>
            <?= e((string)$u['name']) ?> (#<?= (int)$u['id'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>

  <?php if ($editAdminId > 0): ?>
    <div class="cov-editor">
      <input type="hidden" name="audio_coverage_admin_save" value="1">
      <input type="hidden" name="cov_admin_id" value="<?= $editAdminId ?>">
      <label class="checkbox">
        <input type="checkbox" name="cov_adm_enabled" value="1"<?= !empty($editAdmin['enabled']) ? ' checked' : '' ?>>
        <?= te('audio_pipeline.settings.profile_enabled') ?>
      </label>
      <label class="checkbox">
        <input type="checkbox" name="cov_adm_match_procedures" value="1"<?= !empty($admCfg['match_procedures']) ? ' checked' : '' ?>>
        <?= te('audio_pipeline.settings.match_procedures') ?>
      </label>
      <label class="checkbox">
        <input type="checkbox" name="cov_adm_disable_outside" value="1"<?= !empty($admCfg['disable_outside_coverage']) ? ' checked' : '' ?>>
        <?= te('audio_pipeline.settings.disable_outside') ?>
      </label>
      <label class="checkbox cov-flag-readonly">
        <input type="checkbox" disabled<?= $editAdminFlagOk ? ' checked' : '' ?>>
        <?= te('audio_pipeline.settings.flag_checkbox') ?>
      </label>
      <?php if (!$editAdminFlagOk): ?>
        <p class="flash flash-warn"><?= te('audio_pipeline.settings.flag_warn_admin') ?></p>
      <?php else: ?>
        <p class="muted small"><?= te('audio_pipeline.settings.flag_ok_hint') ?></p>
      <?php endif; ?>
      <p class="muted small"><?= te('audio_pipeline.settings.flag_edit_elsewhere') ?></p>
      <div class="form-row-inline">
        <label><?= te('audio_pipeline.settings.procedure_buffer') ?>
          <input type="number" min="0" name="cov_adm_buffer" value="<?= (int)($admCfg['procedure_buffer_sec'] ?? 1600) ?>">
        </label>
        <label><?= te('audio_pipeline.settings.min_procedure') ?>
          <input type="number" min="0" name="cov_adm_min" value="<?= (int)($admCfg['min_procedure_sec'] ?? 360) ?>">
        </label>
      </div>
    </div>
  <?php endif; ?>
</fieldset>
<?php endif; ?>
