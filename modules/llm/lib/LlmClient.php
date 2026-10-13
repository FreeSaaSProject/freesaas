<?php
declare(strict_types=1);

/** OpenAI-compatible /chat/completions client (DeepSeek and similar). */
final class LlmClient
{
    /**
     * @param array<string,mixed> $body
     * @param array{timeout?:int,profile?:string} $opts
     * @return array{ok:bool,data:?array,error:string,profile:?string}
     */
    public static function chat(array $body, array $opts = []): array
    {
        $profileId = isset($opts['profile']) ? (string)$opts['profile'] : null;
        $cfg = LlmConfig::resolve($profileId);
        if ($cfg === null) {
            return ['ok' => false, 'data' => null, 'error' => t('llm.err.config'), 'profile' => null];
        }
        if (empty($body['model'])) {
            $body['model'] = $cfg['model_chat'];
        }
        $timeout = max(1, (int)($opts['timeout'] ?? 60));
        $json = self::encodeBody($body);
        if ($json === false) {
            return [
                'ok' => false,
                'data' => null,
                'error' => 'JSON encode failed: ' . json_last_error_msg(),
                'profile' => $cfg['id'],
            ];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $cfg['api_base'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $cfg['api_key'],
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = t('llm.err.curl') . ': ' . curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'data' => null, 'error' => $err, 'profile' => $cfg['id']];
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = is_string($response) ? $response : '';
            if (strlen($snippet) > 400) {
                $snippet = substr($snippet, 0, 400) . '...';
            }
            return [
                'ok' => false,
                'data' => null,
                'error' => t('llm.err.http') . ' ' . $httpCode . ': ' . $snippet,
                'profile' => $cfg['id'],
            ];
        }
        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'data' => null, 'error' => 'Unexpected LLM response', 'profile' => $cfg['id']];
        }
        return ['ok' => true, 'data' => $data, 'error' => '', 'profile' => $cfg['id']];
    }

    /**
     * Streaming completion. Returns accumulated text.
     *
     * @param array<string,mixed> $body
     * @param callable|null $onChunk function(string $delta, string $full): void
     * @param array{timeout?:int,profile?:string} $opts
     * @return array{ok:bool,text:string,error:string,profile:?string}
     */
    public static function chatStream(array $body, ?callable $onChunk = null, array $opts = []): array
    {
        $profileId = isset($opts['profile']) ? (string)$opts['profile'] : null;
        $cfg = LlmConfig::resolve($profileId);
        if ($cfg === null) {
            return ['ok' => false, 'text' => '', 'error' => t('llm.err.config'), 'profile' => null];
        }
        if (empty($body['model'])) {
            $body['model'] = $cfg['model_chat'];
        }
        $body['stream'] = true;
        $timeout = max(1, (int)($opts['timeout'] ?? 300));
        $postData = self::encodeBody($body);
        if ($postData === false) {
            return [
                'ok' => false,
                'text' => '',
                'error' => 'JSON encode failed: ' . json_last_error_msg(),
                'profile' => $cfg['id'],
            ];
        }

        $fullContent = '';
        $buffer = '';
        $writeCallback = static function ($ch, $data) use (&$fullContent, &$buffer, $onChunk) {
            $len = strlen($data);
            if ($len === 0) {
                return 0;
            }
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);
                if ($line === '' || strpos($line, 'data:') !== 0) {
                    continue;
                }
                $jsonStr = trim(substr($line, 5));
                if ($jsonStr === '[DONE]') {
                    continue;
                }
                $chunk = json_decode($jsonStr, true);
                if (isset($chunk['choices'][0]['delta']['content'])) {
                    $delta = (string)$chunk['choices'][0]['delta']['content'];
                    $fullContent .= $delta;
                    if (is_callable($onChunk)) {
                        $onChunk($delta, $fullContent);
                    }
                }
            }
            return $len;
        };

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $cfg['api_base'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: text/event-stream',
                'Authorization: Bearer ' . $cfg['api_key'],
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_WRITEFUNCTION => $writeCallback,
        ]);
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            return ['ok' => false, 'text' => $fullContent, 'error' => t('llm.err.curl') . ': ' . $err, 'profile' => $cfg['id']];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'text' => $fullContent, 'error' => t('llm.err.http') . ' ' . $httpCode, 'profile' => $cfg['id']];
        }
        return ['ok' => true, 'text' => trim($fullContent), 'error' => '', 'profile' => $cfg['id']];
    }

    /**
     * @param mixed $contentField
     * @param array<string,string> $fallbackRegexes
     * @return array{ok:bool,data:?array,error:string}
     */
    public static function parseMessageJson($contentField, array $fallbackRegexes = []): array
    {
        $errorText = '';
        $res = null;
        if (is_array($contentField)) {
            $res = $contentField;
        } else {
            $content = trim((string)$contentField);
            $res = json_decode($content, true);
            if (!is_array($res)) {
                $start = strpos($content, '{');
                $end = strrpos($content, '}');
                if ($start !== false && $end !== false && $end > $start) {
                    $res = json_decode(substr($content, $start, $end - $start + 1), true);
                }
            }
        }
        if (is_array($res)) {
            return ['ok' => true, 'data' => $res, 'error' => ''];
        }

        $raw = is_string($contentField) ? $contentField : (string)json_encode($contentField, JSON_UNESCAPED_UNICODE);
        if ($fallbackRegexes !== []) {
            $partial = [];
            foreach ($fallbackRegexes as $field => $pattern) {
                if (preg_match($pattern, $raw, $m)) {
                    $partial[$field] = isset($m[1]) ? (string)$m[1] : '';
                }
            }
            if ($partial !== []) {
                return ['ok' => true, 'data' => $partial, 'error' => ''];
            }
        }
        $snippet = $raw;
        if (strlen($snippet) > 400) {
            $snippet = substr($snippet, 0, 400) . '...';
        }
        return ['ok' => false, 'data' => null, 'error' => t('llm.err.parse') . ': ' . $snippet];
    }

    /**
     * Convenience: chat + extract choices[0].message.content + optional JSON parse.
     *
     * @param list<array{role:string,content:string}> $messages
     * @param array{timeout?:int,profile?:string,temperature?:float,max_tokens?:int,json?:bool,model?:string} $opts
     * @return array{ok:bool,text:string,json:?array,raw:?array,error:string,profile:?string}
     */
    public static function complete(array $messages, array $opts = []): array
    {
        $body = [
            'messages' => $messages,
            'temperature' => isset($opts['temperature']) ? (float)$opts['temperature'] : 0.2,
            'stream' => false,
        ];
        if (isset($opts['max_tokens'])) {
            $body['max_tokens'] = (int)$opts['max_tokens'];
        }
        if (isset($opts['model']) && trim((string)$opts['model']) !== '') {
            $body['model'] = trim((string)$opts['model']);
        }
        if (!empty($opts['json'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        $res = self::chat($body, [
            'timeout' => (int)($opts['timeout'] ?? 60),
            'profile' => isset($opts['profile']) ? (string)$opts['profile'] : null,
        ]);
        if (!$res['ok'] || !is_array($res['data'])) {
            return [
                'ok' => false,
                'text' => '',
                'json' => null,
                'raw' => $res['data'],
                'error' => $res['error'],
                'profile' => $res['profile'],
            ];
        }
        $content = $res['data']['choices'][0]['message']['content'] ?? '';
        $text = is_string($content) ? $content : (string)json_encode($content, JSON_UNESCAPED_UNICODE);
        $parsed = null;
        if (!empty($opts['json'])) {
            $pj = self::parseMessageJson($content);
            if ($pj['ok']) {
                $parsed = $pj['data'];
            } else {
                return [
                    'ok' => false,
                    'text' => $text,
                    'json' => null,
                    'raw' => $res['data'],
                    'error' => $pj['error'],
                    'profile' => $res['profile'],
                ];
            }
        }
        return [
            'ok' => true,
            'text' => $text,
            'json' => $parsed,
            'raw' => $res['data'],
            'error' => '',
            'profile' => $res['profile'],
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @return string|false
     */
    private static function encodeBody(array $body)
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        return json_encode($body, $flags);
    }

    /**
     * Chat with OpenAI-compatible tools / tool_calls (non-streaming).
     *
     * @param list<array<string,mixed>> $messages
     * @param list<array<string,mixed>> $tools
     * @param array{timeout?:int,profile?:string,temperature?:float,max_tokens?:int,model?:string,tool_choice?:mixed} $opts
     * @return array{
     *   ok:bool,
     *   message:array<string,mixed>,
     *   finish_reason:string,
     *   raw:?array,
     *   error:string,
     *   profile:?string
     * }
     */
    public static function chatWithTools(array $messages, array $tools, array $opts = []): array
    {
        $body = [
            'messages' => $messages,
            'temperature' => isset($opts['temperature']) ? (float)$opts['temperature'] : 0.2,
            'stream' => false,
        ];
        if ($tools !== []) {
            $body['tools'] = $tools;
            if (array_key_exists('tool_choice', $opts)) {
                $body['tool_choice'] = $opts['tool_choice'];
            } else {
                $body['tool_choice'] = 'auto';
            }
        }
        if (isset($opts['max_tokens'])) {
            $body['max_tokens'] = (int)$opts['max_tokens'];
        }
        if (isset($opts['model']) && trim((string)$opts['model']) !== '') {
            $body['model'] = trim((string)$opts['model']);
        }
        $res = self::chat($body, [
            'timeout' => (int)($opts['timeout'] ?? 90),
            'profile' => isset($opts['profile']) ? (string)$opts['profile'] : null,
        ]);
        if (!$res['ok'] || !is_array($res['data'])) {
            return [
                'ok' => false,
                'message' => [],
                'finish_reason' => '',
                'raw' => $res['data'],
                'error' => $res['error'],
                'profile' => $res['profile'],
            ];
        }
        $choice = $res['data']['choices'][0] ?? null;
        $message = is_array($choice) && is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $finish = is_array($choice) ? (string)($choice['finish_reason'] ?? '') : '';
        return [
            'ok' => true,
            'message' => $message,
            'finish_reason' => $finish,
            'raw' => $res['data'],
            'error' => '',
            'profile' => $res['profile'],
        ];
    }
}
