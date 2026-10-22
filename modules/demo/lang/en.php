<?php
declare(strict_types=1);

return [
    'demo.module.name' => 'Demo stand',
    'demo.module.description' => 'Public demo seed and guest provision (only when app.demo_readonly=1).',
    'demo.module.summary' => 'Enable only on public demo hosts with app.demo_readonly=1. Provides CLI seed/provision and the read-only banner. Keep =0 in production.',
    'demo.perm.manage' => 'Manage demo (seed / provision)',
    'demo.notice_short' => 'Demo · read-only',
    'demo.notice_title' => 'Public demo: browsing only. Mutations are blocked; data may reset nightly.',
];
