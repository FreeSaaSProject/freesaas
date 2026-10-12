<?php
declare(strict_types=1);

/**
 * Local module catalog DTOs: bilocale blurbs, category/tags filters, screenshots.
 * Shared by /modules UI and AgentOps module list/inspect.
 */
final class ModuleCatalog
{
    /** @var list<string> Many-to-many; module may list several. */
    public const CATEGORIES = [
        'platform',
        'finance',
        'accounting',
        'warehouse',
        'dictionaries',
        'team',
        'audio',
        'crm',
        'communications',
        'integrations',
        'site',
        'wellness',
    ];

    /** @var list<string> */
    public const TAGS = [
        'dictionary',
        'report',
        'journal',
        'integration',
        'oauth',
        'jobs',
        'llm',
        'embed',
        'transport',
        'payments',
        'crm',
        'sync',
        'settings',
    ];

    /** @var list<string> */
    public const MATURITIES = [
        'alpha',
        'beta',
        'release',
    ];

    /** Default when catalog.maturity omitted (until modules are hardened). */
    public const MATURITY_DEFAULT = 'beta';

    /** @var array<string, array<string, string>>|null locale => key => text */
    private static $localeCatalogs = null;

    public static function resetLocaleCache(): void
    {
        self::$localeCatalogs = null;
    }

    /**
     * @param array{q?:string,category?:string,tag?:string,status?:string,maturity?:string} $filters
     * @return list<array<string, mixed>>
     */
    public static function list(array $filters = []): array
    {
        $out = [];
        foreach (ModuleManager::all() as $id => $_) {
            $card = self::card((string)$id);
            if ($card === null) {
                continue;
            }
            if (!self::matches($card, $filters)) {
                continue;
            }
            $out[] = $card;
        }
        return $out;
    }

