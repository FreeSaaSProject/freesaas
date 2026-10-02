<?php
/** @var list<array{path:string,title:string,group:string}> $commands */
$commands = $commands ?? [];
?>
<div class="command">
  <label class="sr-only" for="cmd"><?= te('core.home.command') ?></label>
  <input id="cmd" type="search" placeholder="<?= te('core.home.command_placeholder') ?>" data-commands='<?= e(json_encode($commands, JSON_UNESCAPED_UNICODE)) ?>' autocomplete="off">
  <ul id="cmd-list" class="command-list" hidden></ul>
</div>
