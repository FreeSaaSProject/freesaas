<?php
declare(strict_types=1);

/**
 * KPI report families: shipped JSON recipes + DB overrides/customs. No PHP runners.
 */
final class ReportRegistry
{
    /** @var array<string,ReportFamily>|null */
    private static $families = null;

    /**
     * @return array<string,ReportFamily>
     */
    public static function all(): array
    {
        if (self::$families === null) {
            self::$families = [];
            self::loadAll();
        }
        return self::$families;
    }

    public static function reloadCustoms(): void
    {
        self::$families = [];
        if (class_exists('BiDatasetCatalog', false)) {
            BiDatasetCatalog::flush();
        }
        if (class_exists('BiShippedRecipes', false)) {
            BiShippedRecipes::flush();
        }
        if (class_exists('BiDeriveRegistry', false)) {
            BiDeriveRegistry::flush();
        }
        self::loadAll();
    }

    /** @deprecated use isShippedId */
    public static function isBuiltinId(string $id): bool
    {
        return self::isShippedId($id);
    }

    public static function isShippedId(string $id): bool
    {
        return BiShippedRecipes::isShipped($id);
    }

    /**
     * @return list<string>
     */
    public static function builtinIds(): array
    {
        return BiShippedRecipes::ids();
    }

    public static function register(ReportFamily $family): void
    {
        if (self::$families === null) {
            self::$families = [];
        }
        self::$families[$family->id()] = $family;
    }

    public static function get(string $id): ?ReportFamily
    {
        $all = self::all();
        return $all[$id] ?? null;
    }

    /**
     * @return list<array{id:string,title:string,ready:bool,ready_note:string,custom:bool,shipped:bool,def_id:int}>
     */
    public static function catalog(): array
    {
        $customMeta = [];
        if (class_exists('BiFamilyDefsRepository', false) && BiFamilyDefsRepository::tableReady()) {
            foreach (BiFamilyDefsRepository::listAll() as $def) {
                $customMeta[(string)$def['FAMILY_ID']] = $def;
            }
        }
        $out = [];
        foreach (self::all() as $f) {
            $noteKey = $f->readyNoteKey();
            $id = $f->id();
            // Recipe titles are plain constructor text (not i18n keys).
            $title = (string)$f->titleKey();
            $shipped = self::isShippedId($id);
            $hasDb = isset($customMeta[$id]);
            $out[] = [
                'id' => $id,
                'title' => $title,
                'ready' => $f->isReady(),
                'ready_note' => $noteKey !== '' ? t($noteKey) : '',
                'custom' => $hasDb && !$shipped,
                'shipped' => $shipped,
                'def_id' => $hasDb ? (int)$customMeta[$id]['ID'] : 0,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function run(string $familyId, array $params): array
    {
        $family = self::get($familyId);
        if ($family === null) {
            throw new InvalidArgumentException('unknown_family');
        }
        if (!$family->isReady()) {
            throw new RuntimeException('family_not_ready');
        }
        $norm = ReportParams::normalize($params);
        ReportProgress::note('Family: ' . $family->id());
        ReportProgress::note('Period: ' . sprintf('%04d-%02d', $norm['year'], $norm['month']));
        $result = $family->run($norm);
        $result['family'] = $family->id();
        $result['params'] = $norm;
        if (!isset($result['title'])) {
            $tk = $family->titleKey();
            $result['title'] = (strpos($tk, '.') !== false) ? t($tk) : $tk;
        } elseif (is_string($result['title']) && strpos($result['title'], '.') !== false && strpos($result['title'], ' ') === false) {
            $result['title'] = t($result['title']);
        }
        return $result;
    }

    private static function loadAll(): void
    {
        // Shipped files first
        foreach (BiShippedRecipes::all() as $recipe) {
            $id = (string)($recipe['id'] ?? '');
            if ($id === '') {
                continue;
            }
            self::register(new RecipeFamily($recipe, (string)($recipe['title'] ?? $id)));
        }
        // DB rows override / add customs
        if (class_exists('BiFamilyDefsRepository', false)
            && BiFamilyDefsRepository::tableReady()
            && BiFamilyDefsRepository::hasRecipeColumn()
        ) {
            foreach (BiFamilyDefsRepository::listEnabled() as $def) {
                $recipe = $def['RECIPE'] ?? [];
                if (!is_array($recipe) || $recipe === []) {
                    continue;
                }
                $recipe['id'] = (string)$def['FAMILY_ID'];
                if (trim((string)($recipe['title'] ?? '')) === '' && (string)$def['NAME'] !== '') {
                    $recipe['title'] = (string)$def['NAME'];
                }
                $label = (string)$def['NAME'];
                if ($label === '') {
                    $label = (string)($recipe['title'] ?? $recipe['id']);
                }
                try {
                    self::register(new RecipeFamily($recipe, $label));
                } catch (Throwable $e) {
                    continue;
                }
            }
        }

    }

    /** @deprecated coded runners removed — keep for run UI edit-hide compatibility */
    public static function isCodedId(string $id): bool
    {
        return false;
    }
}
