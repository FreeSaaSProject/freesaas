<?php
declare(strict_types=1);

/**
 * Storefront LP: decode MODULES blob, merge clone←parent, render theme promo_modules.
 */
final class PromopageRenderer
{
    /**
     * Effective promo row for public display (parent modules/meta when clone).
     *
     * @return array<string,mixed>|null
     */
    public static function resolveForPublic(?array $promo): ?array
    {
        if ($promo === null) {
            return null;
        }
        if (empty($promo['enabled'])) {
            return null;
        }
        $expire = (int)($promo['dt_expire'] ?? 0);
        if ($expire > 0 && $expire < time()) {
            return null;
        }
        $parentId = (int)($promo['id_parent'] ?? 0);
        if ($parentId < 1) {
            return $promo;
        }
        $parent = PromopagesRepository::find($parentId);
        if ($parent === null || empty($parent['enabled'])) {
            return $promo;
        }
        $code = (string)($promo['code'] ?? '');
        $phone = trim((string)($promo['phone'] ?? ''));
        $merged = $parent;
        $merged['id'] = (int)($promo['id'] ?? 0);
        $merged['code'] = $code;
        $merged['id_parent'] = $parentId;
        if ($phone !== '') {
            $merged['phone'] = $phone;
        }
        $merged['is_clone'] = true;
        return $merged;
    }

    /**
     * @param array<string,mixed> $promo normalized row (after resolveForPublic)
     * @return array{html:string,section_nav:list<array{code:string,label:string,num:int}>}
     */
    public static function render(array $promo): array
    {
        $modules = is_array($promo['modules'] ?? null)
            ? $promo['modules']
            : PromopageModules::decode((string)($promo['modules_raw'] ?? ''));

        $blocks = [];
        $nav = [];
        foreach ($modules as $code => $data) {
            if (!is_string($code) || !is_array($data)) {
                continue;
            }
            $num = (int)($data['NUMBER'] ?? 0);
            if ($num < 1) {
                continue;
            }
            $html = self::captureModule($code, $data, $promo);
            if ($html === '') {
                continue;
            }
            $blocks[$num] = $html;
            $menu = trim((string)($data['MENUNAME'] ?? ''));
            if ($menu !== '') {
                $nav[] = ['code' => $code, 'label' => $menu, 'num' => $num];
            }
        }
        ksort($blocks, SORT_NUMERIC);
        usort($nav, static function (array $a, array $b): int {
            return $a['num'] <=> $b['num'];
        });

        $html = implode("\n", $blocks);
        $html = self::applyPlaceholders($html);

        return ['html' => $html, 'section_nav' => $nav];
    }

    /**
     * @param array<string,mixed> $promo
     */
    public static function bumpShows(array $promo): void
    {
        $id = (int)($promo['id'] ?? 0);
        if ($id < 1 || !Database::tableExists('base_promopages')) {
            return;
        }
        try {
            Database::pdo()->prepare('UPDATE base_promopages SET SHOWS = SHOWS + 1 WHERE ID = ?')->execute([$id]);
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    /** Replace [N] date offsets (legacy replaceData). */
    public static function replaceDateTokens(string $text): string
    {
        return (string)preg_replace_callback(
            '|\[(\d*?)\]|',
            static function (array $m): string {
                $days = (int)($m[1] ?? 0);
                return date('d.m.Y', time() + 3600 * 24 * $days);
            },
            $text
        );
    }

    /**
     * @param array<string,string> $moduleData
     * @param array<string,mixed> $promo
     */
    private static function captureModule(string $code, array $moduleData, array $promo): string
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $code)) {
            return '';
        }
        $file = '';
        if (class_exists(SiteTheme::class, false) || class_exists(SiteTheme::class)) {
            $candidate = SiteTheme::path('promo_modules/' . $code . '.php');
            if (is_file($candidate)) {
                $file = $candidate;
            }
        }
        if ($file === '') {
            $fallback = base_path('modules/sites/views/promo_modules/' . $code . '.php');
            if (is_file($fallback)) {
                $file = $fallback;
            }
        }
        if ($file === '') {
            return '';
        }

        $moduleCode = $code;
        $promoId = (int)($promo['id'] ?? 0);
        ob_start();
        try {
            require $file;
        } catch (Throwable $e) {
            ob_end_clean();
            return '<!-- promo module ' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
                . ' error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . ' -->';
        }
        return (string)ob_get_clean();
    }

    private static function applyPlaceholders(string $html): string
    {
        if ($html === '') {
            return '';
        }
        if (strpos($html, '%Year%') !== false) {
            $html = str_replace('%Year%', date('Y'), $html);
        }
        return $html;
    }
}
