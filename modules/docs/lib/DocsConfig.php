<?php
declare(strict_types=1);

/** docs module ini helpers (cfg/modules/docs.ini). */
final class DocsConfig
{
    /** Organization requisites available to templates as {org.key}. */
    public const ORG_KEYS = [
        'name', 'short_name', 'brand', 'inn', 'kpp', 'ogrn', 'ogrn_date', 'okpo', 'registrar',
        'license', 'license_date', 'license_issuer', 'license_issuer_address', 'license_scope',
        'address', 'city', 'region', 'region_genitive', 'phone', 'site', 'domain',
        'director_genitive', 'director_short', 'director_short_genitive',
        'bank', 'account', 'corr_account', 'bik', 'signature',
    ];

    /** @var array<string,string>|null */
    private static $orgCache = null;

    /** @return array<string,string> key => value (all ORG_KEYS present) */
    public static function org(): array
    {
        if (self::$orgCache !== null) {
            return self::$orgCache;
        }
        // base64(JSON): values contain quotes («ООО "…"») that plain ini values do not survive.
        $raw = (string)Config::get('docs', 'org', '');
        $decoded = $raw !== '' ? json_decode((string)base64_decode($raw, true), true) : null;
        $out = [];
        foreach (self::ORG_KEYS as $k) {
            $out[$k] = is_array($decoded) && is_scalar($decoded[$k] ?? null) ? trim((string)$decoded[$k]) : '';
        }
        return self::$orgCache = $out;
    }

    /** @param array<string,mixed> $values */
    public static function setOrg(array $values): void
    {
        $out = [];
        foreach (self::ORG_KEYS as $k) {
            $out[$k] = mb_substr(trim((string)($values[$k] ?? '')), 0, 500);
        }
        Config::set('docs', 'org', base64_encode((string)json_encode($out, JSON_UNESCAPED_UNICODE)));
        self::$orgCache = $out;
    }

    public static function qrArchive(): bool
    {
        $raw = strtolower(trim((string)Config::get('docs', 'qr_archive', '0')));
        return in_array($raw, ['1', 'true', 'on', 'yes'], true);
    }

    public static function setQrArchive(bool $on): void
    {
        Config::set('docs', 'qr_archive', $on ? '1' : '0');
    }

    /** Medcard kinds are gated by docs.medical on top of view/edit. */
    public static function canSeeKind(string $kind): bool
    {
        if (!DocsDefsRepository::isMedicalKind($kind)) {
            return true;
        }
        return Auth::can('docs.medical');
    }

    public static function canSeeEventDocs(): bool
    {
        if (!Auth::can('docs.view')) {
            return false;
        }
        return Auth::capability('EVT_DOC_LINKS') || Auth::capability('SUPERADMIN_ACCESS');
    }
}
