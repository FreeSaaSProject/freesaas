<?php
declare(strict_types=1);

/**
 * Filesystem scaffolds for new product modules and migrations (CLI / agents).
 */
final class ModuleScaffold
{
    /**
     * @return array{ok:bool,id?:string,path?:string,files?:list<string>,error?:string,error_code?:string,http?:int,next?:list<string>}
     */
    public static function create(string $id, string $title = ''): array
    {
        $id = strtolower(trim($id));
        if (!preg_match('/^[a-z][a-z0-9_]{1,48}$/', $id)) {
            return [
                'ok' => false,
                'error' => 'Invalid module id (use lowercase snake, 2–49 chars, start with letter)',
                'error_code' => 'scaffold_invalid_id',
                'http' => 400,
            ];
        }
        if ($id === 'core') {
            return [
                'ok' => false,
                'error' => 'Cannot scaffold core',
                'error_code' => 'scaffold_reserved_id',
                'http' => 400,
            ];
        }
        $dir = base_path('modules/' . $id);
        if (is_dir($dir)) {
            return [
                'ok' => false,
                'error' => "Module directory already exists: modules/{$id}",
                'error_code' => 'scaffold_exists',
                'http' => 409,
            ];
        }

        $title = trim($title);
        if ($title === '') {
            $title = ucwords(str_replace('_', ' ', $id));
        }
        $class = self::studly($id);
        $table = 'base_' . $id;
        $repo = $class . 'Repository';

        $files = [];
        self::mkdirp($dir . '/lang');
        self::mkdirp($dir . '/lib');
        self::mkdirp($dir . '/pages');
        self::mkdirp($dir . '/views');
        self::mkdirp($dir . '/migrations');

        $manifest = [
            'id' => $id,
            'name' => $id . '.module.name',
            'description' => $id . '.module.description',
            'version' => '0.1.0',
            'type' => 'product',
            'requires' => ['core' => '>=0.3.0'],
            'optionalRequires' => [],
            'provides' => [],
            'migrations' => 'migrations/',
            'permissions' => [
                ['id' => $id . '.view', 'label' => $id . '.perm.view'],
                ['id' => $id . '.edit', 'label' => $id . '.perm.edit'],
            ],
            'routes' => [
                'pages' => ['/' . $id, '/' . $id . '/new', '/' . $id . '/{id}/edit'],
            ],
            'agent' => [
                'ops' => [
                    [
                        'id' => $id . '.list',
                        'method' => 'GET',
                        'path' => '/' . $id,
                        'permission' => $id . '.view',
                        'description' => 'List ' . $id . '; filter: q',
                    ],
                    [
                        'id' => $id . '.get',
                        'method' => 'GET',
                        'path' => '/' . $id . '/{id}/edit',
                        'permission' => $id . '.edit',
                        'description' => 'One ' . $id . ' edit payload',
                    ],
                    [
                        'id' => $id . '.create',
                        'method' => 'POST',
                        'path' => '/' . $id . '/new',
                        'permission' => $id . '.edit',
                        'description' => 'Create; fields: name, enabled',
                    ],
                    [
                        'id' => $id . '.update',
                        'method' => 'POST',
                        'path' => '/' . $id . '/{id}/edit',
                        'permission' => $id . '.edit',
                        'description' => 'Update',
                    ],
                ],
            ],
            'jobs' => [],
            'menu' => [
                [
                    'group' => 'core.menu.group.dictionaries',
                    'num' => 50,
                    'path' => '/' . $id,
                    'title' => $id . '.menu.list',
                ],
            ],
            'configSchema' => [],
            'tables' => [$table],
            'migrators' => [],
            'hooks' => ['subscribe' => [], 'publish' => []],
            'bulkActions' => [
                ['list_id' => $id, 'action' => 'delete'],
            ],
            'catalog' => [
                'categories' => ['dictionaries'],
                'maturity' => 'beta',
                'tags' => [],
                'screenshots' => [],
            ],
        ];
        $files[] = self::write($dir . '/module.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $files[] = self::write($dir . '/lang/en.php', self::langPhp($id, $title, 'en'));
        $files[] = self::write($dir . '/lang/ru.php', self::langPhp($id, $title, 'ru'));
        $files[] = self::write($dir . '/bootstrap.php', self::bootstrapPhp($id, $repo));
        $files[] = self::write($dir . '/lib/' . $repo . '.php', self::repoPhp($id, $class, $table, $repo));
        $files[] = self::write($dir . '/pages/list.php', self::listPagePhp($id, $repo));
        $files[] = self::write($dir . '/pages/edit.php', self::editPagePhp($id, $repo));
        $files[] = self::write($dir . '/views/list.php', self::listViewPhp($id));
        $files[] = self::write($dir . '/views/edit.php', self::editViewPhp($id));
        $files[] = self::write(
            $dir . '/migrations/001_create_' . $id . '_table.php',
            self::migrationCreatePhp($table)
        );

        return [
            'ok' => true,
            'id' => $id,
            'path' => 'modules/' . $id,
            'files' => $files,
            'http' => 200,
            'next' => [
                'php bin/freesaas.php module enable ' . $id . ' --accept-risk --format=json',
                'php bin/freesaas.php module validate ' . $id . ' --format=json',
                'php bin/freesaas.php health --module=' . $id . ' --format=json',
                'php bin/build-modules-catalog.php  # if shipping in monorepo',
            ],
        ];
    }

    /**
     * @return array{ok:bool,id?:string,migration?:string,path?:string,error?:string,error_code?:string,http?:int}
     */
    public static function addMigration(string $id, string $slug): array
    {
        $id = strtolower(trim($id));
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        if (!preg_match('/^[a-z][a-z0-9_]{1,48}$/', $id)) {
            return [
                'ok' => false,
                'error' => 'Invalid module id',
                'error_code' => 'scaffold_invalid_id',
                'http' => 400,
            ];
        }
        if ($slug === '' || !preg_match('/^[a-z][a-z0-9_]{1,64}$/', $slug)) {
            return [
                'ok' => false,
                'error' => 'Invalid migration slug (lowercase snake)',
                'error_code' => 'scaffold_invalid_slug',
                'http' => 400,
            ];
        }
        $dir = base_path('modules/' . $id . '/migrations');
        if (!is_dir(base_path('modules/' . $id))) {
            return [
                'ok' => false,
                'error' => "Module not found: {$id}",
                'error_code' => 'module_not_found',
                'http' => 404,
            ];
        }
        self::mkdirp($dir);
        $n = 1;
        foreach (glob($dir . '/*.php') ?: [] as $f) {
            $base = basename((string)$f);
            if (preg_match('/^(\d+)_/', $base, $m)) {
                $n = max($n, (int)$m[1] + 1);
            }
        }
        $name = sprintf('%03d_%s.php', $n, $slug);
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            return [
                'ok' => false,
                'error' => 'Migration file already exists',
                'error_code' => 'scaffold_exists',
                'http' => 409,
            ];
        }
        $body = <<<'PHP'
<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Additive migration stub — edit the body.
 */
return static function (PDO $pdo): void {
    // Example:
    // if (!Database::columnExists('base_example', 'FOO')) {
    //     $pdo->exec('ALTER TABLE base_example ADD COLUMN FOO VARCHAR(64) NOT NULL DEFAULT \'\'');
    // }
};

PHP;
        file_put_contents($path, $body);
        return [
            'ok' => true,
            'id' => $id,
            'migration' => $name,
            'path' => 'modules/' . $id . '/migrations/' . $name,
            'http' => 200,
            'next' => [
                'php bin/freesaas.php migrate run --module=' . $id . ' --format=json',
            ],
        ];
    }

