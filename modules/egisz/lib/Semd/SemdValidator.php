<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Field-level checks before XSD/Schematron (later). Not a substitute for federal validation.
 */

final class SemdValidator
{
    /**
     * @param array<string,mixed> $ctx
     * @param list<array{code:string,name:string,known:bool}> $diagnoses
     * @return array{errors:list<string>,warnings:list<string>}
     */
    public static function checkContext(array $ctx, array $diagnoses, string $kind): array
    {
        $errors = [];
        $warnings = [];

        if (EgiszConfig::moOid() === '') {
            $errors[] = t('egisz.err.mo_oid');
        }

        $patient = is_array($ctx['patient'] ?? null) ? $ctx['patient'] : [];
        if (trim((string)($patient['surname'] ?? $patient['name'] ?? '')) === '') {
            $errors[] = t('egisz.err.patient_name');
        }
        if (trim((string)($patient['birthday'] ?? '')) === '') {
            $warnings[] = t('egisz.warn.patient_birthday');
        }
        $snils = trim((string)($patient['snils'] ?? ''));
        if ($snils === '') {
            $errors[] = t('egisz.err.patient_snils');
        } elseif (!Snils::isValid($snils)) {
            $errors[] = t('egisz.err.bad_snils');
        }

        $doctor = is_array($ctx['doctor'] ?? null) ? $ctx['doctor'] : [];
        if ((int)($doctor['id'] ?? 0) < 1) {
            $errors[] = t('egisz.err.doctor');
        }
        $docSnils = trim((string)($doctor['snils'] ?? ''));
        if ($docSnils === '') {
            $errors[] = t('egisz.err.doctor_snils');
        } elseif (!Snils::isValid($docSnils)) {
            $errors[] = t('egisz.err.bad_snils');
        }
        if (trim((string)($doctor['position_code'] ?? '')) === '') {
            $warnings[] = t('egisz.warn.doctor_position');
        }

        if ($diagnoses === []) {
            $errors[] = t('egisz.err.diagnosis');
        } else {
            foreach ($diagnoses as $d) {
                if (empty($d['known'])) {
                    $warnings[] = t('egisz.warn.icd_unknown', ['code' => (string)$d['code']]);
                }
            }
        }

        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        if ($kind === 'consult') {
            if (trim((string)($payload['ana_gen'] ?? '')) === '' && trim((string)($payload['complaints'] ?? '')) === '') {
                $warnings[] = t('egisz.warn.complaints');
            }
            if (trim((string)($payload['ana_obj'] ?? '')) === '') {
                $warnings[] = t('egisz.warn.objective');
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
