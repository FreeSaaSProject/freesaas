<?php
declare(strict_types=1);

/**
 * Staff vs guest gate for legacy-compatible /qr2.php (no qq / qrauth).
 */
final class QrScanGate
{
    public static function canScan(): bool
    {
        if (!Auth::check()) {
            return false;
        }
        return Auth::capability('GOOD_USE') || Auth::capability('SUPERADMIN_ACCESS');
    }

    public static function canRevise(): bool
    {
        if (!self::canScan()) {
            return false;
        }
        if (Auth::capability('SUPERADMIN_ACCESS')) {
            return true;
        }
        $uid = Auth::userId();
        if ($uid < 1 || !class_exists('UsersRepository', false)) {
            return false;
        }
        // find() omits STOCK_REVISION; edit row includes the flag.
        $user = UsersRepository::findForEdit($uid);
        return $user !== null && !empty($user['STOCK_REVISION']);
    }

    public static function eventsMyOnly(): bool
    {
        if (Auth::capability('SUPERADMIN_ACCESS')) {
            return false;
        }
        return !empty(Auth::statusFlags()['EVT_MY_ONLY']);
    }

    /**
     * Guest / unauthorized: HTML → storefront home; JSON → 403.
     */
    public static function denyGuest(): void
    {
        if (AgentHttp::wantsJson()) {
            AgentHttp::error('forbidden', 403);
            return;
        }
        redirect('/');
    }
}
