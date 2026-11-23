<?php
declare(strict_types=1);

/** Optional ID_SOURCE on DPS when acquisition is enabled. */
final class AcquisitionLedgerHooks
{
    public static function register(): void
    {
        EventBus::on('ledger.moves.form.fields', [self::class, 'onFormFields']);
        EventBus::on('ledger.moves.validate', [self::class, 'onValidate']);
    }

    /** @param mixed $payload @return mixed */
    public static function onFormFields($payload)
    {
        if (!is_array($payload) || !Auth::can('acquisition.view')) {
            return $payload;
        }
        if (!Database::columnExists('base_account_moves', 'ID_SOURCE')) {
            return $payload;
        }
        $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
        $current = (int)($row['source_id'] ?? 0);
        $sources = PromoSourcesRepository::list(['enabled' => '1']);
        $options = $sources['items'];
        ob_start();
        ?>
<label><?= te('acquisition.ledger.source') ?>
  <select name="source_id">
    <option value="0"><?= te('acquisition.ledger.source_none') ?></option>
    <?php foreach ($options as $s): ?>
      <option value="<?= (int)$s['id'] ?>"<?= (int)$s['id'] === $current ? ' selected' : '' ?>><?= e((string)$s['name']) ?></option>
    <?php endforeach; ?>
  </select>
</label>
        <?php
        $html = is_array($payload['html'] ?? null) ? $payload['html'] : [];
        $html[] = (string)ob_get_clean();
        $payload['html'] = $html;
        return $payload;
    }

    /** @param mixed $payload @return mixed */
    public static function onValidate($payload)
    {
        if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
            return $payload;
        }
        if (!Database::columnExists('base_account_moves', 'ID_SOURCE')) {
            return $payload;
        }
        $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
        if (array_key_exists('source_id', $post)) {
            $payload['data']['source_id'] = max(0, (int)$post['source_id']);
        }
        return $payload;
    }
}
