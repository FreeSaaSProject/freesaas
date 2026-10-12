<?php
declare(strict_types=1);

/** Database hub facade (app/Db/*). */
final class DbTools
{
    public const PREF_MODE = DbTables::PREF_MODE;
    public const MODE_NORMAL = DbTables::MODE_NORMAL;
    public const MODE_EXPERT = DbTables::MODE_EXPERT;
    public const ROW_CHUNK = DbDump::ROW_CHUNK;
    public const RESTORE_STMT_CHUNK = DbRestore::RESTORE_STMT_CHUNK;

    public static function dumpsDir(): string
    {
        return DbFs::dumpsDir();
    }

    public static function declaredTables(): array
    {
        return DbTables::declaredTables();
    }

    public static function ownedTables(): array
    {
        return DbTables::ownedTables();
    }

    public static function allTables(): array
    {
        return DbTables::allTables();
    }

    public static function foreignTables(): array
    {
        return DbTables::foreignTables();
    }

    public static function tableInventory(): array
    {
        return DbTables::tableInventory();
    }

    public static function isValidTableName(string $name): bool
    {
        return DbTables::isValidTableName($name);
    }

    public static function filterValidTables(array $tables): array
    {
        return DbTables::filterValidTables($tables);
    }

    public static function intersectTables(array $tables, array $allowed): array
    {
        return DbTables::intersectTables($tables, $allowed);
    }

    public static function dump(array $tables, ?string $label = null): array
    {
        return DbDump::dump($tables, $label);
    }

    public static function dumpModule(string $moduleId, ?string $label = null): array
    {
        return DbDump::dumpModule($moduleId, $label);
    }

    public static function restore(string $file, array $allowedTables): array
    {
        return DbRestore::restore($file, $allowedTables);
    }

    public static function optimize(array $tables): array
    {
        return DbMaint::optimize($tables);
    }

    public static function repair(array $tables): array
    {
        return DbMaint::repair($tables);
    }

    public static function jobsDir(): string
    {
        return DbFs::jobsDir();
    }

    public static function jobStart(string $op, array $opts = []): array
    {
        return DbJobs::jobStart($op, $opts);
    }

    public static function jobGet(string $id): array
    {
        return DbJobs::jobGet($id);
    }

    public static function jobTick(string $id): array
    {
        return DbJobs::jobTick($id);
    }

    public static function jobCancel(string $id): array
    {
        return DbJobs::jobCancel($id);
    }

    public static function jobPublic(array $job): array
    {
        return DbJobs::jobPublic($job);
    }

    public static function sanitizeHostMarker(string $host): string
    {
        return DbFetch::sanitizeHostMarker($host);
    }

    public static function publicOrigin(): string
    {
        return DbFetch::publicOrigin();
    }

    public static function agentDumpFetchUrl(string $file): ?string
    {
        return DbFetch::agentDumpFetchUrl($file);
    }

    public static function listDumps(): array
    {
        return DbFs::listDumps();
    }

    public static function resolveDumpPath(string $file): string
    {
        return DbFs::resolveDumpPath($file);
    }

    public static function deleteDump(string $file): void
    {
        DbFs::deleteDump($file);
    }

    public static function saveUploadedDump(array $file): array
    {
        return DbFs::saveUploadedDump($file);
    }

    public static function phpUploadLimitLabel(): string
    {
        return DbFs::phpUploadLimitLabel();
    }

    public static function phpUploadLimitBytes(): int
    {
        return DbFs::phpUploadLimitBytes();
    }

    public static function extractReferencedTables(string $sql): array
    {
        return DbRestore::extractReferencedTables($sql);
    }

    public static function diagnose(array $tables, ?array $ownedTables = null): array
    {
        return DbMaint::diagnose($tables, $ownedTables);
    }

    public static function check(array $tables): array
    {
        return DbMaint::check($tables);
    }

    public static function dropTables(array $tables): array
    {
        return DbMaint::dropTables($tables);
    }

    public static function modeForUser(int $userId): string
    {
        return DbTables::modeForUser($userId);
    }

    public static function setModeForUser(int $userId, string $mode): void
    {
        DbTables::setModeForUser($userId, $mode);
    }

}
