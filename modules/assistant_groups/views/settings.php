<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,mixed> $settings */
$enabled = !empty($settings['group_enabled']);
$mention = !empty($settings['group_require_mention']);
$autoAdd = !empty($settings['auto_add_groups']);
$chats = (string)($settings['group_chat_ids'] ?? '');
$groupChats = is_array($settings['group_chats'] ?? null) ? $settings['group_chats'] : [];
$opsExtra = (string)($settings['group_ops_extra'] ?? '');
$opsCsv = (string)($settings['group_ops'] ?? '');
$catalog = is_array($settings['ops_catalog'] ?? null) ? $settings['ops_catalog'] : [];
$discovered = is_array($settings['discovered_chats'] ?? null) ? $settings['discovered_chats'] : [];
$hasTok = !empty($settings['has_token']);
$preview = (string)($settings['token_preview'] ?? '');
$webhookUrl = (string)($settings['webhook_url'] ?? '');
$whSub = !empty($settings['webhook_subscribed']);
$pendingDiscover = [];
foreach ($discovered as $d) {
    if (is_array($d) && empty($d['allowed'])) {
        $pendingDiscover[] = $d;
    }
}
// One empty row so the admin can append a chat without editing the CSV.
$groupChats[] = ['chat_id' => '', 'title' => '', 'description' => ''];
?>
<section class="page-head">
  <h1><?= te('assistant_groups.settings.title') ?></h1>
  <p class="lede"><?= te('assistant_groups.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid ag-settings" style="max-width:52rem">
  <?= Csrf::field() ?>
  <input type="hidden" name="group_ops_catalog" value="1">

  <fieldset class="form-fieldset">
    <legend><?= te('assistant_groups.settings.sec.general') ?></legend>
    <label class="check">
      <input type="checkbox" name="group_enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
      <?= te('assistant_groups.settings.enabled') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="group_require_mention" value="1"<?= $mention ? ' checked' : '' ?>>
      <?= te('assistant_groups.settings.require_mention') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="auto_add_groups" value="1"<?= $autoAdd ? ' checked' : '' ?>>
      <?= te('assistant_groups.settings.auto_add_groups') ?>
    </label>
    <p class="muted"><?= te('assistant_groups.settings.auto_add_groups_hint') ?></p>
  </fieldset>

  <fieldset class="form-fieldset">
    <legend><?= te('assistant_groups.settings.sec.chats') ?></legend>
    <p class="muted"><?= te('assistant_groups.settings.chats_intro') ?></p>
    <p class="muted"><?= te('assistant_groups.settings.chats_meta_hint') ?></p>
    <div class="ag-chat-rows">
      <?php foreach ($groupChats as $i => $gc):
        if (!is_array($gc)) {
            continue;
        }
        $cid = (string)($gc['chat_id'] ?? '');
        $gtitle = (string)($gc['title'] ?? '');
        $gdescr = (string)($gc['description'] ?? '');
        ?>
        <div class="ag-chat-row" style="border:1px solid var(--border, #ddd);padding:0.75rem;margin-bottom:0.75rem;border-radius:4px">
          <label>
            <?= te('assistant_groups.settings.chat_id') ?>
            <input name="group_chat[<?= (int)$i ?>][id]" value="<?= e($cid) ?>"
                   placeholder="-8024…" autocomplete="off" spellcheck="false">
          </label>
          <label>
            <?= te('assistant_groups.settings.chat_title') ?>
            <input name="group_chat[<?= (int)$i ?>][title]" value="<?= e($gtitle) ?>"
                   maxlength="120" autocomplete="off">
          </label>
          <label>
            <?= te('assistant_groups.settings.chat_description') ?>
            <textarea name="group_chat[<?= (int)$i ?>][description]" rows="2" maxlength="500"><?= e($gdescr) ?></textarea>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
    <details>
      <summary><?= te('assistant_groups.settings.chat_ids_advanced') ?></summary>
      <p class="muted"><?= te('assistant_groups.settings.chat_ids_hint') ?></p>
      <code class="ag-chat-ids-csv"><?= e($chats !== '' ? $chats : '—') ?></code>
    </details>

    <?php if ($pendingDiscover !== []): ?>
      <div class="ag-discovered">
        <p><strong><?= te('assistant_groups.settings.discovered_title') ?></strong></p>
        <p class="muted"><?= te('assistant_groups.settings.discovered_hint') ?></p>
        <?php foreach ($pendingDiscover as $d): ?>
          <label class="check">
            <input type="checkbox" name="discovered_id[]" value="<?= e((string)($d['chat_id'] ?? '')) ?>">
            <code><?= e((string)($d['chat_id'] ?? '')) ?></code>
            <?php if (($d['title'] ?? '') !== ''): ?>
              — <?= e((string)$d['title']) ?>
            <?php endif; ?>
            <span class="muted">(<?= e((string)($d['source'] ?? '')) ?>)</span>
          </label>
        <?php endforeach; ?>
        <div class="form-actions" style="margin-top:0.5rem">
          <button type="submit" class="btn-primary" name="action" value="discover_add"><?= te('assistant_groups.settings.discovered_add') ?></button>
          <button type="submit" class="btn" name="action" value="discover_dismiss"><?= te('assistant_groups.settings.discovered_dismiss') ?></button>
        </div>
      </div>
    <?php else: ?>
      <p class="muted"><?= te('assistant_groups.settings.discovered_empty') ?></p>
    <?php endif; ?>
  </fieldset>

  <fieldset class="form-fieldset">
    <legend><?= te('assistant_groups.settings.sec.ops') ?></legend>
    <p class="muted"><?= te('assistant_groups.settings.ops_catalog_hint') ?></p>
    <?php if ($opsCsv !== ''): ?>
      <p class="muted ag-ops-current"><code><?= e($opsCsv) ?></code></p>
    <?php endif; ?>

    <div class="ag-ops-catalog" data-ag-ops-catalog>
      <?php foreach ($catalog as $group): ?>
        <?php
        if (!is_array($group)) {
            continue;
        }
        $mod = (string)($group['module'] ?? '');
        $title = (string)($group['title'] ?? $mod);
        $star = !empty($group['star']);
        $ops = is_array($group['ops'] ?? null) ? $group['ops'] : [];
        $open = $star;
        if (!$open) {
            foreach ($ops as $o) {
                if (!empty($o['checked'])) {
                    $open = true;
                    break;
                }
            }
        }
        ?>
        <details class="ag-ops-mod" data-module="<?= e($mod) ?>"<?= $open ? ' open' : '' ?>>
          <summary class="ag-ops-mod-sum">
            <label class="check ag-ops-star" onclick="event.stopPropagation()">
              <input type="checkbox"
                     name="group_ops_module[]"
                     value="<?= e($mod) ?>"
                     data-ag-module-star
                     <?= $star ? ' checked' : '' ?>>
              <strong><?= e($title) ?></strong>
              <span class="muted"> (<?= e($mod) ?>.*)</span>
            </label>
            <span class="muted ag-ops-count"><?= (string)count($ops) ?></span>
          </summary>
          <div class="ag-ops-list"<?= $star ? ' hidden' : '' ?> data-ag-ops-list>
            <?php foreach ($ops as $op): ?>
              <?php if (!is_array($op)) {
                  continue;
              } ?>
              <label class="check ag-ops-item">
                <input type="checkbox"
                       name="group_ops_id[]"
                       value="<?= e((string)($op['id'] ?? '')) ?>"
                       data-ag-op
                       <?= !empty($op['checked']) || $star ? ' checked' : '' ?>
                       <?= $star ? ' disabled' : '' ?>>
                <span class="ag-ops-id"><code><?= e((string)($op['id'] ?? '')) ?></code></span>
                <?php if (($op['side_effect'] ?? '') === 'write'): ?>
                  <span class="ag-ops-badge"><?= te('assistant_groups.settings.ops_write') ?></span>
                <?php endif; ?>
                <span class="muted ag-ops-desc"><?= e((string)($op['description'] ?? '')) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </div>

    <details class="ag-ops-advanced">
      <summary><?= te('assistant_groups.settings.ops_advanced') ?></summary>
      <label>
        <?= te('assistant_groups.settings.ops_extra') ?>
        <textarea name="group_ops_extra" rows="2" placeholder="custom.prefix.*"><?= e($opsExtra) ?></textarea>
      </label>
      <p class="muted"><?= te('assistant_groups.settings.ops_extra_hint') ?></p>
    </details>
  </fieldset>

  <fieldset class="form-fieldset">
    <legend><?= te('assistant_groups.settings.sec.token') ?></legend>
    <?php if ($hasTok && $preview !== ''): ?>
      <p class="muted"><?= te('assistant_groups.settings.token_set', ['preview' => $preview]) ?></p>
    <?php endif; ?>
    <label>
      <?= te('assistant_groups.settings.token') ?>
      <input type="password" name="max_token" value="" autocomplete="new-password" spellcheck="false" placeholder="…">
    </label>
    <p class="muted"><?= te('assistant_groups.settings.token_hint') ?></p>
  </fieldset>

  <fieldset class="form-fieldset">
    <legend><?= te('assistant_groups.settings.sec.webhook') ?></legend>
    <p class="muted"><?= te('assistant_groups.settings.webhook_intro') ?></p>
    <label>
      <?= te('assistant_groups.settings.webhook_url') ?>
      <input name="webhook_url" value="<?= e($webhookUrl) ?>" autocomplete="off" spellcheck="false">
    </label>
    <p class="muted">
      <?= $whSub
          ? te('assistant_groups.settings.webhook_status_on')
          : te('assistant_groups.settings.webhook_status_off') ?>
    </p>
  </fieldset>

  <div class="form-actions">
    <button type="submit" class="btn-primary" name="action" value="save"><?= te('assistant_groups.settings.save') ?></button>
    <button type="submit" class="btn" name="action" value="subscribe"><?= te('assistant_groups.settings.subscribe') ?></button>
    <button type="submit" class="btn" name="action" value="unsubscribe"><?= te('assistant_groups.settings.unsubscribe') ?></button>
    <a class="btn" href="/assistant-groups/status"><?= te('assistant_groups.settings.to_status') ?></a>
  </div>
</form>
