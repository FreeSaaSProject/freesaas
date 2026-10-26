<?php
declare(strict_types=1);

/**
 * Per-provider setup wizard: steps, progress in banks.ini, completion checks.
 * UI: /banks/setup/{provider}. Credentials stay SUPERADMIN; OAuth connect uses banks.connect.
 */
final class BanksSetupWizard
{
    /**
     * @return list<array{
     *   id:string,title:string,hint:string,links:list<array{label:string,href:string}>,
     *   required:bool,panel:string,
     *   agent_can:list<string>,ask_user:string,browser:list<string>
     * }>
     */
    public static function steps(string $provider): array
    {
        $p = BanksConfig::normalizeProvider($provider);
        switch ($p) {
            case 'alfa_ul':
                return self::stepsAlfaUl();
            case 'tochka':
                return self::stepsSimple('tochka', true);
            case 'tbank_ul':
                return self::stepsTbankUl();
            case 'alfa_fl':
            case 'tbank_fl':
            case 'sber_ul':
                return self::stepsSimple($p, false);
            case 'sber_fl':
                return self::stepsSberFl();
            default:
                return [];
        }
    }

    /** @return array{done:list<string>,skipped:list<string>} */
    public static function progress(string $provider): array
    {
        $p = BanksConfig::normalizeProvider($provider);
        $raw = trim((string)Config::get('banks', 'setup_' . $p . '_progress', ''));
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return ['done' => [], 'skipped' => []];
        }
        $done = [];
        foreach ($data['done'] ?? [] as $id) {
            if (is_string($id) && $id !== '') {
                $done[] = $id;
            }
        }
        $skipped = [];
        foreach ($data['skipped'] ?? [] as $id) {
            if (is_string($id) && $id !== '') {
                $skipped[] = $id;
            }
        }
        return ['done' => array_values(array_unique($done)), 'skipped' => array_values(array_unique($skipped))];
    }

    public static function markDone(string $provider, string $stepId): void
    {
        self::mutateProgress($provider, static function (array &$prog) use ($stepId): void {
            $prog['done'][] = $stepId;
            $prog['skipped'] = array_values(array_filter(
                $prog['skipped'],
                static fn (string $id): bool => $id !== $stepId
            ));
        });
    }

    public static function markSkipped(string $provider, string $stepId): void
    {
        self::mutateProgress($provider, static function (array &$prog) use ($stepId): void {
            $prog['skipped'][] = $stepId;
            $prog['done'] = array_values(array_filter(
                $prog['done'],
                static fn (string $id): bool => $id !== $stepId
            ));
        });
    }

    /** @param callable(array{done:list<string>,skipped:list<string>}):void $fn */
    private static function mutateProgress(string $provider, callable $fn): void
    {
        $p = BanksConfig::normalizeProvider($provider);
        if ($p === '') {
            return;
        }
        $prog = self::progress($p);
        $fn($prog);
        $prog['done'] = array_values(array_unique($prog['done']));
        $prog['skipped'] = array_values(array_unique($prog['skipped']));
        Config::set('banks', 'setup_' . $p . '_progress', (string)json_encode($prog, JSON_UNESCAPED_UNICODE));
    }

    public static function isStepComplete(string $provider, string $stepId): bool
    {
        $prog = self::progress($provider);
        if (in_array($stepId, $prog['done'], true) || in_array($stepId, $prog['skipped'], true)) {
            return true;
        }
        return self::autoComplete($provider, $stepId);
    }

    public static function autoComplete(string $provider, string $stepId): bool
    {
        $p = BanksConfig::normalizeProvider($provider);
        $egress = BanksEgressIp::detect();
        $mtls = BanksCertStorage::statusForProvider($p === 'alfa_ul' ? 'alfa_ul' : $p);
        $connected = false;
        foreach (BankConnectionsRepository::listAll() as $row) {
            if ((string)($row['provider'] ?? '') === $p && (string)($row['status'] ?? '') === 'active') {
                $connected = true;
                break;
            }
        }
        switch ($stepId) {
            case 'intro':
                return true; // informational; mark when visited via next
            case 'egress':
                return !empty($egress['usable']);
            case 'sandbox_tls':
            case 'mtls':
                return !empty($mtls['cert']['readable']) && !empty($mtls['key']['readable']);
            case 'sandbox_creds':
            case 'creds':
            case 'oauth':
                return BanksConfig::providerConfigured($p);
            case 'connect_sandbox':
            case 'connect':
            case 'connect_prod':
                return $connected;
            case 'prod_csr':
                $csr = BanksAlfaCsr::csrStatus();
                return !empty($csr['readable']);
            case 'prod_cert':
                return !empty($mtls['cert']['readable'])
                    && (int)Config::get('banks', 'alfa_ul_sandbox', 0) !== 1
                    && BanksConfig::providerConfigured('alfa_ul');
            case 'prod_integration':
                return false; // checklist — user marks done/skip
            case 'sandbox_or_oauth':
                return BanksConfig::providerConfigured($p);
            default:
                return false;
        }
    }

    /** @return array{status:string,current_step:string,done:list<string>,skipped:list<string>,steps:list<array<string,mixed>>,egress:array} */
    public static function snapshot(string $provider): array
    {
        $p = BanksConfig::normalizeProvider($provider);
        $steps = self::steps($p);
        $prog = self::progress($p);
        $egress = BanksEgressIp::detect();
        $enriched = [];
        $current = '';
        foreach ($steps as $i => $step) {
            $id = (string)$step['id'];
            $done = in_array($id, $prog['done'], true) || self::autoComplete($p, $id);
            $skipped = in_array($id, $prog['skipped'], true);
            $complete = $done || $skipped;
            if ($current === '' && !$complete) {
                $current = $id;
            }
            $enriched[] = array_merge($step, [
                'index' => $i + 1,
                'done' => $done && !$skipped,
                'skipped' => $skipped,
                'complete' => $complete,
                'auto' => self::autoComplete($p, $id),
            ]);
        }
        if ($current === '' && $enriched !== []) {
            $current = (string)$enriched[count($enriched) - 1]['id'];
        }
        $allDone = true;
        foreach ($enriched as $s) {
            if (empty($s['complete']) && !empty($s['required'])) {
                $allDone = false;
                break;
            }
        }
        $connected = false;
        foreach (BankConnectionsRepository::listAll() as $row) {
            if ((string)($row['provider'] ?? '') === $p && (string)($row['status'] ?? '') === 'active') {
                $connected = true;
                break;
            }
        }
        $status = 'not_started';
        if ($connected) {
            $status = 'connected';
        } elseif ($allDone || BanksConfig::providerConfigured($p)) {
            $status = 'ready';
        } elseif ($prog['done'] !== [] || $prog['skipped'] !== []) {
            $status = 'in_progress';
        }
        return [
            'status' => $status,
            'current_step' => $current,
            'done' => $prog['done'],
            'skipped' => $prog['skipped'],
            'steps' => $enriched,
            'egress' => $egress,
            'configured' => BanksConfig::providerConfigured($p),
            'connected' => $connected,
        ];
    }

    /** @return array<string,array{status:string,current_step:string}> */
    public static function allSnapshots(): array
    {
        $out = [];
        foreach (BanksConfig::PROVIDERS as $p) {
            $s = self::snapshot($p);
            $out[$p] = [
                'status' => $s['status'],
                'current_step' => $s['current_step'],
                'configured' => $s['configured'],
                'connected' => $s['connected'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array{
     *   id:string,title:string,hint:string,links:list<array{label:string,href:string}>,
     *   required:bool,panel:string,agent_can:list<string>,ask_user:string,browser:list<string>
     * }>
     */
    private static function stepsAlfaUl(): array
    {
        $sandbox = 'https://developers.alfabank.ru/selfservice/sandbox/accounts';
        $prod = 'https://developers.alfabank.ru/selfservice/prod/accounts';
        return [
            self::step('intro', 'banks.setup.alfa.intro', 'banks.setup.alfa.intro_hint', [
                ['label' => 'Sandbox H2H', 'href' => $sandbox],
                ['label' => 'Prod', 'href' => $prod],
            ], false, 'intro', ['mark_done'], 'banks.setup.agent.ask.alfa_intro', [$sandbox, $prod]),
            self::step('egress', 'banks.setup.step.egress', 'banks.setup.step.egress_hint', [], true, 'egress',
                ['read_egress'], 'banks.setup.agent.ask.egress', []),
            self::step('sandbox_tls', 'banks.setup.alfa.sandbox_tls', 'banks.setup.alfa.sandbox_tls_hint', [], true, 'sandbox_tls',
                ['upload_cert', 'upload_key'], 'banks.setup.agent.ask.alfa_tls', []),
            self::step('sandbox_creds', 'banks.setup.alfa.sandbox_creds', 'banks.setup.alfa.sandbox_creds_hint', [
                ['label' => 'H2H sandbox', 'href' => $sandbox],
            ], true, 'sandbox_creds',
                ['save_settings', 'probe'], 'banks.setup.agent.ask.alfa_sandbox_creds', [$sandbox]),
            self::step('connect_sandbox', 'banks.setup.alfa.connect_sandbox', 'banks.setup.alfa.connect_sandbox_hint', [], true, 'connect',
                ['oauth_start'], 'banks.setup.agent.ask.connect', []),
            self::step('prod_integration', 'banks.setup.alfa.prod_integration', 'banks.setup.alfa.prod_integration_hint', [
                ['label' => 'Create H2H (prod)', 'href' => $prod],
            ], false, 'prod_integration',
                ['read_redirect_uri', 'mark_done'], 'banks.setup.agent.ask.alfa_prod_integration', [$prod]),
            self::step('prod_csr', 'banks.setup.alfa.prod_csr', 'banks.setup.alfa.prod_csr_hint', [
                ['label' => 'TLS docs', 'href' => 'https://developers.alfabank.ru/products/alfa-api/documentation/articles/selfservice/articles/tls/tls'],
            ], false, 'prod_csr',
                ['csr_generate'], 'banks.setup.agent.ask.alfa_csr', [$prod]),
            self::step('prod_cert', 'banks.setup.alfa.prod_cert', 'banks.setup.alfa.prod_cert_hint', [], false, 'prod_cert',
                ['upload_cert', 'save_settings'], 'banks.setup.agent.ask.alfa_prod_cert', [$prod]),
            self::step('connect_prod', 'banks.setup.alfa.connect_prod', 'banks.setup.alfa.connect_prod_hint', [], false, 'connect',
                ['oauth_start'], 'banks.setup.agent.ask.connect', []),
        ];
    }

    /**
     * @return list<array{
     *   id:string,title:string,hint:string,links:list<array{label:string,href:string}>,
     *   required:bool,panel:string,agent_can:list<string>,ask_user:string,browser:list<string>
     * }>
     */
    private static function stepsSimple(string $provider, bool $withSandbox): array
    {
        $steps = [
            self::step('egress', 'banks.setup.step.egress', 'banks.setup.step.egress_hint', [], true, 'egress',
                ['read_egress'], 'banks.setup.agent.ask.egress', []),
        ];
        if ($withSandbox) {
            $steps[] = self::step('sandbox_or_oauth', 'banks.setup.tochka.creds', 'banks.setup.tochka.creds_hint', [], true, 'tochka_creds',
                ['save_settings'], 'banks.setup.agent.ask.tochka', []);
        } else {
            $hint = $provider === 'sber_ul'
                ? 'banks.setup.sber.oauth_hint'
                : 'banks.setup.oauth_hint';
            $ask = $provider === 'sber_ul'
                ? 'banks.setup.agent.ask.sber'
                : 'banks.setup.agent.ask.oauth';
            $steps[] = self::step('oauth', 'banks.setup.step.oauth', $hint, [], true, 'oauth',
                ['save_settings'], $ask, []);
        }
        $steps[] = self::step('connect', 'banks.setup.step.connect', 'banks.setup.step.connect_hint', [], true, 'connect',
            ['oauth_start'], 'banks.setup.agent.ask.connect', []);
        return $steps;
    }

    /**
     * @return list<array{
     *   id:string,title:string,hint:string,links:list<array{label:string,href:string}>,
     *   required:bool,panel:string,agent_can:list<string>,ask_user:string,browser:list<string>
     * }>
     */
    private static function stepsTbankUl(): array
    {
        return [
            self::step('egress', 'banks.setup.step.egress', 'banks.setup.step.egress_hint', [], true, 'egress',
                ['read_egress'], 'banks.setup.agent.ask.egress', []),
            self::step('creds', 'banks.setup.tbank.creds', 'banks.setup.tbank.creds_hint', [], true, 'tbank_creds',
                ['save_settings'], 'banks.setup.agent.ask.tbank', []),
            self::step('connect', 'banks.setup.step.connect', 'banks.setup.step.connect_hint', [], true, 'connect',
                ['oauth_start'], 'banks.setup.agent.ask.connect', []),
        ];
    }

    /**
     * @return list<array{
     *   id:string,title:string,hint:string,links:list<array{label:string,href:string}>,
     *   required:bool,panel:string,agent_can:list<string>,ask_user:string,browser:list<string>
     * }>
     */
    private static function stepsSberFl(): array
    {
        return [
            self::step('egress', 'banks.setup.step.egress', 'banks.setup.step.egress_hint', [], true, 'egress',
                ['read_egress'], 'banks.setup.agent.ask.egress', []),
            self::step('oauth', 'banks.setup.sber_fl.creds', 'banks.setup.sber_fl.creds_hint', [], true, 'sber_fl_creds',
                ['save_settings'], 'banks.setup.agent.ask.sber_fl', []),
            self::step('connect', 'banks.setup.step.connect', 'banks.setup.step.connect_hint', [], true, 'connect',
                ['oauth_start'], 'banks.setup.agent.ask.connect', []),
        ];
    }

    /**
     * @param list<array{label:string,href:string}> $links
     * @param list<string> $agentCan API actions the agent may perform without the human
     * @param list<string> $browser URLs to open if the agent has a browser
     * @return array{
     *   id:string,title:string,hint:string,links:list<array{label:string,href:string}>,
     *   required:bool,panel:string,agent_can:list<string>,ask_user:string,browser:list<string>
     * }
     */
    private static function step(
        string $id,
        string $titleKey,
        string $hintKey,
        array $links,
        bool $required,
        string $panel,
        array $agentCan,
        string $askUserKey,
        array $browser
    ): array {
        return [
            'id' => $id,
            'title' => t($titleKey),
            'hint' => t($hintKey),
            'links' => $links,
            'required' => $required,
            'panel' => $panel,
            'agent_can' => $agentCan,
            'ask_user' => t($askUserKey),
            'browser' => $browser,
        ];
    }
}
