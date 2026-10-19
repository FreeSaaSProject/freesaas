<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<string,mixed>|null $def */
/** @var array<string,mixed> $version */
/** @var array{sections:list<array<string,mixed>>} $schema */
/** @var array<int,string> $staffNames */
/** @var list<string> $missing */
/** @var bool $locked */
/** @var bool $canEdit */
/** @var bool $canPrint */
/** @var list<array<string,mixed>> $proposals */
/** @var bool $hasSegments */
/** @var string $clientLabel */
/** @var string $message */
/** @var string $error */
$payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
$prov = is_array($payload['_prov'] ?? null) ? $payload['_prov'] : [];
$readonly = $locked || !$canEdit;
$dis = $readonly ? ' disabled' : '';
$fieldMap = DocsSchema::fieldMap($schema);
?>
<section class="page-head">
  <h1><?= e((string)($row['def_name'] ?? '')) ?><?= $isNew ? '' : ' #' . (int)$row['id'] ?></h1>
  <p class="muted docs-meta">
    <span><?= e(DocsDefsRepository::kindLabel((string)$row['kind'])) ?></span>
    · <span class="docs-status docs-status--<?= e((string)$row['status']) ?>"><?= te('docs.status.' . $row['status']) ?></span>
    · <?= te('docs.edit.version', ['n' => (int)$version['version']]) ?>
    <?php if ((int)$row['id_client'] > 0): ?>
      · <a href="/clients/<?= (int)$row['id_client'] ?>"><?= e($clientLabel !== '' ? $clientLabel : ('#' . (int)$row['id_client'])) ?></a>
    <?php endif; ?>
    <?php if ((int)$row['id_event'] > 0): ?>
      · <a href="/calendar/events/<?= (int)$row['id_event'] ?>/edit"><?= te('docs.edit.event', ['id' => (int)$row['id_event']]) ?></a>
    <?php endif; ?>
    <?php if ((int)$row['id_procedure'] > 0): ?>
      · <a href="/procedures/<?= (int)$row['id_procedure'] ?>/edit"><?= te('docs.edit.procedure', ['id' => (int)$row['id_procedure']]) ?></a>
    <?php endif; ?>
    <?php if ((int)($row['dt_print'] ?? 0) > 0): ?>
      · <?= te('docs.edit.printed_at', ['date' => I18n::formatDate((int)$row['dt_print'], 'datetime')]) ?>
    <?php endif; ?>
    <?php if ((int)($row['id_parent'] ?? 0) > 0): ?>
      · <a href="/documents/<?= (int)$row['id_parent'] ?>/edit"><?= te('docs.edit.parent', ['id' => (int)$row['id_parent']]) ?></a>
    <?php endif; ?>
  </p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if ($locked): ?>
  <p class="flash flash-warn"><?= te($row['status'] === 'void' ? 'docs.edit.locked_void' : 'docs.edit.locked_printed') ?></p>
<?php elseif ($missing !== [] && !$isNew): ?>
  <p class="muted"><?= te('docs.edit.missing', ['list' => implode(', ', array_map(
      static fn(string $fid): string => (string)($fieldMap[$fid]['label'] ?? $fid),
      $missing
  ))]) ?></p>
<?php endif; ?>

