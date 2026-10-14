<?php
declare(strict_types=1);
/** @var array $row */
/** @var string $flash */
/** @var string $playUrl */
/** @var bool $canEdit */
/** @var array{html:string,lines:int,trash:int,keys:int} $transcript */
/** @var list<array<string,mixed>> $instances */
/** @var array<int,array{name:string,color:string,bg:string}> $typeMeta */
/** @var list<array{type_id:int,diversity:int,instances:int}> $keyTypes */
$typeMeta = is_array($typeMeta ?? null) ? $typeMeta : [];
$keyTypes = is_array($keyTypes ?? null) ? $keyTypes : [];
$instances = is_array($instances ?? null) ? $instances : [];
$transcript = is_array($transcript ?? null) ? $transcript : ['html' => '', 'lines' => 0, 'trash' => 0, 'keys' => 0];
?>
<?php if ($flash !== ''): ?><p class="flash"><?= e($flash) ?></p><?php endif; ?>

<section class="page-head-row">
  <div></div>
  <div class="page-actions">
    <?php if ($canEdit): ?>
    <form method="post" class="inline-form">
      <?= NavContext::hiddenField() ?>
      <input type="hidden" name="action" value="toggle_disabled">
      <button type="submit"><?= !empty($row['disabled']) ? te('audio.view.enable') : te('audio.view.disable') ?></button>
    </form>
    <?php endif; ?>
  </div>
</section>

<div class="audio-view-grid">
  <div class="audio-view-main">
    <dl class="kv">
      <dt>ID</dt><dd><?= (int)$row['id'] ?></dd>
      <dt><?= te('audio.col.admin') ?></dt><dd><?= UsersRepository::badgeHtml((int)$row['id_admin']) ?></dd>
      <dt><?= te('audio.col.badge') ?></dt><dd><code><?= e((string)$row['id_dictophone']) ?></code></dd>
      <dt><?= te('audio.col.dt') ?></dt><dd><?= !empty($row['dt_recorded']) ? e(date('Y-m-d H:i:s', (int)$row['dt_recorded'])) : '—' ?></dd>
      <dt><?= te('audio.col.filename') ?></dt><dd><?= e((string)$row['filename']) ?></dd>
      <dt><?= te('audio.col.playtime') ?></dt><dd><?= (int)$row['playtime'] ?> s</dd>
      <dt><?= te('audio.col.score') ?></dt><dd><?= (int)$row['score'] ?></dd>
      <dt><?= te('audio.col.quality') ?></dt><dd><?= (int)($row['quality'] ?? 0) ?></dd>
      <dt><?= te('audio.col.disabled') ?></dt><dd><?= !empty($row['disabled']) ? te('core.common.on') : te('core.common.off') ?></dd>
    </dl>

    <?php if ($keyTypes): ?>
    <p class="audio-key-chips">
      <?php foreach ($keyTypes as $chip): ?>
        <?php $meta = $typeMeta[$chip['type_id']] ?? null; ?>
        <span class="key-type-chip" style="<?= $meta ? 'background:' . e($meta['bg']) . ';color:' . e($meta['color']) : '' ?>">
          <?= e($meta['name'] ?? ('#' . $chip['type_id'])) ?>
          <?= te('audio.view.kv_ratio', ['d' => $chip['diversity'], 'i' => $chip['instances']]) ?>
        </span>
      <?php endforeach; ?>
    </p>
    <?php endif; ?>

    <?php if ($playUrl !== ''): ?>
    <section>
      <h2><?= te('audio.view.playback') ?></h2>
      <audio id="audio-player" controls preload="none" src="<?= e($playUrl) ?>" style="width:100%;max-width:40rem"></audio>
    </section>
    <?php endif; ?>

    <section>
      <h2><?= te('audio.view.transcription') ?>
        <span class="muted">(<?= te('audio.transcript.stats', ['lines' => (int)$transcript['lines'], 'keys' => (int)$transcript['keys']]) ?>)</span>
      </h2>
      <div class="transcript" id="audio-transcript">
        <?= $transcript['html'] ?>
      </div>
    </section>

    <?php if (trim((string)($row['description'] ?? '')) !== ''): ?>
    <section>
      <h2><?= te('audio.view.description') ?></h2>
      <pre><?= e((string)$row['description']) ?></pre>
    </section>
    <?php endif; ?>
  </div>

  <aside class="audio-view-side">
    <h2><?= te('audio.view.instances') ?> (<?= count($instances) ?>)</h2>
    <?php if (!$instances): ?>
      <p class="muted"><?= te('audio.view.instances_empty') ?></p>
    <?php else: ?>
      <ul class="audio-instances">
        <?php foreach ($instances as $inst): ?>
          <li>
            <span class="key-hit" style="background:<?= e((string)($inst['type_bg'] ?: '#fde68a')) ?>;color:<?= e((string)($inst['type_color'] ?: '#111')) ?>">
              <?= e((string)($inst['key_name'] ?: ('#' . $inst['id_key']))) ?>
            </span>
            <span class="muted">L<?= (int)$inst['string_num'] ?> @<?= (int)$inst['string_position'] ?> +<?= (int)$inst['length'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </aside>
</div>
