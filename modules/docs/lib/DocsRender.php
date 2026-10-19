<?php
declare(strict_types=1);

/**
 * Merge an instance (or sample data) into a version template: sanitized template + escaped values
 * + engine-built trusted fragments ({fields}, {event.items}, {dossier.*}, {qr}).
 * Syntax: {key}, {key|default when empty}, gender {g:f|m|x}, switch {?flag:yes|no}.
 */
final class DocsRender
{
    /** Context keys beyond schema fields (definition editor cheat-sheet; sample data covers each). */
    public const CONTEXT_KEYS = [
        'client.name', 'client.surname', 'client.pname', 'client.patronymic', 'client.gender',
        'client.tel', 'client.mail', 'client.birthday', 'client.birth_day', 'client.birth_month', 'client.birth_year',
        'client.passport', 'client.passport_serie', 'client.passport_number', 'client.passport_issued_at', 'client.passport_issued_by',
        'client.address', 'client.address_fact', 'client.address_passport', 'client.locality',
        'card.no', 'card.day', 'card.month', 'card.year',
        'event.id', 'event.date', 'event.time', 'event.items', 'event.total', 'event.total_text',
        'event.staff', 'event.procedures', 'event.service_types',
        'doctor.name', 'doctor.position', 'visit.recommends', 'visit.date', 'visit.anamnesis_life',
        'visit.objective_general', 'visit.next_text', 'contract.id', 'contract.date',
        'profile.bmi', 'profile.ana_gyno_print',
        'dossier.diagnoses', 'dossier.visits', 'dossier.procedures',
        'org.ogrn_boxes', 'doc.id', 'doc.code', 'doc.name', 'doc.date', 'doc.expire', 'staff.name', 'qr',
    ];

    /** Flags for {?flag:yes|no}. */
    public const FLAGS = ['visit.first', 'client.male', 'client.female'];

    /** Context values that are engine-built HTML (not escaped). */
    private const HTML_KEYS = [
        'fields', 'event.items', 'qr', 'dossier', 'org.signature', 'org.ogrn_boxes',
        'dossier.diagnoses', 'dossier.visits', 'dossier.procedures',
    ];

    /** Visit sheet: profile fields printed on the first visit (form 025/u items 24). */
    private const PROFILE_ANAMNESIS = ['ana_life', 'ana_gyno', 'ana_alerg'];
    private const PROFILE_OBJECTIVE = ['obj_skin', 'obj_sliz', 'obj_nogot'];

    /** Facsimile image from organization settings (http(s) or site-relative URL). */
    public static function signatureHtml(): string
    {
        $url = DocsConfig::org()['signature'];
        if ($url === '' || !html_safe_url($url) || preg_match('#^\s*data:#i', $url)) {
            return '';
        }
        return '<img class="docs-print-signature" src="' . e($url) . '" alt="" style="height:1cm">';
    }

    /**
     * @param array<string,mixed> $instance DocsInstancesRepository::find() row (with payload)
     */
    public static function render(array $instance): string
    {
        $ver = DocsDefsRepository::version((int)$instance['id_version']);
        if ($ver === null) {
            return '';
        }
        [$vars, $gender, $flags] = self::context($instance, $ver);
        return self::merge($ver, $vars, $gender, $flags);
    }

    /**
     * Template with demo values (no instance, nothing stored).
     *
     * @param array<string,mixed> $def
     * @param array<string,mixed> $ver
     */
    public static function renderSample(array $def, array $ver): string
    {
        return self::merge($ver, DocsSample::vars($def, $ver), 0, ['visit.first' => true, 'client.female' => true]);
    }

    /**
     * Render a version with a prepared context (dossier pages, samples).
     *
     * @param array<string,mixed> $ver
     * @param array<string,string> $vars
     * @param array<string,bool> $flags
     */
    public static function renderWith(array $ver, array $vars, ?int $gender, array $flags = []): string
    {
        return self::merge($ver, $vars, $gender, $flags);
    }

