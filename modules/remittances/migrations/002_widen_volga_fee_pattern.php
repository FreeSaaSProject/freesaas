<?php
declare(strict_types=1);

/**
 * Banks started abbreviating «возмещаемых расходов» → «возмещ.расходов» in purpose text.
 * Widen stored PATTERN_FEE for agreements still on the old Volga Termy preset slot.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $old = '/возмещаемых\s+расходов\s+([0-9\s\x{00A0}]+,\d{2})\s*руб/ui';
    $new = '/возмещ(?:аемых|\.)\s*расходов\s*([0-9\s\x{00A0}]+,\d{2})\s*руб/ui';

    $st = $pdo->prepare(
        'UPDATE base_remittance_agreements
         SET PATTERN_FEE = ?
         WHERE PATTERN_FEE = ? OR PARSER_PRESET = ?'
    );
    $st->execute([$new, $old, 'volga_termy_agency_v1']);
};
