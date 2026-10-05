<?php
declare(strict_types=1);

/**
 * CLI output helpers: human text or JSON (--format=json).
 * Exit codes: 0 ok, 1 fail, 2 usage.
 */
final class Cli
{
    public const EXIT_OK = 0;
    public const EXIT_FAIL = 1;
    public const EXIT_USAGE = 2;

    /** @var bool */
    private static $json = false;

    public static function setJson(bool $json): void
    {
        self::$json = $json;
    }

    public static function isJson(): bool
    {
        return self::$json;
    }

    /**
     * @param list<string> $argv
     * @return array{0: list<string>, 1: array<string, string|bool>}
     */
    public static function parseArgv(array $argv): array
    {
        $args = [];
        $opts = [];
        array_shift($argv); // script
        foreach ($argv as $raw) {
            if ($raw === '--') {
                continue;
            }
            if (strpos($raw, '--') === 0) {
                $body = substr($raw, 2);
                if ($body === '') {
                    continue;
                }
                if (strpos($body, '=') !== false) {
                    [$k, $v] = explode('=', $body, 2);
                    $opts[$k] = $v;
                } else {
                    $opts[$body] = true;
                }
                continue;
            }
            $args[] = $raw;
        }
        if (!empty($opts['format']) && strtolower((string)$opts['format']) === 'json') {
            self::$json = true;
        }
        if (!empty($opts['json'])) {
            self::$json = true;
        }
        return [$args, $opts];
    }

    /**
     * @param mixed $data
     */
    public static function emit($data, int $exitCode = self::EXIT_OK): void
    {
        if (self::$json) {
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        } elseif (is_string($data)) {
            echo $data;
            if ($data !== '' && substr($data, -1) !== "\n") {
                echo "\n";
            }
        } elseif (is_array($data)) {
            self::emitTextTree($data);
        } else {
            echo (string)$data . "\n";
        }
        exit($exitCode);
    }

    public static function fail(string $message, int $exitCode = self::EXIT_FAIL): void
    {
        if (self::$json) {
            echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            fwrite(STDERR, $message . "\n");
        }
        exit($exitCode);
    }

    public static function usage(string $text): void
    {
        self::fail($text, self::EXIT_USAGE);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function emitTextTree(array $data, int $indent = 0): void
    {
        $pad = str_repeat('  ', $indent);
        foreach ($data as $k => $v) {
            if (is_int($k)) {
                if (is_array($v)) {
                    self::emitTextTree($v, $indent);
                } else {
                    echo $pad . '- ' . self::scalar($v) . "\n";
                }
                continue;
            }
            if (is_array($v)) {
                $isList = $v === [] || array_keys($v) === range(0, count($v) - 1);
                if ($isList && $v !== [] && !is_array(reset($v))) {
                    echo $pad . $k . ': ' . implode(', ', array_map([self::class, 'scalar'], $v)) . "\n";
                } elseif ($isList) {
                    echo $pad . $k . ":\n";
                    foreach ($v as $row) {
                        if (is_array($row)) {
                            echo $pad . "  -\n";
                            self::emitTextTree($row, $indent + 2);
                        } else {
                            echo $pad . '  - ' . self::scalar($row) . "\n";
                        }
                    }
                } else {
                    echo $pad . $k . ":\n";
                    self::emitTextTree($v, $indent + 1);
                }
            } else {
                echo $pad . $k . ': ' . self::scalar($v) . "\n";
            }
        }
    }

    /** @param mixed $v */
    private static function scalar($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return (string)$v;
    }
}
