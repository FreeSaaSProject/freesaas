<?php
declare(strict_types=1);
/** @var array<string,string> $cfg */
/** @var array<int,string> $pollOptions */
/** @var list<array{id:int,name:string,disabled:int,score:int}> $dictKeys */
$cfg = is_array($cfg ?? null) ? $cfg : [];
$pollOptions = is_array($pollOptions ?? null) ? $pollOptions : [];
$dictKeys = is_array($dictKeys ?? null) ? $dictKeys : [];
?>
<section class="panel poll-speech-settings">
  <form method="post" action="/poll-speech/settings">
    <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
    <?php require __DIR__ . '/_settings_section.php'; ?>
    <button type="submit" class="btn-primary"><?= te('poll_speech.btn.save') ?></button>
  </form>
</section>