    private static function studly(string $id): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $id)));
    }

    private static function mkdirp(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create directory: ' . $dir);
        }
    }

    private static function write(string $path, string $contents): string
    {
        file_put_contents($path, $contents);
        return str_replace(base_path() . '/', '', $path);
    }

    private static function langPhp(string $id, string $title, string $locale): string
    {
        if ($locale === 'ru') {
            $name = $title;
            $desc = 'Справочник ' . $title . ' (scaffold).';
            $summary = 'Включать для простого CRUD-словаря ' . $title . '.';
            $list = $title;
            $view = 'Просмотр: ' . $title;
            $edit = 'Редактирование: ' . $title;
            $empty = 'Пока нет записей.';
            $nameLabel = 'Название';
            $enabled = 'Включён';
            $new = 'Новая запись';
            $save = 'Сохранить';
        } else {
            $name = $title;
            $desc = $title . ' dictionary (scaffold).';
            $summary = 'Enable for a simple CRUD dictionary of ' . $title . '.';
            $list = $title;
            $view = 'View ' . $title;
            $edit = 'Edit ' . $title;
            $empty = 'No rows yet.';
            $nameLabel = 'Name';
            $enabled = 'Enabled';
            $new = 'New row';
            $save = 'Save';
        }
        $keys = [
            "{$id}.module.name" => $name,
            "{$id}.module.description" => $desc,
            "{$id}.module.summary" => $summary,
            "{$id}.menu.list" => $list,
            "{$id}.perm.view" => $view,
            "{$id}.perm.edit" => $edit,
            "{$id}.list.empty" => $empty,
            "{$id}.list.search_placeholder" => $locale === 'ru' ? 'Поиск…' : 'Search…',
            "{$id}.field.name" => $nameLabel,
            "{$id}.field.enabled" => $enabled,
            "{$id}.edit.new" => $new,
            "{$id}.edit.save" => $save,
            "{$id}.err.not_found" => $locale === 'ru' ? 'Запись #:id не найдена' : 'Row #:id not found',
        ];
        $lines = ["<?php\ndeclare(strict_types=1);\n\nreturn ["];
        foreach ($keys as $k => $v) {
            $lines[] = '    ' . var_export($k, true) . ' => ' . var_export($v, true) . ',';
        }
        $lines[] = "];\n";
        return implode("\n", $lines);
    }

    private static function bootstrapPhp(string $id, string $repo): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/lib/{$repo}.php';

