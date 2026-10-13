<?php
declare(strict_types=1);

/**
 * Staff help catalog from module.json → agent.docs (docs/agents/*.md).
 * Single source of truth for “how do I…” in assistant / MCP — no duplicated guides.
 */
final class HelpDocs
{
    private const MAX_BODY = 16000;

    /**
     * Resolve and validate agent.docs path for a module (allowlist docs/agents/*.md only).
     */
    public static function docsRelPath(string $moduleId): ?string
    {
        $meta = ModuleManager::get($moduleId);
        if ($meta === null || empty($meta['_enabled'])) {
            return null;
        }
        $rel = trim((string)($meta['agent']['docs'] ?? ''));
        if ($rel === '') {
            return null;
        }
        return self::normalizeDocsPath($rel);
    }

    /**
     * @return string|null Relative path under repo root, or null if invalid
     */
    public static function normalizeDocsPath(string $rel): ?string
    {
        $rel = str_replace('\\', '/', trim($rel));
        $rel = ltrim($rel, '/');
        if ($rel === '' || strpos($rel, '..') !== false) {
            return null;
        }
        if (!preg_match('#^docs/agents/[a-z0-9][a-z0-9._-]*\.md$#i', $rel)) {
            return null;
        }
        $abs = base_path($rel);
        if (!is_file($abs) || !is_readable($abs)) {
            return null;
        }
        $real = realpath($abs);
        $root = realpath(base_path('docs/agents'));
        if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $rel;
    }

