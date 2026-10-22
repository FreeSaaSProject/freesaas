<?php
declare(strict_types=1);

return [
    'demo.module.name' => 'Демостенд',
    'demo.module.description' => 'Сид и провижининг публичного демо (только при app.demo_readonly=1).',
    'demo.module.summary' => 'Включайте только на публичных демо-хостах вместе с app.demo_readonly=1. Даёт CLI seed/provision и баннер «только просмотр». На проде держите =0.',
    'demo.perm.manage' => 'Управление демо (сид / провижининг)',
    'demo.notice_short' => 'Демо · только просмотр',
    'demo.notice_title' => 'Публичное демо: только просмотр. Запись заблокирована; данные могут сбрасываться каждую ночь.',
];
