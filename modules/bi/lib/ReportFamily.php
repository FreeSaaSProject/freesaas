<?php
declare(strict_types=1);

/**
 * Coded KPI report family contract.
 */
interface ReportFamily
{
    public function id(): string;

    /** i18n key for title */
    public function titleKey(): string;

    /** Whether required tables/modules are present. */
    public function isReady(): bool;

    /**
     * Human-readable readiness note (i18n key or empty).
     */
    public function readyNoteKey(): string;

    /**
     * @param array<string,mixed> $params from ReportParams::normalize
     * @return array{
     *   family:string,
     *   params:array<string,mixed>,
     *   title:string,
     *   sections:list<array{title:string,type:string,rows?:list<array<string,mixed>>,html?:string,metrics?:array<string,mixed>}>,
     *   meta?:array<string,mixed>
     * }
     */
    public function run(array $params): array;
}
