<?php
/** @var array $user */
/** @var string $statusName */
/** @var string $partnerStatusName */
/** @var int $favoritesCount */
/** @var array<string,bool> $features */
/** @var string $profileChannelsHtml */
/** @var string $profileFlagsHtml */
/** @var string $profileSiteHtml */
$uid = (int)$user['id'];
$features = $features ?? UsersRepository::editFeatures();
$profileChannelsHtml = (string)($profileChannelsHtml ?? '');
$profileFlagsHtml = (string)($profileFlagsHtml ?? '');
$profileSiteHtml = (string)($profileSiteHtml ?? '');
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
      <a class="btn-primary" href="/users/<?= $uid ?>/edit"><?= te('core.common.edit') ?></a>
    </div>
  </div>
</section>

<div class="detail-grid">
  <div class="widget">
    <h2><?= te('core.user.profile') ?></h2>
    <dl class="kv">
      <dt>ID</dt><dd><?= $uid ?></dd>
      <dt><?= te('core.user.email') ?></dt><dd><?= e((string)($user['mail'] ?: '—')) ?></dd>
      <dt><?= te('core.user.phone') ?></dt><dd><?= e((string)($user['tel'] ?: '—')) ?></dd>
      <dt><?= te('core.user.active') ?></dt><dd><?= ((string)$user['enabled'] === '1') ? te('core.common.yes') : te('core.common.no') ?></dd>
      <dt><?= te('core.user.role') ?></dt><dd><?= e($statusName) ?></dd>
      <?php if ($partnerStatusName !== ''): ?>
      <dt><?= te('core.user.partner') ?></dt><dd><?= e($partnerStatusName) ?></dd>
      <?php endif; ?>
      <dt><?= te('core.user.password_expire') ?></dt><dd><?= ((int)($user['expire'] ?? 0) > 0) ? e(I18n::formatDate((int)$user['expire'], 'datetime')) : '—' ?></dd>
      <dt><?= te('core.user.retries') ?></dt><dd><?= (int)($user['retries'] ?? 0) ?></dd>
    </dl>
  </div>
  <div class="widget">
    <h2><?= te('core.user.channels') ?></h2>
    <dl class="kv">
      <dt><?= te('core.user.tips') ?></dt><dd><?php
        $tips = trim((string)($user['link_ok'] ?? ''));
        if ($tips !== '') {
            echo '<a href="' . e($tips) . '" target="_blank" rel="noopener">' . e($tips) . '</a>';
        } else {
            echo '—';
        }
      ?></dd>
      <?php if (!empty($features['employee_bot'])): ?>
      <dt><?= te('core.user_edit.telegram_chat_id') ?></dt><dd><?= e((string)($user['telegram_chat_id'] ?: '—')) ?></dd>
      <dt><?= te('core.user_edit.max_user_id') ?></dt><dd><?= e((string)($user['max_user_id'] ?: '—')) ?></dd>
      <dt><?= te('core.user.bot_off') ?></dt><dd><?= !empty($user['BOT_NO_SEND']) ? te('core.common.yes') : te('core.common.no') ?></dd>
      <?php endif; ?>
      <?= $profileChannelsHtml ?>
    </dl>
  </div>
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
  <?php if (!empty($features['site_profile']) || $profileSiteHtml !== ''): ?>
  <div class="widget">
    <h2><?= te('core.user.site') ?></h2>
    <dl class="kv">
      <?php if (!empty($features['site_profile'])): ?>
      <dt><?= te('core.user.photo') ?></dt><dd><?= e((string)($user['photo'] ?: '—')) ?></dd>
      <dt><?= te('core.user.description') ?></dt><dd><?= e((string)($user['site_descr'] ?: '—')) ?></dd>
      <?php endif; ?>
      <?= $profileSiteHtml ?>
    </dl>
  </div>
  <?php endif; ?>
</div>
