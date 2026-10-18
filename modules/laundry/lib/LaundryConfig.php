<?php
declare(strict_types=1);

final class LaundryConfig
{
    public static function ticketNewsId(): int
    {
        return max(1, (int)Config::get('laundry', 'ticket_news_id', 78));
    }

    public static function termsAccountId(): int
    {
        return max(1, (int)Config::get('laundry', 'terms_account_id', 1));
    }

    public static function laundryAccountId(): int
    {
        return max(1, (int)Config::get('laundry', 'laundry_account_id', 2));
    }
}
