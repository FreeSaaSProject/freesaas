<?php
declare(strict_types=1);

/**
 * Optional «с» in period_from — bank sometimes writes «за период 31.07.26» without «с».
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    $old = '/период\s+с\s+(\d{2}\.\d{2}\.\d{2})/ui';
    $new = '/период\s+(?:с\s+)?(\d{2}\.\d{2}\.\d{2})/ui';

    $st = $pdo->prepare(
        'UPDATE base_remittance_agreements
         SET PATTERN_PERIOD_FROM = ?
         WHERE PATTERN_PERIOD_FROM = ? OR PARSER_PRESET = ?'
    );
    $st->execute([$new, $old, 'volga_termy_agency_v1']);
};
