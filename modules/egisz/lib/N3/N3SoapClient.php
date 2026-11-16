<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Thin SoapClient wrapper for N3 demo EMK / Pix services.
 * WSDL is the source of truth on the stand; method names follow N3 IEMK docs.
 * Connect is lazy — constructing does not hit the network (needed without OpenVPN).
 */

final class N3SoapClient
{
    /** @var SoapClient|null */
    private $client;

    /** @var string */
    private $wsdl;

    /** @var string */
    private $error = '';

    /** @var bool */
    private $attempted = false;

    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $timeout;

    public function __construct(string $serviceUrl, int $connectTimeout = 3, int $timeout = 8)
    {
        $serviceUrl = rtrim(trim($serviceUrl), '/');
        $this->connectTimeout = max(1, $connectTimeout);
        $this->timeout = max($this->connectTimeout, $timeout);
        if ($serviceUrl === '') {
            $this->error = t('egisz.err.n3_url');
            $this->wsdl = '';
            $this->attempted = true;
            return;
        }
        $this->wsdl = (stripos($serviceUrl, '?wsdl') !== false)
            ? $serviceUrl
            : $serviceUrl . '?wsdl';
    }

    public function ready(): bool
    {
        $this->ensure();
        return $this->client !== null;
    }

    public function lastError(): string
    {
        $this->ensure();
        return $this->error;
    }

    public function wsdl(): string
    {
        return $this->wsdl;
    }

    /**
     * @param array<string,mixed> $params positional or named per WSDL
     * @return array{ok:bool, result?:mixed, error?:string, raw?:mixed}
     */
    public function call(string $method, array $params): array
    {
        $this->ensure();
        if ($this->client === null) {
            return ['ok' => false, 'error' => $this->error !== '' ? $this->error : t('egisz.err.soap_missing')];
        }
        try {
            $isList = $params !== [] && array_keys($params) === range(0, count($params) - 1);
            $result = $this->client->__soapCall($method, $isList ? $params : [$params]);
            return [
                'ok' => true,
                'result' => $result,
                'raw' => [
                    'request' => $this->safeTrace('__getLastRequest'),
                    'response' => $this->safeTrace('__getLastResponse'),
                ],
            ];
        } catch (SoapFault $e) {
            return [
                'ok' => false,
                'error' => trim($e->faultstring !== '' ? $e->faultstring : $e->getMessage()),
                'raw' => [
                    'faultcode' => $e->faultcode ?? null,
                    'request' => $this->safeTrace('__getLastRequest'),
                    'response' => $this->safeTrace('__getLastResponse'),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'raw' => [
                    'request' => $this->safeTrace('__getLastRequest'),
                    'response' => $this->safeTrace('__getLastResponse'),
                ],
            ];
        }
    }

    /** @return list<string> */
    public function methods(): array
    {
        $this->ensure();
        if ($this->client === null) {
            return [];
        }
        try {
            $fns = $this->client->__getFunctions();
            return is_array($fns) ? array_values($fns) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function ensure(): void
    {
        if ($this->attempted) {
            return;
        }
        $this->attempted = true;

        if ($this->wsdl === '') {
            return;
        }
        if (!class_exists('SoapClient', false)) {
            $this->error = t('egisz.err.soap_missing');
            return;
        }

        $prev = ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', (string)$this->timeout);
        try {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => $this->timeout,
                    'method' => 'GET',
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $this->client = new SoapClient($this->wsdl, [
                'trace' => true,
                'exceptions' => true,
                'connection_timeout' => $this->connectTimeout,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
                'soap_version' => SOAP_1_1,
                'stream_context' => $ctx,
            ]);
        } catch (Throwable $e) {
            $this->client = null;
            $this->error = $e->getMessage();
        } finally {
            if ($prev !== false) {
                @ini_set('default_socket_timeout', (string)$prev);
            }
        }
    }

    private function safeTrace(string $method): string
    {
        if ($this->client === null || !method_exists($this->client, $method)) {
            return '';
        }
        try {
            $v = $this->client->{$method}();
            return is_string($v) ? mb_substr($v, 0, 8000) : '';
        } catch (Throwable $e) {
            return '';
        }
    }
}
