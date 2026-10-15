<?php
declare(strict_types=1);

return [
    'cabinets.module.name' => 'Rooms',
    'cabinets.menu.group' => 'Dictionaries',
    'cabinets.menu.list' => 'Rooms',
    'cabinets.perm.view' => 'Rooms (view)',
    'cabinets.perm.edit' => 'Rooms (edit)',
    'cabinets.search_placeholder' => 'Search: name, description, id',
    'cabinets.empty' => 'No rooms found',
    'cabinets.col.name' => 'Name',
    'cabinets.col.descr' => 'Description',
    'cabinets.col.limit_min' => 'Limit min',
    'cabinets.col.limit_max' => 'Limit max',
    'cabinets.edit.title_new' => 'New room',
    'cabinets.edit.title' => 'Room: {name}',
    'cabinets.edit.heading_new' => 'New room',
    'cabinets.edit.sec_main' => 'Main',
    'cabinets.err.not_found' => 'Room #{id} not found',
    'cabinets.err.name_required' => 'Name is required',
    'cabinets.err.name_too_long' => 'Name is too long',
    'cabinets.err.limits' => 'Minimum limit cannot exceed maximum',
    'cabinets.user_edit.cabinets' => 'Rooms',
    'cabinets.user_edit.no_cabinets' => 'The rooms list is empty.',
    'cabinets.module.description' => 'Rooms/cabinets dictionary (base_cabinets) for stocks and staff skin.',
    'cabinets.module.summary' => 'Enable when warehouses or calendar need room assignment. Soft-used by stocks, calendar, workweeks. Without it, some ID_CABINET fields fall back to defaults.',
    'cabinets.perm.delete' => 'Delete cabinets',
];