    /**
     * Remote modules not on disk yet (UpdateEngine catalog).
     *
     * @param array{q?:string,category?:string,tag?:string,status?:string,maturity?:string} $filters
     * @return list<array<string, mixed>>
     */
    public static function installableList(array $filters = []): array
    {
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'available') {
            return [];
        }
        $out = [];
        try {
            foreach (UpdateEngine::availableToInstall() as $row) {
                $card = self::installableCard($row);
                if (!self::matches($card, $filters)) {
                    continue;
                }
                $out[] = $card;
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row from UpdateEngine::availableToInstall
     * @return array<string,mixed>
     */
    public static function installableCard(array $row): array
    {
        $id = (string)($row['id'] ?? '');
        $maturity = (string)($row['maturity'] ?? self::MATURITY_DEFAULT);
        if (!self::isValidMaturity($maturity)) {
            $maturity = self::MATURITY_DEFAULT;
        }
        $categories = [];
        foreach ($row['categories'] ?? [] as $c) {
            if (is_string($c) && self::isValidCategory($c)) {
                $categories[] = $c;
            }
        }
        $tags = [];
        foreach ($row['tags'] ?? [] as $tg) {
            if (is_string($tg) && $tg !== '') {
                $tags[] = $tg;
            }
        }
        $name = self::asBilocale($row['name'] ?? null);
        $description = self::asBilocale($row['description'] ?? null);
        $summary = self::asBilocale($row['summary'] ?? null);
        $requires = is_array($row['requires'] ?? null) ? $row['requires'] : [];
        $optional = is_array($row['optionalRequires'] ?? null) ? $row['optionalRequires'] : [];

        return [
            'id' => $id,
            'version' => (string)($row['version'] ?? ''),
            'type' => (string)($row['type'] ?? 'product'),
            'enabled' => false,
            'config_enabled' => false,
            'block_reason' => null,
            'migrations_pending' => 0,
            'deps_ok' => true,
            'status' => 'available',
            'name' => $name,
            'description' => $description,
            'summary' => $summary,
            'catalog' => [
                'categories' => $categories,
                'tags' => $tags,
                'maturity' => $maturity,
            ],
            'maturity' => $maturity,
            'requires_risk_ack' => self::requiresRiskAck($maturity),
            'agent_note' => self::agentNote($maturity),
            'provides' => [],
            'requires' => $requires,
            'optionalRequires' => $optional,
            'can_enable' => false,
            'can_disable' => false,
            'can_toggle' => false,
            'can_uninstall' => false,
            'uninstall_strategy' => 'drop_owned',
            'can_install' => true,
            'installable' => true,
            'required_by' => [],
            'status_requires' => [],
            'remote_version' => (string)($row['version'] ?? ''),
            'ref' => (string)($row['ref'] ?? ''),
        ];
    }

    /**
     * @param mixed $v
     * @return array{en:string,ru:string}
     */
    private static function asBilocale($v): array
    {
        if (!is_array($v)) {
            return ['en' => '', 'ru' => ''];
        }
        return [
            'en' => trim((string)($v['en'] ?? '')),
            'ru' => trim((string)($v['ru'] ?? '')),
        ];
    }

    /**
     * Group cards into catalog sections (CATEGORIES order).
     * Multi-category modules appear in each matching section.
     *
     * @param list<array<string, mixed>> $cards
     * @return list<array{id:string,cards:list<array<string,mixed>>}>
     */
    public static function sections(array $cards): array
    {
        $buckets = [];
        foreach (self::CATEGORIES as $c) {
            $buckets[$c] = [];
        }
        $uncategorized = [];
        foreach ($cards as $card) {
            if (!is_array($card)) {
                continue;
            }
            $cats = $card['catalog']['categories'] ?? [];
            if (!is_array($cats) || $cats === []) {
                $uncategorized[] = $card;
                continue;
            }
            $placed = false;
            foreach ($cats as $c) {
                $c = (string)$c;
                if (!isset($buckets[$c])) {
                    continue;
                }
                $buckets[$c][] = $card;
                $placed = true;
            }
            if (!$placed) {
                $uncategorized[] = $card;
            }
        }
        $sections = [];
        foreach (self::CATEGORIES as $c) {
            if ($buckets[$c] === []) {
                continue;
            }
            $sections[] = [
                'id' => $c,
                'cards' => $buckets[$c],
            ];
        }
        if ($uncategorized !== []) {
            $sections[] = [
                'id' => '',
                'cards' => $uncategorized,
            ];
        }
        return $sections;
    }

    /** @return array<string, mixed>|null */
    public static function card(string $id): ?array
    {
        $meta = ModuleManager::get($id);
        if ($meta === null) {
            return null;
        }
        $st = ModuleManager::status($id);
        $catalog = self::parseCatalog($meta);
        $nameKey = (string)($meta['name'] ?? '');
        $descKey = (string)($meta['description'] ?? '');
        $summaryKey = $id . '.module.summary';
        $maturity = $catalog['maturity'];

        return [
            'id' => $id,
            'version' => (string)($meta['version'] ?? ''),
            'type' => (string)($meta['type'] ?? ''),
            'enabled' => !empty($meta['_enabled']),
            'config_enabled' => !empty($st['config_enabled']),
            'block_reason' => $st['block_reason'] ?? null,
            'migrations_pending' => (int)($st['migrations_pending'] ?? 0),
            'deps_ok' => !empty($st['deps_ok']),
            'status' => self::runtimeStatus($st),
            'name' => self::bilocale($nameKey),
            'description' => self::bilocale($descKey),
            'summary' => self::bilocaleKeyIfPresent($summaryKey),
            'catalog' => [
                'categories' => $catalog['categories'],
                'tags' => $catalog['tags'],
                'maturity' => $maturity,
            ],
            'maturity' => $maturity,
            'requires_risk_ack' => self::requiresRiskAck($maturity),
            'agent_note' => self::agentNote($maturity),
            'provides' => self::stringList($meta['provides'] ?? []),
            'requires' => is_array($meta['requires'] ?? null) ? $meta['requires'] : [],
            'optionalRequires' => is_array($meta['optionalRequires'] ?? null) ? $meta['optionalRequires'] : [],
            'can_enable' => !empty($st['can_enable']),
            'can_disable' => !empty($st['can_disable']),
            'can_toggle' => !empty($st['can_toggle']),
            'can_uninstall' => !empty($st['can_uninstall']),
            'uninstall_strategy' => (string)($st['uninstall_strategy'] ?? 'drop_owned'),
            'required_by' => $st['required_by'] ?? [],
            'status_requires' => $st['requires'] ?? [],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function detail(string $id): ?array
    {
        $card = self::card($id);
        if ($card === null) {
            return null;
        }
        $meta = ModuleManager::get($id);
        if ($meta === null) {
            return null;
        }
        $st = ModuleManager::status($id);
        $catalog = self::parseCatalog($meta);
        $card['screenshots'] = self::resolveScreenshots($id, $catalog['screenshots'], (string)($meta['_dir'] ?? ''));
        $card['status_full'] = $st;
        $card['permissions'] = [];
        foreach ($meta['permissions'] ?? [] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $pid = (string)($p['id'] ?? '');
            if ($pid === '') {
                continue;
            }
            $labelKey = (string)($p['label'] ?? '');
            $card['permissions'][] = [
                'id' => $pid,
                'label' => self::bilocale($labelKey),
            ];
        }
        $card['routes_pages'] = [];
        foreach ($meta['routes']['pages'] ?? [] as $p) {
            if (is_string($p) && $p !== '') {
                $card['routes_pages'][] = $p;
            }
        }
        return $card;
    }

    /**
     * @param array{q?:string,category?:string,tag?:string,status?:string,maturity?:string} $filters
     */
    public static function matches(array $card, array $filters): bool
    {
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $hay = strtolower(
                (string)$card['id'] . ' '
                . (string)($card['name']['en'] ?? '') . ' '
                . (string)($card['name']['ru'] ?? '') . ' '
                . (string)($card['description']['en'] ?? '') . ' '
                . (string)($card['description']['ru'] ?? '') . ' '
                . (string)($card['summary']['en'] ?? '') . ' '
                . (string)($card['summary']['ru'] ?? '')
            );
            if (strpos($hay, strtolower($q)) === false) {
                return false;
            }
        }

        $category = trim((string)($filters['category'] ?? ''));
        if ($category !== '') {
            $cats = $card['catalog']['categories'] ?? [];
            if (!is_array($cats) || !in_array($category, $cats, true)) {
                return false;
            }
        }

        $tag = trim((string)($filters['tag'] ?? ''));
        if ($tag !== '') {
            $tags = $card['catalog']['tags'] ?? [];
            if (!is_array($tags) || !in_array($tag, $tags, true)) {
                return false;
            }
        }

        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            if ((string)($card['status'] ?? '') !== $status) {
                return false;
            }
        }

        $maturity = trim((string)($filters['maturity'] ?? ''));
        if ($maturity !== '') {
            if ((string)($card['maturity'] ?? '') !== $maturity) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{config_enabled?:bool,enabled?:bool} $st
     */
    public static function runtimeStatus(array $st): string
    {
        if (!empty($st['enabled'])) {
            return 'enabled';
        }
        if (!empty($st['config_enabled'])) {
            return 'blocked';
        }
        return 'disabled';
    }

    public static function isValidCategory(string $category): bool
    {
        return in_array($category, self::CATEGORIES, true);
    }

    /**
     * Categories that at least one discovered module declares (preserve CATEGORIES order).
     *
     * @return list<string>
     */
    public static function usedCategories(): array
    {
        $used = [];
        foreach (ModuleManager::all() as $id => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            foreach (self::parseCatalog($meta)['categories'] as $c) {
                $used[$c] = true;
            }
        }
        try {
            foreach (UpdateEngine::availableToInstall(false) as $row) {
                foreach ($row['categories'] ?? [] as $c) {
                    if (is_string($c) && self::isValidCategory($c)) {
                        $used[$c] = true;
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        $out = [];
        foreach (self::CATEGORIES as $c) {
            if (isset($used[$c])) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * Tags actually used by modules (preserve TAGS order).
     *
     * @return list<string>
     */
    public static function usedTags(): array
    {
        $used = [];
        foreach (ModuleManager::all() as $id => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            foreach (self::parseCatalog($meta)['tags'] as $t) {
                $used[$t] = true;
            }
        }
        $out = [];
        foreach (self::TAGS as $t) {
            if (isset($used[$t])) {
                $out[] = $t;
            }
        }
        return $out;
    }

    public static function categoryLabel(string $category): string
    {
        $key = 'core.modules.category.' . $category;
        $label = t($key);
        return $label !== $key ? $label : $category;
    }

    public static function isValidTag(string $tag): bool
    {
        return in_array($tag, self::TAGS, true);
    }

    public static function isValidMaturity(string $maturity): bool
    {
        return in_array($maturity, self::MATURITIES, true);
    }

    public static function requiresRiskAck(string $maturity): bool
    {
        return $maturity === 'alpha' || $maturity === 'beta';
    }

    public static function maturityOf(string $id): string
    {
        $meta = ModuleManager::get($id);
        if ($meta === null) {
            return self::MATURITY_DEFAULT;
        }
        return self::parseCatalog($meta)['maturity'];
    }

    /**
     * Short agent-facing note for this maturity (bilocale).
     *
     * @return array{en:string,ru:string}
     */
    public static function agentNote(string $maturity): array
    {
        if ($maturity === 'alpha') {
            return [
                'en' => 'Alpha — expect instability; be ready to diagnose and repair.',
                'ru' => 'Alpha — ожидай нестабильность, будь готов диагностировать и ремонтировать.',
            ];
        }
        if ($maturity === 'beta') {
            return [
                'en' => 'Beta — mostly usable; still expect rough edges and be ready to diagnose and repair.',
                'ru' => 'Beta — в целом usable, но жди шероховатости; будь готов диагностировать и ремонтировать.',
            ];
        }
        return [
            'en' => 'Release — stability is promised; regressions are high priority.',
            'ru' => 'Release — обещана стабильность; регрессии — высокий приоритет.',
        ];
    }

    /**
     * Shared maturity policy for agents (returned once on module list).
     *
     * @return array{en:string,ru:string}
     */
    public static function maturityPolicy(): array
    {
        return [
            'en' => "FreeSaaS is an open system with source + an agent that has tools. "
                . "Imperfect modules can still have practical value early.\n"
                . "Alpha/Beta: the agent may diagnose failures and propose a local fix. "
                . "If the bug is in FreeSaaS code and reproducible — check existing issues; if no duplicate, create an issue. "
                . "If the agent fixed it and tests pass — attach a patch/PR to the issue.\n"
                . "Release: same process, but the bug gets higher priority because the stability promise was broken.\n"
                . "Do not stop at «HTTP 500, contact an administrator» — investigate, repair locally when possible, then offer to upstream.",
            'ru' => "FreeSaaS — открытая система: исходники + агент с инструментами. "
                . "Даже неидеальный модуль может быть полезен раньше, чем в классическом SaaS.\n"
                . "Alpha/Beta: агент имеет право диагностировать и предлагать локальное исправление. "
                . "Если ошибка относится к коду FreeSaaS и воспроизводится — проверить существующие issues; если дубликата нет, создать issue. "
                . "Если агент внёс исправление и тесты проходят — дополнить issue патчем/PR.\n"
                . "Release: тот же процесс, но баг получает повышенный приоритет, потому что нарушено обещание стабильности.\n"
                . "Не останавливайся на «ошибка 500, обратитесь к администратору» — разберись, почини локально если можно, предложи upstream.",
        ];
    }

    /**
     * Localized display name for current UI locale.
     *
     * @param array{en?:string,ru?:string} $bilocale
     */
    public static function display(array $bilocale, ?string $fallback = null): string
    {
        $loc = class_exists('I18n', false) ? I18n::locale() : 'ru';
        $v = trim((string)($bilocale[$loc] ?? ''));
        if ($v !== '') {
            return $v;
        }
        $v = trim((string)($bilocale['en'] ?? ''));
        if ($v !== '') {
            return $v;
        }
        $v = trim((string)($bilocale['ru'] ?? ''));
        if ($v !== '') {
            return $v;
        }
        return $fallback ?? '';
    }

    /**
     * @return array{
     *   categories:list<string>,
     *   tags:list<string>,
     *   maturity:string,
     *   maturity_declared:string,
     *   screenshots:list<array{file:string,caption:string}>
     * }
     */
    public static function parseCatalog(array $meta): array
    {
        $raw = $meta['catalog'] ?? null;
        $categories = [];
        $tags = [];
        $declared = '';
        $screenshots = [];
        if (is_array($raw)) {
            $declared = strtolower(trim((string)($raw['maturity'] ?? '')));
            // Preferred: categories[] (many-to-many). Legacy: single category string.
            if (isset($raw['categories']) && is_array($raw['categories'])) {
                foreach ($raw['categories'] as $c) {
                    if (is_string($c) && $c !== '') {
                        $categories[] = $c;
                    }
                }
            }
            $legacy = trim((string)($raw['category'] ?? ''));
            if ($legacy !== '') {
                $categories[] = $legacy;
            }
            foreach ($raw['tags'] ?? [] as $t) {
                if (is_string($t) && $t !== '') {
                    $tags[] = $t;
                }
            }
            foreach ($raw['screenshots'] ?? [] as $shot) {
                if (!is_array($shot)) {
                    continue;
                }
                $file = trim((string)($shot['file'] ?? ''));
                if ($file === '') {
                    continue;
                }
                $screenshots[] = [
                    'file' => $file,
                    'caption' => trim((string)($shot['caption'] ?? '')),
                ];
            }
        }
        $categories = array_values(array_unique($categories));
        $validCats = [];
        foreach ($categories as $c) {
            if (self::isValidCategory($c)) {
                $validCats[] = $c;
            }
        }
        $maturity = ($declared !== '' && self::isValidMaturity($declared))
            ? $declared
            : self::MATURITY_DEFAULT;
        return [
            'categories' => $validCats,
            'categories_declared' => $categories,
            'tags' => array_values(array_unique($tags)),
            'maturity' => $maturity,
            'maturity_declared' => $declared,
            'screenshots' => $screenshots,
        ];
    }

    /**
     * @param list<array{file:string,caption:string}> $shots
     * @return list<array{file:string,url:string,caption:array{en:string,ru:string}}>
     */
    private static function resolveScreenshots(string $id, array $shots, string $dir): array
    {
        if ($dir === '') {
            $dir = base_path('modules/' . $id);
        }
        $out = [];
        foreach ($shots as $shot) {
            $file = (string)$shot['file'];
            // Only allow relative paths under assets/catalog/
            $file = str_replace('\\', '/', $file);
            if ($file === '' || strpos($file, '..') !== false || strpos($file, 'assets/catalog/') !== 0) {
                continue;
            }
            $abs = $dir . '/' . $file;
            if (!is_file($abs)) {
                continue;
            }
            $out[] = [
                'file' => $file,
                'url' => '/modules/' . rawurlencode($id) . '/' . $file,
                'caption' => self::bilocale((string)($shot['caption'] ?? '')),
            ];
        }
        return $out;
    }

    /** @return array{en:string,ru:string} */
    private static function bilocale(string $key): array
    {
        if ($key === '' || strpos($key, '.') === false) {
            return ['en' => '', 'ru' => ''];
        }
        self::ensureLocaleCatalogs();
        return [
            'en' => (string)(self::$localeCatalogs['en'][$key] ?? ''),
            'ru' => (string)(self::$localeCatalogs['ru'][$key] ?? ''),
        ];
    }

    /** @return array{en:string,ru:string} */
    private static function bilocaleKeyIfPresent(string $key): array
    {
        self::ensureLocaleCatalogs();
        $en = (string)(self::$localeCatalogs['en'][$key] ?? '');
        $ru = (string)(self::$localeCatalogs['ru'][$key] ?? '');
        if ($en === '' && $ru === '') {
            return ['en' => '', 'ru' => ''];
        }
        return ['en' => $en, 'ru' => $ru];
    }

    private static function ensureLocaleCatalogs(): void
    {
        if (self::$localeCatalogs !== null) {
            return;
        }
        self::$localeCatalogs = [
            'en' => I18n::collectCatalogKeys('en'),
            'ru' => I18n::collectCatalogKeys('ru'),
        ];
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private static function stringList($list): array
    {
        $out = [];
        if (!is_array($list)) {
            return $out;
        }
        foreach ($list as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }
}
