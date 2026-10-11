<?php
declare(strict_types=1);

$lang = (string)($_GET['lang'] ?? $_POST['lang'] ?? '');
$return = (string)($_GET['return'] ?? $_POST['return'] ?? '/');
// Only allow relative local paths (reject // and /\ which WHATWG treats as protocol-relative).
if ($return === '' || $return[0] !== '/' || strpos($return, '//') === 0
    || strpos($return, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $return)) {
    $return = '/';
}
if (strpos($return, '/locale') === 0) {
    $return = '/';
}

$normalized = I18n::normalize($lang);
if ($normalized !== null) {
    I18n::applyUserChoice($normalized, Auth::check());
}

redirect($return);
