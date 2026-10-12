<?php
declare(strict_types=1);

/**
 * Facade: validate KPI recipes (JSON). PHP source path removed.
 */
final class FamilyValidator
{
    /**
     * @param array<string,mixed>|string $recipe
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,recipe?:array<string,mixed>,dry_run?:array<string,mixed>}
     */
    public static function validateRecipe($recipe, string $expectedId = '', bool $dryRun = true): array
    {
        return BiRecipeValidator::validate($recipe, $expectedId, $dryRun);
    }

    /**
     * @deprecated Use validateRecipe
     * @return array{ok:bool,errors:list<string>,warnings:list<string>}
     */
    public static function validateSource(string $source, string $expectedId = '', bool $dryRun = true): array
    {
        return self::validateRecipe($source, $expectedId, $dryRun);
    }

    /**
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,dry_run?:array<string,mixed>}
     */
    public static function validateFamily(ReportFamily $family, bool $dryRun = true): array
    {
        if ($family instanceof RecipeFamily) {
            return self::validateRecipe($family->recipe(), $family->id(), $dryRun);
        }
        $errors = [];
        $warnings = [];
        $dry = null;
        if ($dryRun) {
            ReportProgress::beginCollect();
            try {
                if (!$family->isReady()) {
                    $warnings[] = 'not_ready';
                } else {
                    $result = $family->run(ReportParams::normalize([
                        'year' => (int)date('Y'),
                        'month' => (int)date('n'),
                    ]));
                    if (!is_array($result['sections'] ?? null)) {
                        $errors[] = 'run_missing_sections';
                    } else {
                        $dry = [
                            'sections' => count($result['sections']),
                            'title' => (string)($result['title'] ?? ''),
                        ];
                    }
                }
            } catch (Throwable $e) {
                $errors[] = 'dry_run: ' . $e->getMessage();
            }
            ReportProgress::reset();
        }
        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings, 'dry_run' => $dry];
    }

    /**
     * @return array<string,mixed>
     */
    public static function defaultRecipe(string $familyId, string $name = ''): array
    {
        return BiDatasetCatalog::blankRecipe(
            BiFamilyDefsRepository::safeId($familyId) !== '' ? BiFamilyDefsRepository::safeId($familyId) : 'my_report',
            $name !== '' ? $name : 'My report'
        );
    }

    public static function defaultTemplate(string $familyId, string $name = ''): string
    {
        return json_encode(self::defaultRecipe($familyId, $name), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
    }
}