\$pages = [
    '/{$id}' => ['permission' => '{$id}.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/{$id}/new' => ['permission' => '{$id}.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/{$id}/{id}/edit' => ['permission' => '{$id}.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach (\$pages as \$path => \$def) {
    \$file = (string)\$def['file'];
    ModuleManager::registerRoute(\$path, [
        'module' => '{$id}',
        'methods' => \$def['methods'],
        'permission' => \$def['permission'],
        'handler' => static function () use (\$file): void {
            require base_path('modules/{$id}/pages/' . \$file);
        },
    ]);
}

BulkActions::register('{$id}', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => '{$id}.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array \$ids, array \$params): array {
        return ['done' => {$repo}::deleteByIds(\$ids), 'skipped' => []];
    },
]);

PHP;
    }

    private static function repoPhp(string $id, string $class, string $table, string $repo): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class {$repo}
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
        ];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} \$opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array \$opts = []): array
    {
        if (!Database::tableExists('{$table}')) {
            return ['items' => [], 'total' => 0];
        }
        \$pdo = Database::pdo();
        \$where = ['1=1'];
        \$params = [];
        \$q = trim((string)(\$opts['q'] ?? ''));
        if (\$q !== '') {
            \$where[] = '(NAME LIKE ? OR CAST(ID AS CHAR) = ?)';
            \$params[] = '%' . \$q . '%';
            \$params[] = \$q;
        }
        \$sqlWhere = implode(' AND ', \$where);
        \$stc = \$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE \$sqlWhere");
        \$stc->execute(\$params);
        \$total = (int)\$stc->fetchColumn();

        \$order = trim((string)(\$opts['order'] ?? ''));
        if (\$order === '') {
            \$order = 'NAME ASC';
        }
        \$limit = max(0, (int)(\$opts['limit'] ?? 50));
        \$offset = max(0, (int)(\$opts['offset'] ?? 0));
        \$sql = "SELECT ID AS id, NAME AS name, ENABLED AS enabled
                FROM {$table} WHERE \$sqlWhere ORDER BY \$order";
        if (\$limit > 0) {
            \$sql .= ' LIMIT ' . \$limit . ' OFFSET ' . \$offset;
        }
        \$st = \$pdo->prepare(\$sql);
        \$st->execute(\$params);
        return ['items' => \$st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => \$total];
    }

    public static function find(int \$id): ?array
    {
        if (\$id < 1 || !Database::tableExists('{$table}')) {
            return null;
        }
        \$st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, ENABLED AS enabled
             FROM {$table} WHERE ID = ? LIMIT 1'
        );
        \$st->execute([\$id]);
        \$row = \$st->fetch(PDO::FETCH_ASSOC);
        return \$row ? \$row : null;
    }

    /** @param array{name?:string,enabled?:int|bool} \$data */
    public static function save(?int \$id, array \$data): int
    {
        \$pdo = Database::pdo();
        \$name = trim((string)(\$data['name'] ?? ''));
        \$enabled = !empty(\$data['enabled']) ? 1 : 0;
        if (\$id !== null && \$id > 0) {
            \$st = \$pdo->prepare('UPDATE {$table} SET NAME = ?, ENABLED = ? WHERE ID = ?');
            \$st->execute([\$name, \$enabled, \$id]);
            return \$id;
        }
        \$st = \$pdo->prepare('INSERT INTO {$table} (NAME, ENABLED) VALUES (?, ?)');
        \$st->execute([\$name, \$enabled]);
        return (int)\$pdo->lastInsertId();
    }

    /** @param list<int> \$ids */
    public static function deleteByIds(array \$ids): int
    {
        \$ids = array_values(array_filter(array_map('intval', \$ids), static fn (int \$i): bool => \$i > 0));
        if (\$ids === [] || !Database::tableExists('{$table}')) {
            return 0;
        }
        \$in = implode(',', array_fill(0, count(\$ids), '?'));
        \$st = Database::pdo()->prepare("DELETE FROM {$table} WHERE ID IN (\$in)");
        \$st->execute(\$ids);
        return \$st->rowCount();
    }
}

