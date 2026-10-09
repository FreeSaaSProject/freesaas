# Messenger bots (Telegram / Max)

Product module `bots`: transport, outbound queue, staff/client webhooks, `BotPlugins` registry. Domain features live in owning modules (core, procedures, clients, …).

## Enable

```bash
cp modules/bots/config.example.ini cfg/modules/bots.ini
# edit tokens / webhook secrets in cfg/modules/bots.ini
php bin/freesaas.php module enable bots --format=json
php bin/freesaas.php migrate run --module=bots --format=json
php bin/freesaas.php module validate bots
php bin/freesaas.php locale validate
php bin/freesaas.php health --module=bots --format=json
php bin/smoke.php
```

Webhook paths (public + secret header):

| Channel | Path |
|---------|------|
| Staff Telegram | `POST /bots/webhook/telegram/staff` |
| Staff Max | `POST /bots/webhook/max/staff` |
| Client Telegram | `POST /bots/webhook/telegram/client` |
| Client Max | `POST /bots/webhook/max/client` |

- Telegram secret header: `X-Telegram-Bot-Api-Secret-Token`
- Max secret header: `X-Max-Bot-Api-Secret`

Staff/client link by phone → `base_users.tel` / `base_clients.tel` → writes chat ids into **`base_bot_links`** (`BOT_SOURCE` 2=staff, 1=client). Do not store messenger ids on `base_users` / `base_clients`.

## Bot types (menu)

Rail group **Bots** lists types present by enabled modules:

| Menu | When | Path |
|------|------|------|
| Staff | `bots` on | `/bots/types/staff` |
| Clients | `bots` + `clients` on | `/bots/types/client` (menu entry owned by `clients`) |
| Sends / Settings | `bots` on | `/bots/sends`, `/bots/settings` |

Type page (`/bots/types/{audience}`):

- **Cabinet menu catalog** — oper employeeBot/client features mapped to FreeSaaS modules with status `active` / `pending` / `module_off` / `missing` (`BotMenuCatalog`).
- **Live preview** — labels from `BotPlugins::collectCabinet` right now.
- Channels + queue pending count.

JSON: `GET /bots/types/staff?format=json` → `menu`, `counts`, `preview_buttons`.

## Agent ops (session Auth)

See [`module-agent-api.md`](module-agent-api.md). Same routes as UI; `?format=json` or `Accept: application/json`.

| Op | Call |
|----|------|
| `bots.settings.get` | `GET /bots/settings?format=json` |
| `bots.settings.save` | `POST /bots/settings` (form fields `staff_telegram_token`, …) |
| `bots.queue.list` | `GET /bots/sends?format=json&status=&audience=&q=` |
| `bots.queue.drain` | `POST /bots/sends/work` body `limit`, optional `audience` |
| `bots.send.test` | `POST /bots/sends/test` body `user_id` or `client_id`, `text`, `audience`, optional `drain=1` |
| `bots.plugins.list` | `GET /bots/plugins?format=json` |

Tokens are always masked in JSON.

## Enqueue from domain code

```php
EventBus::emit('bots.send', [
    'audience' => 'staff', // or client
    'user_id' => 123,      // staff; for client use client_id
    'text' => 'Hello',
    'name' => 'optional_key',
    'dedupe_key' => 'optional_unique',
    'deadline' => time() + 3600,
    'buttons' => ['inline_keyboard' => [[['text' => 'Menu', 'callback_data' => 'cabinet']]]],
]);
// Alias (audit): notify.send → same listener
```

Or `BotSend::enqueue([...])` when the bots module is enabled.

Drain with cron/agent: `POST /bots/sends/work?format=json&limit=20`.

## BotPlugins contract

Owning module `bootstrap.php` (when `ModuleManager::isEnabled('bots')`):

```php
BotPlugins::register('my_feature', [
    'priority' => 50,
    'cabinet_buttons' => function (array $ctx): array { /* rows */ return []; },
    'handle_callback' => function (array $ctx) { /* true|null */ return null; },
    'handle_text' => function (array $ctx) { return null; },
    'handle_message' => function (array $ctx) { return null; },
    'chat_context' => function (array $ctx): array {
        return ['title' => '…', 'summary' => '…', 'facts' => []];
    },
]);
```

`$ctx`: `audience`, `platform`, `chat_id`, `user`, `token`, `callback_data`, `text`, `raw`.

Events: `bots.inbound`, `bots.cabinet.collect`, `bots.send` / `notify.send`.

## MVP adapters already wired

| Plugin / hook | Module |
|---------------|--------|
| `my_tasks`, `news`, `password_restore` | `core` |
| `voice_transcribe` | `audio_pipeline` (queue `var/voice_transcribe/`, worker `/audio/voice-transcribe/worker`) |
| `fit_food_entry` (+ recipes) | `wellness` (`BotChatState`, EventBus `bots.voice.transcription_result`) |
| Procedure notify on create/update | `procedures` (`ProcedureBotNotify` → `bots.send`) |
| `client_cabinet` | `clients` (`ClientBotPlugin`) |

Staff/client messenger fields: `bots` via EventBus — `core.user_edit.*`, `core.users.list`, `clients.edit.*`, `clients.view.profile`, `clients.list` (data in `base_bot_links`, not core/clients columns).

## Tables

- `base_telegram_sends` — outbound queue (`BOT_SOURCE` 1=client, 2=staff; STATUS 1/2/3/100)
- `base_bot_links` — messenger identities (`BOT_SOURCE`, `ID_ENTITY`, `telegram_chat_id`, `max_user_id`, `BOT_NO_SEND`)

Legacy columns on `base_users` / `base_clients` (if present) are copied then dropped by migration `002_bot_links_extract`. Fresh installs never add them. Import from phpshop: migrator `phpshop_messenger_to_base_bot_links`.
