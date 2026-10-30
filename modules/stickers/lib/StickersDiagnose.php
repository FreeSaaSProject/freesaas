<?php
declare(strict_types=1);

/** Agent diagnose for stickers / QR warehouse surface. */
final class StickersDiagnose
{
    /** @return array<string,mixed> */
    public static function report(): array
    {
        $issues = [];
        $publicHost = self::normalizeHost(AppRuntime::publicHost());
        $adminHost = self::normalizeHost(AppRuntime::adminHost());
        $cookieDomain = self::normalizeCookieDomain(
            (string)Config::get('app', 'session_cookie_domain', '')
        );
        $suggested = self::suggestCookieDomain($adminHost, $publicHost);
        $hostsSplit = $adminHost !== '' && $publicHost !== ''
            && strcasecmp($adminHost, $publicHost) !== 0;
        $cookieCovers = $cookieDomain !== ''
            && self::cookieDomainCovers($cookieDomain, $adminHost)
            && self::cookieDomainCovers($cookieDomain, $publicHost);

        if ($publicHost === '') {
            $issues[] = [
                'level' => 'warn',
                'code' => 'no_public_host',
                'message' => t('stickers.status.issue.no_public_host'),
                'fix' => t('stickers.status.issue.no_public_host_fix'),
            ];
        }
        if ($hostsSplit && $cookieDomain === '') {
            $issues[] = [
                'level' => 'warn',
                'code' => 'split_host_no_cookie_domain',
                'message' => t('stickers.status.issue.split_host'),
                'fix' => t('stickers.status.issue.split_host_fix', [
                    'admin' => $adminHost,
                    'public' => $publicHost,
                    'suggest' => $suggested !== '' ? $suggested : '.example.com',
                    'settings' => '/stickers/settings',
                ]),
            ];
        } elseif ($hostsSplit && $cookieDomain !== '' && !$cookieCovers) {
            $issues[] = [
                'level' => 'warn',
                'code' => 'cookie_domain_mismatch',
                'message' => t('stickers.status.issue.cookie_mismatch'),
                'fix' => t('stickers.status.issue.cookie_mismatch_fix', [
                    'domain' => $cookieDomain,
                    'admin' => $adminHost,
                    'public' => $publicHost,
                    'suggest' => $suggested !== '' ? $suggested : $cookieDomain,
                    'settings' => '/stickers/settings',
                ]),
            ];
        }
        if (!is_file(__DIR__ . '/phpqrcode/phpqrcode.php')) {
            $issues[] = [
                'level' => 'error',
                'code' => 'phpqrcode_missing',
                'message' => t('stickers.status.issue.phpqrcode'),
                'fix' => t('stickers.status.issue.phpqrcode_fix'),
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
                    'message' => t('stickers.status.issue.soft_off', ['module' => $id]),
                    'fix' => t('stickers.status.issue.soft_off_fix', ['module' => $id]),
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
                    'message' => t('stickers.status.issue.missing_table', ['table' => $t]),
                    'fix' => t('stickers.status.issue.missing_table_fix', ['table' => $t]),
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
                'message' => t('stickers.status.issue.public_print'),
                'fix' => t('stickers.status.issue.public_print_fix', [
                    'settings' => '/stickers/settings',
                ]),
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

        $sessionShareOk = !$hostsSplit || ($cookieDomain !== '' && $cookieCovers);
        $tablesOk = !in_array(false, $tables, true);
        $setup = [
            [
                'id' => 'public_host',
                'ok' => $publicHost !== '',
                'title' => t('stickers.status.setup.public_host'),
                'detail' => $publicHost !== ''
                    ? t('stickers.status.setup.public_host_ok', ['host' => $publicHost])
                    : t('stickers.status.setup.public_host_need'),
            ],
            [
                'id' => 'session_share',
                'ok' => $sessionShareOk,
                'title' => t('stickers.status.setup.session_share'),
                'detail' => self::sessionShareDetail(
                    $hostsSplit,
                    $adminHost,
                    $publicHost,
                    $cookieDomain,
                    $suggested,
                    $cookieCovers
                ),
                'href' => $sessionShareOk ? '' : '/stickers/settings',
            ],
            [
                'id' => 'tables',
                'ok' => $tablesOk,
                'title' => t('stickers.status.setup.tables'),
                'detail' => $tablesOk
                    ? t('stickers.status.setup.tables_ok')
                    : t('stickers.status.setup.tables_need'),
            ],
            [
                'id' => 'phpqrcode',
                'ok' => is_file(__DIR__ . '/phpqrcode/phpqrcode.php'),
                'title' => t('stickers.status.setup.phpqrcode'),
                'detail' => is_file(__DIR__ . '/phpqrcode/phpqrcode.php')
                    ? t('stickers.status.setup.phpqrcode_ok')
                    : t('stickers.status.setup.phpqrcode_need'),
            ],
            [
                'id' => 'staff_good_use',
                'ok' => null,
                'title' => t('stickers.status.setup.good_use'),
                'detail' => t('stickers.status.setup.good_use_detail'),
            ],
            [
                'id' => 'staff_stock_revision',
                'ok' => null,
                'title' => t('stickers.status.setup.stock_revision'),
                'detail' => t('stickers.status.setup.stock_revision_detail'),
            ],
            [
                'id' => 'relogin',
                'ok' => null,
                'title' => t('stickers.status.setup.relogin'),
                'detail' => t('stickers.status.setup.relogin_detail', [
                    'admin' => $adminHost !== '' ? $adminHost : 'admin_host',
                    'public' => $publicHost !== '' ? $publicHost : 'public_host',
                ]),
            ],
        ];

        return [
            'ok' => $status !== 'error',
            'status' => $status,
            'runtime' => AppRuntime::role(),
            'public_host' => $publicHost,
            'admin_host' => $adminHost,
            'hosts_split' => $hostsSplit,
            'session_cookie_domain' => $cookieDomain,
            'session_cookie_domain_suggested' => $suggested,
            'session_share_ok' => $sessionShareOk,
            'sample_qr_url' => StickerQr::absoluteScanUrl(str_repeat('a', 32)),
            'public_print_enabled' => $publicPrint,
            'public_print_url' => StickerPrint::publicPrintUrl(),
            'pending_print' => $pendingPrint,
            'settings_url' => '/stickers/settings',
            'soft' => $soft,
            'tables' => $tables,
            'setup' => $setup,
            'issues' => $issues,
        ];
    }

    public static function normalizeCookieDomain(string $raw): string
    {
        return Auth::normalizeSessionCookieDomain($raw);
    }

    /**
     * Empty string clears the setting. Non-empty must be a host/domain (optional leading dot).
     *
     * @throws InvalidArgumentException
     */
    public static function validateCookieDomain(string $raw): string
    {
        try {
            return Auth::validateSessionCookieDomain($raw);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === t('core.settings.session.err.cookie_domain_parent')) {
                throw new InvalidArgumentException(t('stickers.settings.err.cookie_domain_parent'));
            }
            throw new InvalidArgumentException(t('stickers.settings.err.cookie_domain'));
        }
    }

    public static function suggestCookieDomain(string $adminHost, string $publicHost): string
    {
        return Auth::suggestSessionCookieDomain($adminHost, $publicHost);
    }

    public static function cookieDomainCovers(string $cookieDomain, string $host): bool
    {
        $cookieDomain = self::normalizeCookieDomain($cookieDomain);
        $host = self::normalizeHost($host);
        if ($cookieDomain === '' || $host === '') {
            return false;
        }
        $bare = ltrim($cookieDomain, '.');
        if (strcasecmp($host, $bare) === 0) {
            return true;
        }
        $suffix = '.' . $bare;
        $hlen = strlen($host);
        $slen = strlen($suffix);
        return $hlen > $slen && strcasecmp(substr($host, -$slen), $suffix) === 0;
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }
        if (strpos($host, '://') !== false) {
            $parsed = (string)(parse_url($host, PHP_URL_HOST) ?: '');
            if ($parsed !== '') {
                $host = $parsed;
            }
        }
        if (strpos($host, '/') !== false) {
            $host = explode('/', $host, 2)[0];
        }
        if (strpos($host, ':') !== false && preg_match('/:\d+$/', $host)) {
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        }
        return $host;
    }

    private static function sessionShareDetail(
        bool $hostsSplit,
        string $adminHost,
        string $publicHost,
        string $cookieDomain,
        string $suggested,
        bool $cookieCovers
    ): string {
        if (!$hostsSplit) {
            return t('stickers.status.setup.session_share_same', [
                'host' => $publicHost !== '' ? $publicHost : $adminHost,
            ]);
        }
        if ($cookieDomain === '') {
            return t('stickers.status.setup.session_share_need', [
                'admin' => $adminHost,
                'public' => $publicHost,
                'suggest' => $suggested !== '' ? $suggested : '.example.com',
            ]);
        }
        if (!$cookieCovers) {
            return t('stickers.status.setup.session_share_bad', [
                'domain' => $cookieDomain,
                'admin' => $adminHost,
                'public' => $publicHost,
                'suggest' => $suggested !== '' ? $suggested : $cookieDomain,
            ]);
        }
        return t('stickers.status.setup.session_share_ok', [
            'domain' => $cookieDomain,
            'admin' => $adminHost,
            'public' => $publicHost,
        ]);
    }
}
