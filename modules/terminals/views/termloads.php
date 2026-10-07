<?php
/** @var array{day:string,items:list<array{id:int,value:int,label:string}>,max:int,total_samples:int} $data */
$day = (string)$data['day'];
$max = max(1, (int)$data['max']);
$prev = date('Y-m-d', strtotime($day . ' -1 day'));
$next = date('Y-m-d', strtotime($day . ' +1 day'));
?>
<section class="page-head">
  <h1><?= te('terminals.termloads.title') ?></h1>
</section>

<form class="filters" method="get" action="/terminals/termloads">
  <label><?= te('terminals.termloads.day') ?>
    <input type="date" name="day" value="<?= e($day) ?>">
  </label>
  <button type="submit" class="btn"><?= te('terminals.termloads.filter_submit') ?></button>
  <a class="btn-secondary" href="/terminals/termloads?day=<?= e($prev) ?>">&larr;</a>
  <a class="btn-secondary" href="/terminals/termloads?day=<?= e($next) ?>">&rarr;</a>
</form>

<p class="muted">
  <?= te('terminals.termloads.samples', ['n' => (int)$data['total_samples']]) ?>
  · <?= te('terminals.termloads.max', ['n' => (int)$data['max']]) ?>
</p>

<?php if (!$data['items']): ?>
  <p class="muted"><?= te('terminals.termloads.empty') ?></p>
<?php else: ?>
  <ul class="termloads-bars">
  <?php foreach ($data['items'] as $row): ?>
    <?php $pct = (int)round(((int)$row['value'] / $max) * 100); ?>
    <li>
      <span class="tl-time"><?= e((string)$row['label']) ?></span>
      <span class="tl-bar"><i style="width:<?= $pct ?>%"></i></span>
      <span class="tl-val"><?= (int)$row['value'] ?></span>
    </li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>
