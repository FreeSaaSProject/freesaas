<?php
declare(strict_types=1);

/**
 * Enrich assistant Confirm drafts for catalog products/categories writes.
 */
final class CatalogAssistantPreview
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onWritePreview(array $payload): array
    {
        $opId = (string)($payload['op_id'] ?? '');
        if ($opId === '' || strpos($opId, 'catalog.') !== 0) {
            return $payload;
        }
        if (strpos($opId, 'catalog.browse') === 0) {
            return $payload;
        }
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        if (strpos($opId, 'catalog.products.') === 0) {
            return self::merge($payload, self::linesForProduct($opId, $args));
        }
        if (strpos($opId, 'catalog.categories.') === 0) {
            return self::merge($payload, self::linesForCategory($opId, $args));
        }
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $lines
     * @return array<string,mixed>
     */
    private static function merge(array $payload, array $lines): array
    {
        if ($lines === []) {
            return $payload;
        }
        $existing = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $existing[] = $line;
            }
        }
        $payload['lines'] = $existing;
        $payload['handled'] = true;
        return $payload;
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string>
     */
    private static function linesForProduct(string $opId, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $current = null;
        if ($id > 0) {
            try {
                $current = ProductsRepository::find($id);
            } catch (Throwable $e) {
                $current = null;
            }
        }

        $lines = [];
        if (is_array($current) && $current !== []) {
            $now = self::formatProduct($current);
            if ($now !== '') {
                $lines[] = t('catalog.assistant.preview.now', ['v' => $now]);
            }
        }

        if ($opId === 'catalog.products.delete' || strtolower((string)($args['action'] ?? '')) === 'delete') {
            if ($lines === []) {
                $lines[] = t('catalog.assistant.preview.delete');
            }
            return $lines;
        }

        $next = is_array($current) ? $current : [];
        foreach (['name', 'price', 'enabled'] as $k) {
            if (array_key_exists($k, $args) && $args[$k] !== '' && $args[$k] !== null) {
                $next[$k] = $args[$k];
            }
        }
        $will = self::formatProduct($next);
        if ($will === '') {
            return $lines;
        }
        if ($opId === 'catalog.products.create' || $current === null) {
            $lines[] = t('catalog.assistant.preview.create', ['v' => $will]);
        } else {
            $nowLine = is_array($current) ? self::formatProduct($current) : '';
            if ($will !== $nowLine) {
                $lines[] = t('catalog.assistant.preview.will', ['v' => $will]);
            }
        }
        return $lines;
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string>
     */
    private static function linesForCategory(string $opId, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '' && $id > 0) {
            try {
                $row = CategoriesRepository::find($id);
                $name = is_array($row) ? trim((string)($row['name'] ?? '')) : '';
            } catch (Throwable $e) {
                $name = '';
            }
        }
        if ($name === '') {
            return $id > 0 ? ['#' . $id] : [];
        }
        if (strpos($opId, 'create') !== false) {
            return [t('catalog.assistant.preview.category_create', ['v' => $name])];
        }
        return [t('catalog.assistant.preview.category', ['v' => $name])];
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function formatProduct(array $row): string
    {
        $name = trim((string)($row['name'] ?? ''));
        $price = $row['price'] ?? null;
        $priceLabel = ($price !== null && $price !== '') ? (string)$price : '';
        $bits = array_filter([$name, $priceLabel], static function (string $s): bool {
            return $s !== '';
        });
        return implode(' · ', $bits);
    }
}
