<?php
declare(strict_types=1);

final class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $name, array $data = [], string $layout = 'layout', string $module = 'core'): void
    {
        $file = base_path('modules/' . $module . '/views/' . $name . '.php');
        if (!is_file($file) && $module !== 'core') {
            $file = base_path('modules/core/views/' . $name . '.php');
        }
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $module . '/' . $name);
        }
        extract($data, EXTR_SKIP);
        if (array_key_exists('breadcrumbs', $data) && is_array($data['breadcrumbs'])) {
            $aria = isset($data['breadcrumbs_aria']) && is_string($data['breadcrumbs_aria'])
                ? $data['breadcrumbs_aria']
                : '';
            Breadcrumbs::set($data['breadcrumbs'], $aria);
        }
        ob_start();
        require $file;
        $content = ob_get_clean();
        if (class_exists('StageTiming', false)) {
            StageTiming::mark('Page view');
        }
        if ($layout) {
            $layoutFile = base_path('modules/core/views/' . $layout . '.php');
            require $layoutFile;
            return;
        }
        echo $content;
    }
}
