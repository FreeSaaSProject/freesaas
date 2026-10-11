<?php
declare(strict_types=1);

/**
 * A4 group print for warehouse stickers (oper printstickers parity).
 * Sheet: 5 columns × 13 rows = 65 labels per page; multi-page with CSS breaks.
 */
final class StickerPrint
{
    public const PAGE_COLS = 5;
    public const PAGE_ROWS = 13;
    public const PAGE_SIZE = 65;
    public const QR_SIZE = 58;
    /** Hard cap so a runaway pending queue cannot blow memory. */
    public const MAX_BATCH = 2000;

    public static function isPublicPrintEnabled(): bool
    {
        if (!class_exists('Config', false)) {
            return false;
        }
        return (int)Config::get('stickers', 'public_print_enabled', '0') === 1;
    }

    public static function setPublicPrintEnabled(bool $on): void
    {
        Config::set('stickers', 'public_print_enabled', $on ? '1' : '0');
    }

    /**
     * Absolute URL for the anonymous print sheet (admin host preferred — warehouse PC).
     */
    public static function publicPrintUrl(): string
    {
        $host = AppRuntime::adminHost();
        if ($host === '') {
            $host = AppRuntime::publicHost();
        }
        if ($host === '') {
            $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
        $scheme = 'https';
        if (strpos($host, 'dev') !== false) {
            $scheme = 'http';
        }
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        }
        return $scheme . '://' . $host . '/stickers/printstickers';
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    public static function parseIdsParam(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,\s]+/', trim($raw)) ?: [] as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    /**
     * @param list<array<string,mixed>> $items raw DB rows from listForPrint*
     * @return array{ok:bool,total:int,stickers:list<array<string,mixed>>,pages:int}
     */
    public static function buildPayload(array $items): array
    {
        $names = [];
        if (class_exists('ProductsRepository', false)) {
            foreach ($items as $it) {
                $gid = (int)($it['id_good'] ?? 0);
                if ($gid > 0 && !isset($names[$gid])) {
                    $p = ProductsRepository::find($gid);
                    $names[$gid] = $p ? (string)$p['name'] : ('#' . $gid);
                }
            }
        }
        $stickers = [];
        foreach ($items as $it) {
            $hash = (string)($it['hash'] ?? '');
            $url = $hash !== '' ? StickerQr::absoluteScanUrl($hash) : '';
            $svgHtml = $url !== '' ? StickerQr::svgHtml($url, self::QR_SIZE) : '';
            $stickers[] = [
                'id' => (int)$it['id'],
                'pin' => (string)($it['pin'] ?? ''),
                'hash' => $hash,
                'url' => $url,
                'svg' => $svgHtml,
                'svg_html' => $svgHtml,
                'product_name' => $names[(int)($it['id_good'] ?? 0)] ?? ('#' . (int)($it['id_good'] ?? 0)),
                'left' => round((float)($it['qtyu_in'] ?? 0) - (float)($it['qtyu_out'] ?? 0), 2),
                'dt_create' => (int)($it['dt_create'] ?? 0),
            ];
        }
        $total = count($stickers);
        return [
            'ok' => true,
            'total' => $total,
            'pages' => $total > 0 ? (int)ceil($total / self::PAGE_SIZE) : 0,
            'stickers' => $stickers,
        ];
    }

    /**
     * Chunk stickers into A4 pages (65 each).
     *
     * @param list<array<string,mixed>> $stickers
     * @return list<list<array<string,mixed>>>
     */
    public static function pages(array $stickers): array
    {
        if ($stickers === []) {
            return [];
        }
        return array_chunk($stickers, self::PAGE_SIZE);
    }
}
