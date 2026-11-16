<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** SEMD-oriented protocol of consultation from medcard_visit. */
final class ConsultationProtocolBuilder extends AbstractCdaBuilder
{
    public function kind(): string
    {
        return 'consult';
    }

    protected function documentOid(): string
    {
        return EgiszConfig::semdConsultOid();
    }

    protected function title(): string
    {
        return t('egisz.semd.consult_title');
    }

    protected function bodySections(array $ctx, array $diagnoses): string
    {
        $p = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        $html = '';
        $html .= $this->textSection('COMPL', t('egisz.semd.sec.complaints'), (string)($p['complaints'] ?? ''));
        $html .= $this->textSection('ANAM', t('egisz.semd.sec.anamnesis'), (string)($p['ana_gen'] ?? ''));
        $html .= $this->textSection('OBJ', t('egisz.semd.sec.objective'), (string)($p['ana_obj'] ?? ''));
        $html .= $this->diagnosisSection($diagnoses);
        $html .= $this->textSection('REC', t('egisz.semd.sec.recommends'), (string)($p['recommends'] ?? ''));
        $html .= $this->textSection('THER', t('egisz.semd.sec.therapy'), (string)($p['drugs_physio'] ?? ''));
        return $html;
    }
}
