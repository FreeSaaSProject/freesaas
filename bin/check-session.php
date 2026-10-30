#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Session guard / auth_epoch regressions (needs DB).
 *
 * Uses a disposable temp user (never bumps live staff). Works on MyISAM
 * where transactions do not roll back DML.
 *
 * Exit 0 ok, 1 fail.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$fail = 0;
$check = static function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'OK  ' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

/**
 * Insert a minimal disposable staff row filling NOT NULL columns without defaults.
 *
 * @return int new id
 */
function check_session_insert_temp(PDO $pdo, string $login, string $passwordHash): int
{
    $cols = $pdo->query('SHOW COLUMNS FROM base_users')->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($cols) || $cols === []) {
        throw new RuntimeException('SHOW COLUMNS base_users failed');
    }
    $names = [];
    $placeholders = [];
    $params = [];
    foreach ($cols as $c) {
        if (!is_array($c)) {
            continue;
        }
        $field = (string)($c['Field'] ?? '');
        if ($field === '') {
            continue;
        }
        if (stripos((string)($c['Extra'] ?? ''), 'auto_increment') !== false) {
            continue;
        }
        $names[] = '`' . str_replace('`', '``', $field) . '`';
        if ($field === 'login') {
            $placeholders[] = '?';
            $params[] = $login;
        } elseif ($field === 'password') {
            $placeholders[] = '?';
            $params[] = $passwordHash;
        } elseif ($field === 'name') {
            $placeholders[] = '?';
            $params[] = 'check-session';
        } elseif ($field === 'enabled') {
            $placeholders[] = '?';
            $params[] = '1';
        } elseif ($field === 'auth_epoch') {
            $placeholders[] = '?';
            $params[] = 0;
        } elseif ($field === 'status') {
            $placeholders[] = '?';
            $params[] = serialize(['permissions' => []]);
        } elseif (($c['Null'] ?? '') === 'YES') {
            $placeholders[] = 'NULL';
        } elseif (array_key_exists('Default', $c) && $c['Default'] !== null) {
            $placeholders[] = '?';
            $params[] = $c['Default'];
        } else {
            $type = strtolower((string)($c['Type'] ?? ''));
            if (preg_match('/^(tiny|small|medium|big)?int|decimal|float|double|year/', $type)) {
                $placeholders[] = '?';
                $params[] = 0;
            } elseif (strpos($type, 'enum(') === 0 && preg_match("/enum\\('((?:\\\\'|[^'])*)'/", $type, $m)) {
                $placeholders[] = '?';
                $params[] = stripcslashes($m[1]);
            } elseif (strpos($type, 'datetime') !== false || $type === 'timestamp') {
                $placeholders[] = '?';
                $params[] = date('Y-m-d H:i:s');
            } elseif ($type === 'date') {
                $placeholders[] = '?';
                $params[] = '1970-01-01';
            } else {
                $placeholders[] = '?';
                $params[] = '';
            }
        }
    }
    $sql = 'INSERT INTO base_users (' . implode(',', $names) . ') VALUES (' . implode(',', $placeholders) . ')';
    $pdo->prepare($sql)->execute($params);

    return (int)$pdo->lastInsertId();
}

try {
    Database::pdo()->query('SELECT 1');
} catch (Throwable $e) {
    echo 'SKIP no database: ' . $e->getMessage() . PHP_EOL;
    exit(0);
}

if (!Database::tableExists('base_users')) {
    echo "SKIP base_users missing\n";
    exit(0);
}

$check('auth_epoch_column', Database::columnExists('base_users', 'auth_epoch'));

Auth::startSession();
$_SESSION = [];
$_SESSION['user_id'] = 0;
$check('check_empty', !Auth::check());

$pdo = Database::pdo();
$tempId = 0;
$login = '_check_session_' . bin2hex(random_bytes(4));

