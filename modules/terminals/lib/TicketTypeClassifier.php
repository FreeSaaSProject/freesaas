<?php
declare(strict_types=1);

/**
 * Propose ID_TYPE / IS_LIMITED / IS_PAYER for ticket types via regex,
 * visit-duration stats, then optional LLM.
 */
final class TicketTypeClassifier
{
    private const SESSION_KEY = 'terminals_fullstats_classify_proposals';
    private const LLM_CHUNK = 20;
    private const LLM_MIN_CONFIDENCE = 0.55;

    /** Max visit minutes that still look like a timed ticket (above → unlimited). */
    private const UNLIM_MAX_MIN = 240;

    /**
     * Timed ticket buckets: IS_LIMITED code => nominal minutes.
     *
     * @var array<int,int>
     */
    private const DURATION_BUCKETS = [
        9 => 60,
        2 => 120,
        3 => 180,
        4 => 240,
        5 => 300,
    ];

    /**
     * @param list<array{ticket_type:string,title_sample:string,cnt:int,sample_id?:int,avg_min?:float,max_min?:int}> $types
     * @return list<array<string,mixed>>
     */
    public static function propose(array $types): array
    {
        $proposals = [];
        foreach ($types as $row) {
            $ticketType = trim((string)($row['ticket_type'] ?? ''));
            if ($ticketType === '') {
                continue;
            }
            $title = (string)($row['title_sample'] ?? '');
            $avgMin = (float)($row['avg_min'] ?? 0);
            $maxMin = (int)($row['max_min'] ?? 0);
            $rx = self::viaRegex($ticketType, $title, $avgMin, $maxMin);
            $proposals[] = [
                'ticket_type' => $ticketType,
                'title_sample' => $title,
                'cnt' => (int)($row['cnt'] ?? 0),
                'sample_id' => (int)($row['sample_id'] ?? 0),
                'avg_min' => $avgMin,
                'max_min' => $maxMin,
                'id_type' => (int)$rx['id_type'],
                'is_limited' => (int)$rx['is_limited'],
                'is_payer' => (int)$rx['is_payer'],
                'is_oasis' => (int)$rx['is_oasis'],
                'source' => (string)$rx['source'],
                'rationale' => (string)$rx['rationale'],
            ];
        }

        if (self::llmReady()) {
            $need = [];
            foreach ($proposals as $i => $p) {
                if ((int)$p['id_type'] < 1 || (int)$p['is_limited'] < 1
                    || (int)$p['is_payer'] < 1 || (int)$p['is_oasis'] < 1) {
                    $need[$i] = $p;
                }
            }
            if ($need !== []) {
                self::fillViaLlm($proposals, $need);
            }
        }

        self::storeProposals($proposals);
        return $proposals;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function loadProposals(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $raw = $_SESSION[self::SESSION_KEY] ?? null;
        return is_array($raw) ? $raw : [];
    }

    public static function clearProposals(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    /**
     * @param list<array<string,mixed>> $proposals
     */
    public static function storeProposals(array $proposals): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $proposals;
        }
    }

    public static function llmReady(): bool
    {
        return class_exists('ModuleManager', false)
            && ModuleManager::isEnabled('llm')
            && class_exists('LlmClient', false)
            && class_exists('LlmConfig', false)
            && LlmConfig::hasReadyProfile();
    }

