<?php
declare(strict_types=1);

/** SMTP settings in cfg/modules/mail.ini ([mail] section). */
final class MailConfig
{
    public const SECTION = 'mail';
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /** @var array<string,string> */
    public const DEFAULTS = [
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_user' => '',
        'smtp_pass' => '',
        'from_email' => '',
        'from_name' => '',
        'reply_to' => '',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    public static function get(string $key): string
    {
        $default = self::DEFAULTS[$key] ?? '';
        $val = (string)Config::get(self::SECTION, $key, $default);
        if ($key === 'smtp_pass') {
            // Passwords may legitimately contain edge spaces; do not trim.
            return $val;
        }
        $val = trim($val);
        if ($key === 'smtp_encryption') {
            $val = strtolower($val);
            return in_array($val, self::ENCRYPTIONS, true) ? $val : self::DEFAULTS['smtp_encryption'];
        }
        if ($key === 'smtp_port') {
            $port = (int)$val;
            return (string)($port >= 1 && $port <= 65535 ? $port : (int)self::DEFAULTS['smtp_port']);
        }
        return $val;
    }

    /** @return array<string,string> raw values incl. password (server-side only; never send to UI/agents). */
    public static function all(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = self::get($key);
        }
        return $out;
    }

    /**
     * Values for forms / agent JSON: password replaced by a masked placeholder.
     *
     * @return array<string,mixed>
     */
    public static function formValues(): array
    {
        $all = self::all();
        $pass = $all['smtp_pass'];
        $all['smtp_pass'] = self::maskSecret($pass);
        $out = $all;
        $out['smtp_port'] = (int)$all['smtp_port'];
        $out['has_pass'] = $pass !== '';
        $out['configured'] = self::isConfigured();
        return $out;
    }

    /** Host set and a valid sender address. */
    public static function isConfigured(): bool
    {
        return self::get('smtp_host') !== '' && self::validEmail(self::get('from_email'));
    }

    /**
     * Persist settings. Only keys present in $post are updated.
     * A masked password value keeps the stored password; an empty one clears it.
     *
     * @param array<string,mixed> $post
     * @throws InvalidArgumentException on invalid values (nothing is written)
     */
    public static function saveFromPost(array $post): void
    {
        $write = [];

        if (array_key_exists('smtp_host', $post)) {
            $host = trim((string)$post['smtp_host']);
            if ($host !== '' && !preg_match('/^[A-Za-z0-9._:\[\]-]+$/', $host)) {
                throw new InvalidArgumentException(t('mail.err.host'));
            }
            $write['smtp_host'] = $host;
        }
        if (array_key_exists('smtp_port', $post)) {
            $raw = trim((string)$post['smtp_port']);
            $port = $raw === '' ? (int)self::DEFAULTS['smtp_port'] : (int)$raw;
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException(t('mail.err.port'));
            }
            $write['smtp_port'] = (string)$port;
        }
        if (array_key_exists('smtp_encryption', $post)) {
            $enc = strtolower(trim((string)$post['smtp_encryption']));
            if (!in_array($enc, self::ENCRYPTIONS, true)) {
                throw new InvalidArgumentException(t('mail.err.encryption'));
            }
            $write['smtp_encryption'] = $enc;
        }
        if (array_key_exists('smtp_user', $post)) {
            $write['smtp_user'] = self::oneLine((string)$post['smtp_user']);
        }
        if (array_key_exists('smtp_pass', $post)) {
            $raw = (string)$post['smtp_pass'];
            if ($raw === '') {
                $write['smtp_pass'] = '';
            } elseif (!self::looksMasked($raw)) {
                $write['smtp_pass'] = $raw;
            }
        }
        foreach (['from_email', 'reply_to'] as $key) {
            if (array_key_exists($key, $post)) {
                $val = trim((string)$post[$key]);
                if ($val !== '' && !self::validEmail($val)) {
                    throw new InvalidArgumentException(t('mail.err.email', ['field' => $key]));
                }
                $write[$key] = $val;
            }
        }
        if (array_key_exists('from_name', $post)) {
            $write['from_name'] = self::oneLine((string)$post['from_name']);
        }

        foreach ($write as $key => $val) {
            Config::set(self::SECTION, $key, $val);
        }
    }

    public static function validEmail(string $addr): bool
    {
        $addr = trim($addr);
        if ($addr === '' || strlen($addr) > 254 || preg_match('/[\r\n<>,;\s]/', $addr)) {
            return false;
        }
        return filter_var($addr, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function maskSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }
        return str_repeat('•', min(12, max(8, strlen($value))));
    }

    public static function looksMasked(string $raw): bool
    {
        return strpos($raw, '•') !== false
            || strpos($raw, '…') !== false
            || preg_match('/^\*+$/', $raw) === 1;
    }

    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? '');
    }
}
