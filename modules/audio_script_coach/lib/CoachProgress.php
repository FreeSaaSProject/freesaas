<?php
declare(strict_types=1);

/**
 * @deprecated Use StreamProgress — thin alias for backwards compatibility.
 */
final class CoachProgress
{
    public static function enabled(): bool
    {
        return StreamProgress::enabled();
    }

    public static function start(string $title = ''): void
    {
        StreamProgress::start($title, 'asc');
    }

    public static function beginCollect(): void
    {
        StreamProgress::beginCollect();
    }

    public static function mark(string $msg, string $color = '#86efac'): void
    {
        StreamProgress::mark($msg, $color);
    }

    public static function note(string $msg, string $color = '#e2e8f0'): void
    {
        StreamProgress::note($msg, $color);
    }

    /** @param array<string,mixed>|null $payload */
    public static function done(?array $payload = null): void
    {
        StreamProgress::done($payload, 'ascWorkDone');
    }

    /** @return list<array{msg:string,color:string,at:float}> */
    public static function collectStages(): array
    {
        return StreamProgress::collectStages();
    }

    public static function reset(): void
    {
        StreamProgress::reset();
    }
}