    /**
     * @return array{id_type:int,is_limited:int,is_payer:int,is_oasis:int,source:string,rationale:string}
     */
    public static function viaRegex(
        string $ticketType,
        string $titleFull = '',
        float $avgMin = 0.0,
        int $maxMin = 0
    ): array {
        $text = mb_strtolower(trim($ticketType . ' ' . $titleFull), 'UTF-8');
        $idType = 0;
        $isLimited = 0;
        $isPayer = 0;
        $isOasis = 0;
        $bits = [];

        // Certificates are always adult tickets.
        if (preg_match('/сертификат|certificat|серт(?![а-яa-z])/u', $text)) {
            $idType = 3;
            $bits[] = 'age=adult(cert)';
        } elseif (preg_match('/детск|ребен|ребён|child|kids?/u', $text)) {
            $idType = 1;
            $bits[] = 'age=child';
        } elseif (preg_match('/пенс|pension|senior/u', $text)) {
            $idType = 2;
            $bits[] = 'age=pensioner';
        } elseif (preg_match('/взросл|adult|стандарт/u', $text)) {
            $idType = 3;
            $bits[] = 'age=adult';
        }

        if (preg_match('/безлим|unlim|без\s*лимит/u', $text)) {
            $isLimited = 1;
            $bits[] = 'dur=unlim';
        } elseif (preg_match('/\b1\s*ч|\b1\s*h|час\b/u', $text) && !preg_match('/[2-5]\s*ч/u', $text)) {
            $isLimited = 9;
            $bits[] = 'dur=1h';
        } elseif (preg_match('/\b2\s*ч|\b2\s*h/u', $text)) {
            $isLimited = 2;
            $bits[] = 'dur=2h';
        } elseif (preg_match('/\b3\s*ч|\b3\s*h/u', $text)) {
            $isLimited = 3;
            $bits[] = 'dur=3h';
        } elseif (preg_match('/\b4\s*ч|\b4\s*h/u', $text)) {
            $isLimited = 4;
            $bits[] = 'dur=4h';
        } elseif (preg_match('/\b5\s*ч|\b5\s*h/u', $text)) {
            $isLimited = 5;
            $bits[] = 'dur=5h';
        }

        // Visit-length heuristics when title did not name a duration.
        if ($isLimited < 1 && ($avgMin > 0 || $maxMin > 0)) {
            $fromStats = self::durationFromStats($avgMin, $maxMin);
            if ($fromStats['code'] > 0) {
                $isLimited = $fromStats['code'];
                $bits[] = $fromStats['note'];
            }
        }

        if (preg_match('/оазис|oasis/u', $text)) {
            $isOasis = 1;
            $bits[] = 'oasis=yes';
        } else {
            // Name has no oasis marker → treat as not-oasis when we can classify.
            $isOasis = 2;
            $bits[] = 'oasis=no';
        }

        // Child → never payer; adult/senior → always payer.
        if ($idType === 1) {
            $isPayer = 2;
            $bits[] = 'payer=no(child)';
        } elseif ($idType === 2 || $idType === 3) {
            $isPayer = 1;
            $bits[] = 'payer=yes(age)';
        } elseif (preg_match('/сопров|гость|guest|бесплат|free\b|компл/u', $text)) {
            $isPayer = 2;
            $bits[] = 'payer=no';
        }

        return [
            'id_type' => TicketTypeEnums::normalizeIdType($idType),
            'is_limited' => TicketTypeEnums::normalizeIsLimited($isLimited),
            'is_payer' => TicketTypeEnums::normalizeIsPayer($isPayer),
            'is_oasis' => TicketTypeEnums::normalizeIsOasis($isOasis),
            'source' => $bits !== [] ? 'regex' : 'none',
            'rationale' => $bits !== [] ? implode(', ', $bits) : '',
        ];
    }

