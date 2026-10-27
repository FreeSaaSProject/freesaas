<?php declare(strict_types=1); ?>
<section>
  <p class="actions"><a class="btn-primary" href="/video-label/new"><?= te('video_label.projects.new') ?></a></p>
  <table class="table">
    <thead><tr>
      <th><?= te('video_label.col.name') ?></th>
      <th><?= te('video_label.col.images') ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/video-label/<?= (int)$r['ID'] ?>/workspace"><?= e((string)$r['NAME']) ?></a></td>
        <td><?= (int)($r['IMAGE_COUNT'] ?? 0) ?></td>
        <td>
          <a class="btn-ghost btn-sm" href="/video-label/<?= (int)$r['ID'] ?>/edit"><?= te('core.common.edit') ?></a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?>
      <tr><td colspan="3" class="muted"><?= te('video_label.empty.projects') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
