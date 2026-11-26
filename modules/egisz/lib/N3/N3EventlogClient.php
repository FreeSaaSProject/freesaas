<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * N3.Health Eventlog REST (status of REMD / IEMK submissions).
 * @see https://api.n3health.ru/eventlog/
 */

final class N3EventlogClient
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $token;

    /** @var string */
    private $idLpu;

    public function __construct(?string $baseUrl = null, ?string $token = null, ?string $idLpu = null)
    {
        $this->baseUrl = $baseUrl !== null && $baseUrl !== ''
            ? rtrim($baseUrl, '/') . '/'
            : EgiszConfig::n3EventlogUrl();
        $tok = $token !== null ? trim($token) : EgiszConfig::n3EventlogToken();
        // Many stands accept the same GUID as IEMK auth when a dedicated eventlog token is absent.
        $this->token = $tok !== '' ? $tok : EgiszConfig::n3Guid();
        $this->idLpu = $idLpu !== null ? trim($idLpu) : EgiszConfig::n3IdLpu();
    }

    public function configured(): bool
    {
        return $this->token !== '' && $this->idLpu !== '';
    }

    /**
     * @return array{ok:bool, status?:string, error?:string, raw?:mixed}
     */
    public function getStatus(string $idDocumentMis): array
    {
        $idDocumentMis = trim($idDocumentMis);
        if ($idDocumentMis === '') {
            return ['ok' => false, 'error' => t('egisz.err.eventlog_id')];
        }
        if (!$this->configured()) {
            return ['ok' => false, 'error' => t('egisz.err.n3_not_configured')];
        }

        $attempts = [
            $this->baseUrl . 'GetStatus?' . http_build_query([
                'IdDocumentMis' => $idDocumentMis,
                'idLpu' => $this->idLpu,
            ]),
            $this->baseUrl . 'GetStatusTries?' . http_build_query([
                'IdDocumentMis' => $idDocumentMis,
                'idLpu' => $this->idLpu,
            ]),
        ];

        $lastErr = '';
        $lastRaw = null;
        foreach ($attempts as $url) {
            $res = $this->httpGet($url);
            $lastRaw = $res['body'] ?? null;
            if (!$res['ok']) {
                $lastErr = (string)($res['error'] ?? '');
                continue;
            }
            $json = json_decode((string)($res['body'] ?? ''), true);
            $status = self::extractStatus($json);
            return [
                'ok' => true,
                'status' => $status,
                'raw' => $json !== null ? $json : $res['body'],
            ];
        }

        return ['ok' => false, 'error' => $lastErr !== '' ? $lastErr : t('egisz.err.http'), 'raw' => $lastRaw];
    }

    /**
     * @param mixed $json
     */
    private static function extractStatus($json): string
    {
        if (!is_array($json)) {
            return 'unknown';
        }
        foreach (['Status', 'status', 'StatusName', 'state', 'Result'] as $k) {
            if (isset($json[$k]) && (is_string($json[$k]) || is_numeric($json[$k]))) {
                return (string)$json[$k];
            }
        }
        if (isset($json[0]) && is_array($json[0])) {
            return self::extractStatus($json[0]);
        }
        return 'unknown';
    }

    /**
     * @return array{ok:bool, body?:string, error?:string, http_code?:int}
     */
    private function httpGet(string $url): array
    {
        $headers = [
            'Accept: application/json',
            'Authorization: Token ' . $this->token,
            'X-Auth-Token: ' . $this->token,
        ];

        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 8,
                    'header' => implode("\r\n", $headers) . "\r\n",
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
            CURLOPT_HTTPHEADER => $headers,
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