<?php if ($proposals !== [] && !$locked): ?>
<section class="docs-proposals">
  <h2><?= te('docs.proposals.title', ['n' => count($proposals)]) ?></h2>
  <?php foreach ($proposals as $p):
      $f = $fieldMap[(string)$p['field_id']] ?? null;
      if ($f === null) {
          continue;
      }
      $current = DocsSchema::displayValue($f, $payload[(string)$p['field_id']] ?? null, $staffNames);
      $proposed = DocsSchema::displayValue($f, $p['value'], $staffNames);
      ?>
    <div class="docs-proposal">
      <div class="docs-proposal-head">
        <b><?= e((string)$f['label']) ?></b>
        <span class="muted"><?= te('docs.source.' . $p['source']) ?><?= (float)$p['confidence'] > 0 ? ' · ' . e((string)round((float)$p['confidence'] * 100)) . '%' : '' ?></span>
      </div>
      <div class="docs-proposal-diff">
        <div><span class="muted"><?= te('docs.proposals.current') ?>:</span> <?= $current !== '' ? nl2br(e($current), false) : '—' ?></div>
        <div><span class="muted"><?= te('docs.proposals.proposed') ?>:</span> <?= $proposed !== '' ? nl2br(e($proposed), false) : '—' ?></div>
      </div>
      <?php if ($canEdit): ?>
      <form method="post" class="docs-proposal-actions">
        <input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>">
        <button class="btn-primary btn-sm" type="submit" name="action" value="accept"><?= te('docs.proposals.accept') ?></button>
        <button class="btn-secondary btn-sm" type="submit" name="action" value="reject"><?= te('docs.proposals.reject') ?></button>
      </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<form method="post" class="form-grid form-wide docs-form" data-docs-form>
  <?php if ($isNew): ?>
    <input type="hidden" name="def" value="<?= (int)$row['id_def'] ?>">
    <input type="hidden" name="client" value="<?= (int)$row['id_client'] ?>">
    <input type="hidden" name="event" value="<?= (int)$row['id_event'] ?>">
    <input type="hidden" name="procedure" value="<?= (int)$row['id_procedure'] ?>">
  <?php endif; ?>
  <?php if ($schema['sections'] === [] || $fieldMap === []): ?>
    <p class="muted"><?= te('docs.edit.no_fields') ?></p>
  <?php endif; ?>
  <?php foreach ($schema['sections'] as $s): ?>
    <?php if ($s['fields'] === []) { continue; } ?>
    <fieldset>
      <?php if ((string)$s['title'] !== ''): ?><legend><?= e((string)$s['title']) ?></legend><?php endif; ?>
      <?php foreach ($s['fields'] as $f):
          $fid = (string)$f['id'];
          $name = 'f[' . $fid . ']';
          $value = $payload[$fid] ?? null;
          $req = !empty($f['required']);
          $src = (string)($prov[$fid]['source'] ?? '');
          $badge = ($src !== '' && $src !== 'manual' && $src !== 'spawn' && $src !== 'revision')
              ? '<span class="docs-prov docs-prov--' . e($src) . '">' . te('docs.source.' . $src) . '</span>'
              : '';
          ?>
        <?php if ($f['type'] === 'checkbox'): ?>
          <label class="check">
            <input type="hidden" name="<?= e($name) ?>" value="0">
            <input type="checkbox" name="<?= e($name) ?>" value="1"<?= (int)$value === 1 ? ' checked' : '' ?><?= $dis ?>>
            <?= e((string)$f['label']) ?> <?= $badge ?>
          </label>
        <?php elseif ($f['type'] === 'segments'):
            $segs = is_array($value) ? $value : [];
            ?>
          <div class="docs-segments" data-docs-segments data-name="<?= e($name) ?>">
            <span class="docs-field-label"><?= e((string)$f['label']) ?><?= $req ? ' *' : '' ?> <?= $badge ?></span>
            <div class="docs-segments-rows">
              <?php foreach ($segs as $seg): ?>
                <div class="docs-segment-row">
                  <input type="time" name="<?= e($name) ?>[from][]" value="<?= e((string)($seg['from'] ?? '')) ?>"<?= $dis ?>>
                  <input type="time" name="<?= e($name) ?>[to][]" value="<?= e((string)($seg['to'] ?? '')) ?>"<?= $dis ?>>
                  <input type="text" name="<?= e($name) ?>[note][]" value="<?= e((string)($seg['note'] ?? '')) ?>" maxlength="255" placeholder="<?= te('docs.segments.note') ?>"<?= $dis ?>>
                  <?php if (!$readonly): ?><button type="button" class="btn-secondary btn-sm" data-docs-seg-remove aria-label="<?= te('docs.segments.remove') ?>">×</button><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="<?= e($name) ?>[from][]" value="">
            <?php if (!$readonly): ?>
              <button type="button" class="btn-secondary btn-sm" data-docs-seg-add
                      data-placeholder="<?= te('docs.segments.note') ?>"
                      data-remove-label="<?= te('docs.segments.remove') ?>"><?= te('docs.segments.add') ?></button>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <label><?= e((string)$f['label']) ?><?= $req ? ' *' : '' ?> <?= $badge ?>
            <?php if ($f['type'] === 'textarea'): ?>
              <textarea name="<?= e($name) ?>" rows="3"<?= $dis ?>><?= e(is_scalar($value) ? (string)$value : '') ?></textarea>
            <?php elseif ($f['type'] === 'number'): ?>
              <input type="number" step="any" name="<?= e($name) ?>" value="<?= e(is_scalar($value) ? (string)$value : '') ?>"<?= $dis ?>>
            <?php elseif ($f['type'] === 'date'): ?>
              <input type="date" name="<?= e($name) ?>" value="<?= e(is_scalar($value) ? (string)$value : '') ?>"<?= $dis ?>>
            <?php elseif ($f['type'] === 'select'): ?>
              <select name="<?= e($name) ?>"<?= $dis ?>>
                <option value="">—</option>
                <?php foreach ((array)($f['options'] ?? []) as $o): ?>
                  <option value="<?= e((string)$o['value']) ?>"<?= (string)$value === (string)$o['value'] ? ' selected' : '' ?>><?= e((string)$o['label']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($f['type'] === 'multiselect'):
                $sel = is_array($value) ? array_map('strval', $value) : [];
                ?>
              <select name="<?= e($name) ?>[]" multiple size="<?= min(8, max(3, count((array)($f['options'] ?? [])))) ?>"<?= $dis ?>>
                <?php foreach ((array)($f['options'] ?? []) as $o): ?>
                  <option value="<?= e((string)$o['value']) ?>"<?= in_array((string)$o['value'], $sel, true) ? ' selected' : '' ?>><?= e((string)$o['label']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($f['type'] === 'staff'): ?>
              <select name="<?= e($name) ?>"<?= $dis ?>>
                <option value="">—</option>
                <?php foreach ($staffNames as $sid => $slabel): ?>
                  <option value="<?= (int)$sid ?>"<?= (int)$value === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="text" name="<?= e($name) ?>" value="<?= e(is_scalar($value) ? (string)$value : '') ?>" maxlength="1000"<?= $dis ?>>
            <?php endif; ?>
            <?php if (!empty($f['hint'])): ?><span class="form-hint"><?= e((string)$f['hint']) ?></span><?php endif; ?>
          </label>
        <?php endif; ?>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>

  <div class="page-actions">
    <?php if (!$readonly): ?>
      <button class="btn-primary" type="submit" name="action" value="save"><?= te($isNew ? 'docs.edit.create' : 'core.common.save') ?></button>
    <?php endif; ?>
    <?php if (!$isNew && $canPrint && $row['status'] !== 'void'): ?>
      <a class="btn-secondary" href="/documents/<?= (int)$row['id'] ?>/print"><?= te((int)($row['dt_print'] ?? 0) > 0 ? 'docs.edit.open_print' : 'docs.edit.print') ?></a>
    <?php endif; ?>
    <?php if (!$isNew && $hasSegments && !$readonly): ?>
      <button class="btn-secondary" type="submit" name="action" value="pull_segments" formnovalidate><?= te('docs.edit.pull_segments') ?></button>
    <?php endif; ?>
    <?php if (!$isNew && $canEdit && $row['status'] !== 'void'): ?>
      <?php if ($row['status'] === 'printed'): ?>
        <button class="btn-secondary" type="submit" name="action" value="revise" formnovalidate><?= te('docs.edit.revise') ?></button>
      <?php endif; ?>
      <button class="btn-secondary" type="submit" name="action" value="void" formnovalidate
              data-confirm="<?= te('docs.edit.void_confirm') ?>"><?= te('docs.edit.void') ?></button>
    <?php endif; ?>
    <?php if ((int)$row['id_client'] > 0 && Auth::can('docs.medical') && Auth::can('docs.print')): ?>
      <a class="btn-secondary" href="/documents/clients/<?= (int)$row['id_client'] ?>/medcard"><?= te('docs.client.dossier') ?></a>
    <?php endif; ?>
  </div>
</form>
