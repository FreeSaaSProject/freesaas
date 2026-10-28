<?php
declare(strict_types=1);
/**
 * Enable control → activate wizard (risk ack lives on /modules/{id}/activate).
 *
 * @var string $moduleId
 * @var string $maturity
 * @var bool $requiresRiskAck
 * @var string $retPath
 * @var string $formAction  unused (kept for callers)
 * @var array{en?:string,ru?:string}|null $agentNote
 */
$activateHref = '/modules/' . rawurlencode($moduleId) . '/activate';
?>
<a class="btn-primary btn-sm" href="<?= e($activateHref) ?>"><?= te('core.modules.btn_enable') ?></a>
<?php if (!empty($requiresRiskAck)): ?>
  <span class="pill maturity-<?= e((string)($maturity ?? 'beta')) ?>"><?= te('core.modules.maturity.' . (string)($maturity ?? 'beta')) ?></span>
<?php endif; ?>
