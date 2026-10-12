<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$familyIdParam = trim((string)(Router::param('id') ?? ''));
$isNewRoute = $familyIdParam === '' || $familyIdParam === 'new';

$flash = '';
$error = '';
$validateResult = null;
$readonlyId = false;
$recipe = BiDatasetCatalog::blankRecipe('my_report', 'My report');
$name = '';
$familyId = 'my_report';
$enabled = 1;
$defId = 0;
$builtinFork = '';
$shipped = false;

if (!$isNewRoute) {
    $def = BiFamilyDefsRepository::findByFamilyId($familyIdParam);
    $shippedRecipe = BiShippedRecipes::get($familyIdParam);
    if ($def !== null) {
        $defId = (int)$def['ID'];
        $familyId = (string)$def['FAMILY_ID'];
        $name = (string)$def['NAME'];
        $recipe = is_array($def['RECIPE']) && $def['RECIPE'] !== []
            ? $def['RECIPE']
            : ($shippedRecipe ?? BiDatasetCatalog::blankRecipe($familyId, $name));
        $enabled = (int)$def['ENABLED'];
        $builtinFork = (string)$def['BUILTIN_FORK'];
        $readonlyId = true;
        $shipped = BiShippedRecipes::isShipped($familyId);
    } elseif ($shippedRecipe !== null) {
        $familyId = (string)$shippedRecipe['id'];
        $name = (string)($shippedRecipe['title'] ?? $familyId);
        $recipe = $shippedRecipe;
        $shipped = true;
        $readonlyId = true; // keep shipped id stable
    } else {
        if ($wantsJson) {
            AgentHttp::error(t('bi.err.unknown_family'), 404);
            return;
        }
        redirect('/bi');
    }
}

$recipeText = json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (function_exists('request_raw_body')) {
        $rawBody = request_raw_body();
        if ($rawBody !== '') {
            $decodedBody = json_decode($rawBody, true);
            if (is_array($decodedBody)) {
                foreach ($decodedBody as $k => $v) {
                    if (!array_key_exists((string)$k, $_POST)) {
                        $_POST[(string)$k] = $v;
                    }
                }
            }
        }
    }
    $action = trim((string)($_POST['action'] ?? 'save'));
    $familyId = BiFamilyDefsRepository::safeId((string)($_POST['family_id'] ?? $familyId));
    $name = trim((string)($_POST['name'] ?? $name));
    if (isset($_POST['recipe']) && is_array($_POST['recipe'])) {
        $recipeText = json_encode($_POST['recipe'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
    } elseif (isset($_POST['recipe_json'])) {
        $recipeText = is_array($_POST['recipe_json'])
            ? (json_encode($_POST['recipe_json'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
            : (string)$_POST['recipe_json'];
    }
    $enabled = !empty($_POST['enabled']) ? 1 : 0;
    $builtinFork = trim((string)($_POST['builtin_fork'] ?? $builtinFork));
    $defId = (int)($_POST['def_id'] ?? $defId);
    $shipped = BiShippedRecipes::isShipped($familyId);

    if ($action === 'delete' && $defId > 0) {
        BiFamilyDefsRepository::delete($defId);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'action' => 'delete', 'id' => $defId]);
            return;
        }
        redirect('/bi?flash=family_deleted');
    }

    $validateResult = FamilyValidator::validateRecipe($recipeText, $familyId, true);
    if (!empty($validateResult['recipe']) && is_array($validateResult['recipe'])) {
        $recipe = $validateResult['recipe'];
        $recipe['id'] = $familyId;
        if ($name !== '') {
            $recipe['title'] = $name;
        }
        $recipeText = json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: $recipeText;
    }

    if ($action === 'validate') {
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => !empty($validateResult['ok']),
                'action' => 'validate',
                'result' => $validateResult,
            ]);
            return;
        }
    } elseif ($action === 'save') {
        if (empty($validateResult['ok'])) {
            $error = t('bi.family.err.validate');
            if ($wantsJson) {
                AgentHttp::error($error, 400, ['result' => $validateResult]);
                return;
            }
        } else {
            $msgParts = [];
            if (!empty($validateResult['warnings'])) {
                $msgParts[] = 'warnings: ' . implode('; ', $validateResult['warnings']);
            }
            if (!empty($validateResult['dry_run'])) {
                $msgParts[] = 'dry_run sections=' . (int)($validateResult['dry_run']['sections'] ?? 0);
            }
            try {
                $toSave = $validateResult['recipe'] ?? $recipe;
                if (!is_array($toSave)) {
                    throw new InvalidArgumentException('invalid_recipe');
                }
                $toSave['id'] = $familyId;
                if ($name !== '') {
                    $toSave['title'] = $name;
                }
                $savedId = BiFamilyDefsRepository::save([
                    'FAMILY_ID' => $familyId,
                    'NAME' => $name !== '' ? $name : $familyId,
                    'RECIPE_JSON' => $toSave,
                    'ENABLED' => $enabled,
                    'BUILTIN_FORK' => $builtinFork,
                    'LAST_VALIDATE_OK' => 1,
                    'LAST_VALIDATE_MSG' => implode(' | ', $msgParts),
                ], $defId);
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'action' => 'save',
                        'id' => $savedId,
                        'family_id' => $familyId,
                        'result' => $validateResult,
                    ]);
                    return;
                }
                redirect('/bi/families/' . rawurlencode($familyId) . '?flash=saved');
            } catch (InvalidArgumentException $e) {
                $code = $e->getMessage();
                if ($code === 'family_id_taken') {
                    $error = t('bi.family.err.id_taken');
                } else {
                    $error = t('bi.family.err.save');
                }
                if ($wantsJson) {
                    AgentHttp::error($error, 400, ['detail' => $code]);
                    return;
                }
            } catch (Throwable $e) {
                $error = t('bi.family.err.save');
                if ($wantsJson) {
                    AgentHttp::error($error, 500, ['detail' => $e->getMessage()]);
                    return;
                }
            }
        }
    }
}

if (isset($_GET['flash']) && $_GET['flash'] === 'saved') {
    $flash = t('bi.family.saved');
}

if ($wantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    AgentHttp::emit([
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
            'validate' => 'POST same path or /bi/families/new action=validate',
            'save' => 'POST action=save with recipe_json',
            'delete_override' => 'POST action=delete&def_id= (shipped file restored if shipped)',
            'run' => 'POST /bi/work?format=json action=run&family=' . $familyId,
        ],
    ]);
    return;
}

$title = ($defId > 0 || $shipped)
    ? t('bi.family.edit_title')
    : t('bi.family.new_title');

$legend = BiDatasetCatalog::legend();

View::render('family_edit', [
    'title' => $title,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bi.menu.group'), 'href' => '/bi'],
        ['label' => t('bi.menu.list'), 'href' => '/bi'],
        ['label' => $title, 'current' => true],
    ]),
    'def_id' => $defId,
    'family_id' => $familyId,
    'name' => $name,
    'recipe_text' => $recipeText,
    'enabled' => $enabled,
    'builtin_fork' => $builtinFork,
    'readonly_id' => $readonlyId,
    'shipped' => $shipped,
    'flash' => $flash,
    'error' => $error,
    'validate' => $validateResult,
    'legend' => $legend,
], 'layout', 'bi');
