<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $day */
/** @var string $progress */
/** @var array<int,string> $pollNames */
/** @var array<int,string> $staffNames */
/** @var bool $canEdit */
$items = is_array($items ?? null) ? $items : [];
$pollNames = is_array($pollNames ?? null) ? $pollNames : [];
$staffNames = is_array($staffNames ?? null) ? $staffNames : [];
$day = (string)($day ?? '');
$progress = (string)($progress ?? '');
$canEdit = !empty($canEdit);

$resolveStaffId = static function (array $row, string $role) use ($staffNames): array {
    if ($role === 'admin') {
        $id = (int)($row['id_proc_admin'] ?? 0);
        if ($id < 1) {
            $id = (int)($row['proc_admin'] ?? 0);
        }
        if ($id < 1) {
            $resp = (string)($row['resp_users'] ?? '');
            if (preg_match_all('/#(\d+)#/', $resp, $m) && isset($m[1][1])) {
                $id = (int)$m[1][1];
            }
        }
    } else {
        $id = (int)($row['id_proc_operator'] ?? 0);
        if ($id < 1) {
            $id = (int)($row['proc_operator'] ?? 0);
        }
        if ($id < 1) {
            $resp = (string)($row['resp_users'] ?? '');
            if (preg_match('/#(\d+)#/', $resp, $m)) {
                $id = (int)$m[1];
            }
        }
    }
    $name = $id > 0 ? (string)($staffNames[$id] ?? ('#' . $id)) : '';
    return [$id, $name];
};

$resolveScore = static function (array $row): int {
    if (isset($row['score']) && $row['score'] !== null && (int)$row['score'] >= 1) {
        return (int)$row['score'];
    }
    $staff = isset($row['score_staff']) && $row['score_staff'] !== null && $row['score_staff'] !== ''
        ? (int)$row['score_staff'] : 0;
    $service = isset($row['score_service']) && $row['score_service'] !== null && $row['score_service'] !== ''
        ? (int)$row['score_service'] : 0;
    if (($staff < 1 || $service < 1) && class_exists('PollSpeechScore', false)
        && class_exists('PollAnswersCodec', false)
    ) {
        $opts = PollAnswersCodec::decode((string)($row['data_opts'] ?? ''));
        if (is_array($opts)) {
            $decoded = PollSpeechScore::fromDataOpts($opts);
            if ($staff < 1) {
                $staff = (int)$decoded['staff'];
            }
            if ($service < 1) {
                $service = (int)$decoded['service'];
            }
        }
    }
    if ($staff >= 1) {
        return $staff;
    }
    return $service >= 1 ? $service : 0;
};

