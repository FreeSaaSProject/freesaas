<?php
declare(strict_types=1);

/**
 * Shared enable/disable/migrate/install POST handling for /modules and /modules/{id}.
 */
final class ModulesAdmin
{
    /**
     * @return array{redirect:string}|null null if not a POST action request
     */
    public static function handlePost(string $fallbackPath): ?array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return null;
        }
        $action = (string)($_POST['action'] ?? '');
        $moduleId = trim((string)($_POST['module'] ?? ''));
        $ret = trim((string)($_POST['ret'] ?? $fallbackPath));
        if ($ret === '' || $ret[0] !== '/') {
            $ret = $fallbackPath;
        }
        // Open-redirect guard: only same-site module admin paths
        if (strpos($ret, '/modules') !== 0) {
            $ret = $fallbackPath;
        }

        try {
            if ($action === 'refresh_catalog') {
                if (!Auth::capability('SUPERADMIN_ACCESS')) {
                    throw new RuntimeException(t('core.modules.err.superadmin'));
                }
                $cat = UpdateEngine::fetchRemoteCatalog(true);
                if (empty($cat['ok'])) {
                    throw new RuntimeException((string)($cat['error'] ?? t('core.modules.err.catalog')));
                }
                $n = count(UpdateEngine::availableToInstall(false));
                return ['redirect' => self::withQuery($ret, 'msg', t('core.modules.msg.catalog_refreshed', ['n' => $n]))];
            }

            if ($action === 'install') {
                if (!Auth::capability('SUPERADMIN_ACCESS')) {
                    throw new RuntimeException(t('core.modules.err.superadmin'));
                }
                if ($moduleId === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $moduleId)) {
                    throw new RuntimeException(t('core.modules.err.no_module'));
                }
                $report = UpdateEngine::installModule($moduleId);
                if (empty($report['ok'])) {
                    throw new RuntimeException((string)($report['error'] ?? t('core.modules.err.install')));
                }
                return ['redirect' => self::withQuery($ret, 'msg', t('core.modules.msg.installed', [
                    'module' => $moduleId,
                    'version' => (string)($report['version'] ?? ''),
                ]))];
            }

            if ($action === 'dismiss_available' || $action === 'restore_available') {
                if (!Auth::capability('SUPERADMIN_ACCESS')) {
                    throw new RuntimeException(t('core.modules.err.superadmin'));
                }
                if ($moduleId === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $moduleId)) {
                    throw new RuntimeException(t('core.modules.err.no_module'));
                }
                $dismiss = $action === 'dismiss_available';
                $report = AgentOps::moduleDismiss($moduleId, $dismiss);
                if (empty($report['ok'])) {
                    throw new RuntimeException((string)($report['error'] ?? t('core.modules.err.no_module')));
                }
                return ['redirect' => self::withQuery($ret, 'msg', t(
                    $dismiss ? 'core.modules.msg.dismissed' : 'core.modules.msg.restored',
                    ['module' => $moduleId]
                ))];
            }

            if ($action === 'uninstall') {
                if (!Auth::capability('SUPERADMIN_ACCESS')) {
                    throw new RuntimeException(t('core.modules.err.superadmin'));
                }
                if ($moduleId === '' || ModuleManager::get($moduleId) === null) {
                    throw new RuntimeException(t('core.modules.err.no_module'));
                }
                $confirm = !empty($_POST['confirm_uninstall']);
                $report = ModuleManager::uninstall($moduleId, $confirm);
                $dumpFile = '';
                if (is_array($report['dump'] ?? null) && !empty($report['dump']['file'])) {
                    $dumpFile = (string)$report['dump']['file'];
                }
                $msg = $dumpFile !== ''
                    ? t('core.modules.msg.uninstalled_dump', [
                        'module' => $moduleId,
                        'file' => $dumpFile,
                        'n' => count($report['dropped'] ?? []),
                    ])
                    : t('core.modules.msg.uninstalled', [
                        'module' => $moduleId,
                        'n' => count($report['dropped'] ?? []),
                    ]);
                return ['redirect' => self::withQuery($ret, 'msg', $msg)];
            }

            if ($moduleId === '' || ModuleManager::get($moduleId) === null) {
                throw new RuntimeException(t('core.modules.err.no_module'));
            }
            if ($action === 'enable') {
                $maturity = ModuleCatalog::maturityOf($moduleId);
                if (ModuleCatalog::requiresRiskAck($maturity) && empty($_POST['accept_risk'])) {
                    throw new RuntimeException(t('core.modules.err.risk_required', [
                        'stage' => t('core.modules.maturity.' . $maturity),
                    ]));
                }
                ModuleManager::setEnabled($moduleId, true);
                return ['redirect' => self::withQuery($ret, 'msg', t('core.modules.msg.enabled', ['module' => $moduleId]))];
            }
            if ($action === 'disable') {
                ModuleManager::setEnabled($moduleId, false);
                return ['redirect' => self::withQuery($ret, 'msg', t('core.modules.msg.disabled', ['module' => $moduleId]))];
            }
            if ($action === 'migrate') {
                $schemaReport = SchemaMigrator::runModule($moduleId);
                foreach ($schemaReport as $row) {
                    if (($row['status'] ?? '') === 'error') {
                        throw new RuntimeException(
                            t('core.modules.err.migration', [
                                'module' => (string)$row['module'],
                                'id' => (string)$row['id'],
                                'message' => (string)($row['message'] ?? 'error'),
                            ])
                        );
                    }
                }
                $applied = 0;
                foreach ($schemaReport as $row) {
                    if (($row['status'] ?? '') === 'ok') {
                        $applied++;
                    }
                }
                $msg = $applied > 0
                    ? t('core.modules.msg.migrated', ['module' => $moduleId, 'n' => $applied])
                    : t('core.modules.msg.no_migrations', ['module' => $moduleId]);
                ModuleManager::syncRegistry();
                return ['redirect' => self::withQuery($ret, 'msg', $msg)];
            }
            throw new RuntimeException(t('core.modules.err.unknown_action'));
        } catch (Throwable $e) {
            return ['redirect' => self::withQuery($ret, 'err', $e->getMessage())];
        }
    }

    private static function withQuery(string $path, string $key, string $value): string
    {
        $parts = parse_url($path);
        $base = (string)($parts['path'] ?? '/modules');
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }
        unset($query['msg'], $query['err']);
        $query[$key] = $value;
        return $base . '?' . http_build_query($query);
    }
}