    /**
     * Infer IS_LIMITED from avg/max visit minutes.
     * max > 4h → unlimited; otherwise nearest stable hour bucket when visits cluster.
     *
     * @return array{code:int,note:string}
     */
    public static function durationFromStats(float $avgMin, int $maxMin): array
    {
        if ($maxMin > self::UNLIM_MAX_MIN) {
            return [
                'code' => 1,
                'note' => 'dur=unlim(stats max>' . self::UNLIM_MAX_MIN . 'm avg=' . round($avgMin) . 'm)',
            ];
        }
        if ($avgMin < 20) {
            return ['code' => 0, 'note' => ''];
        }

        $bestCode = 0;
        $bestDist = PHP_FLOAT_MAX;
        foreach (self::DURATION_BUCKETS as $code => $nominal) {
            $dist = abs($avgMin - $nominal);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestCode = $code;
            }
        }
        if ($bestCode < 1) {
            return ['code' => 0, 'note' => ''];
        }
        $nominal = self::DURATION_BUCKETS[$bestCode];
        // Cluster: avg near bucket and max not much beyond it (same-duration tendency).
        if ($bestDist <= 25.0 && $maxMin <= $nominal + 45) {
            return [
                'code' => $bestCode,
                'note' => 'dur=stats(avg=' . round($avgMin) . 'm max=' . $maxMin . 'm → ' . $nominal . 'm)',
            ];
        }
        return ['code' => 0, 'note' => ''];
    }

    /**
     * @param list<array<string,mixed>> $proposals
     * @param array<int,array<string,mixed>> $need index => proposal
     */
    private static function fillViaLlm(array &$proposals, array $need): void
    {
        $chunks = array_chunk($need, self::LLM_CHUNK, true);
        foreach ($chunks as $chunk) {
            $items = [];
            foreach ($chunk as $idx => $p) {
                $items[] = [
                    'i' => (int)$idx,
                    'ticket_type' => (string)$p['ticket_type'],
                    'title' => (string)$p['title_sample'],
                    'avg_visit_min' => (float)($p['avg_min'] ?? 0),
                    'max_visit_min' => (int)($p['max_min'] ?? 0),
                    'id_type' => (int)$p['id_type'],
                    'is_limited' => (int)$p['is_limited'],
                    'is_payer' => (int)$p['is_payer'],
                    'is_oasis' => (int)$p['is_oasis'],
                ];
            }
            $catalog = TicketTypeEnums::llmCatalog();
            $messages = [
                [
                    'role' => 'system',
                    'content' => 'You classify spa/therm ticket types for analytics. '
                        . 'Return JSON {"items":[{"i":0,"id_type":N,"is_limited":N,"is_payer":N,"is_oasis":N,"confidence":0.0,"rationale":"..."}]}. '
                        . 'Only fill fields that are currently 0; keep known non-zero values. '
                        . 'Hard rules: certificates (сертификат) → id_type=3 adult; '
                        . 'id_type=1 child → is_payer=2; id_type=2|3 adult/senior → is_payer=1; '
                        . 'name contains оазис/oasis → is_oasis=1 else usually is_oasis=2. '
                        . 'Duration hints from real visits: avg_visit_min / max_visit_min (minutes). '
                        . 'If max_visit_min > 240, is_limited is usually 1 (unlimited). '
                        . 'If visits cluster near 60/120/180/240/300 minutes, pick that timed bucket '
                        . '(is_limited 9/2/3/4/5). '
                        . 'Use only catalog ids. confidence 0..1. Russian ticket names are normal.',
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'catalog' => $catalog,
                        'items' => $items,
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ];
            try {
                $res = LlmClient::complete($messages, [
                    'temperature' => 0.1,
                    'max_tokens' => 2000,
                    'json' => true,
                    'timeout' => 60,
                ]);
            } catch (Throwable $e) {
                continue;
            }
            if (empty($res['ok'])) {
                continue;
            }
            $parsed = is_array($res['json'] ?? null) ? $res['json'] : [];
            if ($parsed === []) {
                $pj = LlmClient::parseMessageJson((string)($res['text'] ?? ''));
                $parsed = !empty($pj['ok']) && is_array($pj['data'] ?? null) ? $pj['data'] : [];
            }
            $list = is_array($parsed['items'] ?? null) ? $parsed['items'] : [];
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $i = (int)($row['i'] ?? -1);
                if (!isset($proposals[$i])) {
                    continue;
                }
                $confidence = (float)($row['confidence'] ?? 0);
                if ($confidence < self::LLM_MIN_CONFIDENCE) {
                    continue;
                }
                $cur = $proposals[$i];
                $changed = false;
                if ((int)$cur['id_type'] < 1) {
                    $v = TicketTypeEnums::normalizeIdType((int)($row['id_type'] ?? 0));
                    if ($v > 0) {
                        $cur['id_type'] = $v;
                        $changed = true;
                    }
                }
                if ((int)$cur['is_limited'] < 1) {
                    $v = TicketTypeEnums::normalizeIsLimited((int)($row['is_limited'] ?? 0));
                    if ($v > 0) {
                        $cur['is_limited'] = $v;
                        $changed = true;
                    }
                }
                if ((int)$cur['is_payer'] < 1) {
                    $v = TicketTypeEnums::normalizeIsPayer((int)($row['is_payer'] ?? 0));
                    if ($v > 0) {
                        $cur['is_payer'] = $v;
                        $changed = true;
                    }
                }
                if ((int)$cur['is_oasis'] < 1) {
                    $v = TicketTypeEnums::normalizeIsOasis((int)($row['is_oasis'] ?? 0));
                    if ($v > 0) {
                        $cur['is_oasis'] = $v;
                        $changed = true;
                    }
                }
                $cur = self::applyHardRules($cur, $changed);
                if ($changed) {
                    $rationale = trim((string)($row['rationale'] ?? ''));
                    $prev = (string)($cur['rationale'] ?? '');
                    $cur['rationale'] = $prev !== '' && $rationale !== ''
                        ? $prev . '; llm: ' . $rationale
                        : ($rationale !== '' ? 'llm: ' . $rationale : $prev);
                    $src = (string)($cur['source'] ?? 'none');
                    $cur['source'] = ($src === 'regex' || $src === 'mixed') ? 'mixed' : 'llm';
                    $proposals[$i] = $cur;
                }
            }
        }
        foreach ($proposals as $i => $p) {
            $changed = false;
            $proposals[$i] = self::applyHardRules($p, $changed);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function applyHardRules(array $row, bool &$changed): array
    {
        $idType = (int)($row['id_type'] ?? 0);
        if ($idType === 1 && (int)($row['is_payer'] ?? 0) !== 2) {
            $row['is_payer'] = 2;
            $changed = true;
        } elseif (($idType === 2 || $idType === 3) && (int)($row['is_payer'] ?? 0) !== 1) {
            $row['is_payer'] = 1;
            $changed = true;
        }
        return $row;
    }
}
