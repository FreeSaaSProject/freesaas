<?php
declare(strict_types=1);

/**
 * CSRF for /t POST forms that survives a lost session cookie.
 *
 * Floor shells (Android WebView / kiosk browsers) may not keep the session
 * cookie between the NFC GET and the form POST, so Csrf::verify() alone fails.
 * Forms also carry a signed token bound to terminalid + braceletid + scan token.
 */
final class TerminalFormGuard
{
    public const FIELD = 'tform';
    private const TTL_SECONDS = 86400;

    public static function token(string $terminalid, string $braceletid, string $scanToken, ?int $issuedAt = null): string
    {
        $ts = $issuedAt ?? time();
        return $ts . '.' . self::sign($ts, $terminalid, $braceletid, $scanToken);
    }

    /** @param array<string,scalar|null> $baseQ */
    public static function fields(array $baseQ): string
    {
        $tok = self::token(
            (string)($baseQ['terminalid'] ?? ''),
            (string)($baseQ['braceletid'] ?? ''),
            (string)($baseQ['token'] ?? '')
        );
        return Csrf::field()
            . '<input type="hidden" name="' . self::FIELD . '" value="' . e($tok) . '">';
    }

    public static function verify(string $provided, string $terminalid, string $braceletid, string $scanToken): bool
    {
        if ($terminalid === '' || $braceletid === '' || $scanToken === '') {
            return false;
        }
        $parts = explode('.', $provided, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return false;
        }
        $ts = (int)$parts[0];
        $age = time() - $ts;
        if ($age < -300 || $age > self::TTL_SECONDS) {
            return false;
        }
        return hash_equals(self::sign($ts, $terminalid, $braceletid, $scanToken), $parts[1]);
    }

    /** Same-site check, then session CSRF or the signed form token; denies otherwise. */
    public static function enforcePost(): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return;
        }
        if (!Csrf::isSameSiteRequest()) {
            Csrf::deny('cross_site');
        }
        if (Csrf::verify()) {
            return;
        }
        $src = array_merge($_GET, $_POST);
        $ok = self::verify(
            trim((string)($_POST[self::FIELD] ?? '')),
            trim((string)($src['terminalid'] ?? '')),
            trim((string)($src['braceletid'] ?? '')),
            trim((string)($src['token'] ?? ''))
        );
        if (!$ok) {
            Csrf::deny('csrf');
        }
    }

    private static function sign(int $ts, string $terminalid, string $braceletid, string $scanToken): string
    {
        $key = (string)Config::get('salts', 'site', 'freesaas');
        return hash_hmac('sha256', 't-form|' . $ts . '|' . $terminalid . '|' . $braceletid . '|' . $scanToken, $key);
    }
}
