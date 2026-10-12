<?php
declare(strict_types=1);

/**
 * ReportFamily adapter for a validated BI JSON recipe.
 * Report titles / labels / text bodies are plain constructor strings (not i18n).
 */
final class RecipeFamily implements ReportFamily
{
    /** @var array<string,mixed> */
    private $recipe;

    /** @var string */
    private $label;

    /**
     * @param array<string,mixed> $recipe
     */
    public function __construct(array $recipe, string $label = '')
    {
        $this->recipe = $recipe;
        $id = (string)($recipe['id'] ?? '');
        $this->label = $label !== '' ? $label : (string)($recipe['title'] ?? $id);
    }

    public function id(): string
    {
        return (string)($this->recipe['id'] ?? '');
    }

    public function titleKey(): string
    {
        return $this->label;
    }

    public function isReady(): bool
    {
        $requires = is_array($this->recipe['requires'] ?? null) ? $this->recipe['requires'] : [];
        if ($requires === []) {
            return true;
        }
        foreach ($requires as $req) {
            if (!BiDatasetCatalog::isReady((string)$req)) {
                return false;
            }
        }
        return true;
    }

    public function readyNoteKey(): string
    {
        if ($this->isReady()) {
            return '';
        }
        $requires = is_array($this->recipe['requires'] ?? null) ? $this->recipe['requires'] : [];
        if (in_array('workweeks', $requires, true)) {
            return 'bi.ready.need_workweeks';
        }
        if (in_array('guests', $requires, true) || in_array('fullstats', $requires, true)) {
            return 'bi.ready.need_conversion';
        }
        return 'bi.ready.need_procedures';
    }

    public function run(array $params): array
    {
        return BiRecipeEngine::run($this->recipe, $params);
    }

    /**
     * @return array<string,mixed>
     */
    public function recipe(): array
    {
        return $this->recipe;
    }
}
