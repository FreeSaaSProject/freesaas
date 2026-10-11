<?php
declare(strict_types=1);
/**
 * Oper-style red banner when inbox has unparsed audio files.
 *
 * @var int $inboxCount
 * @var string $inboxPath
 * @var bool $canIngest
 */
$inboxCount = (int)($inboxCount ?? 0);
$inboxPath = (string)($inboxPath ?? '/audio');
$canIngest = !empty($canIngest);
if ($inboxCount < 1) {
    return;
}
$ret = request_path();
$qs = $_GET ?? [];
if (is_array($qs) && $qs !== []) {
    $ret .= '?' . http_build_query($qs);
}
$href = '/audio/ingest?ret=' . rawurlencode($ret);
?>
<div class="audio-inbox-banner" role="status">
  <?= t('audio.inbox.banner_html', [
      'path' => '<code>' . e($inboxPath) . '</code>',
      'n' => '<strong>' . (int)$inboxCount . '</strong>',
  ]) ?>
  <?php if ($canIngest): ?>
    <a href="<?= e($href) ?>"
       target="_blank"
       rel="noopener"
       onclick="return confirm(<?= tj('audio.inbox.confirm') ?>);"><?= te('audio.inbox.run') ?></a>
  <?php endif; ?>
</div>
