<?php
declare(strict_types=1);

return [
    'bitrix.module.name' => 'Bitrix24 CRM',
    'bitrix.module.description' => 'Bidirectional bridge: Bitrix contacts/companies/deals/leads ↔ FreeSaaS clients + sales CRM.',
    'bitrix.module.summary' => 'Requires clients + crm. Configure incoming webhook REST URL, webhook_token and worker_token. Dual-run with outbox push.',

    'bitrix.perm.view' => 'View Bitrix bridge',
    'bitrix.perm.import' => 'Run Bitrix import',

    'bitrix.menu.group' => 'Bitrix24',
    'bitrix.menu.status' => 'Bitrix status',
    'bitrix.menu.import' => 'Import',
    'bitrix.menu.settings' => 'Settings',

    'bitrix.cron.import_contacts' => 'Bitrix pull contacts',
    'bitrix.cron.outbox' => 'Bitrix outbox push',

    'bitrix.status.api' => 'REST configured',
    'bitrix.status.outbox' => 'Outbox pending',
    'bitrix.action.ping' => 'Ping Bitrix',
    'bitrix.warn.api' => 'Configure rest_url in settings first',
    'bitrix.import.dry_run' => 'Dry run (no writes)',
    'bitrix.import.run' => 'Pull',
    'bitrix.import.hint' => 'Prefer dry run first. Leads map to pipeline kind=lead; deals to kind=sale.',
    'bitrix.import.done' => 'Import finished.',
    'bitrix.import.failed' => 'Import failed.',
    'bitrix.status.pull_off' => 'Pull is disabled in settings',
    'bitrix.longjob.start' => 'Importing {entity}…',
    'bitrix.longjob.page' => '{entity}: page {page}, fetched {fetched}',
    'bitrix.longjob.done' => '{entity} import done ({n} rows).',
    'bitrix.col.pull' => 'Pull enabled',
    'bitrix.col.push' => 'Push enabled',
    'bitrix.col.portal' => 'Portal host',
    'bitrix.col.rest_url' => 'REST webhook URL',
    'bitrix.col.webhook_token' => 'Inbound webhook token',
    'bitrix.col.worker_token' => 'Worker token (jobs)',
    'bitrix.col.timeout' => 'Timeout (sec)',
    'bitrix.col.page_size' => 'Page size',
    'bitrix.secret.keep' => 'Leave blank to keep',
    'bitrix.flash.saved' => 'Saved',
    'bitrix.flash.ping_ok' => 'Ping OK',
    'bitrix.flash.ping_fail' => 'Ping failed',

    'bitrix.setup.step.config' => 'Configure Bitrix REST',
    'bitrix.setup.step.config_help' => 'Copy config.example.ini and set rest_url + tokens.',
    'bitrix.setup.ask.config' => 'Open settings?',
    'bitrix.setup.choice.open' => 'Open settings',
];
