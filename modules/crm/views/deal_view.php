<?php
declare(strict_types=1);
/** @var array<string,mixed> $row */
/** @var list<array<string,mixed>> $stages */
/** @var list<array<string,mixed>> $contacts */
/** @var list<array<string,mixed>> $companyCandidates */
/** @var list<array<string,mixed>> $timeline */
$dealId = (int)$row['id'];
$currentStage = (int)$row['stage_id'];
$passed = true;
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1><?= e((string)$row['title']) ?></h1>
      <?php if ((int)$row['company_id'] > 0): ?>
        <div class="muted"><a href="/crm/companies/<?= (int)$row['company_id'] ?>"><?= e((string)($row['company_name'] ?? '')) ?></a></div>
      <?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-secondary" href="/crm/deals/<?= $dealId ?>/edit"><?= te('core.common.edit') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if ($stages): ?>
<form method="post" class="crm-stagebar" aria-label="<?= te('crm.col.stage') ?>">
  <input type="hidden" name="action" value="move_stage">
  <?php foreach ($stages as $s):
      $sid = (int)$s['id'];
      $isCurrent = $sid === $currentStage;
      $cls = 'crm-stagebar-step crm-sem-' . strtolower((string)$s['semantic']);
      if ($isCurrent) {
          $cls .= ' is-current';
          $passed = false;
      } elseif ($passed) {
          $cls .= ' is-passed';
      }
  ?>
    <button type="submit" name="stage_id" value="<?= $sid ?>" class="<?= e($cls) ?>"
      <?= (!$canEdit || $isCurrent) ? 'disabled' : '' ?>
      title="<?= e((string)$s['name']) ?>"><?= e((string)$s['name']) ?></button>
  <?php endforeach; ?>
</form>
<?php endif; ?>

