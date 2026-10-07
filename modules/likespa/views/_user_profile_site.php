<?php
declare(strict_types=1);
/** @var array $user */
?>
<dt><?= te('likespa.user.photo') ?></dt><dd><?= e((string)(($user['photo'] ?? '') !== '' ? $user['photo'] : '—')) ?></dd>
<dt><?= te('likespa.user.site_descr') ?></dt><dd><?= e((string)(($user['site_descr'] ?? '') !== '' ? $user['site_descr'] : '—')) ?></dd>
