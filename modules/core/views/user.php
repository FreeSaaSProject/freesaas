<?php
declare(strict_types=1);
/** @var array $user */
/** @var string $statusName */
/** @var string $partnerStatusName */
/** @var int $favoritesCount */
/** @var string $profileChannelsHtml */
/** @var string $profileFlagsHtml */
/** @var string $profileSiteHtml */
/** @var bool $canManageAuth */
/** @var bool $canEdit */
/** @var bool $canSeeSensitive */
/** @var bool $canBotLinkCode */
/** @var array{type?:string,message?:string}|null $flash */
/** @var array{available?:bool,groups?:array<string,list<array{title:string,path:string,permission:string,extras:list<array{id:string,label:string}>}>>}|null $menuPreview */
$uid = (int)$user['id'];
$profileChannelsHtml = (string)($profileChannelsHtml ?? '');
$profileFlagsHtml = (string)($profileFlagsHtml ?? '');
$profileSiteHtml = (string)($profileSiteHtml ?? '');
$canManageAuth = !empty($canManageAuth);
$canEdit = !empty($canEdit);
$canSeeSensitive = !empty($canSeeSensitive);
$canBotLinkCode = !empty($canBotLinkCode);
$flash = is_array($flash ?? null) ? $flash : null;
$menuPreview = is_array($menuPreview ?? null) ? $menuPreview : null;
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1>
        <?= UsersRepository::badgeHtml($uid, false) ?>
        <?= e((string)($user['name'] ?: $user['login'])) ?>
      </h1>
      <p class="lede"><code><?= e((string)$user['login']) ?></code> · <?= e($statusName) ?></p>
    </div>
    <div class="page-actions">
      <?php if ($canManageAuth): ?>
      <form method="post" class="inline-form" action="/users/<?= $uid ?>">
        <input type="hidden" name="action" value="login_link">
        <button type="submit" class="btn-secondary"><?= te('core.user.login_link_create') ?></button>
      </form>
      <form method="post" class="inline-form" action="/users/<?= $uid ?>">
        <input type="hidden" name="action" value="unlock">
        <button type="submit" class="btn-secondary"><?= te('core.user.unlock') ?></button>
      </form>
      <form method="post" class="inline-form" action="/users/<?= $uid ?>"
            onsubmit="return confirm(<?= tj('core.user.logout_all_confirm') ?>)">
        <input type="hidden" name="action" value="logout_all">
        <button type="submit" class="btn-secondary"><?= te('core.user.logout_all') ?></button>
      </form>
      <?php endif; ?>
      <?php if ($canBotLinkCode): ?>
      <form method="post" class="inline-form" action="/users/<?= $uid ?>">
        <input type="hidden" name="action" value="bot_link_code">
        <button type="submit" class="btn-secondary"><?= te('core.user.bot_link_code') ?></button>
      </form>
      <?php endif; ?>
      <?php if ($canEdit): ?>
      <a class="btn-primary" href="/users/<?= $uid ?>/edit"><?= te('core.common.edit') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php if ($flash !== null && ($flash['message'] ?? '') !== ''): ?>
  <div class="flash flash-<?= e((string)($flash['type'] ?? 'ok')) ?>"><?= e((string)$flash['message']) ?></div>
<?php endif; ?>

