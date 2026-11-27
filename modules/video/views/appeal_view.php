<?php declare(strict_types=1); ?>
<section class="video-appeal-card">
  <dl>
    <dt><?= te('video.appeal.col.staff') ?></dt><dd><?= e((string)($ap['STAFF_NAME'] ?? '')) ?></dd>
    <dt><?= te('video.col.camera') ?></dt><dd><?= e((string)($ap['CAMERA_NAME'] ?? '')) ?></dd>
    <dt><?= te('video.appeal.col.alert_dt') ?></dt><dd><?= e(date('Y-m-d H:i:s', (int)$ap['ALERT_DT'])) ?></dd>
    <dt><?= te('video.col.message') ?></dt><dd><?= e((string)$ap['MESSAGE']) ?></dd>
    <dt><?= te('video.appeal.col.reason') ?></dt><dd><?= e((string)$ap['REASON']) ?></dd>
    <dt><?= te('video.appeal.col.status') ?></dt>
    <dd><?= te('video.appeal.status.' . (int)$ap['STATUS']) ?>
      <?php if ((int)$ap['STATUS'] !== 0): ?>
        — <?= e((string)($ap['DECIDER_NAME'] ?? '')) ?>, <?= e(date('Y-m-d H:i', (int)$ap['DT_DECISION'])) ?>
        <?= (string)$ap['COMMENT'] !== '' ? ': ' . e((string)$ap['COMMENT']) : '' ?>
      <?php endif; ?>
    </dd>
  </dl>

  <?php if ($frames !== []): ?>
    <div class="video-frames">
      <?php foreach ($frames as $rel): ?>
        <a href="/video/media?rel=<?= e(rawurlencode((string)$rel)) ?>" target="_blank">
          <img src="/video/media?rel=<?= e(rawurlencode((string)$rel)) ?>" alt="" loading="lazy" style="max-width:240px;margin:0 6px 6px 0">
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($canDecide && (int)$ap['STATUS'] === 0): ?>
    <form method="post" action="/video/appeals" class="form page-actions">
      <input type="hidden" name="id" value="<?= (int)$ap['ID'] ?>">
      <label><?= te('video.appeal.col.comment') ?> <input name="comment" maxlength="1000"></label>
      <button type="submit" name="action" value="accept" class="btn-primary"><?= te('video.bot.btn.accept') ?></button>
      <button type="submit" name="action" value="reject" class="btn-danger"><?= te('video.bot.btn.reject') ?></button>
    </form>
  <?php endif; ?>
</section>
