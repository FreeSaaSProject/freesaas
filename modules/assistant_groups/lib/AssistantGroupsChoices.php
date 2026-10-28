<?php
declare(strict_types=1);

/**
 * Validated choice buttons and soft Cancel/Problem without a pending token.
 */
final class AssistantGroupsChoices
{
    public static function applyChoice(
        string $apiToken,
        string $chatId,
        string $pendingToken,
        int $choiceN,
        int $uid,
        array $user,
        string $callbackMid = ''
    ): void {
        $choices = AssistantGroupsDialog::getChoices($chatId, $pendingToken);
        $idx = $choiceN - 1;
        if ($idx < 0 || !isset($choices[$idx])) {
            AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId, $callbackMid);
            AssistantGroupsKeyboards::reply($apiToken, $chatId, t('assistant_groups.bot.choice_missing'));
            return;
        }
        $choice = $choices[$idx];
        $opId = (string)$choice['op_id'];
        $args = is_array($choice['args'] ?? null) ? $choice['args'] : [];

        // Merge onto current pending args so id_operator etc. stay if choice only patches time.
        $old = class_exists('PendingActionsRepository', false)
            ? PendingActionsRepository::findByToken($pendingToken)
            : null;
        if (is_array($old)) {
            $oldArgs = PendingActionsRepository::decodeArgs($old);
            if (is_array($oldArgs)) {
                $args = array_merge($oldArgs, $args);
            }
        }

