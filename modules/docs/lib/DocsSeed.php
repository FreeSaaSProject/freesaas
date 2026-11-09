<?php
declare(strict_types=1);

/**
 * Default definitions seeded at runtime (labels in the installer's locale).
 * Field ids are stable — see docs/agents/docs.md "Legacy → field ids".
 */
final class DocsSeed
{
    /** @var array<string,array<string,string>>|null locale => docs catalog */
    private static $catalogs = null;

    /**
     * Seeded names/labels are stored in the installer's locale; while they still equal the seed text
     * in any shipped locale they are shown in the current UI locale. Edited text is left as is.
     */
    public static function localize(string $key, string $stored): string
    {
        foreach (self::catalogs() as $catalog) {
            if (isset($catalog[$key]) && $catalog[$key] === $stored) {
                return t($key);
            }
        }
        return $stored;
    }

    public static function localizedName(string $code, string $stored): string
    {
        return self::localize('docs.seed.name.' . $code, $stored);
    }

    /**
     * @param array{sections:list<array<string,mixed>>} $schema
     * @return array{sections:list<array<string,mixed>>}
     */
    public static function localizeSchema(array $schema): array
    {
        foreach ($schema['sections'] as $si => $s) {
            $schema['sections'][$si]['title'] = self::localize('docs.seed.sec.' . $s['id'], (string)$s['title']);
            foreach ($s['fields'] as $fi => $f) {
                $schema['sections'][$si]['fields'][$fi]['label'] = self::localize('docs.seed.field.' . $f['id'], (string)$f['label']);
                foreach ((array)($f['options'] ?? []) as $oi => $o) {
                    $optKey = self::optionKey((string)$f['id'], (string)$o['value']);
                    $schema['sections'][$si]['fields'][$fi]['options'][$oi]['label'] = self::localize($optKey, (string)$o['label']);
                }
            }
        }
        return $schema;
    }

    /** @var array<string,array<string,string>>|null locale => code => seed print HTML */
    private static $seedHtml = null;

