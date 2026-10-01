<?php
declare(strict_types=1);

require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/Config.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/EventBus.php';
require __DIR__ . '/../app/Auth.php';
require __DIR__ . '/../app/I18n.php';
require __DIR__ . '/../app/ModuleManager.php';

Config::load(base_path('cfg/config.ini'));
Auth::startSession();
I18n::bootFromRequest();
ModuleManager::discover();
ModuleManager::bootEnabled();

function collectKeys(string $locale): array
{
    $files = [base_path('app/lang/' . $locale . '.php')];
    foreach (scandir(base_path('modules')) ?: [] as $id) {
        if ($id === '.' || $id === '..') {
            continue;
        }
        $f = base_path('modules/' . $id . '/lang/' . $locale . '.php');
        if (is_file($f)) {
            $files[] = $f;
        }
    }
    $out = [];
    foreach ($files as $f) {
        $d = include $f;
        if (!is_array($d)) {
            continue;
        }
        foreach ($d as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }
    }
    return $out;
}

$ru = collectKeys('ru');
$en = collectKeys('en');
echo 'total ru=' . count($ru) . ' en=' . count($en) . PHP_EOL;
$onlyRu = array_diff(array_keys($ru), array_keys($en));
$onlyEn = array_diff(array_keys($en), array_keys($ru));
echo 'only ru: ' . count($onlyRu) . PHP_EOL;
foreach (array_slice($onlyRu, 0, 40) as $k) {
    echo "  $k\n";
}
echo 'only en: ' . count($onlyEn) . PHP_EOL;
foreach (array_slice($onlyEn, 0, 40) as $k) {
    echo "  $k\n";
}

$used = [];
$root = realpath(base_path(''));
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $p = $f->getPathname();
    if (strpos($p, '/lang/') !== false || strpos($p, '/vendor/') !== false) {
        continue;
    }
    if (!preg_match('/\.(php|js)$/', $p)) {
        continue;
    }
    if (preg_match('#/\.#', str_replace($root, '', $p))) {
        continue;
    }
    $c = file_get_contents($p);
    if ($c === false) {
        continue;
    }
    if (preg_match_all("/\\b(?:t|te|tj)\\(\\s*['\"]([^'\"]+)['\"]/", $c, $m)) {
        foreach ($m[1] as $k) {
            $used[$k] = $p;
        }
    }
}

$missingRu = [];
$missingEn = [];
foreach ($used as $k => $file) {
    if (!isset($ru[$k])) {
        $missingRu[$k] = $file;
    }
    if (!isset($en[$k])) {
        $missingEn[$k] = $file;
    }
}
echo 'used keys: ' . count($used) . PHP_EOL;
echo 'missing ru: ' . count($missingRu) . PHP_EOL;
foreach ($missingRu as $k => $f) {
    echo "  $k ($f)\n";
}
echo 'missing en: ' . count($missingEn) . PHP_EOL;
foreach ($missingEn as $k => $f) {
    echo "  $k ($f)\n";
}

foreach (['core', 'clients', 'contracts', 'comments'] as $id) {
    $j = json_decode((string)file_get_contents(base_path('modules/' . $id . '/module.json')), true);
    $check = [];
    if (!empty($j['name'])) {
        $check[] = $j['name'];
    }
    foreach ($j['permissions'] ?? [] as $p) {
        if (!empty($p['label'])) {
            $check[] = $p['label'];
        }
    }
    foreach ($j['menu'] ?? [] as $m) {
        if (!empty($m['title'])) {
            $check[] = $m['title'];
        }
        if (!empty($m['group'])) {
            $check[] = $m['group'];
        }
    }
    foreach ($check as $k) {
        if (!isset($ru[$k])) {
            echo "module.json missing ru: $k ($id)\n";
        }
        if (!isset($en[$k])) {
            echo "module.json missing en: $k ($id)\n";
        }
    }
}

I18n::setLocale('en');
echo 'EN logout: ' . t('core.layout.logout') . PHP_EOL;
echo 'EN clients menu: ' . t('clients.menu.base') . PHP_EOL;
I18n::setLocale('ru');
echo 'RU logout: ' . t('core.layout.logout') . PHP_EOL;
