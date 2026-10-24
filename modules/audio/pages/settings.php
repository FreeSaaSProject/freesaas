<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$message = '';
$error = '';
$workerTokenPlain = '';
$workerTokenJustGenerated = false;

$asrAuthLib = base_path('modules/asr/lib/AsrWorkerAuth.php');
if (is_file($asrAuthLib)) {
    require_once $asrAuthLib;
}
$canWorkerToken = class_exists(AsrWorkerAuth::class, false);

$resolveTab = static function () use ($canWorkerToken): string {
    $tab = trim((string)($_POST['audio_settings_tab'] ?? $_GET['tab'] ?? ''));
    if ($tab === '' && (max(0, (int)($_GET['cov_position_id'] ?? 0)) > 0
        || max(0, (int)($_GET['cov_admin_id'] ?? 0)) > 0)
    ) {
        $tab = 'coverage';
    }
    if ($tab === '') {
        $tab = 'storage';
    }
    if ($tab === 'worker' && !$canWorkerToken) {
        $tab = 'storage';
    }

    return $tab;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'worker_token_generate') {
            if (!$canWorkerToken) {
                throw new RuntimeException(t('audio.settings.worker_token.asr_required'));
            }
            if ($wantsJson) {
                AgentHttp::error('worker_token_generate is UI-only', 403);
            }
            $workerTokenPlain = AsrWorkerAuth::generateAndPersist();
            $workerTokenJustGenerated = true;
            $message = t('audio.settings.worker_token.generated');
        } else {
            $post = $_POST;
            // Only persist the active tab's fields (one panel per request).
            if (!empty($post['audio_storage_save'])) {
                $map = [
                    AudioSettingsRepository::KEY_MODE => trim((string)($post['audio_storage_mode'] ?? 'local')),
                    AudioSettingsRepository::KEY_INBOX => trim((string)($post['audio_inbox'] ?? 'audio')),
                    AudioSettingsRepository::KEY_PARSED => trim((string)($post['audio_parsed'] ?? 'audioParsed')),
                    AudioSettingsRepository::KEY_CHUNKS => trim((string)($post['audio_chunks'] ?? 'audiochunks')),
                    AudioSettingsRepository::KEY_FTP_HOST => trim((string)($post['audio_ftp_host'] ?? '')),
                    AudioSettingsRepository::KEY_FTP_PORT => trim((string)($post['audio_ftp_port'] ?? '21')),
                    AudioSettingsRepository::KEY_FTP_USER => trim((string)($post['audio_ftp_user'] ?? '')),
                    AudioSettingsRepository::KEY_FTP_BASE => trim((string)($post['audio_ftp_base'] ?? '/')),
                    AudioSettingsRepository::KEY_FTP_HTTP => trim((string)($post['audio_ftp_http'] ?? '')),
                    AudioSettingsRepository::KEY_STEM_CACHE => trim((string)($post['audio_stem_cache'] ?? 'var/audio_stem_cache')),
                ];
                if ($map[AudioSettingsRepository::KEY_MODE] !== 'ftp') {
                    $map[AudioSettingsRepository::KEY_MODE] = 'local';
                }
                foreach ($map as $key => $value) {
                    AudioSettingsRepository::set($key, $value);
                }
                // Password field is always posted on storage tab; empty clears it.
                AudioSettingsRepository::setFtpPass((string)($post['audio_ftp_pass'] ?? ''));
            }

            $saveResult = EventBus::emit('audio.settings.save', [
                'post' => $post,
                'files' => $_FILES,
                'warnings' => [],
            ]);
            $saveWarnings = is_array($saveResult) && is_array($saveResult['warnings'] ?? null)
                ? $saveResult['warnings']
                : [];

            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'saved' => true,
                    'cfg' => AudioSettingsRepository::storageConfigForAgent(),
                    'worker_token_configured' => $canWorkerToken && AsrWorkerAuth::isConfigured(),
                    'warnings' => $saveWarnings,
                ]);
                return;
            }
            $q = ['tab' => $resolveTab()];
            $covPos = max(0, (int)($post['cov_position_id'] ?? $_GET['cov_position_id'] ?? 0));
            $covAdm = max(0, (int)($post['cov_admin_id'] ?? $_GET['cov_admin_id'] ?? 0));
            if ($covPos > 0) {
                $q['cov_position_id'] = $covPos;
            }
            if ($covAdm > 0) {
                $q['cov_admin_id'] = $covAdm;
            }
            if ($saveWarnings !== []) {
                flash_set('warn', t('core.common.saved') . ' ' . implode(' ', array_map('strval', $saveWarnings)));
            } else {
                flash_set('ok', t('core.common.saved'));
            }
            redirect('/audio/settings?' . http_build_query($q));
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $workerTokenJustGenerated = false;
    }
}

