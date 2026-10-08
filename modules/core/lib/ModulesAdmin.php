<?php
declare(strict_types=1);

/**
 * Shared enable/disable/migrate POST handling for /modules and /modules/{id}.
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
