<?php
declare(strict_types=1);

/**
 * Gate salary UI when the viewer has unread feed items / unviewed reviews.
 * Settings: cfg/modules/payments.ini → hide_salary_*.
 */
final class SalaryVisibility
{
    /**
     * @return list<array{kind:string,n:int,href:string,label:string}>
     */
    public static function blockedReasons(?int $uid = null): array
    {
        $uid = $uid ?? Auth::userId();
        if ($uid < 1) {
            return [];
        }
        if (Auth::capability('SUPERADMIN_ACCESS')
            && (string)Config::get('payments', 'hide_salary_apply_superadmin', '0') !== '1'
        ) {
            return [];
        }

        $reasons = [];
        if ((string)Config::get('payments', 'hide_salary_unread_news', '0') === '1'
            && class_exists('NoticesRepository', false)
        ) {
            $n = NoticesRepository::countUnreadForUser($uid);
            if ($n > 0) {
                $reasons[] = [
                    'kind' => 'news',
                    'n' => $n,
                    'href' => '/news?unread=1',
                    'label' => t('payments.salary_gate.reason_news', ['n' => $n]),
                ];
            }
        }

        if ((string)Config::get('payments', 'hide_salary_unread_reviews', '0') === '1'
            && ModuleManager::isEnabled('poll_invites')
            && class_exists('PollInvitesRepository', false)
        ) {
            $n = PollInvitesRepository::countUnviewedForUser($uid);
            if ($n > 0) {
                $reasons[] = [
                    'kind' => 'reviews',
                    'n' => $n,
                    'href' => '/poll-invites?progress=unviewed',
                    'label' => t('payments.salary_gate.reason_reviews', ['n' => $n]),
                ];
            }
        }

        return $reasons;
    }

    public static function canSeeSalary(?int $uid = null): bool
    {
        return self::blockedReasons($uid) === [];
    }

    /**
     * Trusted HTML banner with links to clear the gate.
     */
    public static function inviteHtml(?int $uid = null): string
    {
        $reasons = self::blockedReasons($uid);
        if ($reasons === []) {
            return '';
        }
        $links = [];
        foreach ($reasons as $r) {
            $links[] = '<a href="' . e((string)$r['href']) . '">' . e((string)$r['label']) . '</a>';
        }
        return '<div class="flash flash-warn payments-salary-gate">'
            . '<p>' . e(t('payments.salary_gate.invite')) . '</p>'
            . '<p>' . implode(' · ', $links) . '</p>'
            . '</div>';
    }
}