<div class="detail-grid">
  <div class="widget">
    <h2><?= te('core.user.profile') ?></h2>
    <dl class="kv">
      <dt>ID</dt><dd><?= $uid ?></dd>
      <?php if (Database::columnExists('base_users', 'num')): ?>
      <dt><?= te('core.user.num') ?></dt><dd><?= (int)($user['num'] ?? 0) > 0 ? (int)$user['num'] : '—' ?></dd>
      <?php endif; ?>
      <dt><?= te('core.user.email') ?></dt><dd><?= e((string)($user['mail'] ?: '—')) ?></dd>
      <dt><?= te('core.user.phone') ?></dt><dd><?= e((string)($user['tel'] ?: '—')) ?></dd>
      <?php
        $bdayTs = (int)($user['bday'] ?? 0);
        $bdayCivil = ($bdayTs > 0 && Database::columnExists('base_users', 'bday'))
          ? UsersRepository::bdayCivilYmd($bdayTs)
          : '';
      ?>
      <?php if (Database::columnExists('base_users', 'bday')): ?>
      <dt><?= te('core.user.bday') ?></dt>
      <dd>
        <?php if ($bdayCivil !== ''): ?>
          <?php
            $bdayShow = substr($bdayCivil, 8, 2) . '.' . substr($bdayCivil, 5, 2) . '.' . substr($bdayCivil, 0, 4);
            $age = UsersRepository::ageOnDate($bdayTs, date('Y-m-d'));
          ?>
          <?= e(t('core.user.bday_value', ['date' => $bdayShow, 'age' => $age])) ?>
          <?php if (Database::columnExists('base_users', 'show_bday_in_cal')): ?>
            <span class="muted"> · <?= !empty($user['show_bday_in_cal']) ? te('core.user.bday_in_cal_yes') : te('core.user.bday_in_cal_no') ?></span>
          <?php endif; ?>
        <?php else: ?>
          —
        <?php endif; ?>
      </dd>
      <?php endif; ?>
      <dt><?= te('core.user.active') ?></dt><dd><?= ((string)$user['enabled'] === '1') ? te('core.common.yes') : te('core.common.no') ?></dd>
      <dt><?= te('core.user.role') ?></dt><dd><?= e($statusName) ?></dd>
      <?php if ($partnerStatusName !== ''): ?>
      <dt><?= te('core.user.partner') ?></dt><dd><?= e($partnerStatusName) ?></dd>
      <?php endif; ?>
      <dt><?= te('core.user.password_expire') ?></dt><dd><?= ((int)($user['expire'] ?? 0) > 0) ? e(I18n::formatDate((int)$user['expire'], 'datetime')) : '—' ?></dd>
      <?php if ($canSeeSensitive): ?>
      <dt><?= te('core.user.retries') ?></dt><dd><?= (int)($user['retries'] ?? 0) ?></dd>
      <?php endif; ?>
    </dl>
  </div>
  <?php if ($profileChannelsHtml !== ''): ?>
  <div class="widget">
    <h2><?= te('core.user.channels') ?></h2>
    <dl class="kv">
      <?= $profileChannelsHtml ?>
    </dl>
  </div>
  <?php endif; ?>
  <?php if ($canSeeSensitive): ?>
  <div class="widget">
    <h2><?= te('core.user.flags') ?></h2>
    <dl class="kv">
      <dt><?= te('core.user.hidden') ?></dt><dd><?= !empty($user['IS_HIDDEN']) ? te('core.common.yes') : te('core.common.no') ?></dd>
      <dt>Assist team</dt><dd><?= !empty($user['ASSIST_TEAM']) ? te('core.common.yes') : te('core.common.no') ?></dd>
      <dt><?= te('core.user.not_in_team') ?></dt><dd><?= !empty($user['NOT_IN_TEAM']) ? te('core.common.yes') : te('core.common.no') ?></dd>
      <?= $profileFlagsHtml ?>
      <dt><?= te('core.user.favorites') ?></dt><dd><?= (int)$favoritesCount ?></dd>
      <dt><?= te('core.user.news_after') ?></dt><dd><?= ((int)($user['showNewsAfter'] ?? 0) > 0) ? e(I18n::formatDate((int)$user['showNewsAfter'], 'datetime')) : '—' ?></dd>
    </dl>
  </div>
  <?php endif; ?>
  <?php if ($profileSiteHtml !== ''): ?>
  <div class="widget">
    <h2><?= te('core.user.site') ?></h2>
    <dl class="kv">
      <?= $profileSiteHtml ?>
    </dl>
  </div>
  <?php endif; ?>
</div>

<?php if ($canSeeSensitive && $menuPreview !== null): ?>
<section class="widget user-menu-preview">
  <h2><?= te('core.user.menu_preview') ?></h2>
  <?php if (empty($menuPreview['available'])): ?>
    <p class="muted"><?= te('core.user.menu_preview_disabled') ?></p>
  <?php elseif (empty($menuPreview['groups'])): ?>
    <p class="muted"><?= te('core.user.menu_preview_empty') ?></p>
  <?php else: ?>
    <?php foreach ($menuPreview['groups'] as $groupTitle => $items): ?>
      <div class="user-menu-preview-group">
        <h3 class="user-menu-preview-group-title"><?= e((string)$groupTitle) ?></h3>
        <ul class="user-menu-preview-list">
          <?php foreach ($items as $item): ?>
            <?php
            $itemPath = (string)($item['path'] ?? '');
            $itemTitle = (string)($item['title'] ?? '');
            $extras = is_array($item['extras'] ?? null) ? $item['extras'] : [];
            ?>
            <li class="user-menu-preview-item">
              <?php if ($itemPath !== ''): ?>
                <a href="<?= e($itemPath) ?>"><?= e($itemTitle) ?></a>
              <?php else: ?>
                <span><?= e($itemTitle) ?></span>
              <?php endif; ?>
              <?php if ($extras !== []): ?>
                <span class="user-menu-preview-perms">
                  <?php foreach ($extras as $extra): ?>
                    <span class="user-menu-preview-perm" title="<?= e((string)($extra['id'] ?? '')) ?>">+<?= e((string)($extra['label'] ?? '')) ?></span>
                  <?php endforeach; ?>
                </span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
<?php endif; ?>
