<?php
declare(strict_types=1);

/** Agent diagnose for stickers / QR warehouse surface. */
final class StickersDiagnose
{
    /** @return array<string,mixed> */
    public static function report(): array
    {
        $issues = [];
        $publicHost = AppRuntime::publicHost();
        if ($publicHost === '') {
            $issues[] = [
                'level' => 'warn',
                'code' => 'no_public_host',
                'message' => 'app.public_host / likespa.mothersite empty — QR absolute URLs fall back to HTTP_HOST',
            ];
        }
        $cookieDomain = trim((string)Config::get('app', 'session_cookie_domain', ''));
        $adminHost = AppRuntime::adminHost();
        if ($adminHost !== '' && $publicHost !== '' && strcasecmp($adminHost, $publicHost) !== 0
            && $cookieDomain === '') {
            $issues[] = [
                'level' => 'warn',
                'code' => 'split_host_no_cookie_domain',
                'message' => 'admin_host ≠ public_host and session_cookie_domain empty — staff must login on public host for QR',
            ];
        }
        if (!is_file(__DIR__ . '/phpqrcode/phpqrcode.php')) {
            $issues[] = [
                'level' => 'error',
                'code' => 'phpqrcode_missing',
                'message' => 'phpqrcode vendor missing under modules/stickers/lib/phpqrcode/',
            ];
        }
        $soft = [
            'units' => ModuleManager::isEnabled('units'),
            'procedures' => ModuleManager::isEnabled('procedures'),
            'bots' => ModuleManager::isEnabled('bots'),
            'suppliers' => ModuleManager::isEnabled('suppliers'),
        ];
        foreach (['units', 'procedures'] as $id) {
            if (!$soft[$id]) {
                $issues[] = [
                    'level' => 'info',
                    'code' => 'soft_off_' . $id,
                    'message' => $id . ' disabled — related QR features degraded',
                ];
            }
        }
        $tables = [];
        foreach (['base_good_sticker_ins', 'base_good_sticker_outs', 'base_acts2_in', 'base_acts2_out'] as $t) {
            $tables[$t] = Database::tableExists($t);
            if (!$tables[$t]) {
                $issues[] = [
                    'level' => 'error',
                    'code' => 'missing_table',
                    'message' => $t . ' missing',
                ];
            }
        }
        $status = 'ok';
        foreach ($issues as $i) {
            if (($i['level'] ?? '') === 'error') {
                $status = 'error';
                break;
            }
            if (($i['level'] ?? '') === 'warn') {
                $status = 'warn';
            }
        }
        $publicPrint = StickerPrint::isPublicPrintEnabled();
        if ($publicPrint) {
            $issues[] = [
                'level' => 'warn',
                'code' => 'public_print_enabled',
                'message' => 'Anonymous GET /stickers/printstickers is ON — print sheet only, still exposes pending label data',
            ];
            if ($status === 'ok') {
                $status = 'warn';
            }
        }
        $pendingPrint = 0;
        if (!empty($tables['base_good_sticker_ins'])) {
            try {
                $pendingPrint = (int)Database::pdo()->query(
                    'SELECT COUNT(*) FROM base_good_sticker_ins
                     WHERE (DT_PRINT = 0 OR DT_REPRINT > 0) AND QTYU_IN > QTYU_OUT'
                )->fetchColumn();
            } catch (Throwable $e) {
                $pendingPrint = 0;
            }
        }
        return [
            'ok' => $status !== 'error',
            'status' => $status,
            'runtime' => AppRuntime::role(),
            'public_host' => $publicHost,
            'admin_host' => $adminHost,
            'session_cookie_domain' => $cookieDomain,
            'sample_qr_url' => StickerQr::absoluteScanUrl(str_repeat('a', 32)),
            'public_print_enabled' => $publicPrint,
            'public_print_url' => StickerPrint::publicPrintUrl(),
            'pending_print' => $pendingPrint,
            'soft' => $soft,
            'tables' => $tables,
            'issues' => $issues,
        ];
    }
}
