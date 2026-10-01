<?php
declare(strict_types=1);

final class Config
{
    /** @var array<string,mixed> */
    private static $data = [];

    /** @var string */
    private static $path = '';

    public static function load(string $iniPath): void
    {
        if (!is_file($iniPath)) {
            throw new RuntimeException('Missing config: ' . $iniPath . ' (copy cfg/config.example.ini)');
        }
        $parsed = parse_ini_file($iniPath, true, INI_SCANNER_RAW);
        if ($parsed === false) {
            throw new RuntimeException('Invalid config.ini');
        }
        // strip surrounding quotes from values
        foreach ($parsed as $section => $rows) {
            if (!is_array($rows)) continue;
            foreach ($rows as $k => $v) {
                if (is_string($v)) {
                    $v = trim($v);
                    if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
                        $v = substr($v, 1, -1);
                    }
                    $parsed[$section][$k] = $v;
                }
            }
        }
        self::$data = $parsed;
        self::$path = $iniPath;
    }

    public static function path(): string
    {
        return self::$path;
    }

    public static function get(string $section, string $key, $default = null)
    {
        return self::$data[$section][$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public static function section(string $section): array
    {
        $s = self::$data[$section] ?? [];
        return is_array($s) ? $s : [];
    }

    public static function moduleEnabled(string $moduleId): bool
    {
        if ($moduleId === 'core') {
            return true;
        }
        $mods = self::section('modules');
        if (!array_key_exists($moduleId, $mods)) {
            return true; // default on
        }
        return self::truthyFlag($mods[$moduleId]);
    }

    /**
     * Persist module on/off flag into cfg/config.ini [modules].
     * core cannot be toggled.
     */
    public static function setModuleEnabled(string $moduleId, bool $enabled): void
    {
        if ($moduleId === 'core') {
            throw new RuntimeException(t('app.module.err.core_locked'));
        }
        if (self::$path === '' || !is_file(self::$path)) {
            throw new RuntimeException(t('app.config.err.not_loaded'));
        }
        if (!isset(self::$data['modules']) || !is_array(self::$data['modules'])) {
            self::$data['modules'] = [];
        }
        self::$data['modules'][$moduleId] = $enabled ? '1' : '0';
        self::persistModulesSection();
    }

    private static function truthyFlag($value): bool
    {
        $v = strtolower(trim((string)$value));
        return !in_array($v, ['0', 'false', 'off', 'no'], true);
    }

    private static function persistModulesSection(): void
    {
        $raw = file_get_contents(self::$path);
        if ($raw === false) {
            throw new RuntimeException(t('app.config.err.read'));
        }
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $out = [];
        $inModules = false;
        $written = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $m)) {
                if ($inModules) {
                    $inModules = false;
                }
                if (strtolower(trim($m[1])) === 'modules') {
                    foreach (self::modulesBlockLines() as $bl) {
                        $out[] = $bl;
                    }
                    $inModules = true;
                    $written = true;
                    continue;
                }
                $out[] = $line;
                continue;
            }
            if ($inModules) {
                continue;
            }
            $out[] = $line;
        }
        if (!$written) {
            if ($out && end($out) !== '') {
                $out[] = '';
            }
            foreach (self::modulesBlockLines() as $bl) {
                $out[] = $bl;
            }
        }
        // Drop trailing empty lines, then one newline at EOF
        while ($out && end($out) === '') {
            array_pop($out);
        }
        if (file_put_contents(self::$path, implode("\n", $out) . "\n") === false) {
            throw new RuntimeException(t('app.config.err.write'));
        }
    }

    /** @return list<string> */
    private static function modulesBlockLines(): array
    {
        $lines = [
            '[modules]',
            '; id=0 disables a module; absent or 1 = enabled',
        ];
        $mods = self::section('modules');
        ksort($mods);
        foreach ($mods as $id => $val) {
            $lines[] = $id . '=' . (self::truthyFlag($val) ? '1' : '0');
        }
        return $lines;
    }
}
