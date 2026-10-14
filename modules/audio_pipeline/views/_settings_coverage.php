<?php
declare(strict_types=1);
/**
 * @var array<string,mixed> $coverage
 * @var list<array{id:int,name:string}> $statuses
 * @var list<array{id:int,name:string}> $staff
 * @var array<string,mixed>|null $editPosition
 * @var array<string,mixed>|null $editAdmin
 * @var int $editPositionId
 * @var int $editAdminId
 */
$coverage = is_array($coverage ?? null) ? $coverage : AudioCoverageConfig::defaults();
$statuses = is_array($statuses ?? null) ? $statuses : [];
$staff = is_array($staff ?? null) ? $staff : [];
$editPosition = is_array($editPosition ?? null) ? $editPosition : null;
$editAdmin = is_array($editAdmin ?? null) ? $editAdmin : null;
$editPositionId = (int)($editPositionId ?? 0);
$editAdminId = (int)($editAdminId ?? 0);
$posCfg = is_array($editPosition['config'] ?? null) ? $editPosition['config'] : AudioCoverageProfiles::normalizeConfig([]);
$admCfg = is_array($editAdmin['config'] ?? null) ? $editAdmin['config'] : AudioCoverageProfiles::normalizeConfig([]);
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
  <legend><?= te('audio_pipeline.settings.coverage_by_position') ?></legend>
  <p class="muted"><?= te('audio_pipeline.settings.coverage_by_position_hint') ?></p>
  <input type="hidden" name="audio_coverage_position_save" value="1">
  <label><?= te('audio_pipeline.settings.position') ?>
    <select name="cov_position_id" onchange="this.form.submit()">
      <option value="0">—</option>
      <?php foreach ($statuses as $s): ?>
        <option value="<?= (int)$s['id'] ?>"<?= $editPositionId === (int)$s['id'] ? ' selected' : '' ?>>
          <?= e((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if ($editPositionId > 0): ?>
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
    <div class="form-row-inline">
      <label><?= te('audio_pipeline.settings.procedure_buffer') ?>
        <input type="number" min="0" name="cov_pos_buffer" value="<?= (int)($posCfg['procedure_buffer_sec'] ?? 1600) ?>">
      </label>
      <label><?= te('audio_pipeline.settings.min_procedure') ?>
        <input type="number" min="0" name="cov_pos_min" value="<?= (int)($posCfg['min_procedure_sec'] ?? 360) ?>">
      </label>
    </div>
  <?php endif; ?>
</fieldset>
<?php endif; ?>

<?php if ($staff !== []): ?>
<fieldset class="form-fieldset">
  <legend><?= te('audio_pipeline.settings.coverage_by_admin') ?></legend>
  <p class="muted"><?= te('audio_pipeline.settings.coverage_by_admin_hint') ?></p>
  <input type="hidden" name="audio_coverage_admin_save" value="1">
  <label><?= te('audio_pipeline.settings.admin') ?>
    <select name="cov_admin_id" onchange="this.form.submit()">
      <option value="0">—</option>
      <?php foreach ($staff as $u): ?>
        <option value="<?= (int)$u['id'] ?>"<?= $editAdminId === (int)$u['id'] ? ' selected' : '' ?>>
          <?= e((string)$u['name']) ?> (#<?= (int)$u['id'] ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if ($editAdminId > 0): ?>
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
    <div class="form-row-inline">
      <label><?= te('audio_pipeline.settings.procedure_buffer') ?>
        <input type="number" min="0" name="cov_adm_buffer" value="<?= (int)($admCfg['procedure_buffer_sec'] ?? 1600) ?>">
      </label>
      <label><?= te('audio_pipeline.settings.min_procedure') ?>
        <input type="number" min="0" name="cov_adm_min" value="<?= (int)($admCfg['min_procedure_sec'] ?? 360) ?>">
      </label>
    </div>
  <?php endif; ?>
</fieldset>
<?php endif; ?>