    /**
     * @param array<string,mixed> $ver
     * @param array<string,string> $vars key => value (HTML_KEYS trusted, others plain text)
     * @param int|null $gender 1 male, 0 female, null unknown
     * @param array<string,bool> $flags
     */
    private static function merge(array $ver, array $vars, ?int $gender, array $flags = []): string
    {
        $template = DocsHtml::sanitizeTemplate((string)$ver['print_html']);
        if (trim($template) === '') {
            $template = '<h2>{doc.name}</h2>{fields}{qr}';
        }
        $template = (string)preg_replace_callback(
            '/\{\?([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)?):([^{}|]*)\|([^{}|]*)\}/u',
            static fn(array $m): string => !empty($flags[$m[1]]) ? $m[2] : $m[3],
            $template
        );
        // Legacy gender forms [f|m|x] → {g:f|m|x}; x (or "f/m") when gender is unknown.
        $template = (string)preg_replace_callback(
            '/\{g:([^{}|]*)\|([^{}|]*)(?:\|([^{}|]*))?\}/u',
            static function (array $m) use ($gender): string {
                if ($gender === 1) {
                    return $m[2];
                }
                if ($gender === 0) {
                    return $m[1];
                }
                $unknown = $m[3] ?? '';
                return $unknown !== '' ? $unknown : ($m[1] === $m[2] ? $m[1] : $m[1] . '/' . $m[2]);
            },
            $template
        );
        $body = (string)preg_replace_callback(
            '/\{([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)?)(?:\|([^{}]*))?\}/u',
            static function (array $m) use ($vars): string {
                $key = $m[1];
                $hasDefault = array_key_exists(2, $m);
                if (!array_key_exists($key, $vars)) {
                    return $hasDefault ? e($m[2]) : $m[0];
                }
                $value = (string)$vars[$key];
                if ($hasDefault && trim(strip_tags($value)) === '') {
                    return e($m[2]);
                }
                return in_array($key, self::HTML_KEYS, true) ? $value : nl2br(e($value), false);
            },
            $template
        );
        $copies = max(1, (int)$ver['copies']);
        if ($copies === 1) {
            return $body;
        }
        return str_repeat('<div class="docs-print-copy">' . $body . '</div>', $copies);
    }

    /**
     * Placeholder keys for the definition editor cheat-sheet.
     *
     * @param array{sections:list<array<string,mixed>>} $schema
     * @return list<string>
     */
    public static function placeholderKeys(array $schema, string $kind): array
    {
        $keys = ['fields'];
        foreach (DocsSchema::fieldMap($schema) as $fid => $_) {
            $keys[] = $fid;
        }
        foreach (self::CONTEXT_KEYS as $k) {
            $keys[] = $k;
        }
        foreach (DocsConfig::ORG_KEYS as $k) {
            $keys[] = 'org.' . $k;
        }
        if ($kind === 'medcard_dossier') {
            $keys[] = 'dossier';
        }
        $profileVer = self::profileVersion();
        if ($profileVer !== null && $kind !== 'medcard_profile') {
            foreach (DocsSchema::fieldMap($profileVer['schema']) as $fid => $_) {
                $keys[] = 'profile.' . $fid;
            }
        }
        $keys[] = 'g:' . t('docs.def.gender_f') . '|' . t('docs.def.gender_m') . '|';
        $keys[] = '?visit.first:' . t('docs.def.flag_yes') . '|' . t('docs.def.flag_no');
        return $keys;
    }

    /** @return array<string,mixed>|null */
    public static function profileVersion(): ?array
    {
        $def = DocsDefsRepository::firstOfKind('medcard_profile');
        return $def !== null ? DocsDefsRepository::currentVersion($def) : null;
    }

    /**
     * Fields of one instance as printable sections.
     *
     * @param array{sections:list<array<string,mixed>>} $schema
     * @param array<string,mixed> $payload
     * @param array<int,string>|null $staffNames override (samples use demo names)
     */
    public static function fieldsHtml(array $schema, array $payload, ?array $staffNames = null): string
    {
        if ($staffNames === null) {
            $staffIds = [];
            foreach (DocsSchema::fieldMap($schema) as $fid => $f) {
                if ((string)$f['type'] === 'staff' && (int)($payload[$fid] ?? 0) > 0) {
                    $staffIds[] = (int)$payload[$fid];
                }
            }
            $staffNames = $staffIds !== [] && class_exists('UsersRepository', false)
                ? UsersRepository::staffNamesIncluding($staffIds)
                : [];
        }
        $out = '';
        foreach ($schema['sections'] as $s) {
            if ($s['fields'] === []) {
                continue;
            }
            $out .= '<div class="docs-print-section">';
            if ((string)$s['title'] !== '') {
                $out .= '<h3>' . e((string)$s['title']) . '</h3>';
            }
            $out .= '<table class="docs-print-fields">';
            foreach ($s['fields'] as $f) {
                $val = DocsSchema::displayValue($f, $payload[(string)$f['id']] ?? null, $staffNames);
                $out .= '<tr><th>' . e((string)$f['label']) . '</th><td>'
                    . ($val !== '' ? nl2br(e($val), false) : '—') . '</td></tr>';
            }
            $out .= '</table></div>';
        }
        return $out;
    }

