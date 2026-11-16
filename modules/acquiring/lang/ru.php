<?php
declare(strict_types=1);

return [
    'acquiring.module.name' => 'Онлайн-эквайринг',
    'acquiring.module.description' => 'Мульти-провайдерные онлайн-платежи за заказы витрины (свои таблицы; флаг оплаты через EventBus).',
    'acquiring.module.summary' => 'Включите для приёма онлайн-оплат. Настройте одного или нескольких провайдеров. Soft-deps: orders, client_mcp. Не заменяет модуль staff payments.',

    'acquiring.perm.view' => 'Просмотр онлайн-платежей',
    'acquiring.perm.manage' => 'Настройки эквайринга',
    'acquiring.menu.payments' => 'Онлайн-платежи',
    'acquiring.menu.settings' => 'Эквайринг',

    'acquiring.setup.step.provider' => 'Подключить провайдера',
    'acquiring.setup.step.provider_help' => 'Включите ЮKassa и/или Т‑Банк и укажите ключи магазина.',
    'acquiring.setup.ask.provider' => 'Открыть настройки эквайринга?',
    'acquiring.setup.choice.open' => 'Открыть настройки',

    'acquiring.settings.title' => 'Настройки эквайринга',
    'acquiring.settings.saved' => 'Настройки сохранены',
    'acquiring.settings.default' => 'Провайдер по умолчанию',
    'acquiring.settings.allow_choice' => 'Дать клиенту выбор провайдера на checkout',
    'acquiring.settings.enabled' => 'Включён',
    'acquiring.settings.save' => 'Сохранить',
    'acquiring.settings.yookassa' => 'ЮKassa',
    'acquiring.settings.tbank' => 'Т‑Банк',
    'acquiring.settings.shop_id' => 'Shop ID',
    'acquiring.settings.secret' => 'Секретный ключ',
    'acquiring.settings.terminal_key' => 'Terminal key',
    'acquiring.settings.password' => 'Пароль',
    'acquiring.settings.stub_hint' => 'Если ключи пустые, но провайдер включён — demo/stub режим с локальным success URL.',

    'acquiring.payments.title' => 'Онлайн-платежи',
    'acquiring.payments.empty' => 'Платежей пока нет.',
    'acquiring.payments.col_id' => 'ID',
    'acquiring.payments.col_order' => 'Заказ',
    'acquiring.payments.col_provider' => 'Провайдер',
    'acquiring.payments.col_amount' => 'Сумма',
    'acquiring.payments.col_status' => 'Статус',
    'acquiring.payments.col_dt' => 'Создан',

    'acquiring.status.title' => 'Статус эквайринга',
    'acquiring.err.no_provider' => 'Нет включённого провайдера эквайринга',
    'acquiring.err.order' => 'Заказ не найден',
    'acquiring.err.create' => 'Не удалось создать платёж',
    'acquiring.checkout.ok' => 'Платёж создан',
];
