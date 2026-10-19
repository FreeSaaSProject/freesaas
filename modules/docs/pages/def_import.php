<?php
declare(strict_types=1);

/** Import templates from bundled library packs or an uploaded template file. */
$wantsJson = AgentHttp::wantsJson();
DocsDefsRepository::ensureSeeded();

$packs = DocsLibrary::packs();
$packId = (string)($_GET['pack'] ?? $_POST['pack'] ?? ($packs[0]['id'] ?? ''));
$opts = [
    'keep_ids' => !empty($_POST['keep_ids']),
    'update' => !empty($_POST['update']),
    'enable' => !empty($_POST['enable']),
];
$results = [];
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $items = [];
        if ((string)($_POST['action'] ?? '') === 'upload') {
            $tmp = (string)($_FILES['file']['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp) || (int)($_FILES['file']['size'] ?? 0) > 5 * 1024 * 1024) {
                throw new InvalidArgumentException(t('docs.import.err.file'));
            }
            $raw = json_decode((string)file_get_contents($tmp), true);
            $isList = is_array($raw) && $raw !== [] && array_keys($raw) === range(0, count($raw) - 1);
            $list = $isList ? $raw : [$raw];
            foreach ($list as $one) {
                $data = is_array($one) ? DocsLibrary::read((string)json_encode($one)) : null;
                if ($data === null) {
                    throw new InvalidArgumentException(t('docs.import.err.format'));
                }
                $items[] = $data;
            }
        } else {
            foreach ((array)($_POST['files'] ?? []) as $file) {
                $data = DocsLibrary::readPackFile($packId, (string)$file);
                if ($data !== null) {
                    $items[] = $data;
                }
            }
        }
        if ($items === []) {
            throw new InvalidArgumentException(t('docs.import.err.nothing'));
        }
        foreach ($items as $data) {
            $results[] = DocsLibrary::import($data, $opts) + ['name' => (string)$data['name']];
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
foreach ($results as $r) {
    $counts[$r['status']]++;
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'packs' => $packs,
        'pack' => $packId,
        'templates' => DocsLibrary::packTemplates($packId),
        'results' => $results,
        'counts' => $counts,
    ]);
    return;
}

View::render('def_import', [
    'title' => t('docs.import.title'),
    'packs' => $packs,
    'packId' => $packId,
    'templates' => DocsLibrary::packTemplates($packId),
    'results' => $results,
    'counts' => $counts,
    'error' => $error,
    'opts' => $opts,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('docs.menu.defs'), 'href' => '/documents/defs'],
        ['label' => t('docs.import.title'), 'current' => true],
    ]),
], 'layout', 'docs');