    /**
     * @param list<array{name:string,qty:int,price:float}> $lines
     */
    public static function itemsTable(array $lines): string
    {
        if ($lines === []) {
            return '';
        }
        $total = 0.0;
        $rows = '';
        foreach ($lines as $l) {
            $sum = $l['price'] * $l['qty'];
            $total += $sum;
            $rows .= '<tr><td>' . e($l['name']) . '</td><td style="text-align:right">' . (int)$l['qty']
                . '</td><td style="text-align:right">' . e(number_format($l['price'], 0, '.', ' '))
                . '</td><td style="text-align:right">' . e(number_format($sum, 0, '.', ' ')) . '</td></tr>';
        }
        return '<table class="docs-print-items"><thead><tr><th>' . e(t('docs.print.col_item')) . '</th><th>'
            . e(t('docs.print.col_qty')) . '</th><th>' . e(t('docs.print.col_price')) . '</th><th>'
            . e(t('docs.print.col_sum')) . '</th></tr></thead><tbody>' . $rows
            . '</tbody><tfoot><tr><th colspan="3" style="text-align:right">' . e(t('docs.print.total'))
            . '</th><th style="text-align:right">' . e(number_format($total, 0, '.', ' ')) . '</th></tr></tfoot></table>';
    }

    /** QR block for a hash (empty when the archive is off or the library is missing). */
    public static function qrHtml(string $hashExt, int $docId): string
    {
        if (!DocsConfig::qrArchive()) {
            return '';
        }
        $svg = DocsQr::svgHtml(DocsQr::archiveUrl($hashExt));
        return $svg === '' ? '' : '<div class="docs-print-qr">' . $svg
            . '<small>' . e(t('docs.print.qr_caption', ['id' => $docId])) . '</small></div>';
    }

    /** Form 025/u item 6 from the address (legacy heuristic: city / village markers). */
    public static function locality(string $address): string
    {
        if (preg_match('/(^|[\s,])(г|гор|город)\.?\s/iu', $address)) {
            return t('docs.print.locality_city');
        }
        if (preg_match('/(^|[\s,])(с|село|д|дер|пос|п)\.\s/iu', $address)) {
            return t('docs.print.locality_rural');
        }
        return t('docs.print.locality_any');
    }

