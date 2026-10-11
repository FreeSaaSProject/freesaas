<?php
/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/app/bootstrap.php';

try {
    Router::dispatch();
} catch (Throwable $e) {
    http_response_code(500);
    $schema = class_exists('Database', false) && Database::isMissingSchemaError($e);
    $layout = $schema ? 'layout_auth' : 'layout';
    $data = [
        'title' => $schema ? t('core.error.schema_title') : t('core.error.title'),
        'message' => $schema ? t('core.error.schema_message') : $e->getMessage(),
        'hint' => $schema ? '' : ($e->getFile() . ':' . $e->getLine()),
        'schema' => $schema,
        'detail' => $schema ? $e->getMessage() : '',
    ];
    try {
        if (class_exists('View', false)) {
            View::render('error', $data, $layout);
        } else {
            echo 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    } catch (Throwable $e2) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body>';
        echo '<h1>' . htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8') . '</h1>';
        echo '<p>' . htmlspecialchars($data['message'], ENT_QUOTES, 'UTF-8') . '</p>';
        if ($schema) {
            echo '<p>CLI: <code>php bin/freesaas.php migrate run</code> · then <code>bootstrap</code> / <code>rescue</code> · or sign in and open /db</p>';
        }
        if ($data['detail'] !== '') {
            echo '<pre>' . htmlspecialchars($data['detail'], ENT_QUOTES, 'UTF-8') . '</pre>';
        }
        echo '<p class="muted">' . htmlspecialchars($e2->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        echo '</body></html>';
    }
}
