<?php
declare(strict_types=1);

$schemaReport = [];
$dataReport = [];
$maintReport = [];
$message = '';
$error = '';
$wantsJson = AgentHttp::wantsJson();
$uid = Auth::userId();
$canDb = Auth::check() && Auth::can('core.db');
$mode = ($uid > 0) ? DbTools::modeForUser($uid) : DbTools::MODE_NORMAL;
$isExpert = $mode === DbTools::MODE_EXPERT;

$ownedTables = [];
$allTables = [];
$foreignTables = [];
try {
    $ownedTables = DbTools::ownedTables();
    if ($canDb) {
        $allTables = DbTools::allTables();
        if ($isExpert) {
            $foreignTables = DbTools::foreignTables();
        }
    }
} catch (Throwable $e) {
    // DB may be mid-setup
}

/**
 * @param list<string>|null $posted
 * @return list<string>
 */
$resolveTables = static function (?array $posted) use ($isExpert, $ownedTables, $allTables): array {
    if (!$isExpert) {
        return $ownedTables;
    }
    if ($posted === null || $posted === []) {
        return $ownedTables;
    }
    return DbTools::intersectTables($posted, $allTables !== [] ? $allTables : DbTools::allTables());
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

    // post_max_size exceeded: PHP empties $_POST/$_FILES but Content-Type stays multipart.
    if ($action === ''
        && empty($_POST)
        && empty($_FILES)
        && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false
    ) {
        $error = t('core.db.err.upload_too_large', ['limit' => DbTools::phpUploadLimitLabel()]);
        if ($wantsJson) {
            AgentHttp::error($error, 400);
        }
        $action = '__upload_blocked__';
    }

    if ($action === 'mode') {
        if (!$canDb) {
            $error = t('core.db.err.forbidden');
            if ($wantsJson) {
                AgentHttp::error($error, 403);
            }
        } else {
            $newMode = (string)($_POST['mode'] ?? '') === DbTools::MODE_EXPERT
                ? DbTools::MODE_EXPERT
                : DbTools::MODE_NORMAL;
            DbTools::setModeForUser($uid, $newMode);
            $mode = $newMode;
            $isExpert = $mode === DbTools::MODE_EXPERT;
            unset($_SESSION['freesaas_db_diag']);
            $message = $isExpert ? t('core.db.msg.mode_expert') : t('core.db.msg.mode_normal');
            if ($isExpert) {
                $allTables = DbTools::allTables();
                $foreignTables = DbTools::foreignTables();
            }
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'action' => 'mode', 'mode' => $mode, 'message' => $message]);
            }
        }
    } elseif ($action === 'schema') {
        $schemaReport = SchemaMigrator::runAll();
        try {
            ModuleManager::syncRegistry();
        } catch (Throwable $e) {
            // ignore — registry optional after schema
        }
        $applied = 0;
        $errors = 0;
        foreach ($schemaReport as $row) {
            if (($row['status'] ?? '') === 'ok') {
                $applied++;
            }
            if (($row['status'] ?? '') === 'error') {
                $errors++;
                if ($error === '') {
                    $error = t('core.modules.err.migration', [
                        'module' => (string)$row['module'],
                        'id' => (string)$row['id'],
                        'message' => (string)($row['message'] ?? 'error'),
                    ]);
                }
            }
        }
        if ($errors === 0) {
            $message = $applied > 0
                ? t('core.db.msg.schema_applied', ['n' => $applied])
                : t('core.db.msg.schema_none');
        }
        if ($wantsJson) {
            $setup = SchemaMigrator::setupStatus();
            AgentHttp::emit([
                'ok' => $errors === 0,
                'action' => 'schema',
                'applied' => $applied,
                'errors' => $errors,
                'message' => $error !== '' ? $error : $message,
                'report' => $schemaReport,
                'setup' => $setup,
                'pending' => SchemaMigrator::pendingList(),
            ], $errors === 0 ? 200 : 500);
        }
    } elseif ($action === 'data') {
        if (!Database::tableExists('base_users')) {
            $error = t('core.db.msg.schema_first');
            if ($wantsJson) {
                AgentHttp::error($error, 400);
            }
        } else {
            $dataReport = DataMigrator::runAll(true);
            $message = t('core.db.msg.data_done');
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'data',
                    'message' => $message,
                    'report' => $dataReport,
                ]);
            }
        }
    } elseif (in_array($action, ['db_job_start', 'db_job_tick', 'db_job_cancel'], true)) {
        if (!$canDb) {
            AgentHttp::error(t('core.db.err.forbidden'), 403);
        }
        try {
            if ($action === 'db_job_cancel') {
                $jobId = (string)($_POST['job_id'] ?? '');
                $job = DbTools::jobCancel($jobId);
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'db_job_cancel',
                    'job' => DbTools::jobPublic($job),
                ]);
            }
            if ($action === 'db_job_start') {
                $op = strtolower(trim((string)($_POST['op'] ?? '')));
                if ($op === 'dump') {
                    $posted = isset($_POST['tables']) && is_array($_POST['tables']) ? $_POST['tables'] : null;
                    $tables = $resolveTables($posted);
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    $job = DbTools::jobStart('dump', ['tables' => $tables]);
                } elseif ($op === 'restore') {
                    $file = (string)($_POST['file'] ?? '');
                    $allowed = $isExpert
                        ? array_values(array_unique(array_merge(DbTools::declaredTables(), DbTools::allTables())))
                        : array_values(array_unique(array_merge(DbTools::declaredTables(), $ownedTables)));
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    $job = DbTools::jobStart('restore', ['file' => $file, 'allowed' => $allowed]);
                } elseif ($op === 'fetch') {
                    $url = trim((string)($_POST['url'] ?? ''));
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    $job = DbTools::jobStart('fetch', ['url' => $url]);
                } elseif ($op === 'diagnose') {
                    // All tables (owned + foreign): feeds maintenance hints and inventory table.
                    $scope = $allTables !== [] ? $allTables : DbTools::allTables();
                    if ($scope === []) {
                        $scope = $ownedTables;
                    }
                    $job = DbTools::jobStart('diagnose', [
                        'tables' => $scope,
                        'owned' => $ownedTables,
                    ]);
                } elseif (in_array($op, ['optimize', 'repair', 'check'], true)) {
                    $posted = isset($_POST['tables']) && is_array($_POST['tables']) ? $_POST['tables'] : null;
                    $tables = $resolveTables($posted);
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    $job = DbTools::jobStart($op, ['tables' => $tables]);
                } else {
                    AgentHttp::error('Unknown job op', 400);
                }
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'db_job_start',
                    'job' => DbTools::jobPublic($job),
                ]);
            }
            $jobId = (string)($_POST['job_id'] ?? '');
            $peekOp = '';
            try {
                $peekOp = (string)(DbTools::jobGet($jobId)['op'] ?? '');
            } catch (Throwable $e) {
                AgentHttp::error($e->getMessage(), 400);
            }
            if ($peekOp !== 'diagnose' && session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $job = DbTools::jobTick($jobId);
            if (($job['op'] ?? '') === 'diagnose' && ($job['status'] ?? '') === 'done') {
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    @session_start();
                }
                $_SESSION['freesaas_db_diag'] = [
                    'at' => time(),
                    'mode' => $mode,
                    'rows' => $job['result']['diag'] ?? [],
                ];
            }
            AgentHttp::emit([
                'ok' => true,
                'action' => 'db_job_tick',
                'job' => DbTools::jobPublic($job),
                'error' => (($job['status'] ?? '') === 'error')
                    ? (string)($job['error'] ?? $job['message'] ?? 'Job failed')
                    : null,
            ], 200);
        } catch (Throwable $e) {
            AgentHttp::error($e->getMessage(), 400);
        }
    } elseif (in_array($action, ['db_dump', 'db_restore', 'db_delete_dump', 'db_upload', 'db_check', 'db_repair', 'db_optimize', 'db_drop'], true)) {
        if (!$canDb) {
            $error = t('core.db.err.forbidden');
            if ($wantsJson) {
                AgentHttp::error($error, 403);
            }
        } elseif ($action === 'db_drop' && $wantsJson) {
            AgentHttp::error(t('core.db.err.drop_ui_only'), 403);
        } else {
            try {
                if ($action === 'db_upload') {
                    $upload = $_FILES['dump'] ?? null;
                    if (!is_array($upload)) {
                        throw new InvalidArgumentException(t('core.db.err.upload_missing'));
                    }
                    $result = DbTools::saveUploadedDump($upload);
                    $message = t('core.db.msg.upload_ok', ['file' => $result['file']]);
                    if ($wantsJson) {
                        AgentHttp::emit(['ok' => true, 'action' => 'db_upload', 'dump' => $result, 'message' => $message]);
                    }
                } elseif ($action === 'db_dump') {
                    $posted = isset($_POST['tables']) && is_array($_POST['tables']) ? $_POST['tables'] : null;
                    $tables = $resolveTables($posted);
                    $result = DbTools::dump($tables, null);
                    $message = t('core.db.msg.dump_ok', ['file' => $result['file']]);
                    if ($wantsJson) {
                        AgentHttp::emit(['ok' => true, 'action' => 'db_dump', 'dump' => $result, 'message' => $message]);
                    }
                } elseif ($action === 'db_restore') {
                    $file = (string)($_POST['file'] ?? '');
                    $allowed = $isExpert
                        ? array_values(array_unique(array_merge(DbTools::declaredTables(), DbTools::allTables())))
                        : array_values(array_unique(array_merge(DbTools::declaredTables(), $ownedTables)));
                    $result = DbTools::restore($file, $allowed);
                    $message = t('core.db.msg.restore_ok', ['n' => (string)$result['statements']]);
                    $ownedTables = DbTools::ownedTables();
                    if ($isExpert) {
                        $allTables = DbTools::allTables();
                        $foreignTables = DbTools::foreignTables();
                    }
                    if ($wantsJson) {
                        AgentHttp::emit(['ok' => true, 'action' => 'db_restore', 'result' => $result, 'message' => $message]);
                    }
                } elseif ($action === 'db_delete_dump') {
                    $file = (string)($_POST['file'] ?? '');
                    DbTools::deleteDump($file);
                    $message = t('core.db.msg.dump_deleted', ['file' => basename($file)]);
                    if ($wantsJson) {
                        AgentHttp::emit(['ok' => true, 'action' => 'db_delete_dump', 'file' => basename($file), 'message' => $message]);
                    }
                } elseif ($action === 'db_check' || $action === 'db_repair' || $action === 'db_optimize') {
                    $posted = isset($_POST['tables']) && is_array($_POST['tables']) ? $_POST['tables'] : null;
                    $tables = $resolveTables($posted);
                    if ($action === 'db_check') {
                        $maintReport = DbTools::check($tables);
                    } elseif ($action === 'db_repair') {
                        $maintReport = DbTools::repair($tables);
                    } else {
                        $maintReport = DbTools::optimize($tables);
                    }
                    $message = t('core.db.msg.maint_done', ['op' => strtoupper(substr($action, 3))]);
                    if ($wantsJson) {
                        AgentHttp::emit([
                            'ok' => true,
                            'action' => $action,
                            'report' => $maintReport,
                            'message' => $message,
                        ]);
                    }
                } elseif ($action === 'db_drop') {
                    if (!$isExpert) {
                        throw new RuntimeException(t('core.db.err.expert_required'));
                    }
                    $posted = isset($_POST['tables']) && is_array($_POST['tables']) ? $_POST['tables'] : [];
                    $tables = DbTools::intersectTables($posted, DbTools::allTables());
                    if ($tables === []) {
                        throw new InvalidArgumentException(t('core.db.err.no_tables'));
                    }
                    $ownedSet = array_fill_keys($ownedTables, true);
                    $hasOwned = false;
                    foreach ($tables as $t) {
                        if (isset($ownedSet[$t])) {
                            $hasOwned = true;
                            break;
                        }
                    }
                    if ($hasOwned && empty($_POST['confirm_drop_owned'])) {
                        throw new RuntimeException(t('core.db.err.confirm_owned'));
                    }
                    $result = DbTools::dropTables($tables);
                    $message = t('core.db.msg.dropped', ['n' => (string)count($result['dropped'])]);
                    $ownedTables = DbTools::ownedTables();
                    $allTables = DbTools::allTables();
                    $foreignTables = DbTools::foreignTables();
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
                if ($wantsJson) {
                    AgentHttp::error($error, 400);
                }
            }
        }
    } elseif ($wantsJson) {
        AgentHttp::error('Unknown action', 400);
    }
}