    /** "Фамилия И.О." from a full name. */
    public static function shortName(string $full): string
    {
        $parts = preg_split('/\s+/u', trim($full)) ?: [];
        if (count($parts) < 2) {
            return trim($full);
        }
        $out = array_shift($parts) . ' ';
        foreach (array_slice($parts, 0, 2) as $p) {
            $out .= mb_substr($p, 0, 1) . '.';
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $instance
     * @param array<string,mixed> $ver
     * @return array{0:array<string,string>,1:int|null,2:array<string,bool>} [vars, client gender, flags]
     */
    private static function context(array $instance, array $ver, bool $nested = false): array
    {
        $vars = [];
        $payload = is_array($instance['payload'] ?? null) ? $instance['payload'] : [];
        $schema = $ver['schema'];
        $kind = (string)$instance['kind'];
        $template = (string)$ver['print_html'];
        foreach (DocsSchema::fieldMap($schema) as $fid => $f) {
            $vars[$fid] = DocsSchema::displayValue($f, $payload[$fid] ?? null);
        }
        $vars['fields'] = self::fieldsHtml($schema, $payload);

        $clientId = (int)$instance['id_client'];
        [$client, $gender] = self::clientVars($clientId);
        foreach ($client as $k => $v) {
            $vars['client.' . $k] = $v;
        }
        $flags = ['client.male' => $gender === 1, 'client.female' => $gender === 0];

        $eventId = (int)$instance['id_event'];
        $ev = self::eventVars($eventId);
        foreach ($ev['text'] as $k => $v) {
            $vars['event.' . $k] = $v;
        }
        $vars['event.items'] = $ev['items_html'];

        $visit = self::visitSheet($eventId, $kind === 'medcard_visit' ? $payload : null);
        $vars['visit.recommends'] = (string)($visit['recommends'] ?? '');
        $doctorId = (int)($visit['doctor_id'] ?? 0) ?: (int)$ev['operator_id'];
        foreach (self::doctorVars($doctorId) as $k => $v) {
            $vars['doctor.' . $k] = $v;
        }
        foreach (self::contractVars($clientId, (int)$instance['id']) as $k => $v) {
            $vars['contract.' . $k] = $v;
        }

        $profile = null;
        $pver = null;
        if ($clientId > 0) {
            $profile = $kind === 'medcard_profile' ? $instance : DocsInstancesRepository::latestForClient($clientId, 'medcard_profile');
            $pver = $profile !== null ? ($kind === 'medcard_profile' ? $ver : DocsDefsRepository::version((int)$profile['id_version'])) : null;
        }
        $profilePayload = $profile !== null ? (array)$profile['payload'] : [];
        $profileSchema = $pver !== null ? $pver['schema'] : ['sections' => []];
        foreach (self::profileVars($profileSchema, $profilePayload, $gender) as $k => $v) {
            $vars['profile.' . $k] = $v;
        }

        // Form 025/u card header: number = client id, fill date = first medical visit (else profile creation).
        $visitIds = $clientId > 0 ? DocsInstancesRepository::visitIdsForClient($clientId) : [];
        $cardTs = $profile !== null ? (int)$profile['dt_create'] : time();
        if ($visitIds !== []) {
            $first = DocsInstancesRepository::find($visitIds[0]);
            $cardTs = $first !== null ? self::visitTs($first) : $cardTs;
        }
        $vars += self::cardVars($clientId, $cardTs);

        if ($kind === 'medcard_visit') {
            $isFirst = $visitIds === [] || (int)$visitIds[0] === (int)$instance['id'];
            $flags['visit.first'] = $isFirst;
            $vars['visit.date'] = I18n::formatDate(self::visitTs($instance), 'date');
            $vars += self::visitHelperVars($payload, $profileSchema, $profilePayload, $gender, $isFirst);
        }

        // Client-level 025 sheets are built only when the template asks for them.
        if (!$nested && $clientId > 0 && strpos($template, '{dossier.') !== false) {
            $vars['dossier.diagnoses'] = strpos($template, '{dossier.diagnoses') !== false ? self::diagnosesHtml($visitIds) : '';
            $vars['dossier.visits'] = strpos($template, '{dossier.visits') !== false ? self::dossierVisitsHtml($visitIds) : '';
            $vars['dossier.procedures'] = strpos($template, '{dossier.procedures') !== false ? self::proceduresHtml($clientId) : '';
        }

        $printTs = (int)$instance['dt_print'] > 0 ? (int)$instance['dt_print'] : time();
        $def = DocsDefsRepository::find((int)$instance['id_def']);
        $expire = (int)$instance['dt_expire'];
        if ($expire < 1 && $def !== null && (int)$def['expire_days'] > 0) {
            $expire = $printTs + (int)$def['expire_days'] * 86400;
        }
        $vars['doc.id'] = (string)(int)$instance['id'];
        $vars['doc.code'] = (string)($def['code'] ?? '');
        $vars['doc.name'] = (string)($def['name'] ?? '');
        $vars['doc.date'] = I18n::formatDate($printTs, 'date');
        $vars['doc.expire'] = $expire > 0 ? I18n::formatDate($expire, 'date') : '';
        $vars['staff.name'] = (string)($_SESSION['user_name'] ?? '');
        foreach (DocsConfig::org() as $k => $v) {
            $vars['org.' . $k] = $v;
        }
        $vars['org.signature'] = self::signatureHtml();
        $vars['org.ogrn_boxes'] = self::boxes($vars['org.ogrn'], 13);
        $vars['qr'] = $nested ? '' : self::qrHtml((string)$instance['hash_ext'], (int)$instance['id']);
        if ($kind === 'medcard_dossier' && strpos($template, '{dossier}') !== false) {
            $vars['dossier'] = self::dossierHtml($clientId);
        }

        $extra = EventBus::emit('docs.merge.context', ['instance' => $instance, 'vars' => []]);
        if (is_array($extra) && is_array($extra['vars'] ?? null)) {
            foreach ($extra['vars'] as $k => $v) {
                if (is_string($k) && preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)?$/', $k) && is_scalar($v)
                    && !in_array($k, self::HTML_KEYS, true)
                ) {
                    $vars[$k] = (string)$v;
                }
            }
        }
        return [$vars, $gender, $flags];
    }

    /**
     * Profile fields as text + 025 helpers (BMI, gynecological anamnesis "n/a" for men).
     *
     * @param array{sections:list<array<string,mixed>>} $schema
     * @param array<string,mixed> $payload
     * @return array<string,string> key without "profile." prefix
     */
    public static function profileVars(array $schema, array $payload, ?int $gender, ?array $staffNames = null): array
    {
        $out = [];
        foreach (DocsSchema::fieldMap($schema) as $fid => $f) {
            $out[$fid] = DocsSchema::displayValue($f, $payload[$fid] ?? null, $staffNames ?? []);
        }
        $out['bmi'] = self::bmiText($payload['height_cm'] ?? null, $payload['weight_kg'] ?? null);
        $out['ana_gyno_print'] = $gender === 1 ? t('docs.print.not_applicable') : (string)($out['ana_gyno'] ?? '');
        return $out;
    }

    /** "22.9 — норма" from height (cm) and weight (kg); '' when unknown. */
    public static function bmiText($height, $weight): string
    {
        $h = is_numeric($height) ? (float)$height : 0.0;
        $w = is_numeric($weight) ? (float)$weight : 0.0;
        if ($h <= 0 || $w <= 0) {
            return '';
        }
        $bmi = round($w / (($h / 100) ** 2), 1);
        $band = 'obese3';
        foreach ([[16.0, 'deficit_severe'], [18.5, 'deficit'], [25.0, 'normal'], [30.0, 'overweight'], [35.0, 'obese1'], [40.0, 'obese2']] as [$limit, $key]) {
            if ($bmi <= $limit) {
                $band = $key;
                break;
            }
        }
        return $bmi . ' — ' . t('docs.print.bmi.' . $band);
    }

    /** Digits in bordered cells (OGRN box row of form 025/u). */
    public static function boxes(string $value, int $min): string
    {
        $digits = preg_split('//u', preg_replace('/\s+/u', '', $value) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while (count($digits) < $min) {
            $digits[] = "\u{00a0}";
        }
        $out = '';
        foreach ($digits as $d) {
            $out .= '<b style="border:1px solid #000;padding:0 2px;margin-right:1px">' . e($d) . '</b>';
        }
        return $out;
    }

    /** @return array<string,string> */
    public static function cardVars(int $clientId, int $ts): array
    {
        return [
            'card.no' => $clientId > 0 ? (string)$clientId : '',
            'card.day' => date('d', $ts),
            'card.month' => date('m', $ts),
            'card.year' => date('Y', $ts),
        ];
    }

    /** Visit sheet date: its event start, else creation. */
    private static function visitTs(array $visit): int
    {
        $eventId = (int)($visit['id_event'] ?? 0);
        if ($eventId > 0 && class_exists('EventsRepository', false)) {
            $ev = EventsRepository::find($eventId);
            if ($ev && (int)($ev['dtstart'] ?? 0) > 0) {
                return (int)$ev['dtstart'];
            }
        }
        return (int)($visit['dt_create'] ?? time());
    }

    /**
     * Form 025/u item 24: on the first visit the anamnesis of life and the general exam come
     * from the client profile; repeat visits print only the visit data.
     *
     * @param array<string,mixed> $visitPayload
     * @param array{sections:list<array<string,mixed>>} $profileSchema
     * @param array<string,mixed> $profilePayload
     * @return array<string,string>
     */
    public static function visitHelperVars(array $visitPayload, array $profileSchema, array $profilePayload, ?int $gender, bool $first): array
    {
        $out = ['visit.anamnesis_life' => '', 'visit.objective_general' => '', 'visit.next_text' => ''];
        $next = (string)($visitPayload['next_visit'] ?? '');
        if ($next !== '' && ($ts = strtotime($next . ' 00:00:00')) !== false) {
            $out['visit.next_text'] = t('docs.print.next_visit', ['date' => I18n::formatDate((int)$ts, 'date')]);
        }
        if (!$first) {
            return $out;
        }
        $fields = DocsSchema::fieldMap($profileSchema);
        $profile = self::profileVars($profileSchema, $profilePayload, $gender);
        $line = static function (array $ids) use ($fields, $profile): string {
            $bits = [];
            foreach ($ids as $fid) {
                if (!isset($fields[$fid])) {
                    continue;
                }
                $value = rtrim($fid === 'ana_gyno' ? $profile['ana_gyno_print'] : (string)($profile[$fid] ?? ''), " .\t\n\r");
                // Empty stays a blank line to fill by hand — never a made-up "no findings".
                $bits[] = (string)$fields[$fid]['label'] . ': ' . ($value !== '' ? $value : '__________');
            }
            return $bits === [] ? '' : implode('. ', $bits) . '.';
        };
        $out['visit.anamnesis_life'] = $line(self::PROFILE_ANAMNESIS);
        $general = $line(self::PROFILE_OBJECTIVE);
        $body = [];
        foreach (['height_cm', 'weight_kg', 'eyes', 'hairs'] as $fid) {
            if (isset($fields[$fid]) && (string)($profile[$fid] ?? '') !== '') {
                $body[] = (string)$fields[$fid]['label'] . ': ' . $profile[$fid];
            }
        }
        if ($profile['bmi'] !== '') {
            $body[] = t('docs.print.bmi_label') . ': ' . $profile['bmi'];
        }
        $out['visit.objective_general'] = trim($general . ($body !== [] ? ' ' . implode('; ', $body) . '.' : ''));
        return $out;
    }

    /**
     * Item 20: final (refined) diagnoses — first occurrence of each code across visits.
     *
     * @param list<int> $visitIds ordered
     * @param list<array{date:string,diagnosis:string,doctor:string}>|null $rows prepared rows (samples)
     */
    public static function diagnosesHtml(array $visitIds, ?array $rows = null): string
    {
        if ($rows === null) {
            $rows = [];
            $seen = [];
            foreach ($visitIds as $vid) {
                $visit = DocsInstancesRepository::find($vid);
                if ($visit === null) {
                    continue;
                }
                $doctor = self::doctorVars((int)($visit['payload']['doctor_id'] ?? 0))['name'];
                foreach (preg_split('/\s*[,;]\s*/u', trim((string)($visit['payload']['dia_codes'] ?? ''))) ?: [] as $dia) {
                    $key = mb_strtolower($dia);
                    if ($dia === '' || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $rows[] = ['date' => I18n::formatDate(self::visitTs($visit), 'date'), 'diagnosis' => $dia, 'doctor' => $doctor];
                }
            }
        }
        $html = '<table border="1" cellpadding="3" cellspacing="0" width="100%" class="docs-f025-table"><tr><th>'
            . e(t('docs.print.f025.dia_date')) . '</th><th>' . e(t('docs.print.f025.dia_final')) . '</th><th>'
            . e(t('docs.print.f025.dia_first')) . '</th><th>' . e(t('docs.print.f025.doctor')) . '</th></tr>';
        foreach ($rows as $r) {
            $html .= '<tr><td>' . e($r['date']) . '</td><td>' . e($r['diagnosis']) . '</td><td>'
                . e(t('docs.print.f025.first_time')) . '</td><td>' . e($r['doctor']) . '</td></tr>';
        }
        for ($i = count($rows); $i < 11; $i++) {
            $html .= '<tr><td>&nbsp;</td><td></td><td></td><td></td></tr>';
        }
        return $html . '</table>';
    }

    /**
     * Items 24–25: every visit sheet rendered with its own template, one per page.
     *
     * @param list<int> $visitIds ordered
     */
    private static function dossierVisitsHtml(array $visitIds): string
    {
        $out = [];
        foreach ($visitIds as $vid) {
            $visit = DocsInstancesRepository::find($vid);
            $vver = $visit !== null ? DocsDefsRepository::version((int)$visit['id_version']) : null;
            if ($visit === null || $vver === null) {
                continue;
            }
            [$vvars, $vgender, $vflags] = self::context($visit, $vver, true);
            $out[] = self::merge($vver, $vvars, $vgender, $vflags);
        }
        return implode('<div class="docs-page-break"></div>', $out);
    }

    /**
     * "Performed procedures" sheet: medical journal rows of the client.
     *
     * @param list<array{date:string,name:string,staff:string}>|null $rows prepared rows (samples)
     */
    public static function proceduresHtml(int $clientId, ?array $rows = null): string
    {
        if ($rows === null) {
            $rows = [];
            if ($clientId > 0 && class_exists('ProceduresRepository', false)) {
                $list = ProceduresRepository::list([
                    'id_user' => $clientId,
                    'enabled' => 1,
                    'order' => 'p.DTSTART ASC',
                    'limit' => 1000,
                    'skip_total' => true,
                ])['items'];
                foreach ($list as $pr) {
                    if (!DocsSpawner::isMedicalType((int)($pr['type'] ?? 0))) {
                        continue;
                    }
                    $rows[] = [
                        'date' => I18n::formatDate((int)$pr['dtstart'], 'date'),
                        'name' => (string)($pr['product_name'] ?? ('#' . (int)$pr['id_good'])),
                        'staff' => self::shortName((string)($pr['operator_name'] ?? '')),
                    ];
                }
            }
        }
        $html = '<h3>' . e(t('docs.print.f025.procedures')) . '</h3><ol class="docs-f025-procedures">';
        foreach ($rows as $r) {
            $html .= '<li>[' . e($r['date']) . '] ' . e($r['name'])
                . ($r['staff'] !== '' ? '. ' . e(t('docs.print.f025.med_worker')) . ': ' . e($r['staff']) : '') . '</li>';
        }
        if ($rows === []) {
            $html .= '<li>' . e(t('docs.print.dossier_empty')) . '</li>';
        }
        return $html . '</ol>';
    }

    /** Profile + all visit sheets of a client (legacy getCardData order: by visit). */
    private static function dossierHtml(int $clientId): string
    {
        if ($clientId < 1) {
            return '';
        }
        $out = '';
        $profile = DocsInstancesRepository::latestForClient($clientId, 'medcard_profile');
        if ($profile !== null) {
            $pver = DocsDefsRepository::version((int)$profile['id_version']);
            if ($pver !== null) {
                $out .= self::dossierProfileHtml($pver['schema'], $profile['payload']);
            }
        }
        foreach (DocsInstancesRepository::visitsForClient($clientId) as $visit) {
            $vver = DocsDefsRepository::version((int)$visit['id_version']);
            if ($vver === null) {
                continue;
            }
            $ev = self::eventVars((int)$visit['id_event']);
            $when = $ev['text']['date'] !== ''
                ? $ev['text']['date'] . ' ' . $ev['text']['time']
                : I18n::formatDate((int)$visit['dt_create'], 'date');
            $out .= self::dossierVisitHtml($when, $ev['items_html'], $vver['schema'], $visit['payload']);
        }
        return $out !== '' ? $out : '<p>' . e(t('docs.print.dossier_empty')) . '</p>';
    }

    /**
     * @param array{sections:list<array<string,mixed>>} $schema
     * @param array<int,string>|null $staffNames
     */
    public static function dossierProfileHtml(array $schema, array $payload, ?array $staffNames = null): string
    {
        return '<div class="docs-dossier-profile"><h3>' . e(t('docs.print.dossier_profile')) . '</h3>'
            . self::fieldsHtml($schema, $payload, $staffNames) . '</div>';
    }

    /**
     * @param array{sections:list<array<string,mixed>>} $schema
     * @param array<int,string>|null $staffNames
     */
    public static function dossierVisitHtml(string $when, string $itemsHtml, array $schema, array $payload, ?array $staffNames = null): string
    {
        return '<div class="docs-dossier-visit"><h3>' . e(t('docs.print.dossier_visit', ['date' => $when])) . '</h3>'
            . $itemsHtml . self::fieldsHtml($schema, $payload, $staffNames) . '</div>';
    }

    /**
     * @return array{0:array<string,string>,1:int|null}
     */
    private static function clientVars(int $clientId): array
    {
        $out = array_fill_keys([
            'name', 'surname', 'pname', 'patronymic', 'gender', 'tel', 'mail',
            'birthday', 'birth_day', 'birth_month', 'birth_year', 'passport', 'passport_serie', 'passport_number',
            'passport_issued_at', 'passport_issued_by', 'address', 'address_fact', 'address_passport', 'locality',
        ], '');
        if ($clientId < 1 || !class_exists('ClientsRepository', false)) {
            return [$out, null];
        }
        $c = ClientsRepository::find($clientId);
        if (!$c) {
            return [$out, null];
        }
        $s = static fn(string $k): string => trim((string)($c[$k] ?? ''));
        $fio = trim(implode(' ', array_filter([$s('surname'), $s('pname'), $s('patronymic')])));
        $bd = $s('birthday');
        $out['name'] = $fio !== '' ? $fio : $s('name');
        $out['surname'] = $s('surname');
        $out['pname'] = $s('pname');
        $out['patronymic'] = $s('patronymic');
        $out['tel'] = $s('tel');
        $out['mail'] = $s('mail');
        $bts = $bd !== '' ? strtotime($bd . ' 00:00:00') : false;
        if ($bts !== false) {
            $out['birthday'] = I18n::formatDate((int)$bts, 'date');
            $out['birth_day'] = date('d', (int)$bts);
            $out['birth_month'] = date('m', (int)$bts);
            $out['birth_year'] = date('Y', (int)$bts);
        }
        $out['passport_serie'] = $s('passport_serie');
        $out['passport_number'] = $s('passport_number');
        $out['passport_issued_at'] = $s('passport_issued_at');
        $out['passport_issued_by'] = $s('passport_issued_by');
        $out['passport'] = trim(implode(', ', array_filter([
            trim($out['passport_serie'] . ' ' . $out['passport_number']),
            $out['passport_issued_by'],
            $out['passport_issued_at'],
        ])));
        $out['address_fact'] = $s('address_fact');
        $out['address_passport'] = $s('address_passport');
        $out['address'] = $out['address_fact'] !== '' ? $out['address_fact'] : $out['address_passport'];
        $out['locality'] = self::locality($out['address']);
        $g = $c['gender'] ?? null;
        $gender = ($g === null || $g === '') ? null : ((int)$g === 1 ? 1 : 0);
        if ($gender !== null) {
            $out['gender'] = t($gender === 1 ? 'docs.print.gender_m' : 'docs.print.gender_f');
        }
        return [$out, $gender];
    }

    /**
     * @return array{text:array<string,string>,items_html:string,operator_id:int}
     */
    private static function eventVars(int $eventId): array
    {
        $text = array_fill_keys(['id', 'date', 'time', 'total', 'total_text', 'staff', 'procedures', 'service_types'], '');
        $empty = ['text' => $text, 'items_html' => '', 'operator_id' => 0];
        if ($eventId < 1 || !class_exists('EventsRepository', false)) {
            return $empty;
        }
        $ev = EventsRepository::find($eventId);
        if (!$ev) {
            return $empty;
        }
        $start = (int)($ev['dtstart'] ?? 0);
        $end = (int)($ev['dtend'] ?? 0);
        $op = (int)($ev['id_operator'] ?? 0);
        $text['id'] = (string)$eventId;
        $text['date'] = $start > 0 ? I18n::formatDate($start, 'date') : '';
        $text['time'] = $start > 0 ? date('H:i', $start) . ($end > $start ? '–' . date('H:i', $end) : '') : '';
        $staff = $op > 0 && class_exists('UsersRepository', false) ? UsersRepository::staffNamesIncluding([$op]) : [];
        $text['staff'] = (string)($staff[$op] ?? '');
        $names = class_exists('ProductsRepository', false) ? ProductsRepository::names(true) : [];
        $lines = [];
        $types = [];
        foreach ((array)($ev['items'] ?? []) as $it) {
            $gid = (int)($it['id_good'] ?? 0);
            if ($gid < 1) {
                continue;
            }
            $lines[] = ['name' => (string)($names[$gid] ?? ('#' . $gid)), 'qty' => max(1, (int)($it['qty'] ?? 1)), 'price' => (float)($it['price'] ?? 0)];
            $p = class_exists('ProductsRepository', false) ? ProductsRepository::find($gid) : null;
            $typeId = $p ? (int)($p['type_id'] ?? 0) : 0;
            if ($typeId > 0 && class_exists('ProcedureTypesRepository', false)) {
                $t = ProcedureTypesRepository::find($typeId);
                $tn = trim((string)($t['type'] ?? ''));
                if ($tn !== '') {
                    $types[$tn] = true;
                }
            }
        }
        $total = 0.0;
        foreach ($lines as $l) {
            $total += $l['price'] * $l['qty'];
        }
        $text['total'] = number_format($total, 0, '.', ' ');
        $text['total_text'] = DocsMoney::words($total);
        $text['service_types'] = implode(', ', array_keys($types));
        $proc = [];
        if (class_exists('ProceduresRepository', false)) {
            foreach (ProceduresRepository::listByEventParent($eventId) as $pr) {
                $gid = (int)($pr['id_good'] ?? 0);
                $proc[] = (string)($names[$gid] ?? ('#' . $gid));
            }
        }
        if ($proc === []) {
            $proc = array_map(static fn(array $l): string => $l['name'], $lines);
        }
        $text['procedures'] = implode('; ', $proc);
        return ['text' => $text, 'items_html' => self::itemsTable($lines), 'operator_id' => $op];
    }

    /**
     * Medical visit sheet values of the event (doctor / recommendations).
     *
     * @param array<string,mixed>|null $ownPayload this instance is the visit sheet
     * @return array<string,mixed>
     */
    private static function visitSheet(int $eventId, ?array $ownPayload): array
    {
        if ($ownPayload !== null) {
            return $ownPayload;
        }
        if ($eventId < 1) {
            return [];
        }
        foreach (DocsInstancesRepository::listForEvent($eventId) as $inst) {
            if ($inst['kind'] === 'medcard_visit') {
                $full = DocsInstancesRepository::find((int)$inst['id']);
                return is_array($full['payload'] ?? null) ? $full['payload'] : [];
            }
        }
        return [];
    }

    /** @return array{name:string,position:string} */
    private static function doctorVars(int $userId): array
    {
        if ($userId < 1 || !class_exists('UsersRepository', false)) {
            return ['name' => '', 'position' => ''];
        }
        $u = UsersRepository::find($userId);
        if (!$u) {
            return ['name' => '', 'position' => ''];
        }
        $statuses = UsersRepository::statusNames();
        return [
            'name' => self::shortName((string)($u['name'] ?? '')),
            'position' => trim((string)($statuses[(int)($u['ID_STATUS'] ?? 0)] ?? '')),
        ];
    }

    /** Latest contract of the client (acts reference it: legacy {actDogNum}/{actDogDate}). */
    private static function contractVars(int $clientId, int $selfId): array
    {
        if ($clientId < 1) {
            return ['id' => '', 'date' => ''];
        }
        foreach (DocsInstancesRepository::list(['client' => $clientId, 'kind' => 'contract', 'limit' => 5])['items'] as $c) {
            if ((int)$c['id'] === $selfId) {
                continue;
            }
            $ts = (int)$c['dt_print'] > 0 ? (int)$c['dt_print'] : (int)$c['dt_create'];
            return ['id' => (string)(int)$c['id'], 'date' => I18n::formatDate($ts, 'date')];
        }
        return ['id' => '', 'date' => ''];
    }
}
