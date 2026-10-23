<?php
declare(strict_types=1);

/**
 * Cron handlers for demo stands (registered only under app.demo_readonly=1).
 * Schedule: DemoSchedule ([demo] in cfg/modules/demo.ini).
 */
final class DemoJobs
{
    /** demo.reseed — scheduled full reseed. */
    public static function reseed(): array
    {
        $s = DemoSchedule::load();
        self::ensureGuest();
        $r = DemoSeed::run(['reset' => true, 'locale' => self::locale()]);
        return [
            'ok' => true,
            'processed' => 1,
            'message' => self::withWarnings('reseeded v' . (string)$r['seed_version'], $s['warnings']),
        ];
    }

    /** demo.version_check — reseed right after modules/demo version changed (code update). */
    public static function versionCheck(): array
    {
        $s = DemoSchedule::load();
        if (!$s['reseed_on_version']) {
            return ['ok' => true, 'skipped' => 1, 'message' => self::withWarnings('reseed_on_version=0', $s['warnings'])];
        }
        $current = DemoStand::moduleVersion();
        $row = DemoStand::get();
        $seeded = (string)($row['SEED_VERSION'] ?? '');
        if ($row !== null && $seeded === $current) {
            return ['ok' => true, 'skipped' => 1, 'message' => self::withWarnings('seed v' . $current . ' up to date', $s['warnings'])];
        }
        self::ensureGuest();
        DemoSeed::run(['reset' => true, 'locale' => self::locale()]);
        return [
            'ok' => true,
            'processed' => 1,
            'message' => self::withWarnings(
                'version ' . ($seeded !== '' ? $seeded : '—') . ' → ' . $current . ': reseeded',
                $s['warnings']
            ),
        ];
    }

    /** Ensure Demo Viewer + auto-login user exist before seed (shared hosting has no CLI). */
    private static function ensureGuest(): void
    {
        $login = class_exists('DemoMode', false) ? DemoMode::autoLoginUser() : 'demo';
        DemoProvision::run([
            'login' => $login,
            'password' => 'demodemo',
        ]);
    }

    /**
     * demo.update — pull code via UpdateEngine. update_target=demo escalates to all when
     * any module listed in demo requires/optionalRequires has an update too, so the new
     * seed never runs against older modules. Reseed happens in a later tick
     * (demo.version_check): this process still holds the old DemoSeed class.
     */
    public static function update(): array
    {
        $s = DemoSchedule::load();
        if (!$s['update_enabled']) {
            return ['ok' => true, 'skipped' => 1, 'message' => self::withWarnings('update_enabled=0', $s['warnings'])];
        }
        $check = UpdateEngine::check(null);
        if (empty($check['ok'])) {
            return ['ok' => false, 'message' => 'update check failed: ' . (string)($check['error'] ?? 'unknown')];
        }
        $available = [];
        foreach ($check['targets'] ?? [] as $t) {
            if (is_array($t) && !empty($t['update_available'])) {
                $available[] = (string)($t['id'] ?? '');
            }
        }
        $available = array_values(array_filter($available));
        if ($available === []) {
            return ['ok' => true, 'skipped' => 1, 'message' => self::withWarnings('up to date', $s['warnings'])];
        }

        $target = $s['update_target'];
        $note = '';
        if ($target === 'demo') {
            $hit = array_values(array_intersect($available, self::seedDependencies()));
            if ($hit !== []) {
                $target = 'all';
                $note = 'escalated to all: ' . implode(', ', $hit) . '; ';
            } elseif (!in_array('demo', $available, true)) {
                return [
                    'ok' => true,
                    'skipped' => 1,
                    'message' => self::withWarnings('no demo update (others: ' . implode(', ', $available) . ')', $s['warnings']),
                ];
            }
        }

        $res = UpdateEngine::apply($target === 'all' ? null : $target, true, false);
        $applied = [];
        foreach ($res['results'] ?? [] as $one) {
            if (is_array($one) && !empty($one['ok'])) {
                $applied[] = (string)($one['id'] ?? $one['target'] ?? '');
            }
        }
        if (empty($res['ok'])) {
            return [
                'ok' => false,
                'message' => $note . 'apply ' . $target . ' failed: ' . (string)($res['error'] ?? 'see /updates'),
            ];
        }
        return [
            'ok' => true,
            'processed' => count($applied),
            'message' => self::withWarnings(
                $note . 'applied ' . $target . ': ' . implode(', ', array_filter($applied)),
                $s['warnings']
            ),
        ];
    }

    /** Modules whose API the seed uses (demo module.json requires + optionalRequires). @return list<string> */
    public static function seedDependencies(): array
    {
        $meta = ModuleManager::get('demo') ?? [];
        $ids = [];
        foreach (['requires', 'optionalRequires'] as $k) {
            foreach (is_array($meta[$k] ?? null) ? $meta[$k] : [] as $id => $_) {
                $ids[] = (string)$id;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function locale(): string
    {
        return I18n::normalize((string)Config::get('app', 'locale', 'ru')) ?? 'ru';
    }

    /** @param list<string> $warnings */
    private static function withWarnings(string $message, array $warnings): string
    {
        return $warnings === [] ? $message : $message . ' | ini: ' . implode('; ', $warnings);
    }
}
