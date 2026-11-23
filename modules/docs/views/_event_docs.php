<?php
declare(strict_types=1);
/**
 * Injected into calendar event edit (inside the event form — links only, no nested forms).
 *
 * @var int $eventId
 * @var int $clientId
 * @var list<array<string,mixed>> $instances
 * @var list<array<string,mixed>> $manualDefs
 * @var bool $blocking
 */
$canPrint = Auth::can('docs.print');
$canMedical = Auth::can('docs.medical');
$canOpenDoc = Auth::can('docs.edit') || Auth::can('docs.view');
?>
<fieldset class="docs-event-block">
  <legend><?= te('docs.event.sec') ?></legend>
  <?php if ($blocking): ?>
    <p class="flash flash-warn"><?= te('docs.event.medcard_blocking') ?></p>
  <?php endif; ?>
  <?php if ($instances === []): ?>
    <p class="muted"><?= te('docs.event.empty') ?></p>
  <?php else: ?>
    <table class="data docs-event-table">
      <tbody>
      <?php foreach ($instances as $inst):
          $kind = (string)$inst['kind'];
          $code = (string)($inst['def_code'] ?? '');
          $isSpecialtyVisit = $kind === 'medcard_visit' && $code !== '' && $code !== 'medcard_visit';
          $label = $isSpecialtyVisit
              ? t('docs.event.visit_sheet', ['name' => (string)$inst['def_name']])
              : (string)$inst['def_name'];
          $printed = (int)($inst['dt_print'] ?? 0) > 0;
          $printHref = '/documents/' . (int)$inst['id'] . '/print';
          // Specialty visit sheets feed form 025/u — official print is the client dossier.
          if ($isSpecialtyVisit && $canMedical && $canPrint && $clientId > 0) {
              $printHref = '/documents/clients/' . $clientId . '/medcard?event=' . (int)$eventId;
          }
          ?>
        <tr>
          <td class="docs-event-name">
            <?php if ($canOpenDoc): ?>
              <a href="/documents/<?= (int)$inst['id'] ?>/edit"><?= e($label) ?></a>
            <?php else: ?>
              <?= e($label) ?>
            <?php endif; ?>
          </td>
          <td class="docs-event-meta">
            <span class="docs-status docs-status--<?= e((string)$inst['status']) ?>"><?= te('docs.status.' . $inst['status']) ?></span>
            <?php if (DocsInstancesRepository::confirmReady() && $inst['status'] !== 'void'): ?>
              <?php if ((int)($inst['dt_confirmed'] ?? 0) > 0): ?>
                <span class="docs-status docs-status--confirmed"><?= te('docs.confirm.badge') ?></span>
              <?php elseif (in_array($kind, ['medcard_visit', 'check'], true)): ?>
                <span class="docs-status docs-status--unconfirmed"><?= te('docs.confirm.badge_pending') ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="docs-event-actions">
            <?php if ($canPrint && $inst['status'] !== 'void'): ?>
              <a class="docs-event-print" href="<?= e($printHref) ?>"
                 title="<?= te($printed && !$isSpecialtyVisit ? 'docs.edit.open_print' : ($isSpecialtyVisit ? 'docs.print.full_025' : 'docs.edit.print')) ?>"
                 aria-label="<?= te($printed && !$isSpecialtyVisit ? 'docs.edit.open_print' : ($isSpecialtyVisit ? 'docs.print.full_025' : 'docs.edit.print')) ?>"><?= te('docs.event.print_mark') ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="docs-event-toolbar">
    <?php if ($canPrint && $instances !== []): ?>
      <a class="btn-secondary btn-sm" href="/documents/event/<?= (int)$eventId ?>/print-all"><?= te('docs.print_event.btn') ?></a>
    <?php endif; ?>
    <?php if ($manualDefs !== [] && $clientId > 0): ?>
      <details class="docs-event-create">
        <summary class="btn-secondary btn-sm"><?= te('docs.event.add') ?></summary>
        <div class="docs-event-create-menu">
          <?php foreach ($manualDefs as $d): ?>
            <a href="/documents/new?def=<?= (int)$d['id'] ?>&amp;event=<?= (int)$eventId ?>&amp;client=<?= (int)$clientId ?>"><?= e((string)$d['name']) ?></a>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
    <?php if ($clientId > 0 && $canMedical): ?>
      <a class="btn-secondary btn-sm" href="/documents/clients/<?= (int)$clientId ?>/profile"><?= te('docs.client.profile') ?></a>
      <?php if ($canPrint): ?>
        <a class="btn-secondary btn-sm" href="/documents/clients/<?= (int)$clientId ?>/medcard?event=<?= (int)$eventId ?>"><?= te('docs.client.dossier') ?></a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</fieldset>
