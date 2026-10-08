<?php
declare(strict_types=1);

/** HTML chips for ledger account / move rows. */
final class BankBadges
{
    /**
     * @param list<int> $accountIds
     * @return array<int,string> account_id => html
     */
    public static function forAccounts(array $accountIds): array
    {
        $map = BankAccountLinksRepository::linkedAccountProviders($accountIds);
        $out = [];
        foreach ($map as $aid => $info) {
            $label = t('banks.badge.bank');
            $provider = (string)($info['label'] ?? '');
            $title = $provider !== '' ? $provider : $label;
            $out[$aid] = '<span class="bank-chip" title="' . e($title) . '">'
                . e($label)
                . ($provider !== '' ? ' · ' . e($provider) : '')
                . '</span>';
        }
        return $out;
    }

    /**
     * @param list<int> $moveIds
     * @param array<int,int> $typeByMove optional move_id => type_id for unclassified chip
     * @return array<int,string>
     */
    public static function forMoves(array $moveIds, array $typeByMove = []): array
    {
        $map = BankTxMapRepository::byMoveIds($moveIds);
        if ($map === []) {
            return [];
        }
        $defaultType = BanksConfig::defaultMoveTypeId();
        $out = [];
        foreach ($map as $mid => $info) {
            $provider = BanksConfig::providerLabel((string)$info['provider']);
            $html = '<span class="bank-chip" title="' . e($provider) . '">' . e(t('banks.badge.bank'))
                . ' · ' . e($provider) . '</span>';
            $typeId = (int)($typeByMove[$mid] ?? 0);
            if ($defaultType > 0 && $typeId === $defaultType) {
                $html .= ' <span class="bank-chip bank-chip-warn">' . e(t('banks.badge.unclassified')) . '</span>';
            }
            $out[$mid] = $html;
        }
        return $out;
    }
}
