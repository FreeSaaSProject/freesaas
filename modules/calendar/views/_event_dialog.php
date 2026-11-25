<?php
declare(strict_types=1);
foreach (['bonuses', 'certificates', 'docs'] as $calDialogMod) {
    if (class_exists('ModuleManager', false) && ModuleManager::isEnabled($calDialogMod)) {
        Assets::need($calDialogMod);
    }
}
?>
<dialog id="calendar-event-dialog" class="calendar-event-dialog" aria-labelledby="calendar-event-dialog-title">
  <div id="calendar-event-dialog-body" class="calendar-event-dialog-body">
    <p class="muted" id="calendar-event-dialog-title"><?= te('calendar.event.dialog_loading') ?></p>
  </div>
</dialog>
