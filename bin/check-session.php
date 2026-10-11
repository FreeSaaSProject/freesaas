#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Session guard / auth_epoch regressions (needs DB).
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

try {
    Database::pdo()->query('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP no database: " . $e->getMessage() . PHP_EOL;
    exit(0);
}

$check('auth_epoch_column', Database::columnExists('base_users', 'auth_epoch'));

Auth::startSession();
$_SESSION = [];
$_SESSION['user_id'] = 0;
$check('check_empty', !Auth::check());

// Pick an enabled user for synthetic session tests.
$st = Database::pdo()->query(
    "SELECT id, login, auth_epoch, enabled FROM base_users WHERE enabled = '1' ORDER BY id ASC LIMIT 1"
);
$row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
if (!is_array($row)) {
    echo "SKIP no enabled users\n";
    exit($fail > 0 ? 1 : 0);
}

$uid = (int)$row['id'];
$epoch = (int)($row['auth_epoch'] ?? 0);

$_SESSION['user_id'] = $uid;
$_SESSION['login'] = (string)$row['login'];
$_SESSION['user_name'] = 'test';
$_SESSION['permissions'] = [];
$_SESSION['auth_epoch'] = $epoch;
$_SESSION['_seen_at'] = time();
$_SESSION['_guard_at'] = 0;
$_SESSION['_regen_at'] = time();
Auth::setStatusContext(0, 0);

$check('guard_ok', Auth::check(true));

// Stale epoch → logout
$_SESSION['user_id'] = $uid;
$_SESSION['auth_epoch'] = $epoch - 1;
$_SESSION['_guard_at'] = 0;
$_SESSION['_seen_at'] = time();
$check('stale_epoch_logs_out', !Auth::check(true));
$check('session_cleared', empty($_SESSION['user_id']));

// Idle timeout
$_SESSION['user_id'] = $uid;
$_SESSION['auth_epoch'] = $epoch;
$_SESSION['_seen_at'] = time() - (8 * 86400);
$_SESSION['_guard_at'] = time();
$check('idle_timeout', !Auth::check(false));

// bumpAuthEpoch increments
$before = $epoch;
$after = Auth::bumpAuthEpoch($uid);
$check('bump_increments', $after === $before + 1 || ($before === 0 && $after >= 1));
// restore epoch for the live user (avoid stranding real admin sessions in shared DB)
Database::pdo()->prepare('UPDATE base_users SET auth_epoch = ? WHERE id = ?')
    ->execute([$before, $uid]);

exit($fail > 0 ? 1 : 0);
