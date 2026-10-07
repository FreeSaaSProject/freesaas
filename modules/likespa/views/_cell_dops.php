<?php
declare(strict_types=1);
/** @var array $dops */
/** @var bool $canMoney */
$d = is_array($dops ?? null) ? $dops : [];
$bits = [];
if ((int)($d['unique_clients'] ?? 0) > 0) {
    $bits[] = t('likespa.dops.clients', ['n' => (string)(int)$d['unique_clients']]);
}
if ((int)($d['count'] ?? 0) > 0) {
    $bits[] = t('likespa.dops.badge', ['n' => (string)(int)$d['count']]);
    if (!empty($canMoney) && (float)($d['sum'] ?? 0) > 0) {
        $bits[] = t('likespa.dops.sum', ['sum' => (string)(int)round((float)$d['sum'])]);
    }
}
if ($bits === []) {
    return;
}
?>
<span class="likespa-dops"><?= e(implode(' · ', $bits)) ?></span>
