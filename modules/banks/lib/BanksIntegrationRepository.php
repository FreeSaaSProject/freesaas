<?php
declare(strict_types=1);

/**
 * Multi-env bank integrations (credentials in DB). Used first for Alfa UL sandbox+prod.
 */
final class BanksIntegrationRepository
{
    public const ENV_SANDBOX = 'sandbox';
    public const ENV_PROD = 'prod';

    public static function ready(): bool
    {
        return Database::tableExists('base_bank_integrations');
    }

    /** @return list<array<string,mixed>> */
    public static function listByProvider(string $provider): array
    {
        if (!self::ready()) {
            return [];
        }
        $p = BanksConfig::normalizeProvider($provider);
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, PROVIDER AS provider, ENV AS env, LABEL AS label, STATUS AS status,
                    CLIENT_ID AS client_id, CLIENT_SECRET AS client_secret, API_KEY AS api_key,
                    SCOPE AS scope, REDIRECT_URI AS redirect_uri,
                    MTLS_CERT AS mtls_cert, MTLS_KEY AS mtls_key, MTLS_CA AS mtls_ca, MTLS_PASS AS mtls_pass,
                    META AS meta, DT_CREATE AS dt_create, DT_UPDATE AS dt_update
             FROM base_bank_integrations
             WHERE PROVIDER = ?
             ORDER BY FIELD(ENV, \'sandbox\', \'prod\'), ID ASC'
        );
        $st->execute([$p]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([self::class, 'maskRow'], $rows);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id, bool $withSecrets = false): ?array
    {
        if ($id < 1 || !self::ready()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, PROVIDER AS provider, ENV AS env, LABEL AS label, STATUS AS status,
                    CLIENT_ID AS client_id, CLIENT_SECRET AS client_secret, API_KEY AS api_key,
                    SCOPE AS scope, REDIRECT_URI AS redirect_uri,
                    MTLS_CERT AS mtls_cert, MTLS_KEY AS mtls_key, MTLS_CA AS mtls_ca, MTLS_PASS AS mtls_pass,
                    META AS meta, DT_CREATE AS dt_create, DT_UPDATE AS dt_update
             FROM base_bank_integrations WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $withSecrets ? self::decodeMeta($row) : self::maskRow($row);
    }

    /** @return array<string,mixed>|null */
    public static function findByProviderEnv(string $provider, string $env, bool $withSecrets = false): ?array
    {
        if (!self::ready()) {
            return null;
        }
        $p = BanksConfig::normalizeProvider($provider);
        $env = self::normalizeEnv($env);
        $st = Database::pdo()->prepare(
            'SELECT ID AS id FROM base_bank_integrations WHERE PROVIDER = ? AND ENV = ? ORDER BY ID ASC LIMIT 1'
        );
        $st->execute([$p, $env]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? self::find($id, $withSecrets) : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        if (!self::ready()) {
            throw new RuntimeException('base_bank_integrations missing — run migrate');
        }
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_bank_integrations
             (PROVIDER, ENV, LABEL, STATUS, CLIENT_ID, CLIENT_SECRET, API_KEY, SCOPE, REDIRECT_URI,
              MTLS_CERT, MTLS_KEY, MTLS_CA, MTLS_PASS, META, DT_CREATE, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $meta = $data['meta'] ?? null;
        $st->execute([
            BanksConfig::normalizeProvider((string)($data['provider'] ?? '')),
            self::normalizeEnv((string)($data['env'] ?? self::ENV_PROD)),
            (string)($data['label'] ?? ''),
            (string)($data['status'] ?? 'draft'),
            (string)($data['client_id'] ?? ''),
            $data['client_secret'] ?? null,
            $data['api_key'] ?? null,
            (string)($data['scope'] ?? ''),
            (string)($data['redirect_uri'] ?? ''),
            (string)($data['mtls_cert'] ?? ''),
            (string)($data['mtls_key'] ?? ''),
            (string)($data['mtls_ca'] ?? ''),
            (string)($data['mtls_pass'] ?? ''),
            $meta === null ? null : (is_string($meta) ? $meta : json_encode($meta, JSON_UNESCAPED_UNICODE)),
            $now,
            $now,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1 || !self::ready()) {
            return;
        }
        $map = [
            'label' => 'LABEL',
            'status' => 'STATUS',
            'client_id' => 'CLIENT_ID',
            'scope' => 'SCOPE',
            'redirect_uri' => 'REDIRECT_URI',
            'mtls_cert' => 'MTLS_CERT',
            'mtls_key' => 'MTLS_KEY',
            'mtls_ca' => 'MTLS_CA',
            'mtls_pass' => 'MTLS_PASS',
            'env' => 'ENV',
        ];
        $fields = [];
        $params = [];
        foreach ($map as $k => $col) {
            if (!array_key_exists($k, $data)) {
                continue;
            }
            $val = $data[$k];
            if ($k === 'env') {
                $val = self::normalizeEnv((string)$val);
            }
            $fields[] = $col . ' = ?';
            $params[] = $val;
        }
        foreach (['client_secret' => 'CLIENT_SECRET', 'api_key' => 'API_KEY'] as $k => $col) {
            if (!array_key_exists($k, $data)) {
                continue;
            }
            $incoming = trim((string)($data[$k] ?? ''));
            if ($incoming === '') {
                continue; // keep existing secret
            }
            $fields[] = $col . ' = ?';
            $params[] = $incoming;
        }
        if (array_key_exists('meta', $data)) {
            $fields[] = 'META = ?';
            $meta = $data['meta'];
            $params[] = is_string($meta) ? $meta : json_encode($meta, JSON_UNESCAPED_UNICODE);
        }
        if ($fields === []) {
            return;
        }
        $fields[] = 'DT_UPDATE = ?';
        $params[] = time();
        $params[] = $id;
        Database::pdo()->prepare(
            'UPDATE base_bank_integrations SET ' . implode(', ', $fields) . ' WHERE ID = ?'
        )->execute($params);
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !self::ready()) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_bank_integrations WHERE ID = ?')->execute([$id]);
    }

    public static function normalizeEnv(string $env): string
    {
        $e = strtolower(trim($env));
        return $e === self::ENV_SANDBOX ? self::ENV_SANDBOX : self::ENV_PROD;
    }

    public static function isSandbox(array $integration): bool
    {
        return self::normalizeEnv((string)($integration['env'] ?? '')) === self::ENV_SANDBOX;
    }

    /**
     * Creds shape compatible with BanksConfig::providerCreds for Alfa UL.
     *
     * @return array{client_id:string,client_secret:string,redirect_uri:string,scope:string,auth_base:string,token_url:string,api_base:string,sandbox:bool,api_key:string}
     */
    public static function credsFrom(array $integration): array
    {
        $sandbox = self::isSandbox($integration);
        $redirect = trim((string)($integration['redirect_uri'] ?? ''));
        if ($redirect === '') {
            $redirect = BanksConfig::oauthRedirectUri('alfa_ul');
        }
        $scope = trim((string)($integration['scope'] ?? ''));
        if ($scope === '') {
            $scope = 'openid customer transactions profile email';
        }
        if ($sandbox) {
            $authBase = 'https://id-sandbox.alfabank.ru';
            $tokenUrl = 'https://sandbox.alfabank.ru/oidc/token';
            $apiBase = 'https://sandbox.alfabank.ru';
        } else {
            $authBase = 'https://id.alfabank.ru';
            $tokenUrl = 'https://baas.alfabank.ru/oidc/token';
            $apiBase = 'https://baas.alfabank.ru';
        }
        return [
            'client_id' => trim((string)($integration['client_id'] ?? '')),
            'client_secret' => trim((string)($integration['client_secret'] ?? '')),
            'redirect_uri' => $redirect,
            'scope' => $scope,
            'auth_base' => $authBase,
            'token_url' => $tokenUrl,
            'api_base' => $apiBase,
            'sandbox' => $sandbox,
            'api_key' => trim((string)($integration['api_key'] ?? '')),
        ];
    }

    /**
     * @return array{mtls_cert?:string,mtls_key?:string,mtls_pass?:string,mtls_ca?:string}
     */
    public static function mtlsFrom(array $integration): array
    {
        $cert = trim((string)($integration['mtls_cert'] ?? ''));
        if ($cert === '' || !is_readable($cert)) {
            return [];
        }
        $out = ['mtls_cert' => $cert];
        $key = trim((string)($integration['mtls_key'] ?? ''));
        if ($key !== '') {
            $out['mtls_key'] = $key;
        }
        $pass = trim((string)($integration['mtls_pass'] ?? ''));
        if ($pass !== '') {
            $out['mtls_pass'] = $pass;
        }
        $ca = trim((string)($integration['mtls_ca'] ?? ''));
        if ($ca !== '') {
            $out['mtls_ca'] = $ca;
        }
        return $out;
    }

    public static function isConfigured(array $integration): bool
    {
        $c = self::credsFrom($integration);
        if ($c['api_key'] !== '') {
            return true;
        }
        return $c['client_id'] !== '' && $c['client_secret'] !== '' && $c['redirect_uri'] !== '';
    }

    /**
     * One-shot: create sandbox/prod rows from legacy banks.ini Alfa UL keys.
     *
     * @return array{created:list<int>,skipped:bool}
     */
    public static function migrateFromIniIfNeeded(): array
    {
        if (!self::ready()) {
            return ['created' => [], 'skipped' => true];
        }
        $existing = self::listByProvider('alfa_ul');
        if ($existing !== []) {
            return ['created' => [], 'skipped' => true];
        }
        $clientId = trim((string)BanksConfig::get('alfa_ul_client_id', ''));
        $secret = trim((string)BanksConfig::get('alfa_ul_client_secret', ''));
        $apiKey = trim((string)BanksConfig::get('alfa_ul_api_key', ''));
        $scope = trim((string)BanksConfig::get('alfa_ul_scope', 'openid customer transactions profile email'));
        $redirect = trim((string)BanksConfig::get('alfa_ul_redirect_uri', ''));
        if ($redirect === '') {
            $redirect = BanksConfig::oauthRedirectUri('alfa_ul');
        }
        $sandboxFlag = (int)BanksConfig::get('alfa_ul_sandbox', 0) === 1;
        if ($clientId === '' && $apiKey === '') {
            // Still create empty draft slots so UI has both envs.
            $created = [];
            foreach ([self::ENV_SANDBOX, self::ENV_PROD] as $env) {
                $created[] = self::create([
                    'provider' => 'alfa_ul',
                    'env' => $env,
                    'label' => BanksConfig::providerLabel('alfa_ul') . ' ' . $env,
                    'status' => 'draft',
                    'scope' => $scope,
                    'redirect_uri' => $redirect,
                ]);
            }
            return ['created' => $created, 'skipped' => false];
        }
        $env = $sandboxFlag ? self::ENV_SANDBOX : self::ENV_PROD;
        $id = self::create([
            'provider' => 'alfa_ul',
            'env' => $env,
            'label' => BanksConfig::providerLabel('alfa_ul') . ' ' . $env . ' (from ini)',
            'status' => 'active',
            'client_id' => $clientId,
            'client_secret' => $secret !== '' ? $secret : null,
            'api_key' => $apiKey !== '' ? $apiKey : null,
            'scope' => $scope,
            'redirect_uri' => $redirect,
            'mtls_cert' => trim((string)BanksConfig::get('alfa_ul_mtls_cert', '')),
            'mtls_key' => trim((string)BanksConfig::get('alfa_ul_mtls_key', '')),
            'mtls_ca' => trim((string)BanksConfig::get('alfa_ul_mtls_ca', '')),
            'mtls_pass' => trim((string)BanksConfig::get('alfa_ul_mtls_pass', '')),
        ]);
        $other = $env === self::ENV_SANDBOX ? self::ENV_PROD : self::ENV_SANDBOX;
        $otherId = self::create([
            'provider' => 'alfa_ul',
            'env' => $other,
            'label' => BanksConfig::providerLabel('alfa_ul') . ' ' . $other,
            'status' => 'draft',
            'scope' => $scope,
            'redirect_uri' => $redirect,
        ]);
        return ['created' => [$id, $otherId], 'skipped' => false];
    }

    /** Ensure sandbox+prod draft rows exist for Alfa UL. */
    public static function ensureAlfaUlSlots(): void
    {
        if (!self::ready()) {
            return;
        }
        self::migrateFromIniIfNeeded();
        foreach ([self::ENV_SANDBOX, self::ENV_PROD] as $env) {
            if (self::findByProviderEnv('alfa_ul', $env) === null) {
                self::create([
                    'provider' => 'alfa_ul',
                    'env' => $env,
                    'label' => BanksConfig::providerLabel('alfa_ul') . ' ' . $env,
                    'status' => 'draft',
                    'scope' => 'openid customer transactions profile email',
                    'redirect_uri' => BanksConfig::oauthRedirectUri('alfa_ul'),
                ]);
            }
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function maskRow(array $row): array
    {
        $row = self::decodeMeta($row);
        foreach (['client_secret', 'api_key', 'mtls_pass'] as $k) {
            $v = (string)($row[$k] ?? '');
            unset($row[$k]);
            $row['has_' . $k] = $v !== '';
            $row[$k . '_masked'] = $v === '' ? '—' : (mb_substr($v, 0, 4) . '…' . mb_substr($v, -4));
        }
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decodeMeta(array $row): array
    {
        $raw = $row['meta'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $row['meta'] = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($raw)) {
            $row['meta'] = [];
        }
        return $row;
    }
}
