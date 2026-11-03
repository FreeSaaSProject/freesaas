<?php
declare(strict_types=1);

require_once base_path('modules/catalog/lib/PriceListPrinter.php');

$opts = PriceListPrinter::optionsFromRequest($_GET);
$data = PriceListPrinter::build($opts);
$presets = PriceListPrinter::hostPresets();

$rootChoices = [];
foreach (
    CategoriesRepository::list([
        'parent_id' => 0,
        'order' => 'num ASC, name ASC',
        'limit' => 500,
    ])['items'] as $c
) {
    $rootChoices[(int)$c['id']] = (string)$c['name'];
}
// Also include selected roots that are not top-level
foreach ($opts['roots'] as $rid) {
    if (isset($rootChoices[$rid])) {
        continue;
    }
    $row = CategoriesRepository::find($rid);
    if ($row) {
        $rootChoices[$rid] = (string)$row['name'];
    }
}

$qs = PriceListPrinter::queryString($opts);
$printQs = PriceListPrinter::queryString($opts, ['print_mode' => true]);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'title' => $opts['title'],
        'roots' => $opts['roots'],
        'host_id' => $opts['host_id'],
        'kind' => $opts['kind'],
        'main_col' => $opts['main_col'],
        'strike_col' => $opts['strike_col'],
        'product_count' => $data['product_count'],
        'presets' => array_map(static function (array $p): array {
            return [
                'id' => $p['id'],
                'label' => $p['label'],
                'title' => $p['title'],
                'roots' => $p['roots'],
            ];
        }, $presets),
        'left_sections' => count($data['left']),
        'right_sections' => count($data['right']),
    ]);
    return;
}

View::render('prices_print', [
    'title' => t('catalog.prices.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('catalog.menu.browse'), 'href' => '/catalog'],
        ['label' => t('catalog.menu.prices'), 'current' => true],
    ]),
    'opts' => $opts,
    'data' => $data,
    'presets' => $presets,
    'root_choices' => $rootChoices,
    'query_string' => $qs,
    'print_query_string' => $printQs,
    'procedures' => $data['procedures'],
], 'layout', 'catalog');
