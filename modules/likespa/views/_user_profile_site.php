<?php
declare(strict_types=1);
/** @var array $user */
$tips = trim((string)($user['link_ok'] ?? ''));
?>
<dt><?= te('likespa.user.tips') ?></dt><dd><?php
if ($tips !== '') {
    echo '<a href="' . e($tips) . '" target="_blank" rel="noopener">' . e($tips) . '</a>';
} else {
    echo '—';
}
?></dd>
<dt><?= te('likespa.user.photo') ?></dt><dd><?= e((string)(($user['photo'] ?? '') !== '' ? $user['photo'] : '—')) ?></dd>
<dt><?= te('likespa.user.site_descr') ?></dt><dd><?= e((string)(($user['site_descr'] ?? '') !== '' ? $user['site_descr'] : '—')) ?></dd>
