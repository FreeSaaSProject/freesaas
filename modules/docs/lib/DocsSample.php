<?php
declare(strict_types=1);

/**
 * Demo values for printing a sample of any template: every placeholder gets a plausible value,
 * schema fields get type-appropriate examples; organization requisites use the real settings
 * where filled (so the sample shows the clinic's own header) and demo values otherwise.
 */
final class DocsSample
{
    /**
     * @param array<string,mixed> $def
     * @param array<string,mixed> $ver
     * @return array<string,string>
     */
    public static function vars(array $def, array $ver): array
    {
        $now = time();
        $schema = $ver['schema'];
        $payload = self::payload($schema);
        $vars = [];
        foreach (DocsSchema::fieldMap($schema) as $fid => $f) {
            $vars[$fid] = DocsSchema::displayValue($f, $payload[$fid] ?? null, self::staffNames());
        }
        $vars['fields'] = DocsRender::fieldsHtml($schema, $payload, self::staffNames());

        $lines = [
            ['name' => t('docs.sample.item1'), 'qty' => 1, 'price' => 3500.0],
            ['name' => t('docs.sample.item2'), 'qty' => 1, 'price' => 1500.0],
        ];
        $itemsHtml = DocsRender::itemsTable($lines);
        $staff = t('docs.sample.staff');
        $demo = [
            'client.name' => t('docs.sample.client_name'),
            'client.tel' => '79001234567',
            'client.mail' => 'client@example.com',
            'client.birthday' => I18n::formatDate((int)strtotime('1990-05-15'), 'date'),
            'client.passport_serie' => '1234',
            'client.passport_number' => '567890',
            'client.passport_issued_at' => I18n::formatDate((int)strtotime('2015-06-01'), 'date'),
            'client.passport_issued_by' => t('docs.sample.passport_issued_by'),
            'client.address_fact' => t('docs.sample.client_address'),
            'client.address_passport' => t('docs.sample.client_address'),
            'event.id' => '1024',
            'event.date' => I18n::formatDate($now, 'date'),
            'event.time' => date('H:i', $now) . '–' . date('H:i', $now + 3600),
            'event.total' => number_format(5000, 0, '.', ' '),
            'event.total_text' => DocsMoney::words(5000),
            'event.staff' => $staff,
            'event.procedures' => $lines[0]['name'] . '; ' . $lines[1]['name'],
            'event.service_types' => t('docs.sample.service_types'),
            'doctor.name' => DocsRender::shortName($staff),
            'doctor.position' => t('docs.sample.doctor_position'),
            'visit.recommends' => t('docs.sample.recommends'),
            'contract.id' => '1001',
            'contract.date' => I18n::formatDate($now - 86400 * 30, 'date'),
            'doc.id' => '0000',
            'doc.code' => (string)$def['code'],
            'doc.name' => (string)$def['name'],
            'doc.date' => I18n::formatDate($now, 'date'),
            'doc.expire' => (int)$def['expire_days'] > 0 ? I18n::formatDate($now + (int)$def['expire_days'] * 86400, 'date') : '',
            'staff.name' => $staff,
        ];
        $demo['client.passport'] = $demo['client.passport_serie'] . ' ' . $demo['client.passport_number']
            . ', ' . $demo['client.passport_issued_by'] . ', ' . $demo['client.passport_issued_at'];
        $demo['client.address'] = $demo['client.address_fact'];
        $vars = array_merge($vars, $demo);
        $vars['event.items'] = $itemsHtml;

        foreach (DocsConfig::org() as $k => $v) {
            $vars['org.' . $k] = $v !== '' ? $v : t('docs.sample.org.' . $k);
        }
        $vars['org.signature'] = DocsRender::signatureHtml();

        $bts = (int)strtotime('1990-05-15');
        $vars += [
            'client.surname' => t('docs.sample.client_surname'),
            'client.pname' => t('docs.sample.client_pname'),
            'client.patronymic' => t('docs.sample.client_patronymic'),
            'client.gender' => t('docs.print.gender_f'),
            'client.birth_day' => date('d', $bts),
            'client.birth_month' => date('m', $bts),
            'client.birth_year' => date('Y', $bts),
            'client.locality' => t('docs.print.locality_city'),
            'visit.date' => $vars['event.date'],
        ];
        $vars += DocsRender::cardVars(1024, $now - 86400 * 60);
        $vars['org.ogrn_boxes'] = DocsRender::boxes($vars['org.ogrn'], 13);

        $profileVer = DocsRender::profileVersion();
        $profileSchema = $profileVer !== null ? $profileVer['schema'] : ['sections' => []];
        $profilePayload = self::payload($profileSchema);
        if (isset(DocsSchema::fieldMap($profileSchema)['height_cm'])) {
            $profilePayload['height_cm'] = 168;
            $profilePayload['weight_kg'] = 61;
        }
        foreach (DocsRender::profileVars($profileSchema, $profilePayload, 0, self::staffNames()) as $k => $v) {
            $vars['profile.' . $k] = $v;
        }
        $visitPayload = (string)$def['kind'] === 'medcard_visit' ? $payload : [];
        $vars += DocsRender::visitHelperVars($visitPayload, $profileSchema, $profilePayload, 0, true);

        // Client-level 025 sheets: two sample visits (first + follow-up) and a short procedures list.
        $visitDef = DocsDefsRepository::firstOfKind('medcard_visit');
        $visitVer = $visitDef !== null ? DocsDefsRepository::currentVersion($visitDef) : null;
        $diaSample = t('docs.sample.diagnosis');
        $vars['dossier.diagnoses'] = DocsRender::diagnosesHtml([], [
            ['date' => $vars['card.day'] . '.' . $vars['card.month'] . '.' . $vars['card.year'], 'diagnosis' => $diaSample, 'doctor' => DocsRender::shortName($staff)],
        ]);
        $vars['dossier.visits'] = '';
        if ($visitVer !== null && (string)$def['kind'] !== 'medcard_visit') {
            $pages = [];
            foreach ([true, false] as $first) {
                $vp = self::payload($visitVer['schema']);
                $vv = $vars;
                foreach (DocsSchema::fieldMap($visitVer['schema']) as $fid => $f) {
                    $vv[$fid] = DocsSchema::displayValue($f, $vp[$fid] ?? null, self::staffNames());
                }
                $vv = array_merge($vv, DocsRender::visitHelperVars($vp, $profileSchema, $profilePayload, 0, $first));
                $vv['visit.date'] = $first ? I18n::formatDate($now - 86400 * 60, 'date') : $vars['event.date'];
                $vv['qr'] = '';
                $pages[] = DocsRender::renderWith($visitVer, $vv, 0, ['visit.first' => $first, 'client.female' => true]);
            }
            $vars['dossier.visits'] = implode('<div class="docs-page-break"></div>', $pages);
        }
        $vars['dossier.procedures'] = DocsRender::proceduresHtml(0, [
            ['date' => $vars['event.date'], 'name' => $lines[0]['name'], 'staff' => DocsRender::shortName($staff)],
            ['date' => $vars['event.date'], 'name' => $lines[1]['name'], 'staff' => DocsRender::shortName($staff)],
        ]);
        if ((string)$def['kind'] === 'medcard_dossier') {
            $dossier = $profileVer !== null ? DocsRender::dossierProfileHtml($profileSchema, $profilePayload, self::staffNames()) : '';
            if ($visitVer !== null) {
                $dossier .= DocsRender::dossierVisitHtml(
                    $vars['event.date'] . ' ' . $vars['event.time'],
                    $itemsHtml,
                    $visitVer['schema'],
                    self::payload($visitVer['schema']),
                    self::staffNames()
                );
            }
            $vars['dossier'] = $dossier;
        }
        $vars['qr'] = DocsRender::qrHtml(str_repeat('0', 32), 0);
        return $vars;
    }

