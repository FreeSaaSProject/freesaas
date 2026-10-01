<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$qs = [];
if (isset($_GET['ret'])) {
    $qs['ret'] = (string)$_GET['ret'];
}
$target = '/procedures/' . max(0, $id) . '/edit';
if ($qs !== []) {
    $target .= '?' . http_build_query($qs);
}
redirect($target);
