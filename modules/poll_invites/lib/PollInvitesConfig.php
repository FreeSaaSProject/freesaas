<?php
declare(strict_types=1);

/** Ini-backed settings for poll_invites ([poll_invites] in cfg/modules/poll_invites.ini). */
final class PollInvitesConfig
{
    /** @return array<string, string|int> */
    public static function defaults(): array
    {
        return [
            'oral_poll_id' => 0,
        ];
    }

    /** @return array<string, string> */
    public static function formValues(): array
    {
        return [
            'oral_poll_id' => (string)self::oralPollId(),
        ];
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        if (!array_key_exists('oral_poll_id', $post)) {
            return;
        }
        $id = max(0, (int)$post['oral_poll_id']);
        if ($id > 0 && class_exists('PollsRepository', false)) {
            $poll = PollsRepository::find($id);
            if ($poll === null || (int)($poll['pid'] ?? 0) !== 0) {
                throw new InvalidArgumentException(t('poll_invites.err.poll_missing', ['id' => $id]));
            }
        }
        Config::set('poll_invites', 'oral_poll_id', (string)$id);
    }

    public static function oralPollId(): int
    {
        return max(0, (int)Config::get('poll_invites', 'oral_poll_id', 0));
    }
}
