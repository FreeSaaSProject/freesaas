<?php
declare(strict_types=1);

return [
    'amocrm.module.name' => 'AmoCRM',
    'amocrm.module.description' => 'Bidirectional bridge: Amo/Kommo contacts, companies, leads ↔ FreeSaaS clients + sales CRM.',
    'amocrm.module.summary' => 'Requires clients + crm. Set base_url + access_token. Amo leads map to CRM deals (sale pipeline).',

    'amocrm.perm.view' => 'View AmoCRM bridge',
    'amocrm.perm.import' => 'Run AmoCRM import',

    'amocrm.menu.group' => 'AmoCRM',
    'amocrm.menu.status' => 'AmoCRM status',
    'amocrm.menu.import' => 'Import',
    'amocrm.menu.settings' => 'Settings',

    'amocrm.cron.import_contacts' => 'AmoCRM pull contacts',
    'amocrm.cron.outbox' => 'AmoCRM outbox push',

    'amocrm.status.api' => 'API configured',
    'amocrm.status.outbox' => 'Outbox pending',
    'amocrm.action.ping' => 'Ping AmoCRM',
    'amocrm.warn.api' => 'Configure base_url and access_token first',
    'amocrm.import.dry_run' => 'Dry run (no writes)',
    'amocrm.import.run' => 'Pull',
    'amocrm.import.hint' => 'Prefer dry run first. Leads sync into sale pipelines.',
    'amocrm.import.done' => 'Import finished.',
    'amocrm.import.failed' => 'Import failed.',
    'amocrm.status.pull_off' => 'Pull is disabled in settings',
    'amocrm.longjob.start' => 'Importing {entity}…',
    'amocrm.longjob.page' => '{entity}: page {page}, fetched {fetched}',
    'amocrm.longjob.done' => '{entity} import done ({n} rows).',
    'amocrm.col.pull' => 'Pull enabled',
    'amocrm.col.push' => 'Push enabled',
    'amocrm.col.base_url' => 'Account base URL',
    'amocrm.col.access_token' => 'Access token',
    'amocrm.col.webhook_token' => 'Inbound webhook token',
    'amocrm.col.worker_token' => 'Worker token (jobs)',
    'amocrm.col.timeout' => 'Timeout (sec)',
    'amocrm.col.page_size' => 'Page size',
    'amocrm.secret.keep' => 'Leave blank to keep',
    'amocrm.flash.saved' => 'Saved',
    'amocrm.flash.ping_ok' => 'Ping OK',
    'amocrm.flash.ping_fail' => 'Ping failed',

    'amocrm.setup.step.config' => 'Configure AmoCRM API',
    'amocrm.setup.step.config_help' => 'Copy config.example.ini and set base_url + access_token.',
    'amocrm.setup.ask.config' => 'Open settings?',
    'amocrm.setup.choice.open' => 'Open settings',
];
