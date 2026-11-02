<?php
declare(strict_types=1);

/**
 * Surface A (/agent/bi/*) — legend / family CRUD / run via agent_token.
 * See docs/agents/bi.md.
 */
final class BiAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/bi/legend', 'bi.agent.legend', [self::class, 'legend'], [
            'description' => 'BI constructor legend (datasets, expr, derive, examples)',
        ]);
        AgentOps::registerModuleOp('GET', '/agent/bi/families', 'bi.agent.families', [self::class, 'families'], [
            'description' => 'List shipped + DB BI families (full catalog)',
        ]);
        AgentOps::registerModuleOp('GET', '/agent/bi/family', 'bi.agent.family.get', [self::class, 'familyGet'], [
            'description' => 'Load one family recipe. Query: id=',
            'query' => ['id'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/bi/family/validate', 'bi.agent.family.validate', [self::class, 'familyValidate'], [
            'description' => 'Validate recipe JSON without write. Body: family_id, name?, recipe|recipe_json',
            'body' => ['family_id', 'name', 'recipe', 'recipe_json'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/bi/family/save', 'bi.agent.family.save', [self::class, 'familySave'], [
            'description' => 'Validate + upsert base_bi_family_defs. Body: family_id, name?, recipe|recipe_json, enabled?, def_id?, builtin_fork?',
            'body' => ['family_id', 'name', 'recipe', 'recipe_json', 'enabled', 'def_id', 'builtin_fork'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/bi/family/delete', 'bi.agent.family.delete', [self::class, 'familyDelete'], [
            'description' => 'Delete DB override. Body: def_id or id (family_id)',
            'body' => ['def_id', 'id'],
        ]);
        AgentOps::registerModuleOp('POST', '/agent/bi/run', 'bi.agent.run', [self::class, 'run'], [
            'description' => 'Run a BI family. Body: family, year, month (+ optional filters)',
            'body' => ['family', 'year', 'month', 'dayshow', 'id_operator', 'id_admin', 'id_good', 'variant'],
        ]);

        $routes = [
            '/agent/bi/legend' => ['GET'],
            '/agent/bi/families' => ['GET'],
            '/agent/bi/family' => ['GET'],
            '/agent/bi/family/validate' => ['POST'],
            '/agent/bi/family/save' => ['POST'],
            '/agent/bi/family/delete' => ['POST'],
            '/agent/bi/run' => ['POST'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'bi',
                'public' => true,
                'csrf' => false,
                'methods' => $methods,
                'handler' => static function (): void {
                    require_once base_path('app/agent_http.php');
                },
            ]);
        }
    }

    /** @return array<string,mixed> */
    public static function legend(): array
    {
        return [
            'ok' => true,
            'legend' => BiDatasetCatalog::legend(),
            'surface' => 'A',
            'hint' => [
                'validate' => 'POST /agent/bi/family/validate',
                'save' => 'POST /agent/bi/family/save',
                'run' => 'POST /agent/bi/run',
                'docs' => 'docs/agents/bi.md',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function families(): array
    {
        return [
            'ok' => true,
            'mode' => 'agent',
            'families' => ReportRegistry::catalog(),
            'hint' => [
                'get' => 'GET /agent/bi/family?id=',
                'legend' => 'GET /agent/bi/legend',
                'save' => 'POST /agent/bi/family/save',
                'run' => 'POST /agent/bi/run',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function familyGet(): array
    {
        $in = AgentOps::requestInput();
        $familyId = BiFamilyDefsRepository::safeId((string)($in['id'] ?? ''));
        if ($familyId === '') {
            return ['ok' => false, 'error' => 'id required', 'http' => 400];
        }

        $def = BiFamilyDefsRepository::findByFamilyId($familyId);
        $shippedRecipe = BiShippedRecipes::get($familyId);
        $shipped = BiShippedRecipes::isShipped($familyId);

        if ($def === null && $shippedRecipe === null) {
            return ['ok' => false, 'error' => 'unknown_family', 'http' => 404];
        }

        $defId = $def !== null ? (int)$def['ID'] : 0;
        $name = $def !== null
            ? (string)$def['NAME']
            : (string)($shippedRecipe['title'] ?? $familyId);
        $recipe = $def !== null && is_array($def['RECIPE']) && $def['RECIPE'] !== []
            ? $def['RECIPE']
            : ($shippedRecipe ?? BiDatasetCatalog::blankRecipe($familyId, $name));
        $enabled = $def !== null ? (int)$def['ENABLED'] : 1;

        return [
            'ok' => true,
            'def_id' => $defId,
            'family_id' => $familyId,
            'name' => $name,
            'recipe' => $recipe,
            'enabled' => $enabled,
            'shipped' => $shipped,
            'has_db_override' => $defId > 0,
            'is_new' => $defId < 1 && !$shipped,
            'hint' => [
                'validate' => 'POST /agent/bi/family/validate',
                'save' => 'POST /agent/bi/family/save',
                'delete_override' => 'POST /agent/bi/family/delete',
                'run' => 'POST /agent/bi/run family=' . $familyId,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function familyValidate(): array
    {
        $parsed = self::parseRecipeInput();
        if (empty($parsed['ok'])) {
            return $parsed;
        }
        $familyId = (string)$parsed['family_id'];
        $name = (string)$parsed['name'];
        $result = FamilyValidator::validateRecipe($parsed['recipe_text'], $familyId, true);
        if (!empty($result['recipe']) && is_array($result['recipe'])) {
            $recipe = $result['recipe'];
            $recipe['id'] = $familyId;
            if ($name !== '') {
                $recipe['title'] = $name;
            }
            $result['recipe'] = $recipe;
        }

        return [
            'ok' => !empty($result['ok']),
            'action' => 'validate',
            'family_id' => $familyId,
            'result' => $result,
            'http' => !empty($result['ok']) ? 200 : 400,
        ];
    }

    /** @return array<string,mixed> */
    public static function familySave(): array
    {
        if (!BiFamilyDefsRepository::tableReady() || !BiFamilyDefsRepository::hasRecipeColumn()) {
            return ['ok' => false, 'error' => 'family_defs_missing', 'http' => 503];
        }

        $parsed = self::parseRecipeInput();
        if (empty($parsed['ok'])) {
            return $parsed;
        }
        $familyId = (string)$parsed['family_id'];
        $name = (string)$parsed['name'];
        $in = $parsed['in'];
        $enabled = !isset($in['enabled']) || !empty($in['enabled']) ? 1 : 0;
        $builtinFork = trim((string)($in['builtin_fork'] ?? ''));
        $defId = (int)($in['def_id'] ?? 0);

        $result = FamilyValidator::validateRecipe($parsed['recipe_text'], $familyId, true);
        if (empty($result['ok']) || !is_array($result['recipe'] ?? null)) {
            return [
                'ok' => false,
                'error' => 'validate_failed',
                'action' => 'save',
                'result' => $result,
                'http' => 400,
            ];
        }

        $toSave = $result['recipe'];
        $toSave['id'] = $familyId;
        if ($name !== '') {
            $toSave['title'] = $name;
        }

        $msgParts = [];
        if (!empty($result['warnings']) && is_array($result['warnings'])) {
            $msgParts[] = 'warnings: ' . implode('; ', $result['warnings']);
        }
        if (!empty($result['dry_run']) && is_array($result['dry_run'])) {
            $msgParts[] = 'dry_run sections=' . (int)($result['dry_run']['sections'] ?? 0);
        }

        try {
            $savedId = BiFamilyDefsRepository::save([
                'FAMILY_ID' => $familyId,
                'NAME' => $name !== '' ? $name : $familyId,
                'RECIPE_JSON' => $toSave,
                'ENABLED' => $enabled,
                'BUILTIN_FORK' => $builtinFork,
                'LAST_VALIDATE_OK' => 1,
                'LAST_VALIDATE_MSG' => implode(' | ', $msgParts),
            ], $defId);

            return [
                'ok' => true,
                'action' => 'save',
                'id' => $savedId,
                'family_id' => $familyId,
                'result' => $result,
            ];
        } catch (InvalidArgumentException $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'action' => 'save',
                'http' => 400,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => 'save_failed',
                'detail' => $e->getMessage(),
                'action' => 'save',
                'http' => 500,
            ];
        }
    }

    /** @return array<string,mixed> */
    public static function familyDelete(): array
    {
        $in = AgentOps::requestInput();
        $defId = (int)($in['def_id'] ?? 0);
        if ($defId < 1) {
            $familyId = BiFamilyDefsRepository::safeId((string)($in['id'] ?? ''));
            if ($familyId !== '') {
                $def = BiFamilyDefsRepository::findByFamilyId($familyId);
                $defId = $def !== null ? (int)$def['ID'] : 0;
            }
        }
        if ($defId < 1) {
            return ['ok' => false, 'error' => 'def_id or id required', 'http' => 400];
        }
        if (!BiFamilyDefsRepository::delete($defId)) {
            return ['ok' => false, 'error' => 'not_found', 'http' => 404];
        }

        return ['ok' => true, 'action' => 'delete', 'id' => $defId];
    }

    /** @return array<string,mixed> */
    public static function run(): array
    {
        $in = AgentOps::requestInput();
        $familyId = BiFamilyDefsRepository::safeId((string)($in['family'] ?? $in['id'] ?? ''));
        if ($familyId === '') {
            return ['ok' => false, 'error' => 'family required', 'http' => 400];
        }
        if (ReportRegistry::get($familyId) === null) {
            return ['ok' => false, 'error' => 'unknown_family', 'http' => 404];
        }

        $params = ReportParams::normalize($in);
        ReportProgress::beginCollect();
        try {
            $report = ReportRegistry::run($familyId, $params);
            $sections = is_array($report['sections'] ?? null) ? $report['sections'] : [];
            $summary = [];
            foreach ($sections as $sec) {
                if (!is_array($sec)) {
                    continue;
                }
                $type = (string)($sec['type'] ?? '');
                $row = [
                    'type' => $type,
                    'title' => (string)($sec['title'] ?? ''),
                ];
                if ($type === 'table') {
                    $row['rows'] = count(is_array($sec['rows'] ?? null) ? $sec['rows'] : []);
                    $row['columns'] = count(is_array($sec['columns'] ?? null) ? $sec['columns'] : []);
                } elseif ($type === 'metrics') {
                    $row['metrics'] = count(is_array($sec['metrics'] ?? null) ? $sec['metrics'] : []);
                } elseif ($type === 'text') {
                    $row['body_len'] = mb_strlen((string)($sec['body'] ?? ''), 'UTF-8');
                }
                $summary[] = $row;
            }

            return [
                'ok' => true,
                'action' => 'run',
                'family' => $familyId,
                'params' => $params,
                'report' => $report,
                'section_summary' => $summary,
                'stages' => ReportProgress::collectStages(),
            ];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 500];
        } finally {
            ReportProgress::reset();
        }
    }

    /**
     * @return array{ok:bool,family_id?:string,name?:string,recipe_text?:string,in?:array<string,mixed>,error?:string,http?:int}
     */
    private static function parseRecipeInput(): array
    {
        $in = AgentOps::requestInput();
        $familyId = BiFamilyDefsRepository::safeId((string)($in['family_id'] ?? $in['id'] ?? ''));
        if ($familyId === '' || strlen($familyId) < 2) {
            return ['ok' => false, 'error' => 'family_id required', 'http' => 400];
        }
        $name = trim((string)($in['name'] ?? ''));
        if (isset($in['recipe']) && is_array($in['recipe'])) {
            $recipeText = json_encode($in['recipe'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        } elseif (isset($in['recipe_json'])) {
            $recipeText = is_array($in['recipe_json'])
                ? (json_encode($in['recipe_json'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
                : (string)$in['recipe_json'];
        } else {
            return ['ok' => false, 'error' => 'recipe or recipe_json required', 'http' => 400];
        }

        return [
            'ok' => true,
            'family_id' => $familyId,
            'name' => $name,
            'recipe_text' => $recipeText,
            'in' => $in,
        ];
    }
}
