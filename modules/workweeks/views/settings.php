<?php
/**
 * @var string $message
 * @var string $error
 * @var bool $loggingOn
 * @var int $newsId
 * @var string $newsLabel
 * @var array<int,string> $newsOptions
 * @var array<int,array{start:string,end:string,label:string}> $shiftWindows
 */
$shiftWindows = $shiftWindows ?? WorkweeksRepository::shiftWindows();
$newsOptions = $newsOptions ?? [];
?>
<section class="page-head">
  <p class="lede"><?= te('workweeks.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <fieldset class="form-fieldset">
    <legend><?= te('workweeks.settings.shifts_title') ?></legend>
    <p class="muted"><?= te('workweeks.settings.shifts_hint') ?></p>
    <table class="data">
      <thead>
        <tr>
          <th>#</th>
          <th><?= te('workweeks.settings.shift_label') ?></th>
          <th><?= te('workweeks.settings.shift_start') ?></th>
          <th><?= te('workweeks.settings.shift_end') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php for ($n = 1; $n <= 5; $n++): ?>
        <?php $w = $shiftWindows[$n]; ?>
        <tr>
          <td class="muted"><?= $n ?></td>
          <td>
            <input type="text" name="shift_<?= $n ?>_label" value="<?= e($w['label']) ?>"
                   maxlength="16" required aria-label="<?= te('workweeks.settings.shift_label') ?>">
          </td>
          <td>
            <input type="time" name="shift_<?= $n ?>_start" value="<?= e($w['start']) ?>" required
                   aria-label="<?= te('workweeks.settings.shift_start') ?>">
          </td>
          <td>
            <input type="time" name="shift_<?= $n ?>_end" value="<?= e($w['end']) ?>" required
                   aria-label="<?= te('workweeks.settings.shift_end') ?>">
          </td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
  </fieldset>

  <?php if (empty($loggingOn)): ?>
    <div class="flash flash-warn"><?= te('workweeks.settings.need_comments') ?></div>
  <?php else: ?>
    <fieldset class="form-fieldset">
      <legend><?= te('workweeks.settings.log_title') ?></legend>
      <label><?= te('workweeks.settings.news_id') ?>
        <select name="news_schedule_id">
          <option value="0"<?= $newsId < 1 ? ' selected' : '' ?>><?= te('workweeks.settings.news_none') ?></option>
          <?php foreach ($newsOptions as $oid => $olabel): ?>
            <option value="<?= (int)$oid ?>"<?= (int)$newsId === (int)$oid ? ' selected' : '' ?>><?= e($olabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="muted"><?= te('workweeks.settings.news_hint') ?></p>
      <?php if ($newsId > 0): ?>
        <p class="muted">
          <?= te('workweeks.settings.current') ?>:
          <?php if (isset($newsOptions[$newsId]) || $newsLabel !== ''): ?>
            <a href="/news/<?= (int)$newsId ?>"><?= e($newsLabel !== '' ? $newsLabel : ('#' . (int)$newsId)) ?></a>
          <?php else: ?>
            #<?= (int)$newsId ?>
            <span class="flash flash-warn" style="display:inline"><?= te('workweeks.settings.err.news') ?></span>
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </fieldset>
  <?php endif; ?>

  <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
</form>
