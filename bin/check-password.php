#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Offline regressions for Auth password verify / policy / bot entropy.
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

// Prefer algo produces storeable length (column is VARCHAR(255)).
$sample = Auth::hashPassword('secret-password-ok');
$check(
    'new_hash_prefix',
    strpos($sample, '$2y$') === 0 || strpos($sample, '$2a$') === 0 || strpos($sample, '$argon2') === 0
);
$check('column_fits_argon2_or_bcrypt', strlen($sample) <= 255 && strlen($sample) >= 50);

$legacy = Auth::legacyHash('secret-password-ok');
$check('legacy_is_40_hex', (bool)preg_match('/^[0-9a-f]{40}$/', $legacy));
$check('legacy_verify', Auth::verifyPassword('secret-password-ok', $legacy));
$check('legacy_wrong_fails', !Auth::verifyPassword('nope', $legacy));
$check('legacy_needs_rehash', Auth::needsRehash($legacy));

$check('modern_verify', Auth::verifyPassword('secret-password-ok', $sample));
$check('modern_wrong_fails', !Auth::verifyPassword('nope', $sample));
$check('empty_stored_fails', !Auth::verifyPassword('x', ''));

$check('policy_short', Auth::passwordPolicyError('a') === 'too_short');
$check('policy_7', Auth::passwordPolicyError('1234567') === 'too_short');
$check('policy_8_ok', Auth::passwordPolicyError('12345678') === null);
$check('policy_super_9', Auth::passwordPolicyError('123456789', true) === 'too_short_super');
$check('policy_super_10', Auth::passwordPolicyError('1234567890', true) === null);

// Bot generator entropy (≥12, alphabet 62) via reflection.
require_once base_path('modules/core/lib/PasswordRestoreBotPlugin.php');
$ref = new ReflectionMethod('PasswordRestoreBotPlugin', 'generatePassword');
$ref->setAccessible(true);
$gen = (string)$ref->invoke(null, 12);
$check('bot_password_len', strlen($gen) >= 12);
$check('bot_password_alphabet', (bool)preg_match('/^[A-Za-z0-9]{12,}$/', $gen));

// Login link token shape (no DB).
$token = bin2hex(random_bytes(32));
$check('login_link_token_len', strlen($token) === 64);

exit($fail > 0 ? 1 : 0);
