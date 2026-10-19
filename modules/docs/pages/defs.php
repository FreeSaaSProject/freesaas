<?php
declare(strict_types=1);

DocsDefsRepository::ensureSeeded();
$defs = DocsDefsRepository::listAll(false);

if (AgentHttp::wantsJson()) {
    $items = [];
    foreach ($defs as $d) {
        $ver = DocsDefsRepository::currentVersion($d);
        $d['version'] = $ver !== null ? (int)$ver['version'] : 0;
        $d['schema'] = $ver !== null ? $ver['schema'] : ['sections' => []];
        $items[] = $d;
    }
    AgentHttp::emit(['ok' => true, 'items' => $items]);
    return;
}

$versionNums = [];
foreach ($defs as $d) {
    $ver = DocsDefsRepository::currentVersion($d);
    $versionNums[(int)$d['id']] = $ver !== null ? (int)$ver['version'] : 0;
}

View::render('defs', [
    'title' => t('docs.menu.defs'),
    'defs' => $defs,
    'versionNums' => $versionNums,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('docs.menu.list'), 'href' => '/documents'],
        ['label' => t('docs.menu.defs'), 'current' => true],
    ]),
], 'layout', 'docs');