    public static function userCanAccessModule(string $moduleId): bool
    {
        $meta = ModuleManager::get($moduleId);
        if ($meta === null || empty($meta['_enabled'])) {
            return false;
        }
        if (Auth::capability('SUPERADMIN_ACCESS')) {
            return true;
        }
        $sawPerm = false;
        foreach ($meta['permissions'] ?? [] as $p) {
            $id = is_array($p) ? trim((string)($p['id'] ?? '')) : '';
            if ($id === '') {
                continue;
            }
            $sawPerm = true;
            if (Auth::can($id)) {
                return true;
            }
        }
        foreach ($meta['menu'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $perm = trim((string)($item['permission'] ?? ''));
            if ($perm !== '' && Auth::canSeeInMenu($perm)) {
                return true;
            }
        }
        // Enabled module with no declared permissions/menu — rare; allow summary only.
        return !$sawPerm;
    }

    /**
     * Catalog entries the current user may see.
     *
     * @return list<array{
     *   module:string,docs:?string,title:string,summary:string,has_docs:bool,
     *   headings:list<string>,menu:list<array{title:string,path:string}>,score:int
     * }>
     */
    public static function search(string $q = '', ?string $moduleFilter = null): array
    {
        $qNorm = self::normQuery($q);
        $out = [];
        foreach (ModuleManager::all() as $moduleId => $meta) {
            $moduleId = (string)$moduleId;
            if (empty($meta['_enabled'])) {
                continue;
            }
            if ($moduleFilter !== null && $moduleFilter !== '' && $moduleId !== $moduleFilter) {
                continue;
            }
            if (!self::userCanAccessModule($moduleId)) {
                continue;
            }
            $docsRel = self::docsRelPath($moduleId);
            $title = self::moduleTitle($moduleId, $meta);
            $summary = self::moduleSummary($moduleId, $meta);
            $headings = [];
            $bodyForScore = $title . "\n" . $summary . "\n" . $moduleId;
            if ($docsRel !== null) {
                $md = self::readFile($docsRel);
                if ($md !== null) {
                    $headings = self::extractHeadings($md);
                    $bodyForScore .= "\n" . implode("\n", $headings) . "\n" . $md;
                }
            }
            $score = $qNorm === '' ? 1 : self::scoreMatch($qNorm, $bodyForScore, $moduleId);
            if ($qNorm !== '' && $score <= 0) {
                continue;
            }
            $menu = self::visibleMenu($meta);
            $botCab = class_exists('AssistantLinks', false)
                ? AssistantLinks::botCabinetLabelForModule($moduleId)
                : '';
            $out[] = [
                'module' => $moduleId,
                'docs' => $docsRel,
                'title' => $title,
                'summary' => $summary,
                'has_docs' => $docsRel !== null,
                'headings' => $headings,
                'menu' => $menu,
                'bot_cabinet' => $botCab !== '' ? $botCab : null,
                'score' => $score,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return strcmp($a['module'], $b['module']);
        });
        return $out;
    }

    /**
     * Full or section help payload for one module.
     *
     * @return array<string,mixed>|null
     */
    public static function get(string $moduleId, string $section = 'all', int $maxChars = self::MAX_BODY): ?array
    {
        $moduleId = trim($moduleId);
        if ($moduleId === '' || !self::userCanAccessModule($moduleId)) {
            return null;
        }
        $meta = ModuleManager::get($moduleId);
        if ($meta === null) {
            return null;
        }
        $docsRel = self::docsRelPath($moduleId);
        $title = self::moduleTitle($moduleId, $meta);
        $summary = self::moduleSummary($moduleId, $meta);
        $menu = self::visibleMenu($meta);
        $botCab = class_exists('AssistantLinks', false)
            ? AssistantLinks::botCabinetLabelForModule($moduleId)
            : '';
        $section = strtolower(trim($section));
        if ($section === '') {
            $section = 'all';
        }

        $markdown = null;
        $sectionUsed = null;
        if ($docsRel !== null) {
            $raw = self::readFile($docsRel);
            if ($raw !== null) {
                if ($section === 'human' || $section === 'human_ui') {
                    $extracted = self::extractSection($raw, 'Human UI');
                    if ($extracted !== null) {
                        $markdown = $extracted;
                        $sectionUsed = 'Human UI';
                    } else {
                        $markdown = $raw;
                        $sectionUsed = 'all';
                    }
                } else {
                    $markdown = $raw;
                    $sectionUsed = 'all';
                }
            }
        }

        if ($markdown !== null && class_exists('AssistantLinks', false)) {
            $markdown = AssistantLinks::absolutize($markdown);
        }
        if ($markdown !== null && $botCab !== '') {
            $markdown .= "\n\nStaff bot cabinet alternative: «" . $botCab . "» (Telegram/Max menu).";
        }
        if ($markdown !== null && $maxChars > 0 && mb_strlen($markdown) > $maxChars) {
            $markdown = mb_substr($markdown, 0, $maxChars) . "\n\n…";
        }

        return [
            'ok' => true,
            'module' => $moduleId,
            'title' => $title,
            'summary' => $summary,
            'docs' => $docsRel,
            'has_docs' => $docsRel !== null,
            'section' => $sectionUsed,
            'menu' => $menu,
            'bot_cabinet' => $botCab !== '' ? $botCab : null,
            'markdown' => $markdown,
            'fallback' => $docsRel === null || $markdown === null,
            'hint' => ($docsRel === null || $markdown === null)
                ? 'No runbook on disk; use summary + menu urls only — do not invent steps.'
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function moduleTitle(string $moduleId, array $meta): string
    {
        $name = $meta['name'] ?? '';
        if (is_string($name) && $name !== '' && function_exists('t')) {
            if (strpos($name, '.') !== false) {
                return t($name);
            }
            return $name;
        }
        return $moduleId;
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function moduleSummary(string $moduleId, array $meta): string
    {
        $key = $moduleId . '.module.summary';
        if (function_exists('t')) {
            $s = t($key);
            if ($s !== $key && $s !== '') {
                return $s;
            }
        }
        $desc = $meta['description'] ?? '';
        if (is_string($desc) && $desc !== '' && function_exists('t')) {
            return t($desc);
        }
        return '';
    }

    /**
     * @param array<string,mixed> $meta
     * @return list<array{title:string,path:string,url:string}>
     */
    private static function visibleMenu(array $meta): array
    {
        $out = [];
        foreach ($meta['menu'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $path = trim((string)($item['path'] ?? ''));
            if ($path === '' || $path[0] !== '/') {
                continue;
            }
            $perm = trim((string)($item['permission'] ?? ''));
            if ($perm !== '' && !Auth::canSeeInMenu($perm)) {
                continue;
            }
            $titleKey = (string)($item['title'] ?? '');
            $title = $titleKey !== '' && function_exists('t') ? t($titleKey) : $path;
            $url = class_exists('AppRuntime', false) ? AppRuntime::adminUrl($path) : $path;
            $out[] = ['title' => $title, 'path' => $path, 'url' => $url];
        }
        return $out;
    }

    private static function readFile(string $rel): ?string
    {
        $abs = base_path($rel);
        $raw = @file_get_contents($abs);
        if ($raw === false) {
            return null;
        }
        return str_replace(["\r\n", "\r"], "\n", $raw);
    }

    /**
     * @return list<string>
     */
    private static function extractHeadings(string $md): array
    {
        $out = [];
        if (preg_match_all('/^#{1,3}\s+(.+)$/m', $md, $m)) {
            foreach ($m[1] as $h) {
                $out[] = trim((string)$h);
            }
        }
        return $out;
    }

    private static function extractSection(string $md, string $heading): ?string
    {
        $pattern = '/^##\s+' . preg_quote($heading, '/') . '\s*$/mi';
        if (!preg_match($pattern, $md, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = (int)$m[0][1];
        $rest = substr($md, $start);
        if (preg_match('/\n##\s+/', $rest, $n, PREG_OFFSET_CAPTURE, strlen($m[0][0]))) {
            $rest = substr($rest, 0, (int)$n[0][1]);
        }
        return trim($rest);
    }

    private static function normQuery(string $q): string
    {
        $q = mb_strtolower(trim($q));
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
        return $q;
    }

    private static function scoreMatch(string $qNorm, string $haystack, string $moduleId): int
    {
        $hay = mb_strtolower($haystack);
        $score = 0;
        if ($qNorm === $moduleId || mb_strpos($hay, $qNorm) !== false) {
            $score += 10;
        }
        foreach (preg_split('/\s+/u', $qNorm) ?: [] as $tok) {
            $tok = trim($tok);
            if ($tok === '' || mb_strlen($tok) < 2) {
                continue;
            }
            if (mb_strpos($hay, $tok) !== false) {
                $score += 3;
            }
        }
        return $score;
    }
}
