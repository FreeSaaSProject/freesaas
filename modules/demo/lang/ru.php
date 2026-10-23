<?php
declare(strict_types=1);

return [
    'demo.module.name' => 'Демостенд',
    'demo.module.description' => 'Сид и провижининг публичного демо (только при app.demo_readonly=1).',
    'demo.module.summary' => 'Включайте только на публичных демо-хостах вместе с app.demo_readonly=1. Даёт CLI seed/provision, баннер «только просмотр», ссылку на стенд другого языка и cron-задачи самообновления и пересева (cfg/modules/demo.ini). На проде держите =0.',
    'demo.perm.manage' => 'Управление демо (сид / провижининг)',
    'demo.notice_short' => 'Демо · только просмотр',
    'demo.notice_title' => 'Публичное демо: только просмотр. Запись заблокирована; данные могут сбрасываться каждую ночь.',
    'demo.switch_to_en' => 'English demo →',
    'demo.switch_to_ru' => 'Русское демо →',
    'demo.switch_title_en' => 'Открыть англоязычный демостенд (отдельные данные)',
    'demo.switch_title_ru' => 'Открыть русскоязычный демостенд (отдельные данные)',
    'demo.cron.update' => 'Демо: обновление кода',
    'demo.cron.reseed' => 'Демо: пересев данных',
    'demo.cron.version_check' => 'Демо: пересев после обновления',
];
