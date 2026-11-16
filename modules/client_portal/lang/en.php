<?php
declare(strict_types=1);

return [
    'client_portal.module.name' => 'Client portal',
    'client_portal.module.description' => 'Storefront personal cabinet for clients: login, profile, and EventBus sections from other modules.',
    'client_portal.module.summary' => 'Enable for a client login (/cabinet) on the storefront host. Soft-deps pull bonuses, certificates, courses, etc. when those modules are on. Configure which sections appear under Client portal settings.',

    'client_portal.perm.manage' => 'Manage client portal',
    'client_portal.menu.settings' => 'Client portal',
    'client_portal.menu.group' => 'Client portal',

    'client_portal.setup.step.visibility' => 'Section visibility',
    'client_portal.setup.step.visibility_help' => 'Choose which enabled-module sections appear in /cabinet.',
    'client_portal.setup.ask.visibility' => 'Open portal settings now?',
    'client_portal.setup.choice.open' => 'Open settings',

    'client_portal.settings.title' => 'Client portal settings',
    'client_portal.settings.saved' => 'Settings saved',
    'client_portal.settings.sections' => 'Cabinet sections',
    'client_portal.settings.show' => 'Show',
    'client_portal.settings.weight' => 'Order',
    'client_portal.settings.module' => 'Module',
    'client_portal.settings.section' => 'Section',
    'client_portal.settings.empty' => 'No sections registered yet. Enable product modules that subscribe to client_portal.sections.',
    'client_portal.settings.save' => 'Save',

    'client_portal.status.title' => 'Client portal status',
    'client_portal.login.title' => 'Sign in',
    'client_portal.login.phone' => 'Phone',
    'client_portal.login.password' => 'Password',
    'client_portal.login.submit' => 'Sign in',
    'client_portal.login.error' => 'Invalid phone or password',
    'client_portal.login.disabled' => 'Account is disabled',
    'client_portal.logout' => 'Sign out',
    'client_portal.home.title' => 'Personal cabinet',
    'client_portal.home.empty' => 'No sections available.',
    'client_portal.section.forbidden' => 'This section is not available.',
    'client_portal.section.not_found' => 'Section not found',
    'client_portal.nav.home' => 'Home',

    'client_portal.section.profile' => 'Profile',
    'client_portal.profile.name' => 'Name',
    'client_portal.profile.tel' => 'Phone',
    'client_portal.profile.mail' => 'Email',
    'client_portal.profile.birthday' => 'Birthday',
    'client_portal.profile.gender' => 'Gender',
    'client_portal.profile.password_new' => 'New password',
    'client_portal.profile.password_hint' => 'Leave empty to keep current password',
    'client_portal.profile.save' => 'Save',
    'client_portal.profile.saved' => 'Profile saved',
    'client_portal.profile.home_card' => 'Contact details and password',

    'client_portal.flash.need_login' => 'Please sign in',
];
