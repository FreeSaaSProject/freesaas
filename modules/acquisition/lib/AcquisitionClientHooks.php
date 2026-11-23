<?php
declare(strict_types=1);

/** Client card first-touch source via clients.edit.* / view.profile. */
final class AcquisitionClientHooks
{
    public static function register(): void
    {
        EventBus::on('clients.edit.sections', [self::class, 'onEditSections']);
        EventBus::on('clients.edit.save', [self::class, 'onEditSave']);
        EventBus::on('clients.view.profile', [self::class, 'onViewProfile']);
        EventBus::on('core.notices.collect', [self::class, 'onNotices']);
    }

    /** @param mixed $payload @return mixed */
    public static function onEditSections($payload)
    {
        if (!is_array($payload) || !Auth::can('acquisition.view')) {
            return $payload;
        }
        if (!Database::columnExists('base_clients', 'source_id')) {
            return $payload;
        }
        $client = is_array($payload['client'] ?? null) ? $payload['client'] : [];
        $sources = PromoSourcesRepository::listEnabledForClients();
        $sourceId = (int)($client['source_id'] ?? 0);
        $sourceDescr = (string)($client['source_descr'] ?? '');
        ob_start();
        require base_path('modules/acquisition/views/_client_edit_source.php');
        $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
        return $payload;
    }

    /** @param mixed $payload @return mixed */
    public static function onEditSave($payload)
    {
        if (!is_array($payload) || !Auth::can('acquisition.edit')) {
            return $payload;
        }
        if (!Database::columnExists('base_clients', 'source_id')) {
            return $payload;
        }
        $id = (int)($payload['id'] ?? 0);
        if ($id < 1) {
            return $payload;
        }
        $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
        $sourceId = max(0, (int)($post['acquisition_source_id'] ?? 0));
        $sourceDescr = trim((string)($post['acquisition_source_descr'] ?? ''));
        $referred = max(0, (int)($post['acquisition_referred_by'] ?? 0));
        $sets = ['source_id = ?', 'source_descr = ?'];
        $params = [$sourceId, $sourceDescr];
        if (Database::columnExists('base_clients', 'referred_by_client_id')) {
            $sets[] = 'referred_by_client_id = ?';
            $params[] = $referred;
        }
        $params[] = $id;
        Database::pdo()->prepare(
            'UPDATE base_clients SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute($params);
        return $payload;
    }

    /** @param mixed $payload @return mixed */
    public static function onViewProfile($payload)
    {
        if (!is_array($payload) || !Auth::can('acquisition.view')) {
            return $payload;
        }
        if (!Database::columnExists('base_clients', 'source_id')) {
            return $payload;
        }
        $client = is_array($payload['client'] ?? null) ? $payload['client'] : [];
        $sourceId = (int)($client['source_id'] ?? 0);
        $sourceDescr = trim((string)($client['source_descr'] ?? ''));
        $name = '';
        if ($sourceId > 0) {
            $src = PromoSourcesRepository::find($sourceId);
            $name = $src !== null ? (string)$src['name'] : ('#' . $sourceId);
        }
        ob_start();
        require base_path('modules/acquisition/views/_client_profile_source.php');
        $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
        return $payload;
    }

    /** @param mixed $payload @return mixed */
    public static function onNotices($payload)
    {
        if (!is_array($payload) || !Auth::can('acquisition.view')) {
            return $payload;
        }
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $unmarked = CallsRepository::countUnmarked();
        if ($unmarked > 0) {
            $items[] = [
                'module' => 'acquisition',
                'level' => 'warn',
                'text' => t('acquisition.notice.unmarked', ['n' => $unmarked]),
                'href' => '/acquisition/calls?unmarked=1',
            ];
        }
        if (Database::columnExists('base_clients', 'source_id')
            && Database::tableExists('base_procedures')) {
            $since = time() - 30 * 86400;
            $st = Database::pdo()->prepare(
                'SELECT COUNT(DISTINCT p.ID_USER) FROM base_procedures p
                 INNER JOIN base_clients c ON c.id = p.ID_USER
                 WHERE p.ENABLED > 0 AND p.SUMM > 0 AND p.DTSTART >= ?
                   AND c.source_id = 0 AND p.ID_USER > 0'
            );
            $st->execute([$since]);
            $n = (int)$st->fetchColumn();
            if ($n > 0) {
                $items[] = [
                    'module' => 'acquisition',
                    'level' => 'warn',
                    'text' => t('acquisition.notice.unmarked_primary', ['n' => $n]),
                    'href' => '/clients?acquisition_no_source=1',
                ];
            }
        }
        $payload['items'] = $items;
        return $payload;
    }
}