PHP;
    }

    private static function listPagePhp(string $id, string $repo): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

\$wantsJson = AgentHttp::wantsJson();
\$q = trim((string)(\$_GET['q'] ?? ''));

\$lq = ListQuery::resolve([
    'list_id' => '{$id}',
    'allowed' => {$repo}::listSortMap(),
    'default' => 'name',
    'default_dir' => 'asc',
    'per_page' => 50,
]);

\$filters = array_merge(['q' => \$q], \$lq['query']);
if (!empty(\$lq['show_all'])) {
    \$filters['page'] = 'all';
}

BulkActions::tryHandle('{$id}', '/{$id}', \$filters);

\$result = {$repo}::list([
    'q' => \$q,
    'limit' => ListQuery::sqlLimit(\$lq),
    'offset' => \$lq['offset'],
    'order' => \$lq['order_sql'],
]);

if (\$wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => \$result['total'],
        'items' => \$result['items'],
        'filters' => ['q' => \$q],
        'page' => \$lq['page'],
        'per_page' => \$lq['per_page'],
        'show_all' => \$lq['show_all'],
    ]);
    return;
}

\$bulkFlash = BulkActions::consumeFlash();

View::render('list', [
    'title' => t('{$id}.menu.list'),
    'items' => \$result['items'],
    'total' => \$result['total'],
    'q' => \$q,
    'canEdit' => Auth::can('{$id}.edit'),
    'sort' => \$lq['sort'],
    'dir' => \$lq['dir'],
    'page' => \$lq['page'],
    'perPage' => \$lq['per_page'],
    'showAll' => \$lq['show_all'],
    'filters' => \$filters,
    'bulkFlash' => \$bulkFlash,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('{$id}.menu.list'), 'current' => true],
    ]),
], 'layout', '{$id}');

PHP;
    }

    private static function editPagePhp(string $id, string $repo): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

\$wantsJson = AgentHttp::wantsJson();
\$idParam = (int)(Router::param('id') ?? 0);
\$isNew = \$idParam < 1;
\$row = \$isNew ? [] : ({$repo}::find(\$idParam) ?? null);

if (!\$isNew && \$row === null) {
    if (\$wantsJson) {
        AgentHttp::error(t('{$id}.err.not_found', ['id' => \$idParam]), 404);
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('{$id}.err.not_found', ['id' => \$idParam]),
    ]);
    return;
}

if ((\$_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        \$newId = {$repo}::save(\$isNew ? null : \$idParam, [
            'name' => (string)(\$_POST['name'] ?? ''),
            'enabled' => !empty(\$_POST['enabled']),
        ]);
        if (\$wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => \$newId, 'item' => {$repo}::find(\$newId)]);
            return;
        }
        redirect('/{$id}/' . \$newId . '/edit');
        return;
    } catch (Throwable \$e) {
        if (\$wantsJson) {
            AgentHttp::error(\$e->getMessage(), 400);
            return;
        }
        flash_set('error', \$e->getMessage());
    }
}

