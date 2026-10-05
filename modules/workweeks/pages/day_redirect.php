<?php
declare(strict_types=1);

$dateParam = (string)(Router::param('date') ?? '');
$dateParam = trim($dateParam);
if ($dateParam === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    redirect('/workweeks');
    return;
}
redirect('/workweeks?date_from=' . rawurlencode($dateParam) . '&date_to=' . rawurlencode($dateParam));
