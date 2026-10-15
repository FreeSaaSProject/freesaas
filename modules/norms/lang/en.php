<?php
declare(strict_types=1);

return [
    'norms.module.name' => 'Consumption norms',
    'norms.menu.list' => 'Default consumptions',
    'norms.perm.view' => 'Norms (view)',
    'norms.perm.edit' => 'Norms (edit)',
    'norms.list.empty' => 'No norms found',
    'norms.col.proc' => 'Procedure',
    'norms.col.good' => 'Material',
    'norms.col.qtyu' => 'Qty (legacy)',
    'norms.col.qtyu2' => 'Use units',
    'norms.edit.title_new' => 'New norm',
    'norms.edit.title' => 'Norm #{id}',
    'norms.embed.title' => 'Default material consumptions',
    'norms.embed.add' => 'Add norm',
    'norms.err.not_found' => 'Norm #{id} not found',
    'norms.err.proc_required' => 'Select a procedure product',
    'norms.err.good_required' => 'Select a material',
    'norms.module.description' => 'Default consumptions (base_good_defaults); embeds on catalog product edit.',
    'norms.module.summary' => 'Enable for default material norms on products. Requires catalog. Soft-used by stocks leftovers logic. Useful when procedures/writeoffs should know default consumptions.',
    'norms.perm.delete' => 'Delete norms',
];