    /**
     * Type-appropriate example per schema field.
     *
     * @param array{sections:list<array<string,mixed>>} $schema
     * @return array<string,mixed>
     */
    public static function payload(array $schema): array
    {
        $out = [];
        foreach (DocsSchema::fieldMap($schema) as $fid => $f) {
            switch ((string)$f['type']) {
                case 'textarea':
                    $out[$fid] = t('docs.sample.textarea');
                    break;
                case 'number':
                    $out[$fid] = 42;
                    break;
                case 'date':
                    $out[$fid] = date('Y-m-d');
                    break;
                case 'checkbox':
                    $out[$fid] = 1;
                    break;
                case 'select':
                    $out[$fid] = (string)($f['options'][0]['value'] ?? '');
                    break;
                case 'multiselect':
                    $out[$fid] = array_map(
                        static fn(array $o): string => (string)$o['value'],
                        array_slice((array)($f['options'] ?? []), 0, 2)
                    );
                    break;
                case 'staff':
                    $out[$fid] = (int)(Auth::userId() ?? 0);
                    break;
                case 'segments':
                    $out[$fid] = [['from' => '10:00', 'to' => '10:40', 'note' => t('docs.sample.segment_note')]];
                    break;
                default:
                    $out[$fid] = t('docs.sample.text');
            }
        }
        return $out;
    }

    /** @return array<int,string> */
    private static function staffNames(): array
    {
        $id = (int)(Auth::userId() ?? 0);
        return $id > 0 ? [$id => t('docs.sample.staff')] : [];
    }
}
