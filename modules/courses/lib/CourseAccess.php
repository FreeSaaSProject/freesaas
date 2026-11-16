<?php
declare(strict_types=1);

final class CourseAccess
{
    /**
     * @return array{ok:bool,enrollment?:array<string,mixed>,error?:string}
     */
    public static function canAccessProgram(string $subjectType, int $subjectId, int $programId): array
    {
        $enr = CourseEnrollmentsRepository::findActive($subjectType, $subjectId, $programId);
        if ($enr === null) {
            return ['ok' => false, 'error' => 'no_enrollment'];
        }
        return ['ok' => true, 'enrollment' => $enr];
    }

    /**
     * @param array<string,mixed> $enrollment
     * @param array<string,mixed> $lesson
     */
    public static function canAccessLesson(array $enrollment, array $lesson): bool
    {
        if (empty($lesson['enabled'])) {
            return false;
        }
        $now = time();
        $from = (int)($enrollment['access_from'] ?? 0);
        $until = (int)($enrollment['access_until'] ?? 0);
        if ($from > $now) {
            return false;
        }
        if ($until > 0 && $until < $now) {
            return false;
        }
        $drip = max(0, (int)($lesson['drip_days'] ?? 0));
        if ($drip > 0 && ($from + $drip * 86400) > $now) {
            return false;
        }
        return true;
    }

    /** @return array{type:string,id:int}|null */
    public static function currentSubject(): ?array
    {
        if (class_exists('ClientPortalAuth', false) && ClientPortalAuth::check()) {
            return ['type' => 'client', 'id' => ClientPortalAuth::clientId()];
        }
        if (class_exists('Auth', false) && Auth::check()) {
            return ['type' => 'staff', 'id' => (int)Auth::userId()];
        }
        return null;
    }

    public static function watermarkLabel(string $subjectType, int $subjectId): string
    {
        if ($subjectType === 'client' && class_exists('ClientsRepository', false)) {
            $c = ClientsRepository::find($subjectId);
            if ($c) {
                $tel = preg_replace('/\D+/', '', (string)($c['tel'] ?? '')) ?? '';
                $mask = strlen($tel) >= 4 ? substr($tel, -4) : $tel;
                return trim((string)($c['name'] ?? '')) . ' ···' . $mask;
            }
        }
        if ($subjectType === 'staff' && class_exists('UsersRepository', false)) {
            $u = UsersRepository::find($subjectId);
            if (is_array($u)) {
                return trim((string)($u['name'] ?? $u['login'] ?? ('#' . $subjectId)));
            }
        }
        return $subjectType . '#' . $subjectId;
    }
}
