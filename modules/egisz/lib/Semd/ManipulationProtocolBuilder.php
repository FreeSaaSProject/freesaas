<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * SEMD-oriented protocol of medical manipulation (cosmetology procedures).
 * Uses visit payload; optional procedure_name / lot / dose from specialty packs.
 */
final class ManipulationProtocolBuilder extends AbstractCdaBuilder
{
    public function kind(): string
    {
        return 'manip';
    }

    protected function documentOid(): string
    {
        return EgiszConfig::semdManipOid();
    }

    protected function title(): string
    {
        return t('egisz.semd.manip_title');
    }

    protected function bodySections(array $ctx, array $diagnoses): string
    {
        $p = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        $procBits = [];
        foreach (['procedure_name', 'lot', 'dose', 'course_plan'] as $k) {
            $v = trim((string)($p[$k] ?? ''));
            if ($v !== '') {
                $procBits[] = $k . ': ' . $v;
            }
        }
        $manipText = implode("\n", $procBits);
        if ($manipText === '') {
            $manipText = trim((string)($p['ana_obj'] ?? $p['recommends'] ?? ''));
        }

        $html = '';
        $html .= $this->textSection('COMPL', t('egisz.semd.sec.complaints'), (string)($p['complaints'] ?? ''));
        $html .= $this->textSection('OBJ', t('egisz.semd.sec.objective'), (string)($p['ana_obj'] ?? ''));
        $html .= $this->diagnosisSection($diagnoses);
        $html .= $this->textSection('MANIP', t('egisz.semd.sec.manipulation'), $manipText);
        $html .= $this->textSection('REC', t('egisz.semd.sec.recommends'), (string)($p['recommends'] ?? ''));
        return $html;
    }
}
