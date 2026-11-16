<?php
declare(strict_types=1);

ClientPortalAuth::ensureBag();
if (!ClientPortalAuth::check()) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error(t('client_portal.flash.need_login'), 401);
        return;
    }
    redirect('/cabinet/login');
}

$sectionId = preg_replace('/[^a-z0-9_]/', '', (string)(Router::param('section') ?? '')) ?? '';
$meta = ClientPortalSections::findVisible($sectionId);
if ($meta === null) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error(t('client_portal.section.not_found'), 404);
        return;
    }
    http_response_code(404);
    ClientPortalView::render('cabinet', [
        'title' => t('client_portal.section.not_found'),
        'cabinet_nav' => ClientPortalSections::nav(''),
        'cabinet_body' => '<p>' . e(t('client_portal.section.forbidden')) . '</p>',
    ]);
    return;
}

$clientId = ClientPortalAuth::clientId();
$flash = '';

if ($sectionId === 'profile' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !AgentHttp::wantsJson() && !Csrf::verify()) {
        Csrf::deny('csrf');
    }
    $client = ClientsRepository::find($clientId);
    if ($client !== null) {
        $post = $_POST;
        $payload = EventBus::emit('client_portal.profile.save', [
            'client_id' => $clientId,
            'client' => $client,
            'post' => $post,
            'error' => '',
            'saved' => false,
        ]);
        if ((string)($payload['error'] ?? '') !== '') {
            $flash = (string)$payload['error'];
        } elseif (!empty($payload['saved'])) {
            $flash = t('client_portal.profile.saved');
        } else {
            try {
                $merged = array_merge($client, [
                    'name' => (string)($post['name'] ?? $client['name'] ?? ''),
                    'tel' => (string)($post['tel'] ?? $client['tel'] ?? ''),
                    'mail' => (string)($post['mail'] ?? $client['mail'] ?? ''),
                    'birthday' => (string)($post['birthday'] ?? $client['birthday'] ?? ''),
                    'gender' => (string)(($post['gender'] ?? $client['gender'] ?? '') === '' || ($post['gender'] ?? null) === null
                        ? (string)($client['gender'] ?? '')
                        : $post['gender']),
                    'enabled' => 1,
                    'status_id' => (int)($client['status_id'] ?? 0),
                    'staff_user_id' => (string)(int)($client['staff_user_id'] ?? 0),
                    'surname' => (string)($client['surname'] ?? ''),
                    'pname' => (string)($client['pname'] ?? ''),
                    'patronymic' => (string)($client['patronymic'] ?? ''),
                    'passport_serie' => (string)($client['passport_serie'] ?? ''),
                    'passport_number' => (string)($client['passport_number'] ?? ''),
                    'passport_issued_by' => (string)($client['passport_issued_by'] ?? ''),
                    'passport_issued_at' => (string)($client['passport_issued_at'] ?? ''),
                    'passport_birthday' => (string)($client['passport_birthday'] ?? ''),
                    'address_passport' => (string)($client['address_passport'] ?? ''),
                    'address_fact' => (string)($client['address_fact'] ?? ''),
                    'subscribe' => (int)($client['subscribe'] ?? 0),
                    'notes' => (string)($client['notes'] ?? ''),
                ]);
                $data = ClientsRepository::dataFromPost($merged);
                $data['enabled'] = (int)($client['enabled'] ?? 1);
                $data['status_id'] = (int)($client['status_id'] ?? 0);
                $data['staff_user_id'] = ((int)($client['staff_user_id'] ?? 0) > 0)
                    ? (int)$client['staff_user_id']
                    : null;
                ClientsRepository::update($clientId, $data);
                $newPass = (string)($post['password_new'] ?? '');
                if ($newPass !== '') {
                    ClientPortalAuth::storePassword($clientId, $newPass);
                }
                $flash = t('client_portal.profile.saved');
            } catch (Throwable $e) {
                $flash = $e->getMessage();
            }
        }
    }
}

$html = ClientPortalSections::renderSection($sectionId, $clientId);
if ($sectionId === 'profile' && $flash !== '') {
    $html = '<p class="flash ok">' . e($flash) . '</p>' . $html;
}

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'section' => $sectionId,
        'title' => $meta['title'],
        'html' => $html,
        'flash' => $flash,
    ]);
    return;
}

if ($html === '') {
    $html = '<p class="muted">' . e(t('client_portal.section.forbidden')) . '</p>';
}

ClientPortalView::render('cabinet', [
    'title' => $meta['title'],
    'cabinet_nav' => ClientPortalSections::nav($sectionId),
    'cabinet_body' => $html,
]);
