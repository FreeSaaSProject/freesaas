<?php
declare(strict_types=1);

final class Config
{
    /** Platform sections always live in cfg/config.ini. */
    private const PLATFORM_SECTIONS = ['connect', 'app', 'salts', 'modules'];

    /** @var array<string,mixed> */
    private static $data = [];

    /** @var string Main cfg/config.ini path */
    private static $path = '';

    /** @var array<string,string> section name (lowercase) => absolute ini path that owns it */
    private static $sectionPaths = [];

    public static function load(string $iniPath): void
    {
        if (!is_file($iniPath)) {
            throw new RuntimeException('Missing config: ' . $iniPath . ' (copy cfg/config.example.ini)');
        }
        $parsed = self::parseIniFile($iniPath);
        self::$data = $parsed;
        self::$path = $iniPath;
        self::$sectionPaths = [];
        foreach (array_keys($parsed) as $section) {
            self::$sectionPaths[strtolower((string)$section)] = $iniPath;
        }

        $dir = dirname($iniPath) . '/modules';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.ini') ?: [] as $modPath) {
                $mod = self::parseIniFile($modPath);
                foreach ($mod as $section => $rows) {
                    if (!is_array($rows)) {
                        continue;
                    }
                    $sec = (string)$section;
                    if (!isset(self::$data[$sec]) || !is_array(self::$data[$sec])) {
                        self::$data[$sec] = [];
                    }
                    foreach ($rows as $k => $v) {
                        self::$data[$sec][$k] = $v;
                    }
                    // Module files override main for section ownership on write.
                    self::$sectionPaths[strtolower($sec)] = $modPath;
                }
            }
        }
    }

    public static function path(): string
    {
        return self::$path;
    }

    /** Directory for module ini files (cfg/modules). */
    public static function modulesDir(): string
    {
        return dirname(self::$path) . '/modules';
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
            // Absent key = disabled (fail-closed). New overlays / git-dropped packs stay off until activate.
            return false;
        }
        return self::truthyFlag($mods[$moduleId]);
    }

    /**
     * Pin every on-disk product module that still lacks a [modules] key to explicit =0.
     * Matches fail-closed defaults (absent = off). Never auto-enables — activate / enable
     * (with accept_risk for alpha/beta) remains the only path to =1.
     * Idempotent when all ids already have keys.
     *
     * @return array{pinned:array<string,int>,skipped:list<string>}
     */
    public static function pinEffectiveModules(): array
    {
        $mods = self::section('modules');
        $pinned = [];
        $skipped = [];
        $root = base_path('modules');
        if (!is_dir($root)) {
            return ['pinned' => [], 'skipped' => []];
        }
        foreach (scandir($root) ?: [] as $id) {
            if ($id === '.' || $id === '..' || $id === 'core') {
                continue;
            }
            if (!is_file($root . '/' . $id . '/module.json')) {
                continue;
            }
            if (array_key_exists($id, $mods)) {
                $skipped[] = $id;
                continue;
            }
            self::setModuleEnabled($id, false);
            $pinned[$id] = 0;
        }
        return ['pinned' => $pinned, 'skipped' => $skipped];
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

    /**
     * Set a config value in memory and rewrite that key on disk.
     * Platform sections → cfg/config.ini; others → cfg/modules/{section}.ini
     * (or the module file that already owns the section).
     */
    public static function set(string $section, string $key, $value): void
    {
        $section = trim($section);
        $key = trim($key);
        if ($section === '' || $key === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $section)
            || !preg_match('/^[A-Za-z0-9_.-]+$/', $key)
        ) {
            throw new InvalidArgumentException('Invalid config section/key');
        }
        if (self::$path === '' || !is_file(self::$path)) {
            throw new RuntimeException(t('app.config.err.not_loaded'));
        }
        if (!isset(self::$data[$section]) || !is_array(self::$data[$section])) {
            self::$data[$section] = [];
        }
        $str = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        self::$data[$section][$key] = $str;
        $file = self::resolvePersistPath($section);
        self::persistSectionKey($file, $section, $key, $str);
        self::$sectionPaths[strtolower($section)] = $file;
    }

    private static function isPlatformSection(string $section): bool
    {
        return in_array(strtolower($section), self::PLATFORM_SECTIONS, true);
    }

    private static function resolvePersistPath(string $section): string
    {
        if (self::isPlatformSection($section)) {
            return self::$path;
        }
        $lower = strtolower($section);
        if (isset(self::$sectionPaths[$lower])) {
            $existing = self::$sectionPaths[$lower];
            // Prefer module file; if still only in main, migrate writes to cfg/modules/{section}.ini
            if ($existing !== self::$path) {
                return $existing;
            }
        }
        $dir = self::modulesDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException(t('app.config.err.write'));
        }
        return $dir . '/' . $section . '.ini';
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function parseIniFile(string $iniPath): array
    {
        $parsed = parse_ini_file($iniPath, true, INI_SCANNER_RAW);
        if ($parsed === false) {
            throw new RuntimeException('Invalid config: ' . $iniPath);
        }
        foreach ($parsed as $section => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $k => $v) {
                if (is_string($v)) {
                    $v = trim($v);
                    if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
                        $v = substr($v, 1, -1);
                    }
                    $parsed[$section][$k] = self::decodeValue($v);
                }
            }
        }
        return $parsed;
    }

    private const B64_PREFIX = 'b64:';

    /**
     * INI cannot hold `;`, `"`, newlines or edge quotes/spaces in a raw value; such values
     * (SEO titles, phones, free text) are stored as b64:<base64> and decoded on load.
     */
    private static function encodeValue(string $value): string
    {
        $unsafe = $value !== trim($value)
            || preg_match('/[;"\r\n\\\\]/', $value)
            || ($value !== '' && ($value[0] === "'" || $value[0] === '#'))
            || strncmp($value, self::B64_PREFIX, strlen(self::B64_PREFIX)) === 0;
        return $unsafe ? self::B64_PREFIX . base64_encode($value) : $value;
    }

    private static function decodeValue(string $value): string
    {
        if (strncmp($value, self::B64_PREFIX, strlen(self::B64_PREFIX)) !== 0) {
            return $value;
        }
        $bin = base64_decode(substr($value, strlen(self::B64_PREFIX)), true);
        return is_string($bin) ? $bin : $value;
    }

    /** Write via temp file + rename so a crash never leaves a half-written ini. */
    private static function writeAtomic(string $filePath, string $content): void
    {
        $tmp = $filePath . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException(t('app.config.err.write'));
        }
        if (is_file($filePath)) {
            $perms = @fileperms($filePath);
            if (is_int($perms)) {
                @chmod($tmp, $perms & 0777);
            }
        }
        if (!@rename($tmp, $filePath)) {
            @unlink($tmp);
            throw new RuntimeException(t('app.config.err.write'));
        }
    }

    private static function truthyFlag($value): bool
    {
        $v = strtolower(trim((string)$value));
        return !in_array($v, ['0', 'false', 'off', 'no'], true);
    }

    /** Upsert one key inside [section]; create section at EOF if missing. */
    private static function persistSectionKey(string $filePath, string $section, string $key, string $value): void
    {
        $raw = is_file($filePath) ? file_get_contents($filePath) : '';
        if ($raw === false) {
            throw new RuntimeException(t('app.config.err.read'));
        }
        $lines = $raw === '' ? [] : (preg_split("/\r\n|\n|\r/", $raw) ?: []);
        $out = [];
        $inSection = false;
        $sectionFound = false;
        $keyWritten = false;
        $assignment = $key . '=' . self::encodeValue($value);
        $secLower = strtolower($section);

        $appendToSection = static function (array &$out, string $assignment): void {
            $trailing = 0;
            while ($out && end($out) === '') {
                array_pop($out);
                $trailing++;
            }
            $out[] = $assignment;
            for ($i = 0; $i < $trailing; $i++) {
                $out[] = '';
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $m)) {
                if ($inSection && !$keyWritten) {
                    $appendToSection($out, $assignment);
                    $keyWritten = true;
                }
                $inSection = strtolower(trim($m[1])) === $secLower;
                if ($inSection) {
                    $sectionFound = true;
                }
                $out[] = $line;
                continue;
            }
            if ($inSection && preg_match('/^\s*([A-Za-z0-9_.-]+)\s*=/', $line, $km)
                && strcasecmp($km[1], $key) === 0
            ) {
                $out[] = $assignment;
                $keyWritten = true;
                continue;
            }
            $out[] = $line;
        }
        if ($inSection && !$keyWritten) {
            $appendToSection($out, $assignment);
            $keyWritten = true;
        }
        if (!$sectionFound) {
            while ($out && end($out) === '') {
                array_pop($out);
            }
            if ($out) {
                $out[] = '';
            }
            $out[] = '[' . $section . ']';
            $out[] = $assignment;
        }
        while ($out && end($out) === '') {
            array_pop($out);
        }
        self::writeAtomic($filePath, implode("\n", $out) . "\n");
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
        while ($out && end($out) === '') {
            array_pop($out);
        }
        self::writeAtomic(self::$path, implode("\n", $out) . "\n");
    }

    /** @return list<string> */
    private static function modulesBlockLines(): array
    {
        $lines = [
            '[modules]',
            '; id=0 or absent = disabled; id=1 = enabled (pin every module you want on)',
        ];
        $mods = self::section('modules');
        ksort($mods);
        foreach ($mods as $id => $val) {
            $lines[] = $id . '=' . (self::truthyFlag($val) ? '1' : '0');
        }
        return $lines;
    }
}
