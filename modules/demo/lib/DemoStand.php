<?php
declare(strict_types=1);

/**
 * Demo stand marker in the DB (base_demo_stand, one row).
 *
 * Second lock next to cfg flags: seed/wipe run only when this DB carries a marker
 * whose STAND_ID equals [demo] stand_id. A DB with real domain data can never be
 * claimed implicitly — only an empty DB (fresh stand) or explicit `demo claim --existing`.
 */
final class DemoStand
{
    public const TABLE = 'base_demo_stand';

    /** Tables whose rows mean "this DB already holds business data". */
    private const DOMAIN_TABLES = ['base_clients', 'base_events', 'base_news'];

    public static function configuredId(): string
    {
        $id = trim((string)Config::get('demo', 'stand_id', ''));
        return preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $id) ? $id : '';
    }

    public static function tableReady(): bool
    {
        return Database::tableExists(self::TABLE);
    }

    /** @return array<string,mixed>|null */
    public static function get(): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $row = Database::pdo()->query('SELECT * FROM ' . self::TABLE . ' WHERE ID = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,int> */
    public static function domainCounts(): array
    {
        $out = [];
        foreach (self::DOMAIN_TABLES as $t) {
            $out[$t] = Database::tableExists($t)
                ? (int)Database::pdo()->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn()
                : 0;
        }
        return $out;
    }

    public static function domainEmpty(): bool
    {
        return array_sum(self::domainCounts()) === 0;
    }

    /**
     * Write the marker. $existing=true is the explicit operator override for a DB
     * that already holds demo data (CLI `demo claim --existing`).
     *
     * @return array<string,mixed>
     */
    public static function claim(string $locale, bool $existing = false): array
    {
        self::assertFlags();
        $id = self::requireConfiguredId();
        self::requireTable();
        $row = self::get();
        if ($row !== null) {
            if ((string)$row['STAND_ID'] !== $id) {
                throw new RuntimeException(
                    'demo stand mismatch: DB marker "' . (string)$row['STAND_ID'] . '" ≠ [demo] stand_id "' . $id . '"'
                );
            }
            Database::pdo()->prepare('UPDATE ' . self::TABLE . ' SET LOCALE = ? WHERE ID = 1')->execute([$locale]);
            return ['ok' => true, 'stand_id' => $id, 'claimed' => false, 'locale' => $locale];
        }
        if (!$existing && !self::domainEmpty()) {
            throw new RuntimeException(
                'demo claim refused: DB already holds data (' . self::countsLabel()
                . '). Only a fresh DB is claimed automatically; for a known demo copy run '
                . '`php bin/freesaas.php demo claim --existing`.'
            );
        }
        Database::pdo()->prepare(
            'INSERT INTO ' . self::TABLE . ' (ID, STAND_ID, LOCALE, CLAIMED_AT) VALUES (1, ?, ?, ?)'
        )->execute([$id, $locale, time()]);
        return ['ok' => true, 'stand_id' => $id, 'claimed' => true, 'locale' => $locale];
    }

    /**
     * Gate before any seed/wipe: flags + matching marker (auto-claim only on an empty DB).
     *
     * @return array<string,mixed> marker row
     */
    public static function assertSeedable(string $locale): array
    {
        self::assertFlags();
        $id = self::requireConfiguredId();
        self::requireTable();
        $row = self::get();
        if ($row === null) {
            self::claim($locale);
            $row = self::get();
        }
        if ($row === null || (string)$row['STAND_ID'] !== $id) {
            throw new RuntimeException(
                'demo seed refused: DB marker "' . (string)($row['STAND_ID'] ?? '') . '" ≠ [demo] stand_id "' . $id . '"'
            );
        }
        return $row;
    }

    /** @param list<string> $dumps */
    public static function recordSeed(string $version, string $locale, array $dumps): void
    {
        Database::pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET SEED_VERSION = ?, SEEDED_AT = ?, LOCALE = ?, DUMPS = ? WHERE ID = 1'
        )->execute([$version, time(), $locale, json_encode(array_values($dumps))]);
    }

    /** @return list<string> */
    public static function dumps(): array
    {
        $row = self::get();
        $list = json_decode((string)($row['DUMPS'] ?? ''), true);
        return is_array($list) ? array_values(array_filter(array_map('strval', $list))) : [];
    }

    public static function moduleVersion(): string
    {
        $meta = ModuleManager::get('demo');
        return trim((string)($meta['version'] ?? ''));
    }

    private static function assertFlags(): void
    {
        if (!class_exists('DemoMode', false) || !DemoMode::readonly()) {
            throw new RuntimeException('demo refused: set app.demo_readonly=1 (see docs/agents/demo.md)');
        }
        if (!ModuleManager::isEnabled('demo')) {
            throw new RuntimeException('demo refused: enable module demo=1');
        }
    }

    private static function requireConfiguredId(): string
    {
        $id = self::configuredId();
        if ($id === '') {
            throw new RuntimeException('demo refused: set [demo] stand_id in cfg/modules/demo.ini');
        }
        return $id;
    }

    private static function requireTable(): void
    {
        if (!self::tableReady()) {
            throw new RuntimeException('demo refused: base_demo_stand missing — run php bin/freesaas.php migrate run --module=demo');
        }
    }

    private static function countsLabel(): string
    {
        $parts = [];
        foreach (self::domainCounts() as $t => $n) {
            $parts[] = $t . '=' . $n;
        }
        return implode(', ', $parts);
    }
}