try {
    $tempId = check_session_insert_temp($pdo, $login, Auth::hashPassword(bin2hex(random_bytes(8))));
    $check('temp_user_created', $tempId > 0);

    $st = $pdo->prepare(
        'SELECT id, login'
        . (Database::columnExists('base_users', 'auth_epoch') ? ', auth_epoch' : '')
        . ' FROM base_users WHERE id = ? LIMIT 1'
    );
    $st->execute([$tempId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('temp user missing after insert');
    }

    $uid = (int)$row['id'];
    $epoch = (int)($row['auth_epoch'] ?? 0);

    $_SESSION['user_id'] = $uid;
    $_SESSION['login'] = (string)$row['login'];
    $_SESSION['user_name'] = 'check-session';
    $_SESSION['permissions'] = [];
    $_SESSION['auth_epoch'] = $epoch;
    $_SESSION['_seen_at'] = time();
    $_SESSION['_guard_at'] = 0;
    $_SESSION['_regen_at'] = time();
    Auth::setStatusContext(0, 0);

    $check('guard_ok', Auth::check(true));

    // CSRF must stay stable across periodic session-id regen and mid-POST Auth::can() → check(false).
    if (class_exists('Csrf', false)) {
        Csrf::rotate();
        $csrfBefore = Csrf::token();
        $_SESSION['_regen_at'] = time() - 2000;
        $_SESSION['_seen_at'] = time();
        $_SESSION['_guard_at'] = time();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $check('get_regen_keeps_session', Auth::check(false));
        $check('get_regen_csrf_stable', Csrf::token() === $csrfBefore && strlen($csrfBefore) === 64);
        $check('get_regen_at_advanced', (int)($_SESSION['_regen_at'] ?? 0) >= time() - 5);

        Csrf::rotate();
        $csrfPost = Csrf::token();
        $regenStale = time() - 2000;
        $_SESSION['_regen_at'] = $regenStale;
        $_SESSION['_seen_at'] = time();
        $_SESSION['_guard_at'] = time();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $check('post_force_guard', Auth::check(true));
        // Mimic Router: check(true) then Auth::can() → check(false) before Csrf::verify.
        Auth::can('core.nonexistent_perm_for_csrf_check');
        $check('post_can_csrf_stable', Csrf::token() === $csrfPost);
        $check('post_can_no_id_regen', (int)($_SESSION['_regen_at'] ?? 0) === $regenStale);
        $_POST['csrf'] = $csrfPost;
        $check('post_can_csrf_verify', Csrf::verify());
        unset($_POST['csrf'], $_SERVER['REQUEST_METHOD']);
        $_SESSION['_regen_at'] = time();
        $_SESSION['_seen_at'] = time();
        $_SESSION['_guard_at'] = time();
    } else {
        echo "SKIP csrf_regen (Csrf missing)\n";
    }

    // runAs before destructive logout paths (idle/epoch) so CLI session_start stays healthy.
    $_SESSION = [];
    $_SESSION['user_id'] = $uid;
    $_SESSION['auth_epoch'] = $epoch - 1;
    $_SESSION['_seen_at'] = time() - (8 * 86400);
    $_SESSION['_guard_at'] = 0;
    $runAsOk = false;
    $runAsCan = false;
    try {
        Auth::runAs($uid, static function () use (&$runAsOk, &$runAsCan): void {
            $runAsOk = Auth::check(true);
            // No personal perms / superadmin on temp user — can() may be false, but check must hold.
            $runAsCan = Auth::check(false);
        });
    } catch (Throwable $e) {
        echo 'FAIL runAs ' . $e->getMessage() . PHP_EOL;
        $fail++;
    }
    $check('runAs_check_holds', $runAsOk && $runAsCan);
    $check('runAs_restored_prior', (int)($_SESSION['user_id'] ?? 0) === $uid);

    if (Database::columnExists('base_users', 'auth_epoch')) {
        $before = $epoch;
        $after = Auth::bumpAuthEpoch($uid);
        $check('bump_increments', $after === $before + 1 || ($before === 0 && $after >= 1));
        $epoch = $after;
    } else {
        echo "SKIP bump_increments (no auth_epoch)\n";
    }

    $_SESSION['user_id'] = $uid;
    $_SESSION['auth_epoch'] = $epoch - 1;
    $_SESSION['_guard_at'] = 0;
    $_SESSION['_seen_at'] = time();
    $check('stale_epoch_logs_out', !Auth::check(true));
    $check('session_cleared', empty($_SESSION['user_id']));

    // Re-hydrate after logout destroyed the PHP session (in-request $_SESSION write is enough for idle).
    // Remember-me must skip idle (cookie lifetime is the long bound).
    // Run before idle_timeout: logout() destroys the PHP session.
    $_SESSION['user_id'] = $uid;
    $_SESSION['auth_epoch'] = $epoch;
    $_SESSION['remember'] = 1;
    $_SESSION['_seen_at'] = time() - (8 * 86400);
    $_SESSION['_guard_at'] = time();
    $check('remember_skips_idle', Auth::check(false));

    $_SESSION['user_id'] = $uid;
    $_SESSION['auth_epoch'] = $epoch;
    $_SESSION['remember'] = 0;
    $_SESSION['_seen_at'] = time() - (8 * 86400);
    $_SESSION['_guard_at'] = time();
    $check('idle_timeout', !Auth::check(false));
} catch (Throwable $e) {
    echo 'FAIL temp_user_setup ' . $e->getMessage() . PHP_EOL;
    $fail++;
} finally {
    if ($tempId > 0) {
        try {
            $pdo->prepare('DELETE FROM base_users WHERE id = ?')->execute([$tempId]);
        } catch (Throwable $e) {
            echo 'FAIL temp_user_cleanup ' . $e->getMessage() . PHP_EOL;
            $fail++;
        }
    }
    $_SESSION = [];
}

$ref = new ReflectionClass('Auth');
$m = $ref->getMethod('dummyPasswordHash');
$m->setAccessible(true);
$dummy = (string)$m->invoke(null);
$algo = Auth::preferredAlgo();
$expectArgon = defined('PASSWORD_ARGON2ID') && $algo === PASSWORD_ARGON2ID;
$check(
    'dummy_matches_preferred_algo',
    $expectArgon
        ? strpos($dummy, '$argon2') === 0
        : (strpos($dummy, '$2y$') === 0 || strpos($dummy, '$2a$') === 0)
);

exit($fail > 0 ? 1 : 0);
