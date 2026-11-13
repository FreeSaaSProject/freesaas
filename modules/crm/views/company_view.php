<?php
declare(strict_types=1);
/** @var array<string,mixed> $row */
/** @var list<array<string,mixed>> $contacts */
/** @var list<array<string,mixed>> $deals */
/** @var list<array<string,mixed>> $notes */
/** @var array<int,string> $noteAuthors */
$companyId = (int)$row['id'];
$dash = '<span class="muted">—</span>';
?>
<section class="page-head">
  <div class="page-head-row">
    <h1><?= e((string)$row['name']) ?></h1>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-primary" href="/crm/deals/new?company_id=<?= $companyId ?>"><?= te('crm.deals.new') ?></a>
      <a class="btn-secondary" href="/crm/companies/<?= $companyId ?>/edit"><?= te('core.common.edit') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<div class="crm-card-grid">
  <aside class="crm-card-side">
    <section class="crm-panel">
      <h2><?= te('crm.card.about') ?></h2>
      <dl class="crm-dl">
        <dt><?= te('crm.col.inn') ?></dt><dd><?= (string)$row['inn'] !== '' ? e((string)$row['inn']) : $dash ?></dd>
        <?php if ((string)$row['kpp'] !== ''): ?><dt><?= te('crm.col.kpp') ?></dt><dd><?= e((string)$row['kpp']) ?></dd><?php endif; ?>
        <dt><?= te('crm.col.phone') ?></dt><dd><?= (string)$row['phone'] !== '' ? e((string)$row['phone']) : $dash ?></dd>
        <dt><?= te('crm.col.email') ?></dt><dd><?= (string)$row['email'] !== '' ? e((string)$row['email']) : $dash ?></dd>
        <?php if ((string)$row['website'] !== ''): ?><dt><?= te('crm.col.website') ?></dt><dd><?= e((string)$row['website']) ?></dd><?php endif; ?>
        <?php if ((string)$row['address'] !== ''): ?><dt><?= te('crm.col.address') ?></dt><dd><?= e((string)$row['address']) ?></dd><?php endif; ?>
        <dt><?= te('crm.col.responsible') ?></dt><dd><?= $row['responsible_name'] !== '' ? e((string)$row['responsible_name']) : $dash ?></dd>
        <?php foreach ($cfDefs as $f):
            $v = (string)($cfValues[(int)$f['id']] ?? '');
            if ($v === '') {
                continue;
            }
        ?>
          <dt><?= e((string)$f['name']) ?></dt><dd><?= e_nl(CrmCustomFieldsRepository::displayValue($f, $v)) ?></dd>
        <?php endforeach; ?>
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
          </div>
          <?php require __DIR__ . '/_contact_meta.php'; ?>
          <?php if ($canEdit): ?>
          <form method="post" class="crm-inline-form">
            <input type="hidden" name="action" value="set_contact_role">
            <input type="hidden" name="client_id" value="<?= $cid ?>">
            <input name="role" maxlength="64" value="<?= e((string)($c['role'] ?? '')) ?>"
                   placeholder="<?= te('crm.contact.role_hint') ?>" aria-label="<?= te('crm.contact.role') ?>">
            <button class="btn-sm" type="submit"><?= te('core.common.save') ?></button>
          </form>
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
          <?php elseif ((string)($c['role'] ?? '') !== ''): ?>
            <div class="muted"><?= e((string)$c['role']) ?></div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if (!$contacts): ?><li class="muted"><?= te('crm.profile.none') ?></li><?php endif; ?>
      </ul>
      <?php if ($canEdit): ?>
      <details class="crm-add-contact"<?= $contacts ? '' : ' open' ?>>
        <summary><?= te('crm.contact.add_by_phone') ?></summary>
        <form method="post" class="form-grid">
          <input type="hidden" name="action" value="add_contact">
          <?php require __DIR__ . '/_contact_fields.php'; ?>
          <label><?= te('crm.contact.role') ?><input name="role" maxlength="64" placeholder="<?= te('crm.contact.role_hint') ?>"></label>
          <button class="btn-primary" type="submit"><?= te('crm.contact.add') ?></button>
        </form>
      </details>
      <?php endif; ?>
    </section>
  </aside>

  <main class="crm-card-main">
    <section class="crm-panel">
      <h2><?= te('crm.deals.title') ?></h2>
      <ul class="crm-deal-list">
      <?php foreach ($deals as $d): ?>
        <li>
          <a href="/crm/deals/<?= (int)$d['id'] ?>"><?= e((string)$d['title']) ?></a>
          <span class="crm-stage-pill crm-sem-<?= e(strtolower((string)($d['stage_semantic'] ?? 'p'))) ?>"><?= e((string)($d['stage_name'] ?? '')) ?></span>
          <span class="crm-num muted"><?= e(CrmUi::money((float)$d['amount'], (string)$d['currency'])) ?></span>
          <?php if ((string)($d['responsible_name'] ?? '') !== ''): ?><span class="muted">· <?= e((string)$d['responsible_name']) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if (!$deals): ?><li class="muted"><?= te('crm.profile.none') ?></li><?php endif; ?>
      </ul>
    </section>
    <section class="crm-panel">
      <h2><?= te('crm.notes.title') ?></h2>
      <?php if ($canEdit): ?>
      <form method="post" class="crm-note-form">
        <input type="hidden" name="action" value="add_note">
        <textarea name="body" rows="3" required placeholder="<?= te('crm.notes.placeholder') ?>"></textarea>
        <button class="btn-primary" type="submit"><?= te('crm.notes.add') ?></button>
      </form>
      <?php endif; ?>
      <ol class="crm-timeline">
      <?php foreach ($notes as $n): $aid = (int)$n['author_user_id']; ?>
        <li class="crm-tl-item crm-tl-note">
          <div class="crm-tl-head">
            <span class="muted"><?= e(I18n::formatDate((int)$n['created_at'], 'datetime')) ?></span>
            <?php if ($aid > 0): ?><span class="muted">· <?= e($noteAuthors[$aid] ?? '#' . $aid) ?></span><?php endif; ?>
          </div>
          <div class="crm-tl-body"><?= e_nl((string)$n['body']) ?></div>
        </li>
      <?php endforeach; ?>
      <?php if (!$notes): ?><li class="muted"><?= te('crm.notes.empty') ?></li><?php endif; ?>
      </ol>
    </section>
  </main>
</div>
