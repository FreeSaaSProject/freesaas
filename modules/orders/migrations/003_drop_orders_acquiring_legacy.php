<?php
declare(strict_types=1);

/**
 * Drop legacy acquiring / dead columns on base_orders.
 * Remap payment STATUS values first so historical rows stay usable.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_orders')) {
        return;
    }

    $remap = [
        'sberPayReady' => 'cashComplete',
        'paidSber' => 'cashComplete',
        'ukassaNew' => 'cashComplete',
        'failedPaySber' => 'userCancelPay',
    ];
    $st = $pdo->prepare('UPDATE base_orders SET STATUS = ? WHERE STATUS = ?');
    foreach ($remap as $from => $to) {
        $st->execute([$to, $from]);
    }

    $drop = [
        'EPAID',
        'SBER_ID',
        'SBER_LINK',
        'SBER_STATUS',
        'SUMM_SBER',
        'PPP_LOG',
        'SUMM_BONUS',
        'ID_EVENT_PAID',
    ];
    foreach ($drop as $col) {
        if (Database::columnExists('base_orders', $col)) {
            $pdo->exec('ALTER TABLE `base_orders` DROP COLUMN `' . $col . '`');
        }
    }
    Database::clearSchemaCache();
};
