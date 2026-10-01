<?php
declare(strict_types=1);

/**
 * Shared paging + column sort for list/CRUD screens.
 *
 * Sort keys from the request are mapped to SQL expressions via an allow-list —
 * never interpolate raw GET values into ORDER BY.
 *
 * Per-page size is remembered per list_id in cookie `freesaas_pp`.
 */
final class ListQuery
{
    private const COOKIE = 'freesaas_pp';
    private const COOKIE_TTL = 86400 * 400; // ~13 months

    /** @return list<int> */
    public static function perPageChoices(): array
    {
        $out = [];
        $i = 0;
        $step = 10;
        $limit = 3000;
        while ($i < $limit) {
            if ($i < 50) {
                $step = 10;
            } elseif ($i < 150) {
                $step = 25;
            } elseif ($i < 600) {
                $step = 50;
            } elseif ($i < 1000) {
                $step = 100;
            } else {
                $step = 500;
            }
            $i += $step;
            if ($i > $limit) {
                break;
            }
            $out[] = $i;
        }
        // Ensure common sizes exist even if stepping skipped them.
        foreach ([10, 25, 50, 100, 200, 500, 1000, 2000, 3000] as $n) {
            $out[] = $n;
        }
        $out = array_values(array_unique($out));
        sort($out, SORT_NUMERIC);
        return $out;
    }

