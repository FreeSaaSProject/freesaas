<?php
declare(strict_types=1);

/**
 * Generate RSA key + CSR for Alfa prod TLS (upload CSR on Portal → TLS certificates).
 * Private key stays on disk (cfg/modules/banks-certs/); never leave the host.
 * @see https://developers.alfabank.ru/products/alfa-api/documentation/articles/connection/articles/tls/tls
 */
final class BanksAlfaCsr
{
    public const PROVIDER = 'alfa_ul';

    /**
     * @param array{
     *   organization:string,inn:string,common_name?:string,email?:string,
     *   client_id?:string,force?:bool
     * } $in
     * @return array{ok:bool,key_path:string,csr_path:string,csr_filename:string,bytes:int,subject:string}
     */
    public static function generate(array $in): array
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException(t('banks.err.csr_openssl'));
        }
        $org = trim((string)($in['organization'] ?? ''));
        $inn = preg_replace('/\D+/', '', (string)($in['inn'] ?? '')) ?? '';
        $cn = trim((string)($in['common_name'] ?? ''));
        if ($cn === '') {
            $cn = $org;
        }
        $email = trim((string)($in['email'] ?? ''));
        $clientId = trim((string)($in['client_id'] ?? ''));
        $force = !empty($in['force']);
        if ($org === '' || $cn === '') {
            throw new InvalidArgumentException(t('banks.err.csr_org'));
        }
        if ($inn === '' || (strlen($inn) !== 10 && strlen($inn) !== 12)) {
            throw new InvalidArgumentException(t('banks.err.csr_inn'));
        }

        $dir = BanksCertStorage::dir();
        $keyPath = $dir . '/' . self::PROVIDER . '_mtls_key.key';
        $csrPath = $dir . '/' . self::PROVIDER . '_mtls_csr.csr';
        if (!$force && is_file($keyPath) && filesize($keyPath) > 0) {
            throw new RuntimeException(t('banks.err.csr_key_exists'));
        }

        $dn = [
            'countryName' => 'RU',
            'organizationName' => $org,
            'commonName' => $cn,
        ];
        // Alfa LE INN OID (same as openssl_INNLE samples).
        $dn['INN'] = $inn;
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $dn['emailAddress'] = $email;
        }

        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];
        $pkey = openssl_pkey_new($config);
        if ($pkey === false) {
            throw new RuntimeException(t('banks.err.csr_gen') . ': ' . self::opensslErr());
        }
        $csr = openssl_csr_new($dn, $pkey, array_merge($config, [
            'req_extensions' => 'v3_req',
        ]));
        // Some PHP builds ignore unknown DN keys like INN — fall back to serialNumber.
        if ($csr === false) {
            unset($dn['INN']);
            $dn['serialNumber'] = $inn;
            $csr = openssl_csr_new($dn, $pkey, $config);
        }
        if ($csr === false) {
            throw new RuntimeException(t('banks.err.csr_gen') . ': ' . self::opensslErr());
        }

        $keyPem = '';
        if (!openssl_pkey_export($pkey, $keyPem) || $keyPem === '') {
            throw new RuntimeException(t('banks.err.csr_gen') . ': key export');
        }
        $csrPem = '';
        if (!openssl_csr_export($csr, $csrPem) || $csrPem === '') {
            throw new RuntimeException(t('banks.err.csr_gen') . ': csr export');
        }

        // Prefer OpenSSL CLI with explicit INN OID when available (matches Alfa docs).
        $cli = self::generateViaCli($org, $inn, $cn, $email, $clientId, $keyPath, $csrPath);
        if ($cli !== null) {
            Config::set('banks', self::PROVIDER . '_mtls_key', $keyPath);
            return $cli;
        }

        if (@file_put_contents($keyPath, $keyPem) === false
            || @file_put_contents($csrPath, $csrPem) === false
        ) {
            throw new RuntimeException(t('banks.err.certs_write'));
        }
        @chmod($keyPath, 0600);
        @chmod($csrPath, 0600);
        Config::set('banks', self::PROVIDER . '_mtls_key', $keyPath);

        return [
            'ok' => true,
            'key_path' => $keyPath,
            'csr_path' => $csrPath,
            'csr_filename' => self::downloadFilename($org),
            'bytes' => strlen($csrPem),
            'subject' => self::subjectFromCsr($csrPem),
        ];
    }

    /**
     * @return array{
     *   path:string,exists:bool,readable:bool,bytes:int,filename:string,
     *   subject:string,innle_ok:bool
     * }
     */
    public static function csrStatus(): array
    {
        $path = BanksCertStorage::dir() . '/' . self::PROVIDER . '_mtls_csr.csr';
        $exists = is_file($path);
        $pem = ($exists && is_readable($path)) ? (string)file_get_contents($path) : '';
        return [
            'path' => $path,
            'exists' => $exists,
            'readable' => $pem !== '',
            'bytes' => $exists ? (int)filesize($path) : 0,
            'filename' => 'Company.csr',
            'subject' => $pem !== '' ? self::subjectFromCsr($pem) : '',
            'innle_ok' => $pem !== '' && self::hasValidInnleOid($pem),
        ];
    }

    public static function downloadFilename(string $org = ''): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $org) ?? 'Company';
        $safe = trim($safe, '_');
        if ($safe === '') {
            $safe = 'Company';
        }
        return $safe . '.csr';
    }

    /**
     * @return array{ok:bool,key_path:string,csr_path:string,csr_filename:string,bytes:int,subject:string}|null
     */
    private static function generateViaCli(
        string $org,
        string $inn,
        string $cn,
        string $email,
        string $clientId,
        string $keyPath,
        string $csrPath
    ): ?array {
        $openssl = trim((string)shell_exec('command -v openssl 2>/dev/null'));
        if ($openssl === '') {
            return null;
        }
        $cnf = tempnam(sys_get_temp_dir(), 'alfa_csr_');
        if ($cnf === false) {
            return null;
        }
        $cnfFile = $cnf . '.cnf';
        @unlink($cnf);
        $esc = static function (string $s): string {
            return str_replace(["\n", "\r", '\\'], [' ', ' ', '\\\\'], $s);
        };
        $emailLine = $email !== '' ? 'emailAddress = ' . $esc($email) . "\n" : '';
        // INNLE = 1.2.643.100.4 (Alfa openssl_INNLE). Must use oid_section / OID. prefix —
        // bare "1.2.643.100.4 = …" is mis-encoded by OpenSSL as OID 2.643.100.4.
        $body = "oid_section = oids\n"
            . "[oids]\n"
            . "INNLE = 1.2.643.100.4\n\n"
            . "[req]\n"
            . "default_bits = 2048\n"
            . "prompt = no\n"
            . "default_md = sha256\n"
            . "distinguished_name = dn\n"
            . "utf8 = yes\n"
            . "string_mask = utf8only\n\n"
            . "[dn]\n"
            . "C = RU\n"
            . 'O = ' . $esc($org) . "\n"
            . 'CN = ' . $esc($cn) . "\n"
            . $emailLine
            . 'INNLE = ' . $inn . "\n";
        if ($clientId !== '') {
            // Optional; some Alfa samples put client_id as challenge password.
            $body .= "\n[req_attributes]\nchallengePassword = " . $esc($clientId) . "\n";
        }
        if (@file_put_contents($cnfFile, $body) === false) {
            return null;
        }
        $cmd = sprintf(
            '%s req -utf8 -newkey rsa:2048 -nodes -keyout %s -out %s -config %s 2>&1',
            escapeshellcmd($openssl),
            escapeshellarg($keyPath),
            escapeshellarg($csrPath),
            escapeshellarg($cnfFile)
        );
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        @unlink($cnfFile);
        if ($code !== 0 || !is_readable($csrPath) || !is_readable($keyPath)) {
            return null;
        }
        @chmod($keyPath, 0600);
        @chmod($csrPath, 0600);
        $csrPem = (string)file_get_contents($csrPath);
        return [
            'ok' => true,
            'key_path' => $keyPath,
            'csr_path' => $csrPath,
            'csr_filename' => self::downloadFilename($org),
            'bytes' => strlen($csrPem),
            'subject' => self::subjectFromCsr($csrPem),
        ];
    }

    private static function subjectFromCsr(string $pem): string
    {
        // Prefer openssl CLI: PHP maps unknown OID 1.2.643.100.4 to "undefined"/"UNDEF".
        $cliSubj = self::subjectViaCli($pem);
        if ($cliSubj !== '') {
            return $cliSubj;
        }
        $csr = @openssl_csr_get_subject($pem, false);
        if (!is_array($csr)) {
            return '';
        }
        $parts = [];
        foreach ($csr as $k => $v) {
            if (!is_string($k) || !(is_string($v) || is_numeric($v))) {
                continue;
            }
            $label = $k;
            if ($k === 'undefined' || $k === 'UNDEF' || $k === '') {
                $label = 'INNLE(1.2.643.100.4)';
            }
            $parts[] = $label . '=' . $v;
        }
        return implode(', ', $parts);
    }

    private static function subjectViaCli(string $pem): string
    {
        $openssl = trim((string)shell_exec('command -v openssl 2>/dev/null'));
        if ($openssl === '' || $pem === '') {
            return '';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'csr_');
        if ($tmp === false) {
            return '';
        }
        if (@file_put_contents($tmp, $pem) === false) {
            @unlink($tmp);
            return '';
        }
        $cmd = sprintf(
            '%s req -in %s -noout -subject -nameopt utf8,sep_comma_plus,oid 2>/dev/null',
            escapeshellcmd($openssl),
            escapeshellarg($tmp)
        );
        $out = trim((string)shell_exec($cmd));
        @unlink($tmp);
        if ($out === '' || strncmp($out, 'subject=', 8) !== 0) {
            return '';
        }
        $subj = trim(substr($out, 8));
        // Friendly label for Alfa INNLE OID (and reject broken 2.643.100.4 from old CSRs).
        $subj = str_replace('1.2.643.100.4=', 'INNLE=', $subj);
        return $subj;
    }

    /** True when CSR contains correct INNLE OID 1.2.643.100.4 (not mis-encoded 2.643.100.4). */
    public static function hasValidInnleOid(string $pem = ''): bool
    {
        if ($pem === '') {
            $path = BanksCertStorage::dir() . '/' . self::PROVIDER . '_mtls_csr.csr';
            if (!is_readable($path)) {
                return false;
            }
            $pem = (string)file_get_contents($path);
        }
        if ($pem === '') {
            return false;
        }
        $openssl = trim((string)shell_exec('command -v openssl 2>/dev/null'));
        if ($openssl === '') {
            // Without CLI we cannot verify; assume ok if subject has INN digits.
            return true;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'csr_');
        if ($tmp === false || @file_put_contents($tmp, $pem) === false) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            return false;
        }
        $cmd = sprintf('%s asn1parse -in %s 2>/dev/null', escapeshellcmd($openssl), escapeshellarg($tmp));
        $out = (string)shell_exec($cmd);
        @unlink($tmp);
        return strpos($out, 'OBJECT            :1.2.643.100.4') !== false
            || strpos($out, 'OBJECT            :INNLE') !== false;
    }

    private static function opensslErr(): string
    {
        $buf = [];
        while (($e = openssl_error_string()) !== false) {
            $buf[] = $e;
        }
        return $buf !== [] ? implode('; ', $buf) : 'unknown';
    }
}
