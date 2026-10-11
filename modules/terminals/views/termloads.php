<?php
declare(strict_types=1);
/** @var string $day */
/** @var list<array{id:int,value:int,label:string}> $items */
/** @var int $max */
/** @var int $total_samples */
$day = (string)$day;
$peak = (int)$max;
$barMax = max(1, $peak);
$items = is_array($items ?? null) ? $items : [];
$total_samples = (int)$total_samples;

$today = date('Y-m-d');
$isToday = ($day === $today);
$prev = date('Y-m-d', strtotime($day . ' -1 day') ?: time());
$next = date('Y-m-d', strtotime($day . ' +1 day') ?: time());
$urlPrev = '/terminals/termloads?day=' . rawurlencode($prev);
$urlNext = '/terminals/termloads?day=' . rawurlencode($next);
$urlToday = '/terminals/termloads?day=' . rawurlencode($today);
$dayTs = strtotime($day . ' 12:00:00') ?: time();
$dayLabel = I18n::formatDate($dayTs, 'date');
?>
<section class="page-head">
  <h1><?= te('terminals.termloads.title') ?></h1>
</section>

<nav class="pager" aria-label="<?= te('terminals.day_nav.aria') ?>"
     data-pager-prev="<?= e($urlPrev) ?>"
     data-pager-next="<?= e($urlNext) ?>">
  <div class="pager-row">
    <div class="pager-nav">
      <a class="btn-secondary" href="<?= e($urlPrev) ?>" title="<?= te('terminals.day_nav.prev') ?>" aria-label="<?= te('terminals.day_nav.prev') ?>">‹</a>
      <strong><?= e($dayLabel) ?></strong>
      <a class="btn-secondary" href="<?= e($urlNext) ?>" title="<?= te('terminals.day_nav.next') ?>" aria-label="<?= te('terminals.day_nav.next') ?>">›</a>
      <?php if (!$isToday): ?>
        <a class="btn-secondary" href="<?= e($urlToday) ?>"><?= te('terminals.day_nav.today') ?></a>
      <?php else: ?>
        <span class="pill"><?= te('terminals.day_nav.today') ?></span>
      <?php endif; ?>
      <span class="muted"><?= te('terminals.day_nav.keyboard') ?></span>
    </div>
  </div>
</nav>

<form class="filters" method="get" action="/terminals/termloads" data-auto-filter>
  <label><?= te('terminals.termloads.day') ?>
    <input type="date" name="day" value="<?= e($day) ?>">
  </label>
</form>

<p class="muted">
  <?= te('terminals.termloads.samples', ['n' => $total_samples]) ?>
  · <?= te('terminals.termloads.max', ['n' => $peak]) ?>
</p>

<?php if (!$items): ?>
  <p class="muted"><?= te('terminals.termloads.empty') ?></p>
<?php else: ?>
  <ul class="termloads-bars">
  <?php foreach ($items as $row): ?>
    <?php $pct = (int)round(((int)$row['value'] / $barMax) * 100); ?>
    <li>
      <span class="tl-time"><?= e((string)$row['label']) ?></span>
      <span class="tl-bar"><i style="width:<?= $pct ?>%"></i></span>
      <span class="tl-val"><?= (int)$row['value'] ?></span>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>
