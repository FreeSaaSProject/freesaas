<?php
declare(strict_types=1);

/**
 * Fallback public site SEO / contact fields (UI: /sites/seo). Stored in cfg/modules/sites.ini [sites].
 * Per-host name/tel live on base_site_hosts.
 */
final class SystemSite
{
    /** @return array{name:string,title:string,keywords:string,tel:string} */
    public static function load(): array
    {
        return [
            'name' => (string)Config::get('sites', 'seo_name', ''),
            'title' => (string)Config::get('sites', 'seo_title', ''),
            'keywords' => (string)Config::get('sites', 'seo_keywords', ''),
            'tel' => (string)Config::get('sites', 'tel', ''),
        ];
    }

    /**
     * @param array{name?:string,title?:string,keywords?:string,tel?:string} $data
     */
    public static function updateBasics(array $data): void
    {
        Config::set('sites', 'seo_name', trim((string)($data['name'] ?? '')));
        Config::set('sites', 'seo_title', trim((string)($data['title'] ?? '')));
        Config::set('sites', 'seo_keywords', trim((string)($data['keywords'] ?? '')));
        Config::set('sites', 'tel', trim((string)($data['tel'] ?? '')));
    }
}
