<?php
declare(strict_types=1);

/**
 * Platform instance chrome (company label). Stored in cfg/config.ini [app] instance.
 * Product settings live in their modules' ini sections (sites, calendar, likespa, audio_reports).
 */
final class SystemRepository
{
    /**
     * Short company label under the logo and in the browser tab.
     */
    public static function companyName(): string
    {
        return trim((string)Config::get('app', 'instance', ''));
    }

    /** @return array{company:string} */
    public static function basics(): array
    {
        return ['company' => self::companyName()];
    }

    /** Platform instance label (FreeSaaS /settings). */
    public static function updateBasics(array $data): void
    {
        if (array_key_exists('company', $data)) {
            Config::set('app', 'instance', trim((string)$data['company']));
        }
    }
}
