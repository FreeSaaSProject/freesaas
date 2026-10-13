<?php
declare(strict_types=1);

/**
 * Staff/MCP help index: modules with agent.docs (and summary fallback).
 * Agent op: core.help.search
 */
$q = trim((string)($_GET['q'] ?? ''));
$module = trim((string)($_GET['module'] ?? ''));
$items = HelpDocs::search($q, $module !== '' ? $module : null);

// Slim cards for tool responses (headings capped).
$cards = [];
foreach ($items as $row) {
    $excerpt = (string)($row['summary'] ?? '');
    if ($excerpt === '' && !empty($row['headings'])) {
        $excerpt = implode(' · ', array_slice($row['headings'], 0, 6));
    }
    $cards[] = [
        'module' => $row['module'],
        'title' => $row['title'],
        'summary' => $excerpt,
        'has_docs' => !empty($row['has_docs']),
        'docs' => $row['docs'],
        'headings' => array_slice($row['headings'], 0, 12),
        'menu' => $row['menu'],
        'score' => $row['score'],
    ];
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'q' => $q,
        'module' => $module !== '' ? $module : null,
        'count' => count($cards),
        'items' => $cards,
        'hint' => 'For how-to answers call core.help.get with module=id (optional section=human for Human UI only).',
    ]);
    return;
}

View::render('help_search', [
    'title' => t('core.help.search_title'),
    'q' => $q,
    'module' => $module,
    'items' => $cards,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.help.search_title'), 'current' => true],
    ]),
], 'layout', 'core');
