<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $shares */
/** @var list<array{id:string,title:string,ready:bool,ready_note:string}> $families */
/** @var list<array<string,mixed>> $staff */
/** @var list<array{id:int,name:string}> $statuses */
/** @var array<string,mixed>|null $created */
/** @var string $flash */
/** @var string $error */
$statuses = $statuses ?? [];
?>
<p class="lede"><?= te('bi.shares.lede') ?></p>
<?php if ($flash !== ''): ?><p class="flash-ok"><?= e($flash) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="flash-err"><?= e($error) ?></p><?php endif; ?>

<?php if ($created !== null): ?>
  <div class="bi-created">
    <strong><?= te('bi.shares.created') ?>:</strong>
    <code id="bi-created-url"><?= e((string)($created['url'] ?? '')) ?></code>
    <button type="button" class="btn-secondary" data-bi-copy="#bi-created-url"><?= te('bi.shares.copy') ?></button>
  </div>
<?php endif; ?>

<form method="post" class="bi-share-form" action="/bi/shares">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <input type="hidden" name="action" value="create">
  <div class="bi-run-grid">
    <label><?= te('bi.shares.family') ?>
      <select name="family" required>
        <option value="">—</option>
        <?php foreach ($families as $f): ?>
          <?php if (empty($f['ready'])) { continue; } ?>
          <option value="<?= e($f['id']) ?>"><?= e($f['title']) ?> (<?= e($f['id']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('bi.shares.audience') ?>
      <select name="audience" id="bi-share-audience">
        <option value="guest"><?= te('bi.shares.audience_guest') ?></option>
        <option value="staff"><?= te('bi.shares.audience_staff') ?></option>
      </select>
    </label>
    <label><?= te('bi.shares.ttl') ?>
      <select name="ttl" id="bi-share-ttl">
        <option value="1h"><?= te('bi.shares.ttl_1h') ?></option>
        <option value="1d" selected><?= te('bi.shares.ttl_1d') ?></option>
        <option value="1mo"><?= te('bi.shares.ttl_1mo') ?></option>
        <option value="3mo"><?= te('bi.shares.ttl_3mo') ?></option>
        <option value="1y" data-bi-ttl-staff-only="1"><?= te('bi.shares.ttl_1y') ?></option>
      </select>
    </label>
    <label><?= te('bi.shares.label') ?>
      <input type="text" name="label" maxlength="255">
    </label>
  </div>

  <div id="bi-share-staff-box" class="bi-span2" hidden>
    <p><?= te('bi.shares.status_pick') ?></p>
    <?php if ($statuses === []): ?>
      <p class="muted"><?= te('bi.shares.status_empty') ?></p>
    <?php else: ?>
      <div class="bi-staff-checks">
        <?php foreach ($statuses as $st): ?>
          <?php
            $sid = (int)($st['id'] ?? 0);
            $sname = (string)($st['name'] ?? '');
            if ($sid < 1) {
                continue;
            }
          ?>
          <label>
            <input type="checkbox" name="staff_status_ids[]" value="<?= $sid ?>">
            <?= e($sname !== '' ? $sname : ('#' . $sid)) ?>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p style="margin-top:0.75rem"><?= te('bi.shares.staff_pick') ?></p>
    <?php if ($staff === []): ?>
      <p class="muted"><?= te('bi.shares.staff_empty') ?></p>
    <?php else: ?>
      <div class="bi-staff-checks">
        <?php foreach ($staff as $u): ?>
          <?php
            $uid = (int)($u['id'] ?? $u['ID'] ?? 0);
            $uname = (string)($u['name'] ?? $u['NAME'] ?? '');
            if ($uid < 1) {
                continue;
            }
          ?>
          <label>
            <input type="checkbox" name="staff_user_ids[]" value="<?= $uid ?>">
            <?= e($uname !== '' ? $uname : ('#' . $uid)) ?>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <p class="muted bi-span2"><?= te('bi.shares.hint.filters') ?></p>
  <label class="bi-span2">
    <input type="checkbox" name="accept_partial_filters" value="1">
    <?= te('bi.shares.accept_partial_filters') ?>
  </label>

  <p style="margin-top:1rem">
    <button type="submit" class="btn"><?= te('bi.shares.create') ?></button>
  </p>
</form>

<h2><?= te('bi.menu.shares') ?></h2>
<?php if ($shares === []): ?>
  <p class="muted"><?= te('bi.empty.shares') ?></p>
<?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th><?= te('bi.shares.family') ?></th>
        <th><?= te('bi.shares.audience') ?></th>
        <th><?= te('bi.shares.col.url') ?></th>
        <th><?= te('bi.shares.col.expires') ?></th>
        <th><?= te('bi.shares.col.status') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($shares as $s): ?>
      <?php
        $usable = !empty($s['usable']);
        $revoked = (int)($s['REVOKED_AT'] ?? 0) > 0;
        $status = $usable ? t('bi.shares.active') : ($revoked ? t('bi.shares.revoked') : t('bi.shares.expired'));
      ?>
      <tr>
        <td>
          <?= e((string)$s['FAMILY']) ?>
          <?php if ((string)($s['LABEL'] ?? '') !== ''): ?>
            <div class="muted"><?= e((string)$s['LABEL']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?= e((string)$s['AUDIENCE']) ?>
          <?php
            $su = is_array($s['staff_user_ids'] ?? null) ? $s['staff_user_ids'] : [];
            $ss = is_array($s['staff_status_ids'] ?? null) ? $s['staff_status_ids'] : [];
            if ($su !== [] || $ss !== []):
          ?>
            <div class="muted">
              <?php if ($ss !== []): ?>
                <?= te('bi.shares.status_ids') ?>: <?= e(implode(', ', array_map('strval', $ss))) ?>
              <?php endif; ?>
              <?php if ($su !== []): ?>
                <?= $ss !== [] ? ' · ' : '' ?><?= te('bi.shares.staff_ids') ?>: <?= e(implode(', ', array_map('strval', $su))) ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </td>
        <td class="mono"><a href="<?= e((string)$s['url']) ?>" target="_blank" rel="noopener"><?= e((string)$s['url']) ?></a></td>
        <td><?= (int)$s['DT_EXPIRE'] > 0 ? e(date('Y-m-d H:i', (int)$s['DT_EXPIRE'])) : '—' ?></td>
        <td><?= e($status) ?></td>
        <td>
          <?php if ($usable): ?>
            <form method="post" action="/bi/shares" class="inline">
              <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
              <input type="hidden" name="action" value="revoke">
              <input type="hidden" name="id" value="<?= (int)$s['ID'] ?>">
              <button type="submit" class="btn-secondary"><?= te('bi.shares.revoke') ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
