<?php
declare(strict_types=1);

/** Self-update engine facade (app/Update/*). */
final class UpdateEngine
{
    public static function updatesIniPath(): string
    {
        return UpdateConfig::updatesIniPath();
    }

    public static function exampleIniPath(): string
    {
        return UpdateConfig::exampleIniPath();
    }

    public static function statePath(): string
    {
        return UpdateFs::statePath();
    }

    public static function workDir(): string
    {
        return UpdateFs::workDir();
    }

    public static function sources(): array
    {
        return UpdateConfig::sources();
    }

    public static function privateModuleIds(): array
    {
        return UpdateConfig::privateModuleIds();
    }

    public static function packageLabel(string $target): string
    {
        return UpdateVersions::packageLabel($target);
    }

    public static function coreSource(): ?array
    {
        return UpdateConfig::coreSource();
    }

    public static function fetchRemoteCatalog(bool $force = false): array
    {
        return UpdateCatalog::fetchRemoteCatalog($force);
    }

    public static function availableToInstall(bool $forceRefresh = false, bool $includeDismissed = false): array
    {
        return UpdateCatalog::availableToInstall($forceRefresh, $includeDismissed);
    }

    public static function dismissedToInstall(): array
    {
        return UpdateCatalog::dismissedToInstall();
    }

    public static function dismissAvailable(string $id): array
    {
        return UpdateCatalog::dismissAvailable($id);
    }

    public static function restoreAvailable(string $id): array
    {
        return UpdateCatalog::restoreAvailable($id);
    }

    public static function installModule(string $id): array
    {
        return UpdateCatalog::installModule($id);
    }

    public static function frozenModules(): array
    {
        return UpdateConfig::frozenModules();
    }

    public static function conflicts(): array
    {
        return UpdateConflicts::listAll();
    }

    public static function resolveConflict(?string $path, string $take, bool $all = false): array
    {
        return UpdateConflicts::resolve($path, $take, $all);
    }

    public static function versionJsonPath(string $target): string
    {
        return UpdateVersions::versionJsonPath($target);
    }

    public static function localVersion(string $target): string
    {
        return UpdateVersions::localVersion($target);
    }

    public static function bumpVersion(string $id, string $mode = 'patch', ?string $set = null): array
    {
        return UpdateVersions::bumpVersion($id, $mode, $set);
    }

    public static function repoFromUrl(string $url): string
    {
        return UpdateVersions::repoFromUrl($url);
    }

    public static function moduleHints(): array
    {
        return UpdateCatalog::moduleHints();
    }

    public static function discoverPackages(?string $target = null): array
    {
        return UpdateCheck::discoverPackages($target);
    }

    public static function resolvePackages(?string $target = null, bool $onlyNewer = false): array
    {
        return UpdateCheck::resolvePackages($target, $onlyNewer);
    }

    public static function status(): array
    {
        return UpdateCheck::status();
    }

    public static function coreSettings(): array
    {
        return UpdateConfig::coreSettings();
    }

    public static function saveCoreSettings(array $in): void
    {
        UpdateConfig::saveCoreSettings($in);
    }

    public static function check(?string $target = null): array
    {
        return UpdateCheck::check($target);
    }

    public static function checkJobStart(?string $target = null): array
    {
        return UpdateCheck::checkJobStart($target);
    }

    public static function checkJobTick(string $id): array
    {
        return UpdateCheck::checkJobTick($id);
    }

    public static function apply(?string $target = null, bool $migrate = true, bool $dbBackup = false): array
    {
        return UpdateApply::apply($target, $migrate, $dbBackup);
    }

    public static function jobStart(?string $target = null, bool $migrate = true, bool $dbBackup = false): array
    {
        return UpdateApply::jobStart($target, $migrate, $dbBackup);
    }

    public static function jobGet(string $id): array
    {
        return UpdateApply::jobGet($id);
    }

    public static function jobPublic(array $job): array
    {
        return UpdateApply::jobPublic($job);
    }

    public static function jobCancel(string $id): array
    {
        return UpdateApply::jobCancel($id);
    }

    public static function jobTick(string $id): array
    {
        return UpdateApply::jobTick($id);
    }

}
