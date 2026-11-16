<?php
declare(strict_types=1);

return [
    'acquiring.module.name' => 'Online acquiring',
    'acquiring.module.description' => 'Multi-provider online payments for storefront orders (own tables; EventBus paid flag).',
    'acquiring.module.summary' => 'Enable to take online card payments. Configure one or more providers under settings. Soft-deps: orders, client_mcp. Does not replace staff payments module.',

    'acquiring.perm.view' => 'View acquiring payments',
    'acquiring.perm.manage' => 'Manage acquiring settings',
    'acquiring.menu.payments' => 'Online payments',
    'acquiring.menu.settings' => 'Acquiring settings',

    'acquiring.setup.step.provider' => 'Connect a provider',
    'acquiring.setup.step.provider_help' => 'Enable YooKassa and/or T-Bank and paste shop credentials.',
    'acquiring.setup.ask.provider' => 'Open acquiring settings?',
    'acquiring.setup.choice.open' => 'Open settings',

    'acquiring.settings.title' => 'Acquiring settings',
    'acquiring.settings.saved' => 'Settings saved',
    'acquiring.settings.default' => 'Default provider',
    'acquiring.settings.allow_choice' => 'Allow client to choose provider at checkout',
    'acquiring.settings.enabled' => 'Enabled',
    'acquiring.settings.save' => 'Save',
    'acquiring.settings.yookassa' => 'YooKassa',
    'acquiring.settings.tbank' => 'T-Bank',
    'acquiring.settings.shop_id' => 'Shop ID',
    'acquiring.settings.secret' => 'Secret key',
    'acquiring.settings.terminal_key' => 'Terminal key',
    'acquiring.settings.password' => 'Password',
    'acquiring.settings.stub_hint' => 'Demo/stub mode returns a local success URL when credentials are empty but provider is enabled.',

    'acquiring.payments.title' => 'Online payments',
    'acquiring.payments.empty' => 'No payments yet.',
    'acquiring.payments.col_id' => 'ID',
    'acquiring.payments.col_order' => 'Order',
    'acquiring.payments.col_provider' => 'Provider',
    'acquiring.payments.col_amount' => 'Amount',
    'acquiring.payments.col_status' => 'Status',
    'acquiring.payments.col_dt' => 'Created',

    'acquiring.status.title' => 'Acquiring status',
    'acquiring.err.no_provider' => 'No acquiring provider enabled',
    'acquiring.err.order' => 'Order not found',
    'acquiring.err.create' => 'Could not create payment',
    'acquiring.checkout.ok' => 'Payment created',
];
