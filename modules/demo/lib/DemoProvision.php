<?php
declare(strict_types=1);

/**
 * Create/update Demo Viewer role + guest user for public demo stands.
 */
final class DemoProvision
{
    /**
     * @param array{login?:string,password?:string,name?:string} $opts
     * @return array<string,mixed>
     */
    public static function run(array $opts = []): array
    {
        if (!class_exists('DemoMode', false) || !DemoMode::readonly()) {
            throw new RuntimeException(
                'demo provision refused: set app.demo_readonly=1 in cfg/config.ini first (see docs/agents/demo.md)'
            );
        }
        require_once base_path('modules/core/lib/StatusesRepository.php');
        require_once base_path('modules/core/lib/UsersRepository.php');

        $login = trim((string)($opts['login'] ?? 'demo'));
        $password = (string)($opts['password'] ?? 'demodemo');
        $defaultName = I18n::locale() === 'en'
            ? 'Demo Guest Viewer'
            : 'Демо Гость Просмотрович';
        $name = trim((string)($opts['name'] ?? ''));
        if ($name === '') {
            $name = $defaultName;
        }
        if ($login === '' || $password === '') {
            throw new InvalidArgumentException('login and password required');
        }

        $perms = ['core.home' => 1];
        foreach (UsersRepository::availablePermissionKeys() as $group) {
            foreach ($group['keys'] as $key) {
                $pid = is_array($key) ? (string)($key['id'] ?? '') : (string)$key;
                if ($pid === '') {
                    continue;
                }
                if ($pid === 'core.home' || substr($pid, -5) === '.view') {
                    $perms[$pid] = 1;
                }
            }
        }
        if (isset(ModuleManager::all()['core'])) {
            $perms['core.news'] = 1;
            $perms['core.users'] = 1;
        }
        if (isset(ModuleManager::all()['docs'])) {
            $perms['docs.print'] = 1;
            $perms['docs.manage'] = 1;
        }
        if (isset(ModuleManager::all()['bi'])) {
            $perms['bi.manage'] = 1;
        }
        $blob = StatusesRepository::encodePermissions($perms);

        $statusName = 'Demo Viewer';
        $statusId = 0;
        if (Database::tableExists('base_admin_statuses')) {
            $st = Database::pdo()->prepare('SELECT ID FROM base_admin_statuses WHERE NAME = ? LIMIT 1');
            $st->execute([$statusName]);
            $statusId = (int)$st->fetchColumn();
            $statusData = [
                'NAME' => $statusName,
                'ENABLED' => 1,
                'SUPERADMIN_ACCESS' => 0,
                'CONTROL_ACCESS' => 0,
            ];
            if (Database::columnExists('base_admin_statuses', 'SHOW_NEWS')) {
                $statusData['SHOW_NEWS'] = 1;
            }
            if (Database::columnExists('base_admin_statuses', 'SHOW_ADMINISTRATOR_DATA')) {
                $statusData['SHOW_ADMINISTRATOR_DATA'] = 1;
            }
            if (Database::columnExists('base_admin_statuses', 'CAN_SEE_SUM_OTHERS')) {
                $statusData['CAN_SEE_SUM_OTHERS'] = 1;
            }
            if (Database::columnExists('base_admin_statuses', 'PERMISSIONS')) {
                $statusData['PERMISSIONS'] = $blob;
            }
            if ($statusId < 1) {
                $statusId = StatusesRepository::create($statusData);
            } else {
                StatusesRepository::update($statusId, $statusData);
            }
        }

        $existing = UsersRepository::findByLogin($login);
        $userId = 0;
        if ($existing === null) {
            $userId = UsersRepository::create([
                'login' => $login,
                'password_plain' => $password,
                'name' => $name !== '' ? $name : $login,
                'enabled' => '1',
                'ID_STATUS' => $statusId,
                'status_blob' => $blob,
                'locale' => I18n::locale(),
            ]);
        } else {
            $userId = (int)$existing['id'];
            UsersRepository::update($userId, [
                'name' => $name !== '' ? $name : (string)$existing['name'],
                'enabled' => '1',
                'ID_STATUS' => $statusId,
                'status_blob' => $blob,
                'password_plain' => $password,
            ]);
        }

        return [
            'ok' => true,
            'demo_readonly' => true,
            'login' => $login,
            'user_id' => $userId,
            'status_id' => $statusId,
            'permissions' => array_keys($perms),
            'permission_count' => count($perms),
        ];
    }
}
