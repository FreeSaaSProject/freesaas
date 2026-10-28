# Assistant in Max groups (`assistant_groups`)

Alpha module: staff Max **group** channel for the in-system assistant. Mention the bot → draft write (pending) → **manager** with the op permission confirms (button or “@bot, confirm”).

Not Telegram-first. Private DM assistant stays in [`assistant`](assistant.md).

## Prerequisites

1. Modules: `llm`, `assistant`, `bots`, `assistant_groups` (enable with `--accept-risk`).
2. Dedicated Max bot (recommended) or staff Max token.
3. Bot **admin** in the group with `read_all_messages` + `write` ([Max docs](https://dev.max.ru/docs/chatbots/bots-coding/prepare)).
4. Staff linked in `base_bot_links` (`max_user_id` = Max user id from `sender` / callback user — **not** the group chat id).

## Settings

UI: **Assistant groups** (`/assistant-groups/settings`, SUPERADMIN).

| Key | Meaning |
|-----|---------|
| `group_enabled` | Process allowlisted Max groups |
| `group_require_mention` | Ignore messages without `@botusername` |
| `group_chat_ids` | Comma-separated negative chat ids (allowlist) |
| `auto_add_groups` | `1` = on `bot_added` append chat_id automatically |
| `group_ops` | Allowlisted `agent.ops` (exact or `module.*`); else “only in private”. Include `stickers.receipts.*`,`stickers.ins.*`,`stocks.list`,`suppliers.list`,`catalog.browse` for warehouse inbound (“receive stock”). Include `procedures.set_admin` (+ `procedures.list`/`procedures.get_edit`, `core.users.list`) to reassign procedure desk admin; `procedures.update` (+ `procedures.list`) to move massage/break times (“move … from 21:20 to 22:20” — journal, not calendar.events). Confirm for set_admin: `PARTNER_STA`. |
| `max_token` | Dedicated bot token (`cfg/modules/assistant_groups.ini`, gitignored) |

**New groups:** Max does not list chats the bot is in. Keep webhook/poll live → add bot to the group → `bot_added` is stored under **Discovered** on settings → «Add to allowlist» (or enable `auto_add_groups`).

**Context:** history is per group chat (`var/assistant_groups/dialogs/`), not personal DM history. A **reply** to the bot’s message (Max `link.type=reply`, sender = bot or known bot mid) continues the task without `@mention`. Mentions still start/continue the same chat thread. Reset via tool `assistant_groups.dialog.clear` (always in the group catalog; LLM calls it on clear/reset wording). Idle TTL 24h.

**Confirm buttons:** mids with keyboards are stored in the dialog file (`keyboard_mids`). Three actions: **Confirm**, **Cancel** (changed mind — no case), **Problem** (`❗`, callback `ag:prob:`) — cancels the draft and appends a JSONL case under `var/assistant_groups/problems/` for later review. Buttons are stripped (message text kept) after confirm/cancel/problem, on stale callback, on dialog clear, and on the next `@mention` / new draft — so old Confirm rows do not linger.

Diagnose: `/assistant-groups/status`.

## Inbound paths

1. **Production (preferred):** public webhook `POST /assistant-groups/webhook/max` + header `X-Max-Bot-Api-Secret`. Subscribe from **Assistant groups** settings (“Subscribe webhook”) or after deploy:
   - copy `cfg/modules/assistant_groups.ini` (token + chat ids) to the host;
   - open `/assistant-groups/settings` on the admin host → Subscribe (URL `https://<admin_host>/assistant-groups/webhook/max`).
   - Max forbids webhook + long-poll together; cron poll skips when `webhook_subscribed=1`.
2. **Local / before subscribe:** cron `assistant_groups.max_poll` long-polls `GET /updates` (dev only).
3. **Same as staff bot:** staff Max webhook (`/bots/webhook/max/staff`) — `BotWebhook` splits group `chat_id` vs sender identity. Unhandled group traffic stays silent.

Spike shape (proven):

- `bot_added` / `message_created` / `message_callback`
- Mention text: `@id….bot …` (plain `body.text`)
- Callbacks need `POST /answers?callback_id=` or the client shows nothing

## Confirm model

- Pending: `CONFIRM_MODE=capability`, `REQUIRED_PERMISSION` from the op (`permission`, or `cap:FLAG` when the op declares `capability`), `GROUP_CHAT_ID` bound.
- Author prepares the draft; linked staff who satisfy the requirement may confirm (`Auth::can` or `Auth::capability`); invoke runs as the confirmer.
- Wrong clicker → localized «no rights…» + buttons again.
- Fallback: mention + confirm phrase → latest pending for that group.

## Verify

```bash
php bin/freesaas.php module enable assistant_groups --accept-risk --format=json
php bin/freesaas.php migrate run --module=assistant --format=json
php bin/freesaas.php module validate assistant_groups --format=json
php bin/freesaas.php cron tick --job=assistant_groups.max_poll --force --format=json
```

Human: enable in settings, put chat id, mention bot in the group, manager confirms.
