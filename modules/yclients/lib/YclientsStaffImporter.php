<?php
declare(strict_types=1);

/** Pull YClients staff into base_users (map entity=staff). Pull-only. */
final class YclientsStaffImporter
{
    /**
     * @return array{
     *   ok:bool,
     *   dry_run:bool,
     *   fetched:int,
     *   created:int,
     *   updated:int,
     *   linked:int,
     *   skipped:int,
     *   error?:string,
     *   sample_errors?:list<string>,
     *   default_status_id?:int
     * }
     */
    public static function run(bool $dryRun = false): array
    {
        $stats = [
            'ok' => false,
            'dry_run' => $dryRun,
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'linked' => 0,
            'skipped' => 0,
            'sample_errors' => [],
        ];

        if (!YclientsConfig::pullEnabled()) {
            $stats['error'] = 'pull disabled';
            YclientsSyncStateRepository::mark('import_staff', false, 'pull disabled', $stats);
            return $stats;
        }
        if (!YclientsConfig::apiReady()) {
            $stats['error'] = 'api config incomplete: ' . implode(', ', YclientsConfig::credentialGaps());
            YclientsSyncStateRepository::mark('import_staff', false, (string)$stats['error'], $stats);
            return $stats;
        }
        if (!YclientsIdMapRepository::tableReady()) {
            $stats['error'] = 'base_yclients_id_map missing — run schema migrate';
            YclientsSyncStateRepository::mark('import_staff', false, (string)$stats['error'], $stats);
            return $stats;
        }
        if (!Database::tableExists('base_users')) {
            $stats['error'] = 'base_users missing';
            YclientsSyncStateRepository::mark('import_staff', false, 'base_users missing', $stats);
            return $stats;
        }

        require_once base_path('modules/core/lib/UsersRepository.php');
        require_once base_path('modules/core/lib/StatusesRepository.php');

        $statusId = self::defaultStatusId();
        if ($statusId < 1) {
            $stats['error'] = 'no non-superadmin status to assign imported staff';
            YclientsSyncStateRepository::mark('import_staff', false, (string)$stats['error'], $stats);
            return $stats;
        }
        $stats['default_status_id'] = $statusId;

        $companyId = YclientsConfig::companyId();
        $res = YclientsApiClient::listStaff();
        if (!$res['ok']) {
            $stats['error'] = (string)($res['error'] ?? 'staff fetch failed');
            YclientsSyncStateRepository::mark('import_staff', false, (string)$stats['error'], $stats);
            return $stats;
        }

        $blob = StatusesRepository::encodePermissions([
            'core.home' => 1,
            'calendar.view' => 1,
            'workweeks.view' => 1,
            'clients.view' => 1,
            'procedures.view' => 1,
        ]);

        foreach ($res['items'] ?? [] as $row) {
            if (!is_array($row)) {
                $stats['skipped']++;
                continue;
            }
            $extId = (string)(int)($row['id'] ?? 0);
            if ($extId === '0') {
                $stats['skipped']++;
                continue;
            }
            $stats['fetched']++;
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $name = 'YC staff ' . $extId;
            }
            $fired = !empty($row['fired']) || !empty($row['is_fired']) || !empty($row['is_deleted']);
            $enabled = $fired ? '0' : '1';
            $phone = trim((string)($row['phone'] ?? ''));
            if ($phone === '' && is_array($row['employee'] ?? null)) {
                $phone = trim((string)($row['employee']['phone'] ?? ''));
            }
            $email = trim((string)($row['email'] ?? ''));
            $login = self::loginFor($extId, $phone, $email);

            try {
                $mapped = YclientsIdMapRepository::findLocalId($companyId, 'staff', $extId);
                if ($mapped !== null && $mapped > 0) {
                    if (!$dryRun) {
                        UsersRepository::update($mapped, [
                            'name' => $name,
                            'mail' => $email,
                            'tel' => $phone,
                            'enabled' => $enabled,
                        ]);
                    }
                    $stats['updated']++;
                    continue;
                }

                $existing = UsersRepository::findByLogin($login);
                if ($existing !== null) {
                    $localId = (int)$existing['id'];
                    if (!$dryRun) {
                        UsersRepository::update($localId, [
                            'name' => $name,
                            'mail' => $email !== '' ? $email : (string)($existing['mail'] ?? ''),
                            'tel' => $phone !== '' ? $phone : (string)($existing['tel'] ?? ''),
                            'enabled' => $enabled,
                        ]);
                        YclientsIdMapRepository::upsert($companyId, 'staff', $extId, $localId);
                    }
                    $stats['linked']++;
                    continue;
                }

                if (!$dryRun) {
                    $localId = UsersRepository::create([
                        'login' => $login,
                        'password_plain' => self::randomPassword(),
                        'name' => $name,
                        'mail' => $email,
                        'tel' => $phone,
                        'enabled' => $enabled,
                        'ID_STATUS' => $statusId,
                        'status_blob' => $blob,
                        'content' => 'yclients staff #' . $extId,
                    ]);
                    YclientsIdMapRepository::upsert($companyId, 'staff', $extId, $localId);
                }
                $stats['created']++;
            } catch (Throwable $e) {
                $stats['skipped']++;
                if (count($stats['sample_errors']) < 5) {
                    $stats['sample_errors'][] = 'staff ' . $extId . ': ' . $e->getMessage();
                }
            }
        }

        $stats['ok'] = true;
        YclientsSyncStateRepository::mark('import_staff', true, '', $stats);
        return $stats;
    }

    private static function defaultStatusId(): int
    {
        if (!Database::tableExists('base_admin_statuses')) {
            return 0;
        }
        $pdo = Database::pdo();
        if (Database::columnExists('base_admin_statuses', 'SUPERADMIN_ACCESS')) {
            $id = $pdo->query(
                "SELECT ID FROM base_admin_statuses
                 WHERE COALESCE(SUPERADMIN_ACCESS,0) = 0
                 ORDER BY ID ASC LIMIT 1"
            )->fetchColumn();
            return $id !== false ? (int)$id : 0;
        }
        $id = $pdo->query('SELECT ID FROM base_admin_statuses ORDER BY ID ASC LIMIT 1')->fetchColumn();
        return $id !== false ? (int)$id : 0;
    }

    private static function loginFor(string $extId, string $phone, string $email): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) >= 10) {
            return 'yc' . $digits;
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $local = strtolower((string)strtok($email, '@'));
            $local = preg_replace('/[^a-z0-9._-]+/', '', $local) ?? '';
            if ($local !== '') {
                return 'yc_' . $local . '_' . $extId;
            }
        }
        return 'ycstaff_' . $extId;
    }

    private static function randomPassword(): string
    {
        return bin2hex(random_bytes(12)) . 'Aa1!';
    }
}