if (\$wantsJson) {
    AgentHttp::emit(['ok' => true, 'is_new' => \$isNew, 'item' => \$row ?: null]);
    return;
}

View::render('edit', [
    'title' => \$isNew ? t('{$id}.edit.new') : t('{$id}.menu.list'),
    'item' => \$row,
    'is_new' => \$isNew,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('{$id}.menu.list'), 'href' => '/{$id}'],
        ['label' => \$isNew ? t('{$id}.edit.new') : ('#' . \$idParam), 'current' => true],
    ]),
], 'layout', '{$id}');

PHP;
    }

    private static function listViewPhp(string $id): string
    {
        return <<<PHP
<?php
/** @var list<array<string,mixed>> \$items */
/** @var int \$total */
/** @var string \$q */
/** @var bool \$canEdit */
/** @var string \$sort */
/** @var string \$dir */
/** @var int \$page */
/** @var int \$perPage */
/** @var bool \$showAll */
/** @var array \$filters */
/** @var array|null \$bulkFlash */
\$path = '/{$id}';
\$listId = '{$id}';
\$showAll = !empty(\$showAll);
\$pager = ListQuery::pager((int)\$total, (int)\$page, (int)\$perPage, \$path, \$filters, \$showAll);
\$actionUrl = ListQuery::url(\$path, \$filters, []);
?>
<section class="page-head">
  <?php if (!empty(\$canEdit)): ?>
  <div class="page-actions">
    <a class="btn-primary" href="/{$id}/new"><?= te('core.common.add') ?></a>
  </div>
  <?php endif; ?>
</section>
<?php if (!empty(\$bulkFlash)): ?>
  <?= BulkActions::flashHtml(\$bulkFlash) ?>
<?php endif; ?>
<form class="filters" method="get" action="<?= e(\$path) ?>">
  <input type="search" name="q" value="<?= e(\$q) ?>" placeholder="<?= te('{$id}.list.search_placeholder') ?>">
</form>
<?= \$pager ?>
<?php if (\$items === []): ?>
  <p class="muted"><?= e(t('{$id}.list.empty')) ?></p>
<?php else: ?>
<?= BulkActions::beginForm(\$listId, \$actionUrl) ?>
<?= BulkActions::bar(\$listId) ?>
<table class="data">
  <thead>
    <tr>
      <?= BulkActions::th(\$listId) ?>
      <th>ID</th>
      <th><?= e(t('{$id}.field.name')) ?></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach (\$items as \$row): ?>
    <tr>
      <?= BulkActions::td(\$listId, (int)\$row['id']) ?>
      <td><?= (int)\$row['id'] ?></td>
      <td><a href="/{$id}/<?= (int)\$row['id'] ?>/edit"><?= e((string)\$row['name']) ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?= BulkActions::endForm(\$listId) ?>
<?php endif; ?>

PHP;
    }

    private static function editViewPhp(string $id): string
    {
        return <<<PHP
<?php
/** @var array<string,mixed> \$item */
/** @var bool \$is_new */
\$name = (string)(\$item['name'] ?? '');
\$enabled = \$is_new ? true : !empty(\$item['enabled']);
\$action = \$is_new ? '/{$id}/new' : '/{$id}/' . (int)(\$item['id'] ?? 0) . '/edit';
?>
<form method="post" action="<?= e(\$action) ?>" class="form">
  <label>
    <?= e(t('{$id}.field.name')) ?>
    <input type="text" name="name" value="<?= e(\$name) ?>" required>
  </label>
  <label>
    <input type="checkbox" name="enabled" value="1"<?= \$enabled ? ' checked' : '' ?>>
    <?= e(t('{$id}.field.enabled')) ?>
  </label>
  <button type="submit" class="btn-primary"><?= e(t('{$id}.edit.save')) ?></button>
</form>

PHP;
    }

    private static function migrationCreatePhp(string $table): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
return static function (PDO \$pdo): void {
    \$pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$table} (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            NAME VARCHAR(255) NOT NULL DEFAULT '',
            ENABLED TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (ID),
            KEY idx_name (NAME)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};

PHP;
    }
}
