<?php
declare(strict_types=1);

return [
    'bitrix.module.name' => 'Bitrix24 CRM',
    'bitrix.module.description' => 'Двусторонний мост: контакты/компании/сделки/лиды Bitrix ↔ clients + sales CRM.',
    'bitrix.module.summary' => 'Нужны clients + crm. Настройте REST webhook URL, webhook_token и worker_token. Dual-run через outbox.',

    'bitrix.perm.view' => 'Просмотр моста Bitrix',
    'bitrix.perm.import' => 'Импорт из Bitrix',

    'bitrix.menu.group' => 'Bitrix24',
    'bitrix.menu.status' => 'Статус Bitrix',
    'bitrix.menu.import' => 'Импорт',
    'bitrix.menu.settings' => 'Настройки',

    'bitrix.cron.import_contacts' => 'Bitrix: pull контактов',
    'bitrix.cron.outbox' => 'Bitrix: outbox push',

    'bitrix.status.api' => 'REST настроен',
    'bitrix.status.outbox' => 'В очереди outbox',
    'bitrix.action.ping' => 'Ping Bitrix',
    'bitrix.warn.api' => 'Сначала укажите rest_url в настройках',
    'bitrix.import.dry_run' => 'Dry run (без записи)',
    'bitrix.import.run' => 'Pull',
    'bitrix.import.hint' => 'Сначала dry run. Лиды → pipeline kind=lead; сделки → kind=sale.',
    'bitrix.import.done' => 'Импорт завершён.',
    'bitrix.import.failed' => 'Импорт не удался.',
    'bitrix.status.pull_off' => 'Pull отключён в настройках',
    'bitrix.longjob.start' => 'Импорт {entity}…',
    'bitrix.longjob.page' => '{entity}: страница {page}, получено {fetched}',
    'bitrix.longjob.done' => 'Импорт {entity} завершён ({n} строк).',
    'bitrix.col.pull' => 'Pull включён',
    'bitrix.col.push' => 'Push включён',
    'bitrix.col.portal' => 'Хост портала',
    'bitrix.col.rest_url' => 'REST webhook URL',
    'bitrix.col.webhook_token' => 'Токен входящего webhook',
    'bitrix.col.worker_token' => 'Worker token (jobs)',
    'bitrix.col.timeout' => 'Таймаут (сек)',
    'bitrix.col.page_size' => 'Размер страницы',
    'bitrix.secret.keep' => 'Пусто = не менять',
    'bitrix.flash.saved' => 'Сохранено',
    'bitrix.flash.ping_ok' => 'Ping OK',
    'bitrix.flash.ping_fail' => 'Ping не удался',

    'bitrix.setup.step.config' => 'Настроить Bitrix REST',
    'bitrix.setup.step.config_help' => 'Скопируйте config.example.ini и задайте rest_url + токены.',
    'bitrix.setup.ask.config' => 'Открыть настройки?',
    'bitrix.setup.choice.open' => 'Открыть настройки',
];