    /** Untouched seed print template → the same template in the current UI locale. */
    public static function localizedHtml(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return $stored;
        }
        foreach (self::seedHtml() as $byCode) {
            foreach ($byCode as $code => $html) {
                if (trim($html) === $stored) {
                    return self::seedHtml()[I18n::locale()][$code] ?? $stored;
                }
            }
        }
        return $stored;
    }

    /** @return array<string,array<string,string>> */
    private static function seedHtml(): array
    {
        if (self::$seedHtml !== null) {
            return self::$seedHtml;
        }
        $current = I18n::locale();
        self::$seedHtml = [];
        foreach (array_keys(self::catalogs()) as $locale) {
            I18n::setLocale($locale);
            foreach (self::definitions() as $seed) {
                self::$seedHtml[$locale][(string)$seed['meta']['code']] = (string)$seed['version']['print_html'];
            }
        }
        I18n::setLocale($current);
        return self::$seedHtml;
    }

    /** @return array<string,array<string,string>> */
    private static function catalogs(): array
    {
        if (self::$catalogs === null) {
            self::$catalogs = [];
            foreach (glob(dirname(__DIR__) . '/lang/*.php') ?: [] as $file) {
                $data = require $file;
                if (is_array($data)) {
                    self::$catalogs[basename($file, '.php')] = $data;
                }
            }
        }
        return self::$catalogs;
    }

    /** Bump when default schemas/templates change; untouched seeds (single version) are upgraded. */
    public const REVISION = 6;

    /** Localization key of a seeded select option. */
    public static function optionKey(string $fieldId, string $value): string
    {
        $slug = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', strtr($value, ['+' => 'pos', '-' => 'neg'])));
        return 'docs.seed.opt.' . $fieldId . '.' . ($slug !== '' ? $slug : 'x');
    }

    /**
     * New seed revision for definitions nobody edited (exactly one version); edited ones are left alone.
     */
    public static function upgradeUntouched(): void
    {
        if ((int)Config::get('docs', 'seed_revision', '1') >= self::REVISION) {
            return;
        }
        foreach (self::definitions() as $seed) {
            $def = DocsDefsRepository::findByCode((string)$seed['meta']['code']);
            if ($def === null || count(DocsDefsRepository::versions((int)$def['id'])) !== 1) {
                continue;
            }
            $prevVid = (int)$def['id_version_current'];
            $newVid = DocsDefsRepository::saveVersionIfChanged((int)$def['id'], $seed['version']);
            // Unprinted shells follow the upgraded seed template (printed snapshots stay frozen).
            if ($newVid > 0 && $newVid !== $prevVid && DocsInstancesRepository::tableReady()) {
                Database::pdo()->prepare(
                    "UPDATE base_doc_instances SET ID_VERSION = ?
                     WHERE ID_DEF = ? AND ID_VERSION = ? AND DT_PRINT = 0 AND STATUS <> 'void'"
                )->execute([$newVid, (int)$def['id'], $prevVid]);
            }
        }
        Config::set('docs', 'seed_revision', (string)self::REVISION);
    }

    /**
     * @return list<array{meta:array<string,mixed>,version:array<string,mixed>}>
     */
    public static function definitions(): array
    {
        return [
            self::def('medcard_profile', 'medcard_profile', 'client', 'manual', 1, 10, self::profileSchema(), self::profileHtml()),
            self::def('medcard_visit', 'medcard_visit', 'event', 'medical', 1, 20, self::visitSchema(), self::visitHtml()),
            self::def('medcard_dossier', 'medcard_dossier', 'client', 'manual', 1, 30, ['sections' => []], self::dossierHtml()),
            // Legacy sample (disabled): kept so older installs that already have it stay stable.
            self::def('consent_medical', 'consent', 'client', 'medical', 0, 40, ['sections' => []], self::consentHtml(), 365),
            // Paid services contract — print body from library pack (rich Д-ЗГ), fallback skinny HTML.
            self::def(
                'contract_citizen',
                'contract',
                'client',
                'medical',
                1,
                50,
                ['sections' => []],
                self::contractCitizenHtml(),
                365,
                self::libraryPrint('elos63', 'd_zg')
            ),
            // Official «Перечень» IDS (приказ 390н) — rich client-level consent from library.
            self::def(
                'consent_list',
                'consent',
                'client',
                'medical',
                1,
                55,
                ['sections' => []],
                self::consentHtml(),
                0,
                self::libraryPrint('elos63', 'ids_p')
            ),
            // Per-visit IDS listing basket procedures (short companion to consent_list).
            self::def('consent_procedure', 'consent', 'event', 'medical', 1, 60, ['sections' => []], self::consentProcedureHtml(), 0),
        ];
    }

    /**
     * Load print layout from modules/docs/library/<pack>/<file>.json when present.
     *
     * @return array{print_html?:string,orientation?:string,format?:string,copies?:int}
     */
    private static function libraryPrint(string $pack, string $file): array
    {
        if (!class_exists('DocsLibrary', false)) {
            require_once __DIR__ . '/DocsLibrary.php';
        }
        $tpl = DocsLibrary::readPackFile($pack, $file);
        if ($tpl === null || trim((string)($tpl['print_html'] ?? '')) === '') {
            return [];
        }
        $orient = (string)($tpl['orientation'] ?? 'portrait');
        $paper = (string)($tpl['paper'] ?? 'A4');
        return [
            'print_html' => (string)$tpl['print_html'],
            'orientation' => in_array($orient, DocsDefsRepository::ORIENTATIONS, true) ? $orient : 'portrait',
            'format' => in_array($paper, DocsDefsRepository::FORMATS, true) ? $paper : 'A4',
            'copies' => max(1, min(9, (int)($tpl['copies'] ?? 1))),
        ];
    }

    /**
     * @param array<string,mixed> $schema
     * @param array{print_html?:string,orientation?:string,format?:string,copies?:int} $print
     * @return array{meta:array<string,mixed>,version:array<string,mixed>}
     */
    private static function def(
        string $code,
        string $kind,
        string $scope,
        string $trigger,
        int $enabled,
        int $num,
        array $schema,
        string $html,
        int $expireDays = 0,
        array $print = []
    ): array {
        return [
            'meta' => [
                'code' => $code,
                'kind' => $kind,
                'name' => t('docs.seed.name.' . $code),
                'scope' => $scope,
                'trigger_mode' => $trigger,
                'trigger_types' => [],
                'trigger_procs' => [],
                'expire_days' => $expireDays,
                'require_medcard' => 0,
                'enabled' => $enabled,
                'num' => $num,
            ],
            'version' => [
                'schema_json' => (string)json_encode($schema, JSON_UNESCAPED_UNICODE),
                'print_html' => (string)($print['print_html'] ?? $html),
                'orientation' => (string)($print['orientation'] ?? 'portrait'),
                'format' => (string)($print['format'] ?? 'A4'),
                'copies' => (int)($print['copies'] ?? 1),
            ],
        ];
    }

    /**
     * Standing part of form 025/u (title page items 7–17, 21–23) + anamnesis / objective status.
     *
     * @return array<string,mixed>
     */
    private static function profileSchema(): array
    {
        return ['sections' => [
            [
                'id' => 'passport',
                'title' => t('docs.seed.sec.passport'),
                'fields' => [
                    self::f('oms_policy', 'text'),
                    self::f('snils', 'text'),
                    self::f('insurer', 'text'),
                    self::f('benefit_code', 'text'),
                    self::f('marital_status', 'select', false, false, self::opts('marital_status', ['1', '2', '3'])),
                    self::f('education', 'select', false, false, self::opts('education', ['1', '2', '3', '4', '5', '6'])),
                    self::f('employment', 'select', false, false, self::opts('employment', ['1', '2', '3', '4', '5', '6'])),
                    self::f('disability', 'text'),
                    self::f('work_place', 'text'),
                    self::f('work_position', 'text'),
                    self::f('dispensary', 'textarea'),
                ],
            ],
            [
                'id' => 'anamnesis',
                'title' => t('docs.seed.sec.anamnesis'),
                'fields' => [
                    self::f('ana_life', 'textarea'),
                    self::f('ana_contras', 'textarea'),
                    self::f('ana_gyno', 'textarea'),
                    self::f('ana_alerg', 'textarea'),
                ],
            ],
            [
                'id' => 'objective',
                'title' => t('docs.seed.sec.objective'),
                'fields' => [
                    self::f('obj_skin', 'textarea'),
                    self::f('obj_sliz', 'textarea'),
                    self::f('obj_nogot', 'textarea'),
                ],
            ],
            [
                'id' => 'body',
                'title' => t('docs.seed.sec.body'),
                'fields' => [
                    self::f('blood_group', 'select', false, false, self::opts('blood_group', ['0', 'A', 'B', 'AB'])),
                    self::f('rh_factor', 'select', false, false, self::opts('rh_factor', ['+', '-'])),
                    self::f('height_cm', 'number'),
                    self::f('weight_kg', 'number'),
                    self::f('eyes', 'text'),
                    self::f('hairs', 'text'),
                ],
            ],
        ]];
    }

    /**
     * Visit sheet = form 025/u items 24–25 (doctor's record / follow-up observation).
     *
     * @return array<string,mixed>
     */
    private static function visitSchema(): array
    {
        return ['sections' => [
            [
                'id' => 'complaints',
                'title' => t('docs.seed.sec.complaints'),
                'fields' => [
                    self::f('complaints', 'textarea'),
                    self::f('ana_gen', 'textarea', true),
                ],
            ],
            [
                'id' => 'exam',
                'title' => t('docs.seed.sec.exam'),
                'fields' => [
                    self::f('ana_obj', 'textarea', true),
                    self::f('dia_codes', 'text', false, true),
                    self::f('complications', 'text'),
                    self::f('comorbid', 'text'),
                    self::f('health_group', 'select', false, false, self::opts('health_group', ['1', '2', '3a', '3b'])),
                ],
            ],
            [
                'id' => 'plan',
                'title' => t('docs.seed.sec.plan'),
                'fields' => [
                    self::f('recommends', 'textarea'),
                    self::f('drugs_physio', 'textarea'),
                    self::f('sick_leave', 'text'),
                    self::f('next_visit', 'date'),
                ],
            ],
            [
                'id' => 'staff',
                'title' => t('docs.seed.sec.staff'),
                'fields' => [
                    self::f('doctor_id', 'staff', true, true),
                    self::f('talk_segments', 'segments'),
                ],
            ],
        ]];
    }

    /**
     * @param list<string> $values
     * @return list<array{value:string,label:string}>
     */
    private static function opts(string $fieldId, array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = ['value' => $v, 'label' => t(self::optionKey($fieldId, $v))];
        }
        return $out;
    }

    /**
     * @param list<array{value:string,label:string}> $options
     * @return array<string,mixed>
     */
    private static function f(string $id, string $type, bool $required = false, bool $index = false, array $options = []): array
    {
        $f = [
            'id' => $id,
            'type' => $type,
            'label' => t('docs.seed.field.' . $id),
            'required' => $required,
            'index' => $index,
        ];
        if ($options !== []) {
            $f['options'] = $options;
        }
        return $f;
    }

    private static function l(string $key): string
    {
        return e(t('docs.seed.f025.' . $key));
    }

    private const BLANK = '__________';
    private const PAGE_BREAK = '<div class="docs-page-break"></div>';

    /** Title page of form 025/u (header + items 1–23). */
    private static function title025(): string
    {
        $b = self::BLANK;
        $dmy = static fn(string $d, string $m, string $y): string => self::l('day') . ' <u>' . $d . '</u> ' . self::l('month')
            . ' <u>' . $m . '</u> ' . self::l('year') . ' <u>' . $y . '</u>';
        return '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:9pt"><tr>'
            . '<td valign="top">' . self::l('org_name') . '<br><b>{org.name|' . $b . '}</b><br>'
            . self::l('address') . ': <u>{org.address|' . $b . '}</u><br>'
            . self::l('ogrn') . ': {org.ogrn_boxes}</td>'
            . '<td width="45%" valign="top">' . self::l('okud') . ' <u>310004</u><br>'
            . self::l('okpo') . ' <u>{org.okpo|' . $b . '}</u><br>'
            . self::l('med_doc') . '<br>' . self::l('form') . '<br>' . self::l('approved') . '</td>'
            . '</tr></table>'
            . '<h3 style="text-align:center">' . self::l('title1') . '<br>' . self::l('title2') . ' № {card.no}</h3>'
            . '<p>1. ' . self::l('i1') . ': ' . $dmy('{card.day}', '{card.month}', '{card.year}') . '<br>'
            . '2. ' . self::l('i2') . ' <u>{client.name|' . $b . '}</u><br>'
            . '3. ' . self::l('i3') . ': {client.gender|' . $b . '} '
            . '4. ' . self::l('i4') . ': ' . $dmy('{client.birth_day|__}', '{client.birth_month|__}', '{client.birth_year|____}') . '<br>'
            . '5. ' . self::l('i5') . ': <u>{client.address|' . $b . '}</u>; ' . self::l('tel') . ' {client.tel|' . $b . '}<br>'
            . '6. ' . self::l('i6') . ': {client.locality}<br>'
            . '7. ' . self::l('i7') . ' {profile.oms_policy|' . $b . '} '
            . '8. ' . self::l('i8') . ' {profile.snils|' . $b . '}<br>'
            . '9. ' . self::l('i9') . ' {profile.insurer|' . $b . '}<br>'
            . '10. ' . self::l('i10') . ' {profile.benefit_code|' . $b . '} '
            . '11. ' . self::l('i11') . ': ' . self::l('serie') . ' {client.passport_serie|____} № {client.passport_number|' . $b . '}<br>'
            . '12. ' . self::l('i12') . ':</p>'
            . '<table border="1" cellpadding="3" cellspacing="0" width="100%"><tr><th>' . self::l('disp_start') . '</th><th>'
            . self::l('disp_end') . '</th><th>' . self::l('diagnosis') . '</th><th>' . self::l('icd') . '</th><th>'
            . self::l('doctor') . '</th></tr><tr><td colspan="5">{profile.dispensary| }</td></tr></table>'
            . '<p>13. ' . self::l('i13') . ': {profile.marital_status|' . self::l('i13_default') . '}<br>'
            . '14. ' . self::l('i14') . ': {profile.education|' . self::l('i14_default') . '}<br>'
            . '15. ' . self::l('i15') . ': {profile.employment|' . $b . '}<br>'
            . '16. ' . self::l('i16') . ': {profile.disability|' . $b . '}<br>'
            . '17. ' . self::l('i17') . ': {profile.work_place|' . $b . '} {profile.work_position|' . $b . '}<br>'
            . '18. ' . self::l('i18') . ' ' . $b . $b . '<br>'
            . '19. ' . self::l('i19') . ' ' . $b . $b . '<br>'
            . '20. ' . self::l('i20') . ':</p>{dossier.diagnoses}'
            . '<p>21. ' . self::l('i21') . ' <u>{profile.blood_group|' . $b . '}</u> '
            . '22. ' . self::l('i22') . ': <u>{profile.rh_factor|' . $b . '}</u><br>'
            . '23. ' . self::l('i23') . ' <u>{profile.ana_alerg|' . $b . '}</u></p>';
    }

    /** Items 26–35: item 26 filled from visit sheets when present; 27–35 blank paper form. */
    private static function tail025(): string
    {
        $b = self::BLANK;
        $line = static fn(string $key, string $extra = ''): string => self::l($key) . ' ' . $b . $b . $extra . '<br>';
        $dia = static fn(): string => self::l('line_main_dia') . ' ' . $b . ' ' . self::l('line_icd') . ' ' . $b . '<br>'
            . self::l('line_complications') . ' ' . $b . $b . '<br>'
            . self::l('line_comorbid') . ' ' . $b . ' ' . self::l('line_icd') . ' ' . $b . '<br>'
            . self::l('line_external') . ' ' . $b . ' ' . self::l('line_icd') . ' ' . $b . '<br>';
        $table = static fn(array $cols): string => '<table border="1" cellpadding="3" cellspacing="0" width="100%"><tr><th>'
            . implode('</th><th>', array_map(static fn(string $k): string => self::l($k), $cols)) . '</th></tr><tr>'
            . str_repeat('<td>&nbsp;</td>', count($cols)) . '</tr></table>';
        return '<div style="font-size:8pt">'
            . '{dossier.stage_epicrisis}'
            . '<p><b>' . self::l('t27') . '</b><br>' . $line('line_date') . $line('line_complaints') . $line('line_treatment')
            . $dia() . $line('line_recs') . $line('line_sick')
            . self::l('line_head') . ' ' . $b . ' ' . self::l('line_attending') . ' ' . $b . '</p>'
            . '<p><b>' . self::l('t28') . '</b><br>' . $line('line_date') . $line('line_complaints') . $line('line_treatment')
            . $dia() . $line('line_commission') . $line('line_recs')
            . self::l('line_chair') . ' ' . $b . ' ' . self::l('line_members') . ' ' . $b . '</p>'
            . '<p><b>' . self::l('t29') . '</b><br>' . $line('line_date') . $line('line_complaints') . $line('line_measures')
            . $dia() . $line('line_next') . $line('line_doctor') . '</p>'
            . '<p><b>' . self::l('t30') . '</b></p>' . $table(['hosp_dates', 'hosp_org', 'hosp_dia'])
            . '<p><b>' . self::l('t31') . '</b></p>' . $table(['op_date', 'op_name', 'doctor'])
            . '<p><b>' . self::l('t32') . '</b></p>' . $table(['op_date', 'xray_name', 'xray_dose'])
            . '<p><b>' . self::l('t33') . '</b><br>' . $b . $b . $b . '</p>'
            . '<p><b>' . self::l('t34') . '</b><br>' . $b . $b . $b . '</p>'
            . '<p><b>' . self::l('t35') . '</b><br>' . $b . $b . $b . '</p>'
            . '</div>';
    }

    private static function profileHtml(): string
    {
        return '<div style="font-size:10pt">' . self::title025() . '{qr}</div>';
    }

    /** Items 24 (first visit) / 25 (follow-up) — layout of the legacy doctor's record sheet. */
    private static function visitHtml(): string
    {
        $b = self::BLANK;
        return '<div style="font-size:10pt">'
            . '<p><b>{?visit.first:' . self::l('v_title_first') . '|' . self::l('v_title_repeat') . '}</b></p>'
            . '<p><b>' . self::l('v_date') . '</b> <u>{visit.date}</u><br>'
            . '<b>' . self::l('v_specialty') . '</b> {?visit.first:' . self::l('v_service_first') . '|' . self::l('v_service_repeat') . '}<br>'
            . '<b>' . self::l('v_complaints') . '</b> {complaints|' . $b . '}<br>'
            . '<b>' . self::l('v_anamnesis') . '</b> {visit.anamnesis_life} ' . self::l('v_anamnesis_disease') . ': {ana_gen|' . $b . '}<br>'
            . '<b>' . self::l('v_objective') . '</b> {visit.objective_general} ' . self::l('v_local') . ': {ana_obj|' . $b . '}<br>'
            . '<b>' . self::l('v_dia') . ':</b> {dia_codes|' . $b . '}<br>'
            . '<b>' . self::l('v_complications') . ':</b> {complications|' . $b . '}<br>'
            . '<b>' . self::l('v_comorbid') . '</b> {comorbid|' . $b . '}<br>'
            . '<b>' . self::l('v_external') . '</b> ' . $b . '<br>'
            . '<b>' . self::l('v_health_group') . '</b> <u>{health_group|____}</u> ' . self::l('v_dispensary') . ' ' . $b . '</p>'
            . '<table border="1" cellpadding="3" cellspacing="0" width="100%">'
            . '<tr><th>' . self::l('v_prescriptions') . '</th><th>' . self::l('v_drugs') . '</th></tr>'
            . '<tr><td>{recommends| } {visit.next_text}</td><td>{drugs_physio| }</td></tr>'
            . '<tr><th>' . self::l('v_sick_leave') . '</th><th>' . self::l('v_recipes') . '</th></tr>'
            . '<tr><td>{sick_leave| }</td><td>&nbsp;</td></tr>'
            . '<tr><th>' . self::l('v_ids') . '</th><th>' . self::l('v_refusal') . '</th></tr>'
            . '<tr><td>&nbsp;</td><td>&nbsp;</td></tr>'
            . '<tr><td colspan="2">' . self::l('v_doctor') . ' _____________/{doctor.name|_____________}</td></tr>'
            . '</table>{qr}</div>';
    }

    /** Whole form 025/u: title page, visit records, procedures sheet, blank items 26–35. */
    private static function dossierHtml(): string
    {
        return '<div style="font-size:10pt">' . self::title025()
            . self::PAGE_BREAK . '{dossier.visits}'
            . self::PAGE_BREAK . '{dossier.procedures}'
            . self::PAGE_BREAK . self::tail025() . '{qr}</div>';
    }

    private static function consentHtml(): string
    {
        return '<h2 style="text-align:center">' . e(t('docs.seed.name.consent_medical')) . '</h2>'
            . '<p>' . e(t('docs.seed.tpl.consent_intro')) . '</p>'
            . '<p><b>' . e(t('docs.seed.tpl.client')) . ':</b> {client.name}, ' . e(t('docs.seed.tpl.passport')) . ' {client.passport}</p>'
            . '{event.items}'
            . '<p>' . e(t('docs.seed.tpl.consent_body')) . '</p>'
            . '<p style="margin-top:2em">' . e(t('docs.seed.tpl.sign_client')) . ' ____________________ {doc.date}</p>'
            . '<p>' . e(t('docs.seed.tpl.valid_until')) . ' {doc.expire}</p>{qr}';
    }

    /** Framework contract for paid medical services (citizen / consumer). */
    private static function contractCitizenHtml(): string
    {
        $b = self::BLANK;
        return '<div style="font-size:9pt">'
            . '<p style="font-size:8pt">' . e(t('docs.seed.tpl.contract_notice')) . '</p>'
            . '<h3 style="text-align:center">' . e(t('docs.seed.tpl.contract_title')) . ' №{doc.id}</h3>'
            . '<table width="100%"><tr><td>' . e(t('docs.seed.tpl.city')) . ' {org.city|' . $b . '}</td>'
            . '<td style="text-align:right">{doc.date}</td></tr></table>'
            . '<p>{org.name|' . $b . '}, ' . e(t('docs.seed.tpl.contract_org')) . ' {org.address|' . $b . '}; '
            . e(t('docs.seed.tpl.inn')) . ' {org.inn|' . $b . '}, ' . e(t('docs.seed.tpl.ogrn')) . ' {org.ogrn|' . $b . '}; '
            . e(t('docs.seed.tpl.license')) . ' {org.license|' . $b . '} ' . e(t('docs.seed.tpl.from')) . ' {org.license_date|' . $b . '}, '
            . e(t('docs.seed.tpl.issued_by')) . ' {org.license_issuer|' . $b . '}.</p>'
            . '<p>' . e(t('docs.seed.tpl.contract_parties')) . ' {client.name}, '
            . e(t('docs.seed.tpl.passport')) . ' {client.passport_serie|' . $b . '} № {client.passport_number|' . $b . '}, '
            . e(t('docs.seed.tpl.address')) . ' {client.address|' . $b . '}.</p>'
            . '<p><b>1. ' . e(t('docs.seed.tpl.contract_s1')) . '</b><br>' . e(t('docs.seed.tpl.contract_s1_body')) . '</p>'
            . '<p><b>2. ' . e(t('docs.seed.tpl.contract_s2')) . '</b><br>' . e(t('docs.seed.tpl.contract_s2_body')) . '</p>'
            . '<p><b>3. ' . e(t('docs.seed.tpl.contract_s3')) . '</b><br>' . e(t('docs.seed.tpl.contract_s3_body')) . '</p>'
            . '<p>' . e(t('docs.seed.tpl.contract_term')) . ' {doc.date} — {doc.expire|' . $b . '}.</p>'
            . '<table width="100%" style="margin-top:1.5em"><tr>'
            . '<td width="50%" valign="top"><b>' . e(t('docs.seed.tpl.provider')) . '</b><br>{org.name|' . $b . '}<br>'
            . e(t('docs.seed.tpl.sign_provider')) . ' _____________ / {org.director_short|' . $b . '}</td>'
            . '<td width="50%" valign="top"><b>' . e(t('docs.seed.tpl.consumer')) . '</b><br>{client.name}<br>'
            . e(t('docs.seed.tpl.sign_client')) . ' _____________ / _____________</td>'
            . '</tr></table>{qr}</div>';
    }

    /** Per-visit informed voluntary consent listing basket procedures. */
    private static function consentProcedureHtml(): string
    {
        $b = self::BLANK;
        return '<div style="font-size:10pt">'
            . '<h3 style="text-align:center">' . e(t('docs.seed.name.consent_procedure')) . '</h3>'
            . '<p>' . e(t('docs.seed.tpl.ids_law')) . '</p>'
            . '<p>' . e(t('docs.seed.tpl.ids_i')) . ' {client.name}, '
            . e(t('docs.seed.tpl.passport')) . ' {client.passport_serie|' . $b . '} № {client.passport_number|' . $b . '}, '
            . e(t('docs.seed.tpl.born')) . ' {client.birthday|' . $b . '}, '
            . e(t('docs.seed.tpl.ids_org')) . ' {org.name|' . $b . '}.</p>'
            . '<p><b>' . e(t('docs.seed.tpl.ids_services')) . '</b></p>{event.items}'
            . '<p>' . e(t('docs.seed.tpl.ids_body')) . '</p>'
            . '<p>' . e(t('docs.seed.tpl.ids_risks')) . '</p>'
            . '<table width="100%" style="margin-top:1.5em"><tr>'
            . '<td width="50%" valign="top">' . e(t('docs.seed.tpl.sign_client')) . '<br>_____________ / {client.name}<br>{doc.date}</td>'
            . '<td width="50%" valign="top">' . e(t('docs.seed.tpl.sign_doctor')) . '<br>_____________ / {doctor.name|' . $b . '}<br>{doc.date}</td>'
            . '</tr></table>{qr}</div>';
    }
}