<div class="crm-card-grid">
  <aside class="crm-card-side">
    <section class="crm-panel">
      <h2><?= te('crm.card.about') ?></h2>
      <dl class="crm-dl">
        <dt><?= te('crm.col.amount') ?></dt>
        <dd class="crm-num"><?= e(CrmUi::money((float)$row['amount'], (string)$row['currency'])) ?></dd>
        <dt><?= te('crm.col.pipeline') ?></dt>
        <dd><?= e((string)($row['pipeline_name'] ?? '')) ?></dd>
        <dt><?= te('crm.col.responsible') ?></dt>
        <dd><?= $row['responsible_name'] !== '' ? e((string)$row['responsible_name']) : '<span class="muted">—</span>' ?></dd>
        <dt><?= te('crm.col.source') ?></dt>
        <dd><?= (string)$row['source'] !== '' ? e((string)$row['source']) : '<span class="muted">—</span>' ?></dd>
        <?php foreach ($cfDefs as $f):
            $v = (string)($cfValues[(int)$f['id']] ?? '');
            if ($v === '') {
                continue;
            }
        ?>
          <dt><?= e((string)$f['name']) ?></dt>
          <dd><?= e_nl(CrmCustomFieldsRepository::displayValue($f, $v)) ?></dd>
        <?php endforeach; ?>
        <dt><?= te('crm.col.created') ?></dt>
        <dd class="muted"><?= e(I18n::formatDate((int)$row['created_at'], 'datetime')) ?></dd>
        <?php if ((int)$row['closed_at'] > 0): ?>
          <dt><?= te('crm.col.closed') ?></dt>
          <dd class="muted"><?= e(I18n::formatDate((int)$row['closed_at'], 'datetime')) ?></dd>
        <?php endif; ?>
      </dl>
      <?php if (trim((string)($row['notes'] ?? '')) !== ''): ?>
        <div class="crm-description"><?= e_nl((string)$row['notes']) ?></div>
      <?php endif; ?>
    </section>

    <section class="crm-panel">
      <h2><?= te('crm.card.contacts') ?></h2>
      <ul class="crm-contacts">
      <?php foreach ($contacts as $c): $cid = (int)$c['client_id']; ?>
        <li>
          <div>
            <a href="/clients/<?= $cid ?>"><?= e((string)($c['name'] ?? ('#' . $cid))) ?></a>
            <?php if (!empty($c['is_primary'])): ?><span class="pill" title="<?= te('crm.contact.primary') ?>">★</span><?php endif; ?>
            <?php if ((string)($c['role'] ?? '') !== ''): ?><span class="muted"><?= e((string)$c['role']) ?></span><?php endif; ?>
          </div>
          <div class="muted crm-contact-meta"><?= e((string)($c['tel'] ?? '')) ?> <?= e((string)($c['mail'] ?? '')) ?></div>
          <?php if ($canEdit): ?>
          <div class="crm-inline-actions">
            <?php if (empty($c['is_primary'])): ?>
            <form method="post"><input type="hidden" name="action" value="set_primary_contact">
              <input type="hidden" name="client_id" value="<?= $cid ?>">
              <button class="btn-sm" type="submit"><?= te('crm.contact.make_primary') ?></button></form>
            <?php endif; ?>
            <form method="post"><input type="hidden" name="action" value="remove_contact">
              <input type="hidden" name="client_id" value="<?= $cid ?>">
              <button class="btn-sm" type="submit"><?= te('crm.contact.remove') ?></button></form>
          </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if (!$contacts): ?><li class="muted"><?= te('crm.profile.none') ?></li><?php endif; ?>
      </ul>
      <?php if ($canEdit): ?>
        <?php if ($companyCandidates): ?>
        <form method="post" class="crm-inline-form">
          <input type="hidden" name="action" value="add_contact">
          <select name="client_id" aria-label="<?= te('crm.contact.from_company') ?>">
            <?php foreach ($companyCandidates as $cc): ?>
              <option value="<?= (int)$cc['client_id'] ?>"><?= e((string)($cc['name'] ?? ('#' . $cc['client_id']))) ?><?= (string)($cc['role'] ?? '') !== '' ? ' — ' . e((string)$cc['role']) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn-sm" type="submit"><?= te('crm.contact.add') ?></button>
        </form>
        <?php endif; ?>
        <details class="crm-add-contact">
          <summary><?= te('crm.contact.add_by_phone') ?></summary>
          <form method="post" class="form-grid">
            <input type="hidden" name="action" value="add_contact">
            <label><?= te('crm.contact.tel') ?><input name="tel" type="tel" required></label>
            <?php if ($canCreateClient): ?>
            <label><?= te('crm.contact.name') ?><input name="name"></label>
            <label><?= te('crm.contact.mail') ?><input name="mail" type="email"></label>
            <?php endif; ?>
            <?php if ((int)$row['company_id'] > 0): ?>
            <label><?= te('crm.contact.role') ?><input name="role" maxlength="64" placeholder="<?= te('crm.contact.role_hint') ?>"></label>
            <label class="check"><input type="checkbox" name="link_company" value="1" checked> <?= te('crm.contact.link_company') ?></label>
            <?php endif; ?>
            <button class="btn-primary" type="submit"><?= te('crm.contact.add') ?></button>
          </form>
        </details>
      <?php endif; ?>
    </section>
  </aside>

  <main class="crm-card-main">
    <?php if ($canEdit): ?>
    <section class="crm-panel">
      <form method="post" class="crm-note-form">
        <input type="hidden" name="action" value="add_note">
        <textarea name="body" rows="3" required placeholder="<?= te('crm.notes.placeholder') ?>"></textarea>
        <button class="btn-primary" type="submit"><?= te('crm.notes.add') ?></button>
      </form>
    </section>
    <?php endif; ?>
    <section class="crm-panel">
      <h2><?= te('crm.timeline.title') ?></h2>
      <ol class="crm-timeline">
      <?php foreach ($timeline as $it): $type = (string)($it['type'] ?? ''); ?>
        <li class="crm-tl-item crm-tl-<?= e($type) ?>">
          <div class="crm-tl-head">
            <span class="crm-tl-kind"><?= $type === 'note' ? te('crm.timeline.kind_note')
                : ($type === 'stage' ? te('crm.timeline.kind_stage') : e((string)($it['kind_label'] ?? ''))) ?></span>
            <span class="muted"><?= e(I18n::formatDate((int)($it['at'] ?? 0), 'datetime')) ?></span>
            <?php if ((string)($it['author_name'] ?? '') !== ''): ?><span class="muted">· <?= e((string)$it['author_name']) ?></span><?php endif; ?>
          </div>
          <div class="crm-tl-body">
            <?php if ($type === 'note'): ?>
              <?= e_nl((string)$it['body']) ?>
            <?php elseif ($type === 'stage'): ?>
              <?= e((string)($it['from_name'] !== '' ? $it['from_name'] : '—')) ?> → <strong><?= e((string)($it['to_name'] ?? '—')) ?></strong>
            <?php elseif (isset($it['html'])): ?>
              <?= safe_html((string)$it['html']) ?>
            <?php else: ?>
              <?= e((string)($it['text'] ?? '')) ?>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
      <?php if (!$timeline): ?><li class="muted"><?= te('crm.timeline.empty') ?></li><?php endif; ?>
      </ol>
    </section>
  </main>
</div>
