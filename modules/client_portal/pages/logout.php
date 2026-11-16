<?php
declare(strict_types=1);

ClientPortalAuth::logout();
if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true]);
    return;
}
redirect('/cabinet/login');