        try {
            Auth::runAs($uid, static function () use ($pendingToken, $uid) {
                return AssistantHarness::resolvePlan($pendingToken, 'cancel', $uid);
            });
        } catch (Throwable $e) {
            error_log('[assistant_groups] choice cancel: ' . $e->getMessage());
        }
        AssistantGroupsDialog::clearChoices($chatId, $pendingToken);
        AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId, $callbackMid);

        $ops = AssistantGroupsCatalog::opsForGroup();
        $catalogIds = [];
        $opMeta = null;
        foreach ($ops as $op) {
            if (!is_array($op)) {
                continue;
            }
            $id = (string)($op['id'] ?? '');
            if ($id !== '') {
                $catalogIds[$id] = true;
            }
            if ($id === $opId) {
                $opMeta = $op;
            }
        }
        if ($opMeta === null) {
            AssistantGroupsKeyboards::reply($apiToken, $chatId, t('assistant_groups.bot.choice_missing'));
            return;
        }

        $desc = (string)($opMeta['description'] ?? $opId);
        $preview = AssistantHarness::writePreview($opId, $args, $desc);
        $preflight = class_exists('AssistantWritePreflight', false)
            ? AssistantWritePreflight::run($opId, $args, $catalogIds)
            : ['blockers' => [], 'suggest' => [], 'choices' => [], 'noop' => false];
        foreach (AssistantWritePreflight::previewLines($preflight) as $line) {
            $preview['lines'][] = $line;
            $preview['summary'] .= "\n" . $line;
        }
        if (!empty($preflight['noop'])) {
            AssistantGroupsKeyboards::reply($apiToken, $chatId, t('assistant.preflight.noop'));
            return;
        }

        $reqPerm = AssistantHarness::requirementForOp($opMeta);
        $row = PendingActionsRepository::create($uid, $opId, $args, $preview['summary'], [
            'confirm_mode' => 'capability',
            'required_permission' => $reqPerm,
            'group_chat_id' => $chatId,
            'group_platform' => 'max',
            'plan_id' => bin2hex(random_bytes(8)),
            'plan_step' => 1,
        ]);
        if ($row === null) {
            AssistantGroupsKeyboards::reply($apiToken, $chatId, t('assistant.chat.error'));
            return;
        }

        $blocked = class_exists('AssistantWritePreflight', false)
            && AssistantWritePreflight::hasBlockers($preflight);
        $newChoices = is_array($preflight['choices'] ?? null) ? $preflight['choices'] : [];
        $pendingItem = [
            'token' => (string)$row['token'],
            'op_id' => $opId,
            'summary' => $preview['summary'],
            'headline' => $preview['headline'],
            'lines' => $preview['lines'],
            'args' => $args,
            'required_permission' => $reqPerm,
            'blocked' => $blocked,
            'suggest_ops' => class_exists('AssistantWritePreflight', false)
                ? AssistantWritePreflight::suggestOpIds($preflight)
                : [],
            'choices' => $newChoices,
        ];
        $confirmable = AssistantHarness::pendingConfirmable([$pendingItem]);
        $lines = [
            t('assistant_groups.bot.choice_applied', [
                'n' => (string)$choiceN,
                'v' => (string)$choice['label'],
            ]),
            $preview['summary'],
            t(
                $confirmable ? 'assistant_groups.bot.need_manager' : 'assistant_groups.bot.blocked_footer',
                ['perm' => AssistantHarness::requirementLabel($reqPerm)]
            ),
        ];
        $out = implode("\n\n", $lines);
        $newTok = (string)$row['token'];
        if ($newChoices !== []) {
            AssistantGroupsDialog::setChoices($chatId, $newTok, $newChoices);
        }
        $kb = AssistantGroupsKeyboards::draftKeyboard($newTok, $confirmable, $newChoices);
        $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $out, $kb);
        $history = AssistantGroupsDialog::getHistory($chatId);
        AssistantGroupsDialog::saveHistory($chatId, array_merge($history, [
            ['role' => 'user', 'content' => t('assistant_groups.bot.choice_picked', [
                'n' => (string)$choiceN,
                'v' => (string)$choice['label'],
            ])],
            ['role' => 'assistant', 'content' => $out],
        ]), $mid);
    }

    public static function resolveSoftDismiss(
        string $apiToken,
        string $chatId,
        int $actorId,
        array $user,
        string $action,
        string $callbackMid = ''
    ): void {
        AssistantGroupsKeyboards::stripKeyboards($apiToken, $chatId, $callbackMid);
        AssistantGroupsDialog::clearSoftOptions($chatId);

        // If a draft appeared in the meantime, prefer the real pending path.
        $live = class_exists('PendingActionsRepository', false)
            ? PendingActionsRepository::findLatestPendingForGroup($chatId, 'max')
            : null;
        if (is_array($live)) {
            $tok = (string)($live['TOKEN'] ?? '');
            if ($tok !== '' && preg_match('/^[a-f0-9]{16}$/', $tok)) {
                if ($action === 'problem') {
                    AssistantGroupsBotHandler::resolveProblem($apiToken, $chatId, $tok, $actorId, $user, $callbackMid);
                    return;
                }
                AssistantGroupsBotHandler::resolveAndAnnounce($apiToken, $chatId, $tok, 'cancel', $actorId, $user, $callbackMid);
                return;
            }
        }

        if ($action === 'problem') {
            $history = AssistantGroupsDialog::getHistory($chatId);
            $summary = '';
            $userText = '';
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $role = (string)($history[$i]['role'] ?? '');
                $content = trim((string)($history[$i]['content'] ?? ''));
                if ($content === '') {
                    continue;
                }
                if ($summary === '' && $role === 'assistant') {
                    $summary = $content;
                }
                if ($userText === '' && $role === 'user') {
                    $userText = $content;
                }
                if ($summary !== '' && $userText !== '') {
                    break;
                }
            }
            $name = trim((string)($user['name'] ?? $user['login'] ?? ('#' . $actorId)));
            $saved = class_exists('AssistantGroupsProblemStore', false)
                ? AssistantGroupsProblemStore::save([
                    'chat_id' => $chatId,
                    'user_id' => $actorId,
                    'user_name' => $name,
                    'pending_token' => '',
                    'op_id' => '',
                    'args' => [],
                    'summary' => $summary,
                    'user_text' => $userText,
                    'bot_mid' => $callbackMid,
                ])
                : ['ok' => false];
            $msg = !empty($saved['ok'])
                ? t('assistant_groups.bot.problem_filed', ['id' => (string)($saved['id'] ?? '')])
                : t('assistant_groups.bot.problem_filed_fail');
            $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $msg);
            AssistantGroupsBotHandler::appendDialogNote($chatId, $msg, $mid);
            return;
        }

        $msg = t('assistant.confirm.cancelled');
        $mid = AssistantGroupsKeyboards::reply($apiToken, $chatId, $msg);
        AssistantGroupsBotHandler::appendDialogNote($chatId, $msg, $mid);
    }

}
