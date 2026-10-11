<?php
/** @var array $user */
/** @var string $statusName */
/** @var string $partnerStatusName */
/** @var int $favoritesCount */
/** @var string $profileChannelsHtml */
/** @var string $profileFlagsHtml */
/** @var string $profileSiteHtml */
/** @var bool $canManageAuth */
/** @var array{type?:string,message?:string}|null $flash */
$uid = (int)$user['id'];
$profileChannelsHtml = (string)($profileChannelsHtml ?? '');
$profileFlagsHtml = (string)($profileFlagsHtml ?? '');
$profileSiteHtml = (string)($profileSiteHtml ?? '');
$canManageAuth = !empty($canManageAuth);
$flash = is_array($flash ?? null) ? $flash : null;
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
        <button type="submit" class="btn"><?= te('core.user.login_link_create') ?></button>
      </form>
      <form method="post" class="inline-form" action="/users/<?= $uid ?>">
        <input type="hidden" name="action" value="unlock">
        <button type="submit" class="btn"><?= te('core.user.unlock') ?></button>
      </form>
      <?php endif; ?>
      <a class="btn-primary" href="/users/<?= $uid ?>/edit"><?= te('core.common.edit') ?></a>
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
      <dt><?= te('core.user.active') ?></dt><dd><?= ((string)$user['enabled'] === '1') ? te('core.common.yes') : te('core.common.no') ?></dd>
      <dt><?= te('core.user.role') ?></dt><dd><?= e($statusName) ?></dd>
      <?php if ($partnerStatusName !== ''): ?>
      <dt><?= te('core.user.partner') ?></dt><dd><?= e($partnerStatusName) ?></dd>
      <?php endif; ?>
      <dt><?= te('core.user.password_expire') ?></dt><dd><?= ((int)($user['expire'] ?? 0) > 0) ? e(I18n::formatDate((int)$user['expire'], 'datetime')) : '—' ?></dd>
      <dt><?= te('core.user.retries') ?></dt><dd><?= (int)($user['retries'] ?? 0) ?></dd>
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
  <?php if ($profileSiteHtml !== ''): ?>
  <div class="widget">
    <h2><?= te('core.user.site') ?></h2>
    <dl class="kv">
      <?= $profileSiteHtml ?>
    </dl>
  </div>
  <?php endif; ?>
</div>