// Download dump (GET)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && (string)($_GET['action'] ?? '') === 'download'
) {
    if (!$canDb) {
        http_response_code(403);
        echo t('core.db.err.forbidden');
        return;
    }
    try {
        $path = DbTools::resolveDumpPath((string)($_GET['file'] ?? ''));
        $name = basename($path);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . (string)filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        return;
    } catch (Throwable $e) {
        http_response_code(404);
        echo $e->getMessage();
        return;
    }
}

$pending = SchemaMigrator::pendingList();
$setup = SchemaMigrator::setupStatus();
$hasUsers = Database::tableExists('base_users');
$hasSystem = Database::tableExists('base_system');
$userCount = $hasUsers
    ? (int)Database::pdo()->query('SELECT COUNT(*) FROM base_users')->fetchColumn()
    : 0;
$dumps = [];
try {
    if ($canDb || $wantsJson) {
        $dumps = DbTools::listDumps();
    }
} catch (Throwable $e) {
    // ignore
}

// Diagnostics are expensive (CHECK TABLE) — never on plain GET.
$diag = [];
$diagNeedCount = 0;
$diagRan = false;
$diagAt = 0;
if ($canDb && !empty($_SESSION['freesaas_db_diag']) && is_array($_SESSION['freesaas_db_diag'])) {
    $cached = $_SESSION['freesaas_db_diag'];
    if (($cached['mode'] ?? '') === $mode && is_array($cached['rows'] ?? null)) {
        $diag = $cached['rows'];
        $diagRan = true;
        $diagAt = (int)($cached['at'] ?? 0);
        foreach ($diag as $row) {
            if (!empty($row['needs_repair']) || !empty($row['needs_optimize'])) {
                $diagNeedCount++;
            }
        }
    }
}

