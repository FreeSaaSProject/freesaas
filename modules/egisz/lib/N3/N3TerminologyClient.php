<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * REST client for N3 terminology (FHIR term) used when coding CDA / IEMK payloads.
 */

final class N3TerminologyClient
{
    /** @var string */
    private $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = $baseUrl !== null && $baseUrl !== ''
            ? rtrim($baseUrl, '/') . '/'
            : EgiszConfig::n3TermUrl();
    }

    /**
     * @return array{ok:bool, items?:list<array<string,mixed>>, error?:string, raw?:mixed}
     */
    public function lookup(string $system, string $code = '', string $filter = '', int $count = 20): array
    {
        $system = trim($system);
        $code = trim($code);
        $filter = trim($filter);
        $count = max(1, min(50, $count));

        // FHIR ValueSet/$expand or CodeSystem/$lookup — try expand with filter first.
        if ($filter !== '' || ($system !== '' && $code === '')) {
            $url = $this->baseUrl . 'ValueSet/$expand?' . http_build_query(array_filter([
                'url' => $system !== '' ? $system : null,
                'filter' => $filter !== '' ? $filter : null,
                'count' => $count,
            ]));
            return $this->getExpand($url);
        }

        if ($system !== '' && $code !== '') {
            $url = $this->baseUrl . 'CodeSystem/$lookup?' . http_build_query([
                'system' => $system,
                'code' => $code,
            ]);
            return $this->getLookup($url);
        }

        return ['ok' => false, 'error' => t('egisz.err.term_params')];
    }

    /**
     * Lightweight connectivity probe (no auth on many demo term endpoints).
     *
     * @return array{ok:bool, error?:string, http_code?:int}
     */
    public function ping(): array
    {
        $url = $this->baseUrl . 'metadata';
        $res = $this->httpGet($url);
        if (!$res['ok']) {
            // Some stands 404 metadata — try root.
            $res = $this->httpGet($this->baseUrl);
        }
        return [
            'ok' => $res['ok'] || (($res['http_code'] ?? 0) >= 200 && ($res['http_code'] ?? 0) < 500),
            'error' => $res['ok'] ? null : (string)($res['error'] ?? ''),
            'http_code' => (int)($res['http_code'] ?? 0),
        ];
    }

    /** @return array{ok:bool, items?:list<array<string,mixed>>, error?:string, raw?:mixed} */
    private function getExpand(string $url): array
    {
        $res = $this->httpGet($url);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? ''), 'raw' => $res['body'] ?? null];
        }
        $json = json_decode((string)($res['body'] ?? ''), true);
        $items = [];
        $contains = $json['expansion']['contains'] ?? [];
        if (is_array($contains)) {
            foreach ($contains as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $items[] = [
                    'system' => (string)($row['system'] ?? ''),
                    'code' => (string)($row['code'] ?? ''),
                    'display' => (string)($row['display'] ?? ''),
                ];
            }
        }
        return ['ok' => true, 'items' => $items, 'raw' => $json];
    }

    /** @return array{ok:bool, items?:list<array<string,mixed>>, error?:string, raw?:mixed} */
    private function getLookup(string $url): array
    {
        $res = $this->httpGet($url);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? ''), 'raw' => $res['body'] ?? null];
        }
        $json = json_decode((string)($res['body'] ?? ''), true);
        $display = '';
        if (is_array($json['parameter'] ?? null)) {
            foreach ($json['parameter'] as $p) {
                if (!is_array($p)) {
                    continue;
                }
                if (($p['name'] ?? '') === 'display') {
                    $display = (string)($p['valueString'] ?? '');
                }
            }
        }
        $items = [];
        if ($display !== '' || is_array($json)) {
            $items[] = [
                'system' => '',
                'code' => '',
                'display' => $display,
            ];
        }
        return ['ok' => true, 'items' => $items, 'raw' => $json];
    }

    /**
     * @return array{ok:bool, body?:string, error?:string, http_code?:int}
     */
    private function httpGet(string $url): array
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'header' => "Accept: application/fhir+json, application/json\r\n",
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                return ['ok' => false, 'error' => t('egisz.err.http'), 'http_code' => 0];
            }
            return ['ok' => true, 'body' => $body, 'http_code' => 200];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Accept: application/fhir+json, application/json'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($body === false) {
            return ['ok' => false, 'error' => $err !== '' ? $err : t('egisz.err.http'), 'http_code' => $code];
        }
        if ($code >= 400) {
            return ['ok' => false, 'error' => 'HTTP ' . $code, 'body' => (string)$body, 'http_code' => $code];
        }
        return ['ok' => true, 'body' => (string)$body, 'http_code' => $code];
    }
}
