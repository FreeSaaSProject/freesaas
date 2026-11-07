<?php
declare(strict_types=1);

return [
    'amocrm.module.name' => 'AmoCRM',
    'amocrm.module.description' => 'Двусторонний мост: контакты, компании, сделки Amo/Kommo ↔ clients + sales CRM.',
    'amocrm.module.summary' => 'Нужны clients + crm. Укажите base_url + access_token. Сделки Amo → CRM deals (sale).',

    'amocrm.perm.view' => 'Просмотр моста AmoCRM',
    'amocrm.perm.import' => 'Импорт из AmoCRM',

    'amocrm.menu.group' => 'AmoCRM',
    'amocrm.menu.status' => 'Статус AmoCRM',
    'amocrm.menu.import' => 'Импорт',
    'amocrm.menu.settings' => 'Настройки',

    'amocrm.cron.import_contacts' => 'AmoCRM: pull контактов',
    'amocrm.cron.outbox' => 'AmoCRM: outbox push',

    'amocrm.status.api' => 'API настроен',
    'amocrm.status.outbox' => 'В очереди outbox',
    'amocrm.action.ping' => 'Ping AmoCRM',
    'amocrm.warn.api' => 'Сначала укажите base_url и access_token',
    'amocrm.import.dry_run' => 'Dry run (без записи)',
    'amocrm.import.run' => 'Pull',
    'amocrm.import.hint' => 'Сначала dry run. Сделки Amo пишутся в sale-воронки.',
    'amocrm.import.done' => 'Импорт завершён.',
    'amocrm.import.failed' => 'Импорт не удался.',
    'amocrm.status.pull_off' => 'Pull отключён в настройках',
    'amocrm.longjob.start' => 'Импорт {entity}…',
    'amocrm.longjob.page' => '{entity}: страница {page}, получено {fetched}',
    'amocrm.longjob.done' => 'Импорт {entity} завершён ({n} строк).',
    'amocrm.col.pull' => 'Pull включён',
    'amocrm.col.push' => 'Push включён',
    'amocrm.col.base_url' => 'Базовый URL аккаунта',
    'amocrm.col.access_token' => 'Access token',
    'amocrm.col.webhook_token' => 'Токен входящего webhook',
    'amocrm.col.worker_token' => 'Worker token (jobs)',
    'amocrm.col.timeout' => 'Таймаут (сек)',
    'amocrm.col.page_size' => 'Размер страницы',
    'amocrm.secret.keep' => 'Пусто = не менять',
    'amocrm.flash.saved' => 'Сохранено',
    'amocrm.flash.ping_ok' => 'Ping OK',
    'amocrm.flash.ping_fail' => 'Ping не удался',

    'amocrm.setup.step.config' => 'Настроить AmoCRM API',
    'amocrm.setup.step.config_help' => 'Скопируйте config.example.ini и задайте base_url + access_token.',
    'amocrm.setup.ask.config' => 'Открыть настройки?',
    'amocrm.setup.choice.open' => 'Открыть настройки',
];