// TEMP: inventory on GET via SHOW TABLE STATUS only (no CHECK) — timing check.
$inventory = [];
if ($canDb) {
    try {
        $inventory = DbTools::tableInventory();
    } catch (Throwable $e) {
        // ignore
    }
}

if ($wantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    AgentHttp::emit([
        'ok' => true,
        'mode' => $mode,
        'setup' => $setup,
        'pending' => $pending,
        'pending_count' => (int)($setup['pending'] ?? count($pending)),
        'schema_needed' => !empty($setup['needed']),
        'tables' => [
            'base_users' => $hasUsers,
            'base_system' => $hasSystem,
            'base_users_count' => $userCount,
            'owned' => $ownedTables,
            'owned_count' => count($ownedTables),
        ],
        'dumps' => $dumps,
        'inventory' => $inventory,
        'diagnose_ran' => $diagRan,
        'diagnose_at' => $diagAt,
        'diagnose_need' => $diagNeedCount,
        'ops' => [
            ['method' => 'POST', 'path' => '/db', 'body' => ['action' => 'schema'], 'description' => 'Apply all pending schema migrations'],
            ['method' => 'POST', 'path' => '/db', 'body' => ['action' => 'data'], 'description' => 'Run data migrators (replace; optional)'],
            ['method' => 'POST', 'path' => '/db', 'body' => ['action' => 'db_dump'], 'description' => 'Dump owned tables (session + core.db)'],
            ['method' => 'POST', 'path' => '/db', 'body' => ['action' => 'db_upload'], 'description' => 'Upload .sql/.sql.gz (multipart field dump; does not restore)'],
            ['method' => 'POST', 'path' => '/db', 'body' => ['action' => 'db_restore', 'file' => '…'], 'description' => 'Restore dump (owned allow-list in normal mode)'],
            ['method' => 'GET', 'path' => '/agent/db', 'auth' => 'agent_token', 'description' => 'Platform DB status'],
            ['method' => 'GET', 'path' => '/agent/db/migrate', 'auth' => 'agent_token', 'description' => 'Schema migrate status'],
            ['method' => 'POST', 'path' => '/agent/db/migrate/run', 'auth' => 'agent_token', 'description' => 'Schema migrate run'],
        ],
    ]);
}

$agentTokenOk = AgentAuth::isConfigured();

View::render('db', [
    'title' => t('core.db.title'),
    'message' => $message,
    'error' => $error,
    'schemaReport' => $schemaReport,
    'dataReport' => $dataReport,
    'maintReport' => $maintReport,
    'pending' => $pending,
    'pendingCount' => (int)($setup['pending'] ?? count($pending)),
    'schemaNeeded' => !empty($setup['needed']),
    'hasUsers' => $hasUsers,
    'hasSystem' => $hasSystem,
    'userCount' => $userCount,
    'canDb' => $canDb,
    'mode' => $mode,
    'isExpert' => $isExpert,
    'ownedTables' => $ownedTables,
    'allTables' => $allTables,
    'foreignTables' => $foreignTables,
    'dumps' => $dumps,
    'inventory' => $inventory,
    'agentTokenOk' => $agentTokenOk,
    'diag' => $diag,
    'diagNeedCount' => $diagNeedCount,
    'diagRan' => $diagRan,
    'diagAt' => $diagAt,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.db.title'), 'current' => true],
    ]),
], 'layout', 'core');
