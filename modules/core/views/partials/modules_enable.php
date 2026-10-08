<?php
/**
 * Enable control with alpha/beta risk ack panel.
 *
 * @var string $moduleId
 * @var string $maturity
 * @var bool $requiresRiskAck
 * @var string $retPath
 * @var string $formAction  empty = current URL
 * @var array{en?:string,ru?:string}|null $agentNote
 */
$formAction = (string)($formAction ?? '');
$requiresRiskAck = !empty($requiresRiskAck);
$maturity = (string)($maturity ?? 'release');
$stageLabel = t('core.modules.maturity.' . $maturity);
$agentLine = '';
if (is_array($agentNote ?? null)) {
    $agentLine = ModuleCatalog::display($agentNote);
}
?>
<?php if (!$requiresRiskAck): ?>
  <form method="post" class="inline-form"<?= $formAction !== '' ? ' action="' . e($formAction) . '"' : '' ?>>
    <input type="hidden" name="module" value="<?= e($moduleId) ?>">
    <input type="hidden" name="action" value="enable">
    <input type="hidden" name="ret" value="<?= e($retPath) ?>">
    <button class="btn-primary btn-sm" type="submit"><?= te('core.modules.btn_enable') ?></button>
  </form>
<?php else: ?>
  <div class="modules-risk-wrap">
    <button class="btn-primary btn-sm" type="button" data-modules-risk-show>
      <?= te('core.modules.btn_enable') ?>
    </button>
    <div class="modules-risk flash flash-warn" data-modules-risk hidden>
      <strong class="modules-risk-title"><?= te('core.modules.risk_title') ?></strong>
      <p class="modules-risk-body"><?= te('core.modules.risk_body', ['stage' => $stageLabel]) ?></p>
      <?php if ($agentLine !== ''): ?>
        <p class="modules-risk-agent"><?= te('core.modules.risk_agent', ['note' => $agentLine]) ?></p>
      <?php endif; ?>
      <div class="modules-risk-actions">
        <button type="button" class="btn-secondary btn-sm" data-modules-risk-cancel><?= te('core.modules.risk_cancel') ?></button>
        <form method="post" class="inline-form"<?= $formAction !== '' ? ' action="' . e($formAction) . '"' : '' ?>>
          <input type="hidden" name="module" value="<?= e($moduleId) ?>">
          <input type="hidden" name="action" value="enable">
          <input type="hidden" name="ret" value="<?= e($retPath) ?>">
          <input type="hidden" name="accept_risk" value="1">
          <button class="btn-primary btn-sm" type="submit"><?= te('core.modules.risk_accept') ?></button>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>
