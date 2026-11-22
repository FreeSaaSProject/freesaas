# Email transport (SMTP)

Product module `mail` (alpha): pure-PHP SMTP client, outbound queue `base_mail_sends`, `mail.send` EventBus enqueue, cron drain. Domain modules never talk SMTP — they enqueue.

## Enable

```bash
cp modules/mail/config.example.ini cfg/modules/mail.ini
# fill smtp_host / smtp_user / smtp_pass / from_email in cfg/modules/mail.ini (or /mail/settings)
php bin/freesaas.php module enable mail --accept-risk --format=json
php bin/freesaas.php migrate run --module=mail --format=json
php bin/freesaas.php module validate mail
php bin/freesaas.php locale validate
php bin/smoke.php
```

Config keys (`[mail]`): `smtp_host`, `smtp_port` (587), `smtp_encryption` (`tls` STARTTLS · `ssl` implicit TLS · `none`), `smtp_user`, `smtp_pass`, `from_email`, `from_name`, `reply_to`. SMTP is "configured" when host and a valid `from_email` are set; until then queued mail stays pending.

## Enqueue from another module

```php
$id = MailSend::enqueue([
    'to' => 'client@example.com',
    'to_name' => 'Anna',          // optional
    'subject' => 'Booking confirmed',
    'text' => 'See you at 14:00',  // text and/or html
    'html' => '<p>See you at <b>14:00</b></p>',
    'dedupe_key' => 'booking-42',  // optional; collapses duplicate pending rows per recipient
    'client_id' => 42,             // optional
    'meta' => ['source' => 'booking'],
]);
// or: EventBus::emit('mail.send', [...]) → payload['queued_id'], ['handled'], ['error']
```

Drain: platform cron job `mail.sends_drain` (every 5 min via `/cron/tick`). Force: `POST /cron/tick?job=mail.sends_drain&force=1` with `agent_token`. Rows: `STATUS` 1 pending, 2 sent, 3 error, 100 skipped.

## Session ops (staff, `agent.ops`)

| Op | Route | Permission |
|----|-------|------------|
| `mail.settings.get` | `GET /mail/settings` | `mail.manage` + SUPERADMIN |
| `mail.settings.save` | `POST /mail/settings` (`action=save`) | `mail.manage` + SUPERADMIN |
| `mail.send.test` | `POST /mail/settings` (`action=test`, `to`, `subject?`, `text?`) | `mail.manage` + SUPERADMIN |
| `mail.queue.list` | `GET /mail/sends?status=&q=` | `mail.view` |

Add `?format=json` or `Accept: application/json`.

## Platform agent ops (Surface A — `agent_token`)

Same auth as `/agent/health` (`X-FreeSaaS-Agent-Token`). The SMTP password is **always masked** in responses.

```bash
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" https://example.com/agent/mail/settings
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H "Content-Type: application/json" \
  -d '{"smtp_host":"smtp.example.com","smtp_port":587,"smtp_encryption":"tls","smtp_user":"u","smtp_pass":"secret","from_email":"noreply@example.com"}' \
  https://example.com/agent/mail/settings
curl -sS -X POST -H "X-FreeSaaS-Agent-Token: $TOKEN" -H "Content-Type: application/json" \
  -d '{"to":"you@example.com"}' https://example.com/agent/mail/test
curl -sS -H "X-FreeSaaS-Agent-Token: $TOKEN" "https://example.com/agent/mail/sends?status=3&limit=20"
```

| Op | Route | Notes |
|----|-------|-------|
| `mail.settings.get` | `GET /agent/mail/settings` | `settings` (password masked, `has_pass`, `configured`), `pending` |
| `mail.settings.save` | `POST /agent/mail/settings` | Only keys present are changed; masked `smtp_pass` keeps the stored one, empty clears it |
| `mail.test` | `POST /agent/mail/test` | Body `to`, optional `subject`, `text`; sends synchronously, returns `ok`, `id`, `error`, `message_id` |
| `mail.sends.list` | `GET /agent/mail/sends` | `status`, `q`, `limit` (≤200), `page`; no bodies |

## Diagnose

- Test send fails with `STARTTLS` / handshake error → wrong `smtp_encryption` for the port (587 → `tls`, 465 → `ssl`); certificate must be valid for `smtp_host`.
- `535` → bad credentials / app password required.
- Rows stay `pending` → SMTP not configured, or cron tick not running (`/cron` journal).
- A row claimed by a crashed worker is retried after 10 minutes.
