<?php
declare(strict_types=1);

$lang = (string)($_GET['lang'] ?? $_POST['lang'] ?? '');
$return = (string)($_GET['return'] ?? $_POST['return'] ?? '/');
// Only allow relative local paths
if ($return === '' || $return[0] !== '/' || strpos($return, '//') === 0) {
    $return = '/';
}
if (strpos($return, "/locale") === 0) {
    $return = '/';
}

$normalized = I18n::normalize($lang);
if ($normalized !== null) {
    I18n::applyUserChoice($normalized, Auth::check());
}

redirect($return);
