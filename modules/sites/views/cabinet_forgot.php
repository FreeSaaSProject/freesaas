<?php
declare(strict_types=1);
/** @var string $error */
/** @var string $message */
/** @var list<array<string,mixed>> $channels */
/** @var array<string,mixed>|null $result */
$error = $error ?? '';
$message = $message ?? '';
$channels = is_array($channels ?? null) ? $channels : [];
$result = $result ?? null;
echo ClientPortalView::captureModule('forgot', [
    'error' => $error,
    'message' => $message,
    'channels' => $channels,
    'result' => $result,
]);
?>
<link rel="stylesheet" href="/modules/client_portal/assets/css/client_portal.css">