$procLabel = static function (array $row): string {
    $procId = (int)($row['id_proc'] ?? 0);
    if ($procId < 1) {
        return '—';
    }
    $start = (int)($row['proc_dtstart'] ?? 0);
    $end = (int)($row['proc_dtend'] ?? 0);
    $parts = ['#' . $procId];
    if ($start > 0) {
        $when = date('d.m.Y H:i', $start);
        if ($end > $start) {
            $when .= '–' . date('H:i', $end);
        }
        $parts[] = $when;
    }
    return implode(' · ', $parts);
};
?>
<section class="panel">
  <form method="get" action="/poll-speech" class="filters-row">
    <label><?= te('poll_speech.list.filter_day') ?>
      <input type="date" name="day" value="<?= e($day) ?>">
    </label>
    <label><?= te('poll_speech.list.filter_progress') ?>
      <select name="progress">
        <option value=""><?= te('poll_speech.list.progress_all') ?></option>
        <option value="open"<?= $progress === 'open' ? ' selected' : '' ?>><?= te('poll_speech.list.progress_open') ?></option>
        <option value="done"<?= $progress === 'done' ? ' selected' : '' ?>><?= te('poll_speech.list.progress_done') ?></option>
        <option value="speech"<?= $progress === 'speech' ? ' selected' : '' ?>><?= te('poll_speech.list.progress_speech') ?></option>
      </select>
    </label>
    <button type="submit" class="btn-secondary"><?= te('poll_speech.btn.filter') ?></button>
  </form>

  <?php if ($canEdit && $day !== ''): ?>
    <form method="post" action="/poll-speech/fill-day" class="inline-form" style="margin:0.75rem 0">
      <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
      <input type="hidden" name="day" value="<?= e($day) ?>">
      <button type="submit" class="btn-primary"><?= te('poll_speech.list.fill_day') ?></button>
    </form>
  <?php endif; ?>
  <?php if ($canEdit && !empty($canSuper)): ?>
    <form method="post" action="/poll-speech/fill-day" class="inline-form" style="margin:0.75rem 0"
          onsubmit="return confirm('<?= e(t('poll_speech.recreate.confirm')) ?>');">
      <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
      <input type="hidden" name="recreate" value="1">
      <button type="submit" class="btn-secondary"><?= te('poll_speech.list.recreate_all') ?></button>
    </form>
  <?php endif; ?>

  <?php if ($items === []): ?>
    <p class="muted"><?= te('poll_speech.list.empty') ?></p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th><?= te('poll_speech.col.id') ?></th>
          <th><?= te('poll_speech.col.poll') ?></th>
          <th><?= te('poll_speech.col.client') ?></th>
          <th><?= te('poll_speech.col.proc') ?></th>
          <th><?= te('poll_speech.col.staff') ?></th>
          <th><?= te('poll_speech.col.score') ?></th>
          <th><?= te('poll_speech.col.snippet') ?></th>
          <th><?= te('poll_speech.col.dates') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $row): ?>
          <?php
            $id = (int)($row['id'] ?? 0);
            $pollId = (int)($row['id_poll'] ?? 0);
            $procId = (int)($row['id_proc'] ?? 0);
            [$opId] = $resolveStaffId($row, 'executor');
            [$admId] = $resolveStaffId($row, 'admin');
            $score = $resolveScore($row);
            $snippet = '';
            if (class_exists('PollAnswersCodec', false)) {
                $texts = PollAnswersCodec::decode((string)($row['data_text'] ?? ''));
                if (is_array($texts)) {
                    foreach ($texts as $tv) {
                        $tv = trim((string)$tv);
                        if ($tv !== '') {
                            $snippet = $tv;
                            break;
                        }
                    }
                }
            }
            $done = (int)($row['dt_completed'] ?? 0);
            $created = (int)($row['dt_create'] ?? 0);
          ?>
          <tr>
            <td><a href="/poll-invites/<?= $id ?>/edit"><?= $id ?></a></td>
            <td><?= e(($pollNames[$pollId] ?? '') !== '' ? '#' . $pollId . ' ' . $pollNames[$pollId] : '#' . $pollId) ?></td>
            <td><?= (int)($row['id_user'] ?? 0) ?></td>
            <td>
              <?php if ($procId > 0 && ModuleManager::isEnabled('procedures')): ?>
                <a href="/procedures/<?= $procId ?>"><?= e($procLabel($row)) ?></a>
              <?php else: ?>
                <?= e($procLabel($row)) ?>
              <?php endif; ?>
            </td>
            <td class="poll-speech-stack">
              <div><?= $opId > 0 && class_exists('UsersRepository', false)
                ? UsersRepository::badgeHtml($opId, true, true) : '—' ?></div>
              <div><?= $admId > 0 && class_exists('UsersRepository', false)
                ? UsersRepository::badgeHtml($admId, true, true) : '—' ?></div>
            </td>
            <td class="poll-speech-score"><?= $score >= 1 ? (int)$score : '—' ?></td>
            <td class="poll-speech-snippet"><?= $snippet !== '' ? e_nl($snippet) : '—' ?></td>
            <td class="poll-speech-stack">
              <div><?= $created > 0 ? e(date('d.m.Y H:i', $created)) : '—' ?></div>
              <div class="muted"><?= $done > 0 ? e(date('d.m.Y H:i', $done)) : '—' ?></div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted"><?= e((string)(int)($total ?? count($items))) ?></p>
  <?php endif; ?>
</section>
