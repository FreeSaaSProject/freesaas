<?php
declare(strict_types=1);

/** Oper-compatible enum maps for base_fullstats classification. */
final class TicketTypeEnums
{
    /**
     * Oasis visits are allowed only Sep–Nov 2026 (inclusive).
     * Outside this window IS_OASIS must stay "not oasis" (2).
     */
    public static function oasisWindowStart(): int
    {
        $t = strtotime('2026-09-01 00:00:00');
        return $t !== false ? $t : 1756684800;
    }

    public static function oasisWindowEndExclusive(): int
    {
        $t = strtotime('2026-12-01 00:00:00');
        return $t !== false ? $t : 1764547200;
    }

    public static function dtInOasisWindow(int $dtstart): bool
    {
        return $dtstart >= self::oasisWindowStart()
            && $dtstart < self::oasisWindowEndExclusive();
    }

    /** @return array<int,string> value => i18n key */
    public static function idTypeKeys(): array
    {
        return [
            0 => 'terminals.fullstats.enum.id_type.0',
            1 => 'terminals.fullstats.enum.id_type.1',
            2 => 'terminals.fullstats.enum.id_type.2',
            3 => 'terminals.fullstats.enum.id_type.3',
        ];
    }

    /** @return array<int,string> */
    public static function isLimitedKeys(): array
    {
        return [
            0 => 'terminals.fullstats.enum.is_limited.0',
            1 => 'terminals.fullstats.enum.is_limited.1',
            2 => 'terminals.fullstats.enum.is_limited.2',
            3 => 'terminals.fullstats.enum.is_limited.3',
            4 => 'terminals.fullstats.enum.is_limited.4',
            5 => 'terminals.fullstats.enum.is_limited.5',
            9 => 'terminals.fullstats.enum.is_limited.9',
        ];
    }

    /** @return array<int,string> */
    public static function isPayerKeys(): array
    {
        return [
            0 => 'terminals.fullstats.enum.is_payer.0',
            1 => 'terminals.fullstats.enum.is_payer.1',
            2 => 'terminals.fullstats.enum.is_payer.2',
        ];
    }

    /** @return array<int,string> */
    public static function isOasisKeys(): array
    {
        return [
            0 => 'terminals.fullstats.enum.is_oasis.0',
            1 => 'terminals.fullstats.enum.is_oasis.1',
            2 => 'terminals.fullstats.enum.is_oasis.2',
        ];
    }

    /** @return list<int> */
    public static function idTypeValues(): array
    {
        return array_map('intval', array_keys(self::idTypeKeys()));
    }

    /** @return list<int> */
    public static function isLimitedValues(): array
    {
        return array_map('intval', array_keys(self::isLimitedKeys()));
    }

    /** @return list<int> */
    public static function isPayerValues(): array
    {
        return array_map('intval', array_keys(self::isPayerKeys()));
    }

    /** @return list<int> */
    public static function isOasisValues(): array
    {
        return array_map('intval', array_keys(self::isOasisKeys()));
    }

    public static function normalizeIdType(int $v): int
    {
        return in_array($v, self::idTypeValues(), true) ? $v : 0;
    }

    public static function normalizeIsLimited(int $v): int
    {
        return in_array($v, self::isLimitedValues(), true) ? $v : 0;
    }

    public static function normalizeIsPayer(int $v): int
    {
        return in_array($v, self::isPayerValues(), true) ? $v : 0;
    }

    public static function normalizeIsOasis(int $v): int
    {
        return in_array($v, self::isOasisValues(), true) ? $v : 0;
    }

    public static function labelIdType(int $v): string
    {
        $keys = self::idTypeKeys();
        return isset($keys[$v]) ? t($keys[$v]) : (string)$v;
    }

    public static function labelIsLimited(int $v): string
    {
        $keys = self::isLimitedKeys();
        return isset($keys[$v]) ? t($keys[$v]) : (string)$v;
    }

    public static function labelIsPayer(int $v): string
    {
        $keys = self::isPayerKeys();
        return isset($keys[$v]) ? t($keys[$v]) : (string)$v;
    }

    public static function labelIsOasis(int $v): string
    {
        $keys = self::isOasisKeys();
        return isset($keys[$v]) ? t($keys[$v]) : (string)$v;
    }

    /**
     * Catalog for LLM prompts (id + human label).
     *
     * @return array{
     *   id_type:list<array{id:int,name:string}>,
     *   is_limited:list<array{id:int,name:string}>,
     *   is_payer:list<array{id:int,name:string}>,
     *   is_oasis:list<array{id:int,name:string}>
     * }
     */
    public static function llmCatalog(): array
    {
        $map = static function (array $keys): array {
            $out = [];
            foreach ($keys as $id => $key) {
                $out[] = ['id' => (int)$id, 'name' => t((string)$key)];
            }
            return $out;
        };
        return [
            'id_type' => $map(self::idTypeKeys()),
            'is_limited' => $map(self::isLimitedKeys()),
            'is_payer' => $map(self::isPayerKeys()),
            'is_oasis' => $map(self::isOasisKeys()),
        ];
    }

    /** Render &lt;option&gt; list for a select. */
    public static function optionsHtml(array $keys, int $selected): string
    {
        $html = '';
        foreach ($keys as $id => $key) {
            $id = (int)$id;
            $sel = $id === $selected ? ' selected' : '';
            $html .= '<option value="' . $id . '"' . $sel . '>' . e(t((string)$key)) . '</option>';
        }
        return $html;
    }
}