    /**
     * @param array{
     *   list_id?: string,
     *   allowed?: array<string,string>,
     *   default?: string,
     *   default_dir?: string,
     *   per_page?: int,
     *   max_per_page?: int,
     *   sort_param?: string,
     *   dir_param?: string,
     *   page_param?: string,
     *   get?: array<string,mixed>
     * } $opts
     * @return array{
     *   page:int,
     *   per_page:int,
     *   offset:int,
     *   show_all:bool,
     *   sort:string,
     *   dir:string,
     *   order_sql:string,
     *   list_id:string,
     *   query:array<string,string|int>
     * }
     */
    public static function resolve(array $opts = []): array
    {
        $get = $opts['get'] ?? $_GET;
        if (!is_array($get)) {
            $get = [];
        }
        $listId = preg_replace('/[^a-z0-9_\-]/i', '', (string)($opts['list_id'] ?? 'list')) ?: 'list';

        $allowed = $opts['allowed'] ?? [];
        if (!is_array($allowed) || $allowed === []) {
            $allowed = ['id' => 'id'];
        }
        $default = (string)($opts['default'] ?? array_key_first($allowed));
        if (!isset($allowed[$default])) {
            $default = (string)array_key_first($allowed);
        }
        $defaultDir = strtolower((string)($opts['default_dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $sortParam = (string)($opts['sort_param'] ?? 'sort');
        $dirParam = (string)($opts['dir_param'] ?? 'dir');
        $pageParam = (string)($opts['page_param'] ?? 'page');

        $sort = (string)($get[$sortParam] ?? $default);
        if (!isset($allowed[$sort])) {
            $sort = $default;
        }
        $dirRaw = strtolower((string)($get[$dirParam] ?? $defaultDir));
        $dir = $dirRaw === 'desc' ? 'desc' : 'asc';

        $pageRaw = (string)($get[$pageParam] ?? '1');
        $perRaw = $get['per_page'] ?? $get['page_limit'] ?? null;

        // «Все» can come as per_page=all (from size select) or page=all.
        $showAll = strtolower($pageRaw) === 'all'
            || ($perRaw !== null && strtolower((string)$perRaw) === 'all');

        $defaultPerPage = max(1, (int)($opts['per_page'] ?? 50));
        $maxPerPage = max($defaultPerPage, (int)($opts['max_per_page'] ?? 3000));

        if ($showAll) {
            // Keep last numeric size in cookie; don't store "all" as per_page.
            $perPage = self::rememberedPerPage($listId) ?? $defaultPerPage;
            $perPage = max(1, min($maxPerPage, $perPage));
            $page = 1;
            $offset = 0;
        } else {
            $perPage = self::resolvePerPage($listId, $get, $defaultPerPage, $maxPerPage);
            $page = max(1, (int)$pageRaw);
            $offset = ($page - 1) * $perPage;
        }

        $orderExpr = (string)$allowed[$sort];
        $orderSql = $orderExpr . ' ' . strtoupper($dir);

        $query = ['sort' => $sort, 'dir' => $dir, 'per_page' => $perPage];
        if ($showAll) {
            $query['page'] = 'all';
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => $offset,
            'show_all' => $showAll,
            'sort' => $sort,
            'dir' => $dir,
            'order_sql' => $orderSql,
            'list_id' => $listId,
            'query' => $query,
        ];
    }

    /**
     * SQL limit: 0 means no LIMIT (show all).
     *
     * @param array{show_all?:bool,per_page?:int} $lq
     */
    public static function sqlLimit(array $lq): int
    {
        if (!empty($lq['show_all'])) {
            return 0;
        }
        return max(1, (int)($lq['per_page'] ?? 50));
    }

    /**
     * @param array{page:int,per_page:int,offset:int,show_all?:bool} $lq
     * @return array{page:int,per_page:int,offset:int,show_all?:bool}
     */
    public static function clampPage(array $lq, int $total): array
    {
        if (!empty($lq['show_all'])) {
            $lq['page'] = 1;
            $lq['offset'] = 0;
            return $lq;
        }
        $perPage = max(1, (int)$lq['per_page']);
        $pages = max(1, (int)ceil(max(0, $total) / $perPage));
        $page = min(max(1, (int)$lq['page']), $pages);
        $lq['page'] = $page;
        $lq['offset'] = ($page - 1) * $perPage;
        return $lq;
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $overrides
     */
    public static function url(string $path, array $base = [], array $overrides = []): string
    {
        $q = array_merge($base, $overrides);
        foreach ($q as $k => $v) {
            if ($v === null || $v === '' || $v === false) {
                unset($q[$k]);
                continue;
            }
            if (($k === 'pid') && (int)$v < 1) {
                unset($q[$k]);
                continue;
            }
            if ($k === 'page' && (string)$v !== 'all' && (int)$v === 1) {
                unset($q[$k]);
            }
        }
        if ($q === []) {
            return $path;
        }
        return $path . '?' . http_build_query($q);
    }

    /**
     * Full-width pager: compact page chips + size select (incl. «Все»).
     *
     * @param array<string,mixed> $query Current filters + sort (page omitted/overridden).
     */
    public static function pager(
        int $total,
        int $page,
        int $perPage,
        string $path,
        array $query = [],
        bool $showAll = false
    ): string {
        if ($total < 1) {
            return '';
        }

        $perPage = max(1, $perPage);
        $pages = max(1, (int)ceil($total / $perPage));
        if (!$showAll) {
            $page = min(max(1, $page), $pages);
        }

        $base = $query;
        unset($base['page']);
        $base['per_page'] = $perPage;

        if ($showAll) {
            $from = 1;
            $to = $total;
        } else {
            $from = ($page - 1) * $perPage + 1;
            $to = min($total, $page * $perPage);
        }

        $prevHref = (!$showAll && $page > 1)
            ? self::url($path, $base, ['page' => $page - 1])
            : '';
        $nextHref = (!$showAll && $page < $pages)
            ? self::url($path, $base, ['page' => $page + 1])
            : '';

        $chip = static function (string $label, ?string $href, bool $current = false, bool $disabled = false, string $extraClass = ''): string {
            $cls = trim('pager-chip ' . $extraClass . ($current ? ' is-current' : '') . ($disabled ? ' is-disabled' : ''));
            if ($current) {
                return '<span class="' . e($cls) . '" aria-current="page">' . e($label) . '</span>';
            }
            if ($disabled || $href === null || $href === '') {
                return '<span class="' . e($cls) . '" aria-disabled="true">' . e($label) . '</span>';
            }
            return '<a class="' . e($cls) . '" href="' . e($href) . '">' . e($label) . '</a>';
        };

        $nav = '';
        if ($pages > 1 && !$showAll) {
            $parts = [];
            $parts[] = $chip('‹', $prevHref !== '' ? $prevHref : null, false, $prevHref === '', 'pager-arrow');
            $window = self::pageWindow($page, $pages);
            $prevN = 0;
            foreach ($window as $p) {
                if ($prevN > 0 && $p > $prevN + 1) {
                    $parts[] = '<span class="pager-gap" aria-hidden="true">…</span>';
                }
                $parts[] = $chip((string)$p, self::url($path, $base, ['page' => $p]), $p === $page);
                $prevN = $p;
            }
            $parts[] = $chip('›', $nextHref !== '' ? $nextHref : null, false, $nextHref === '', 'pager-arrow');
            $nav = '<div class="pager-nav">' . implode('', $parts) . '</div>';
        }

        $sizeSelect = self::perPageSelectHtml($perPage, $path, $base, $showAll, $total);

        $attrs = ' class="pager" aria-label="' . e(t('list.pager.aria')) . '"';
        if ($prevHref !== '') {
            $attrs .= ' data-pager-prev="' . e($prevHref) . '"';
        }
        if ($nextHref !== '') {
            $attrs .= ' data-pager-next="' . e($nextHref) . '"';
        }

        return '<nav' . $attrs . '>'
            . '<div class="pager-row">'
            . ($nav !== '' ? $nav : '<div class="pager-nav pager-nav-empty"></div>')
            . '<div class="pager-aside">'
            . '<span class="pager-meta">' . e((string)$from) . '–' . e((string)$to) . ' ' . e(t('list.pager.of')) . ' ' . e((string)$total) . '</span>'
            . $sizeSelect
            . '</div>'
            . '</div>'
            . '</nav>';
    }

    /**
     * @param array<string,mixed> $query
     */
    public static function sortTh(
        string $label,
        string $key,
        string $currentSort,
        string $currentDir,
        string $path,
        array $query = []
    ): string {
        $isActive = $currentSort === $key;
        $nextDir = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
        $href = self::url($path, $query, [
            'sort' => $key,
            'dir' => $nextDir,
            'page' => 1,
        ]);
        $aria = $isActive
            ? ($currentDir === 'asc' ? 'ascending' : 'descending')
            : 'none';
        $cls = 'th-sort' . ($isActive ? ' is-active is-' . $currentDir : '');
        $mark = '';
        if ($isActive) {
            $mark = $currentDir === 'asc' ? ' ↑' : ' ↓';
        }
        return '<th class="' . e($cls) . '" aria-sort="' . e($aria) . '">'
            . '<a href="' . e($href) . '">' . e($label) . e($mark) . '</a>'
            . '</th>';
    }

    public static function sortHiddenInputs(string $sort, string $dir, ?int $perPage = null): string
    {
        $html = '<input type="hidden" name="sort" value="' . e($sort) . '">'
            . '<input type="hidden" name="dir" value="' . e($dir) . '">';
        if ($perPage !== null && $perPage > 0) {
            $html .= '<input type="hidden" name="per_page" value="' . (int)$perPage . '">';
        }
        return $html;
    }

    /**
     * Which optional columns have at least one non-empty value among $rows
     * (typically the current page of a list table).
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,string|callable> $columns column id => row field name or extractor(row): mixed
     * @return array<string,bool> column id => has data
     */
    public static function columnsWithData(array $rows, array $columns): array
    {
        $out = [];
        foreach ($columns as $id => $spec) {
            $out[(string)$id] = false;
        }
        if ($rows === [] || $columns === []) {
            return $out;
        }
        foreach ($columns as $id => $spec) {
            $key = (string)$id;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (is_callable($spec)) {
                    $value = $spec($row);
                } else {
                    $value = $row[(string)$spec] ?? null;
                }
                if (!self::isEmptyColumnValue($value)) {
                    $out[$key] = true;
                    break;
                }
            }
        }
        return $out;
    }

    /** @param mixed $value */
    private static function isEmptyColumnValue($value): bool
    {
        if ($value === null || $value === false || $value === '') {
            return true;
        }
        if (is_array($value)) {
            return $value === [];
        }
        if (is_int($value) || is_float($value)) {
            return (float)$value == 0.0;
        }
        if (is_string($value)) {
            $t = trim($value);
            return $t === '' || $t === '—' || $t === '-';
        }
        return false;
    }

    /**
     * @param array<string,mixed> $get
     */
    private static function resolvePerPage(string $listId, array $get, int $default, int $max): int
    {
        $raw = $get['per_page'] ?? $get['page_limit'] ?? null;
        if ($raw !== null && $raw !== '' && strtolower((string)$raw) !== 'all') {
            $n = max(1, min($max, (int)$raw));
            self::rememberPerPage($listId, $n);
            return $n;
        }
        $remembered = self::rememberedPerPage($listId);
        if ($remembered !== null) {
            return max(1, min($max, $remembered));
        }
        return max(1, min($max, $default));
    }

    private static function rememberedPerPage(string $listId): ?int
    {
        $map = self::readCookieMap();
        if (!isset($map[$listId])) {
            return null;
        }
        $n = (int)$map[$listId];
        return $n > 0 ? $n : null;
    }

    private static function rememberPerPage(string $listId, int $n): void
    {
        $map = self::readCookieMap();
        if (isset($map[$listId]) && (int)$map[$listId] === $n) {
            return;
        }
        $map[$listId] = $n;
        $payload = json_encode($map, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE, $payload, [
            'expires' => time() + self::COOKIE_TTL,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $payload;
    }

    /** @return array<string,int> */
    private static function readCookieMap(): array
    {
        $raw = (string)($_COOKIE[self::COOKIE] ?? '');
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $k => $v) {
            $key = preg_replace('/[^a-z0-9_\-]/i', '', (string)$k);
            $n = (int)$v;
            if ($key !== '' && $n > 0) {
                $out[$key] = $n;
            }
        }
        return $out;
    }

    /**
     * Compact window: ends + current±1 + one jump each side.
     *
     * @return list<int>
     */
    private static function pageWindow(int $page, int $pages): array
    {
        if ($pages <= 9) {
            return range(1, $pages);
        }

        $jump = max(5, (int)ceil($pages / 10));
        $set = [
            1 => true,
            $pages => true,
        ];
        for ($i = $page - 1; $i <= $page + 1; $i++) {
            if ($i >= 1 && $i <= $pages) {
                $set[$i] = true;
            }
        }
        $left = $page - $jump;
        $right = $page + $jump;
        if ($left > 1) {
            $set[$left] = true;
        }
        if ($right < $pages) {
            $set[$right] = true;
        }

        $out = array_keys($set);
        sort($out, SORT_NUMERIC);
        return $out;
    }

    /**
     * Size select as GET form (works without JS). Includes «Все».
     *
     * @param array<string,mixed> $base
     */
    private static function perPageSelectHtml(
        int $perPage,
        string $path,
        array $base,
        bool $showAll,
        int $total
    ): string {
        $choices = self::perPageChoices();
        if (!in_array($perPage, $choices, true)) {
            $choices[] = $perPage;
            sort($choices, SORT_NUMERIC);
        }

        $hidden = '';
        foreach ($base as $k => $v) {
            if ($k === 'per_page' || $k === 'page' || $v === null || $v === '') {
                continue;
            }
            $hidden .= '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">';
        }

        $options = '<option value="all"' . ($showAll ? ' selected' : '') . '>'
            . e(t('list.pager.all', ['total' => (string)(int)$total])) . '</option>';
        foreach ($choices as $n) {
            $sel = (!$showAll && $n === $perPage) ? ' selected' : '';
            $options .= '<option value="' . (int)$n . '"' . $sel . '>' . (int)$n . '</option>';
        }

        return '<form class="pager-size" method="get" action="' . e($path) . '">'
            . $hidden
            . '<label class="pager-size-label">'
            . '<span>' . e(t('list.pager.per_page')) . '</span>'
            . '<select name="per_page" class="pager-size-select" aria-label="' . e(t('list.pager.per_page_aria')) . '" onchange="this.form.submit()">'
            . $options
            . '</select>'
            . '</label>'
            . '</form>';
    }
}