// First visit / missing token: create once so workers can be wired without editing ini by hand.
if (
    !$wantsJson
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && $canWorkerToken
    && !AsrWorkerAuth::isConfigured()
) {
    try {
        $workerTokenPlain = AsrWorkerAuth::ensureGenerated();
        if ($workerTokenPlain !== '') {
            $workerTokenJustGenerated = true;
            $message = t('audio.settings.worker_token.generated');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$flash = flash_take();
if (is_array($flash)) {
    $ftype = (string)($flash['type'] ?? '');
    $fmsg = (string)($flash['message'] ?? '');
    if ($fmsg !== '') {
        if ($ftype === 'error') {
            $error = $error !== '' ? $error : $fmsg;
        } elseif ($ftype === 'warn') {
            $error = $error !== '' ? $error : $fmsg;
            if ($message === '') {
                $message = '';
            }
        } else {
            $message = $message !== '' ? $message : $fmsg;
        }
    }
}

$cfg = AudioSettingsRepository::storageConfig();
$sectionsPayload = EventBus::emit('audio.settings.sections', [
    'html' => '',
    'sections' => [],
    'cfg' => $cfg,
]);
$extraSections = is_array($sectionsPayload) && is_array($sectionsPayload['sections'] ?? null)
    ? $sectionsPayload['sections']
    : [];
$legacyHtml = is_array($sectionsPayload) ? (string)($sectionsPayload['html'] ?? '') : '';

$workerTokenConfigured = $canWorkerToken && AsrWorkerAuth::isConfigured();
$workerTokenMasked = $canWorkerToken ? AsrWorkerAuth::mask() : '';
// Superadmin settings: full token for copy-paste ingest URL (uploader has no header auth).
if ($canWorkerToken && $workerTokenPlain === '' && $workerTokenConfigured) {
    $workerTokenPlain = AsrWorkerAuth::configuredToken();
}
$ingestUrl = '';
if ($workerTokenConfigured && $workerTokenPlain !== '' && class_exists('AppRuntime', false)) {
    $ingestUrl = AppRuntime::adminUrl(
        '/audio/jobs/ingest?token=' . rawurlencode($workerTokenPlain)
    );
}

$panels = [];
$panels[] = [
    'id' => 'storage',
    'title' => t('audio.settings.storage'),
    'weight' => 10,
    'html' => View::capture('_settings_storage', ['cfg' => $cfg], 'audio'),
];
foreach ($extraSections as $sec) {
    if (!is_array($sec)) {
        continue;
    }
    $id = trim((string)($sec['id'] ?? ''));
    $html = (string)($sec['html'] ?? '');
    if ($id === '' || $html === '') {
        continue;
    }
    $titleKey = (string)($sec['title'] ?? '');
    $panels[] = [
        'id' => $id,
        'title' => $titleKey !== '' ? t($titleKey) : $id,
        'weight' => (int)($sec['weight'] ?? 50),
        'html' => $html,
    ];
}
if ($legacyHtml !== '') {
    $panels[] = [
        'id' => 'extra',
        'title' => t('audio.settings.tab_extra'),
        'weight' => 80,
        'html' => $legacyHtml,
    ];
}
if ($canWorkerToken) {
    $panels[] = [
        'id' => 'worker',
        'title' => t('audio.settings.worker_token.title'),
        'weight' => 90,
        'html' => View::capture('_settings_worker_token', [
            'workerTokenConfigured' => $workerTokenConfigured,
            'workerTokenJustGenerated' => $workerTokenJustGenerated,
            'workerTokenPlain' => $workerTokenJustGenerated ? $workerTokenPlain : '',
            'workerTokenMasked' => $workerTokenMasked,
            'ingestUrl' => $ingestUrl,
        ], 'audio'),
    ];
}
usort($panels, static function (array $a, array $b): int {
    $w = ((int)$a['weight']) <=> ((int)$b['weight']);
    if ($w !== 0) {
        return $w;
    }

    return strcmp((string)$a['id'], (string)$b['id']);
});

$activeTab = $resolveTab();
$knownIds = array_column($panels, 'id');
if (!in_array($activeTab, $knownIds, true)) {
    $activeTab = (string)($knownIds[0] ?? 'storage');
}

$workerExtraHtml = '';
if ($canWorkerToken) {
    ob_start();
    ?>
<form method="post" class="actions settings-worker-token-gen" style="margin-top:.75rem"
      onsubmit="return <?= $workerTokenConfigured ? 'confirm(' . tj('audio.settings.worker_token.confirm_regen') . ')' : 'true' ?>;">
  <input type="hidden" name="action" value="worker_token_generate">
  <input type="hidden" name="audio_settings_tab" value="worker">
  <button class="btn-secondary" type="submit">
    <?= te($workerTokenConfigured ? 'audio.settings.worker_token.btn_regen' : 'audio.settings.worker_token.btn_generate') ?>
  </button>
</form>
<script>
(function () {
  var copied = <?= json_encode(t('audio.settings.worker_token.copied'), JSON_UNESCAPED_UNICODE) ?>;
  var promptLabel = <?= json_encode(t('audio.settings.worker_token.copy_prompt'), JSON_UNESCAPED_UNICODE) ?>;
  document.querySelectorAll('[data-copy-text]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy-text') || '';
      if (!text) return;
      var label = btn.textContent;
      var done = function () {
        btn.textContent = copied;
        setTimeout(function () { btn.textContent = label; }, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () {
          window.prompt(promptLabel, text);
        });
      } else {
        window.prompt(promptLabel, text);
      }
    });
  });
})();
</script>
    <?php
    $workerExtraHtml = (string)ob_get_clean();
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'cfg' => AudioSettingsRepository::storageConfigForAgent(),
        'worker_token_configured' => $workerTokenConfigured,
        'ingest_url_configured' => $ingestUrl !== '',
        'tabs' => array_map(static fn (array $p): string => (string)$p['id'], $panels),
    ]);
    return;
}

View::render('settings', [
    'title' => t('audio.menu.settings'),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
    'panels' => $panels,
    'activeTab' => $activeTab,
    'canWorkerToken' => $canWorkerToken,
    'workerTokenConfigured' => $workerTokenConfigured,
    'workerTokenJustGenerated' => $workerTokenJustGenerated,
    'workerTokenPlain' => $workerTokenJustGenerated ? $workerTokenPlain : '',
    'workerTokenMasked' => $workerTokenMasked,
    'ingestUrl' => $ingestUrl,
    'workerHtml' => $workerExtraHtml,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.list'), 'href' => '/audio'],
        ['label' => t('audio.menu.settings'), 'current' => true],
    ]),
], 'layout', 'audio');
