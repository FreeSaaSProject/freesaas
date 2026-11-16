<?php
declare(strict_types=1);

return [
    'client_portal.module.name' => 'Кабинет клиента',
    'client_portal.module.description' => 'Личный кабинет на витрине: вход, профиль и секции из других модулей через EventBus.',
    'client_portal.module.summary' => 'Включите для входа клиента (/cabinet) на storefront-хосте. Секции бонусов, сертификатов, курсов и т.д. подхватываются, если соответствующие модули включены. Видимость секций — в настройках кабинета.',

    'client_portal.perm.manage' => 'Управление кабинетом клиента',
    'client_portal.menu.settings' => 'Кабинет клиента',
    'client_portal.menu.group' => 'Кабинет клиента',

    'client_portal.setup.step.visibility' => 'Видимость секций',
    'client_portal.setup.step.visibility_help' => 'Выберите, какие секции включённых модулей показывать в /cabinet.',
    'client_portal.setup.ask.visibility' => 'Открыть настройки кабинета?',
    'client_portal.setup.choice.open' => 'Открыть настройки',

    'client_portal.settings.title' => 'Настройки кабинета клиента',
    'client_portal.settings.saved' => 'Настройки сохранены',
    'client_portal.settings.sections' => 'Секции кабинета',
    'client_portal.settings.show' => 'Показывать',
    'client_portal.settings.weight' => 'Порядок',
    'client_portal.settings.module' => 'Модуль',
    'client_portal.settings.section' => 'Секция',
    'client_portal.settings.empty' => 'Секции ещё не зарегистрированы. Включите модули, подписанные на client_portal.sections.',
    'client_portal.settings.save' => 'Сохранить',

    'client_portal.status.title' => 'Статус кабинета клиента',
    'client_portal.login.title' => 'Вход',
    'client_portal.login.phone' => 'Телефон',
    'client_portal.login.password' => 'Пароль',
    'client_portal.login.submit' => 'Войти',
    'client_portal.login.error' => 'Неверный телефон или пароль',
    'client_portal.login.disabled' => 'Аккаунт отключён',
    'client_portal.logout' => 'Выйти',
    'client_portal.home.title' => 'Личный кабинет',
    'client_portal.home.empty' => 'Нет доступных разделов.',
    'client_portal.section.forbidden' => 'Раздел недоступен.',
    'client_portal.section.not_found' => 'Раздел не найден',
    'client_portal.nav.home' => 'Главная',

    'client_portal.section.profile' => 'Профиль',
    'client_portal.profile.name' => 'Имя',
    'client_portal.profile.tel' => 'Телефон',
    'client_portal.profile.mail' => 'Email',
    'client_portal.profile.birthday' => 'День рождения',
    'client_portal.profile.gender' => 'Пол',
    'client_portal.profile.password_new' => 'Новый пароль',
    'client_portal.profile.password_hint' => 'Оставьте пустым, чтобы не менять',
    'client_portal.profile.save' => 'Сохранить',
    'client_portal.profile.saved' => 'Профиль сохранён',
    'client_portal.profile.home_card' => 'Контакты и пароль',

    'client_portal.flash.need_login' => 'Войдите в кабинет',
];
