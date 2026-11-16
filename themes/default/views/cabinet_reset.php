<?php
declare(strict_types=1);
/** @var string $token */
/** @var string $error */
$token = $token ?? '';
$error = $error ?? '';
echo ClientPortalView::captureModule('reset', [
    'token' => $token,
    'error' => $error,
    'done' => false,
]);
?>
<link rel="stylesheet" href="/modules/client_portal/assets/css/client_portal.css">
