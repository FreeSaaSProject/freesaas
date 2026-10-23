<?php
declare(strict_types=1);

return [
    'demo.module.name' => 'Demo stand',
    'demo.module.description' => 'Public demo seed and guest provision (only when app.demo_readonly=1).',
    'demo.module.summary' => 'Enable only on public demo hosts with app.demo_readonly=1. Provides CLI seed/provision, the read-only banner, a link to the other-language stand and self-update / reseed cron jobs (cfg/modules/demo.ini). Keep =0 in production.',
    'demo.perm.manage' => 'Manage demo (seed / provision)',
    'demo.notice_short' => 'Demo · read-only',
    'demo.notice_title' => 'Public demo: browsing only. Mutations are blocked; data may reset nightly.',
    'demo.switch_to_en' => 'English demo →',
    'demo.switch_to_ru' => 'Русское демо →',
    'demo.switch_title_en' => 'Open the English demo stand (separate data)',
    'demo.switch_title_ru' => 'Open the Russian demo stand (separate data)',
    'demo.cron.update' => 'Demo: code update',
    'demo.cron.reseed' => 'Demo: data reseed',
    'demo.cron.version_check' => 'Demo: reseed after update',
];
