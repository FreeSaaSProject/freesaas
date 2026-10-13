<?php
declare(strict_types=1);
/** @var array $user */
$tips = trim((string)($user['link_ok'] ?? ''));
$photo = trim((string)($user['photo'] ?? ''));
$photoUrl = '';
if ($photo !== '') {
    if (preg_match('#^https?://#i', $photo)) {
        $photoUrl = $photo;
    } else {
        $host = class_exists('AppRuntime', false) ? AppRuntime::publicHost() : '';
        if ($host !== '') {
            $path = $photo[0] === '/' ? $photo : '/' . $photo;
            $photoUrl = 'https://' . $host . $path;
        }
    }
}
?>
<dt><?= te('likespa.user.tips') ?></dt><dd><?php
if ($tips !== '') {
    echo '<a href="' . e($tips) . '" target="_blank" rel="noopener">' . e($tips) . '</a>';
} else {
    echo '—';
}
?></dd>
<dt><?= te('likespa.user.photo') ?></dt><dd><?php
if ($photoUrl !== '') {
    echo '<a href="' . e($photoUrl) . '" target="_blank" rel="noopener">'
        . '<img src="' . e($photoUrl) . '" alt="" class="user-site-photo" width="120" height="120" loading="lazy">'
        . '</a>';
    if ($photo !== '' && !preg_match('#^https?://#i', $photo)) {
        echo '<div class="muted" style="margin-top:0.25rem;font-size:0.85em">' . e($photo) . '</div>';
    }
} elseif ($photo !== '') {
    echo e($photo);
} else {
    echo '—';
}
?></dd>
<dt><?= te('likespa.user.site_descr') ?></dt><dd><?= e((string)(($user['site_descr'] ?? '') !== '' ? $user['site_descr'] : '—')) ?></dd>
