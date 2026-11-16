<?php
declare(strict_types=1);

/**
 * Cabinet password-reset needs bots channel config on the storefront host.
 * Older bots builds defaulted to runtime=admin and skipped bootstrap there.
 * When client_portal migrations run (enable/update), force bots to runtime=both
 * if the module is present on disk — no manual module.json edit.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    unset($pdo);
    $file = base_path('modules/bots/module.json');
    if (!is_file($file) || !is_readable($file) || !is_writable($file)) {
        return;
    }
    $raw = (string)file_get_contents($file);
    $data = json_decode($raw, true);
    if (!is_array($data) || (string)($data['id'] ?? '') !== 'bots') {
        return;
    }
    $runtime = strtolower(trim((string)($data['runtime'] ?? 'admin')));
    if ($runtime === 'site' || $runtime === 'public') {
        $runtime = 'storefront';
    }
    if ($runtime === 'both') {
        return;
    }
    $data['runtime'] = 'both';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    file_put_contents($file, $json . "\n");
};
