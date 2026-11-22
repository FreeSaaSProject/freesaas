<?php
declare(strict_types=1);

/**
 * Minimal SMTP client over PHP stream sockets (no Composer).
 * EHLO → STARTTLS|SSL → AUTH LOGIN/PLAIN → MAIL FROM → RCPT TO → DATA (MIME) → QUIT.
 */
final class SmtpClient
{
    public const DEFAULT_TIMEOUT = 20;

    /** @var resource|null */
    private $sock = null;
    private int $timeout;
    /** @var list<string> */
    private array $caps = [];

    private function __construct(int $timeout)
    {
        $this->timeout = max(3, min(120, $timeout));
    }

    /**
     * @param array{
     *   host:string,port?:int,encryption?:string,user?:string,pass?:string,
     *   from:string,from_name?:string,reply_to?:string,
     *   to:string,to_name?:string,subject:string,text?:string,html?:string,
     *   timeout?:int,helo?:string,envelope_from?:string,verify_peer?:bool
     * } $opts
     * @return array{ok:bool,error:string,message_id:string}
     */
    public static function send(array $opts): array
    {
        $client = new self((int)($opts['timeout'] ?? self::DEFAULT_TIMEOUT));
        $messageId = '';
        try {
            $from = self::cleanAddr((string)($opts['from'] ?? ''));
            $to = self::cleanAddr((string)($opts['to'] ?? ''));
            if ($from === '' || $to === '') {
                throw new InvalidArgumentException('Invalid sender or recipient address');
            }
            $host = trim((string)($opts['host'] ?? ''));
            if ($host === '') {
                throw new InvalidArgumentException('SMTP host is empty');
            }
            $text = (string)($opts['text'] ?? '');
            $html = (string)($opts['html'] ?? '');
            if (trim($text) === '' && trim($html) === '') {
                throw new InvalidArgumentException('Message body is empty');
            }
            $messageId = self::makeMessageId($from);
            $data = self::buildMessage(array_merge($opts, ['from' => $from, 'to' => $to]), $messageId);
            $envFrom = self::cleanAddr((string)($opts['envelope_from'] ?? ''));
            if ($envFrom === '') {
                $envFrom = $from;
            }

            $client->connect(
                $host,
                (int)($opts['port'] ?? 587),
                strtolower((string)($opts['encryption'] ?? 'tls')),
                (string)($opts['helo'] ?? ''),
                !array_key_exists('verify_peer', $opts) || !empty($opts['verify_peer'])
            );
            $user = (string)($opts['user'] ?? '');
            if ($user !== '') {
                $client->auth($user, (string)($opts['pass'] ?? ''));
            }
            $client->command('MAIL FROM:<' . $envFrom . '>', [250], 'MAIL FROM');
            $client->command('RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
            $client->command('DATA', [354], 'DATA');
            $client->writeData($data);
            $client->expect([250], 'message body');
            $client->quit();
            return ['ok' => true, 'error' => '', 'message_id' => $messageId];
        } catch (Throwable $e) {
            $client->close();
            return ['ok' => false, 'error' => self::oneLine($e->getMessage()), 'message_id' => $messageId];
        }
    }

    /**
     * Build the full RFC 5322 message (CRLF, UTF-8, multipart/alternative when html is present).
     *
     * @param array<string,mixed> $opts
     */
    public static function buildMessage(array $opts, string $messageId): string
    {
        $from = self::cleanAddr((string)($opts['from'] ?? ''));
        $to = self::cleanAddr((string)($opts['to'] ?? ''));
        $subject = self::oneLine(self::utf8((string)($opts['subject'] ?? '')));
        $text = self::utf8((string)($opts['text'] ?? ''));
        $html = self::utf8((string)($opts['html'] ?? ''));
        if (trim($text) === '' && trim($html) !== '') {
            $text = self::htmlToText($html);
        }

        $h = [];
        $h[] = 'Date: ' . date('r');
        $h[] = 'From: ' . self::formatAddress($from, (string)($opts['from_name'] ?? ''));
        $h[] = 'To: ' . self::formatAddress($to, (string)($opts['to_name'] ?? ''));
        $replyTo = self::cleanAddr((string)($opts['reply_to'] ?? ''));
        if ($replyTo !== '') {
            $h[] = 'Reply-To: ' . $replyTo;
        }
        $h[] = 'Subject: ' . self::encodeHeader($subject);
        $h[] = 'Message-ID: ' . $messageId;
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'X-Mailer: FreeSaaS mail';

        if (trim($html) === '') {
            $h[] = 'Content-Type: text/plain; charset=UTF-8';
            $h[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $h) . "\r\n\r\n" . self::b64($text);
        }

        $boundary = '=_fs_' . bin2hex(random_bytes(12));
        $h[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = "This is a multi-part message in MIME format.\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . self::b64($text)
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . self::b64($html)
            . '--' . $boundary . "--\r\n";
        return implode("\r\n", $h) . "\r\n\r\n" . $body;
    }

    /** RFC 2047 encoded-word (UTF-8, base64) when the value is not plain printable ASCII. */
    public static function encodeHeader(string $value): string
    {
        $value = self::oneLine($value);
        if ($value === '' || preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }
        $words = [];
        $chunk = '';
        $chars = preg_split('//u', self::utf8($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $ch) {
            // 45 raw bytes → 60 base64 chars; with "=?UTF-8?B?…?=" stays within 75 chars.
            if (strlen($chunk) + strlen($ch) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $ch;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode("\r\n ", $words);
    }

    public static function formatAddress(string $addr, string $name = ''): string
    {
        $name = self::oneLine(self::utf8($name));
        if ($name === '') {
            return $addr;
        }
        if (preg_match('/^[\x20-\x7E]*$/', $name) === 1) {
            $phrase = preg_match('/^[A-Za-z0-9 ._-]+$/', $name) === 1
                ? $name
                : '"' . addcslashes($name, "\"\\") . '"';
        } else {
            $phrase = self::encodeHeader($name);
        }
        return $phrase . ' <' . $addr . '>';
    }

    /** Valid single address or empty string (no CR/LF, angle brackets, whitespace). */
    public static function cleanAddr(string $addr): string
    {
        $addr = trim($addr);
        if ($addr === '' || strlen($addr) > 254 || preg_match('/[\x00-\x20<>,;"\\\\]/', $addr)) {
            return '';
        }
        return filter_var($addr, FILTER_VALIDATE_EMAIL) !== false ? $addr : '';
    }

    public static function htmlToText(string $html): string
    {
        $t = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $t = preg_replace('#<br\s*/?>#i', "\n", $t) ?? $t;
        $t = preg_replace('#</(p|div|h[1-6]|li|tr)>#i', "\n\n", $t) ?? $t;
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+/", ' ', $t) ?? $t;
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }

    private static function makeMessageId(string $from): string
    {
        $at = strrpos($from, '@');
        $domain = $at === false ? 'localhost' : substr($from, $at + 1);
        return '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    }

    private static function b64(string $s): string
    {
        return chunk_split(base64_encode($s), 76, "\r\n");
    }

    private static function utf8(string $s): string
    {
        if (preg_match('//u', $s) === 1) {
            return $s;
        }
        if (function_exists('iconv')) {
            $r = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
            if (is_string($r)) {
                return $r;
            }
        }
        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $s) ?? '';
    }

    private static function oneLine(string $s): string
    {
        return trim(preg_replace('/\s*[\r\n]+\s*/', ' ', $s) ?? '');
    }

    private function connect(string $host, int $port, string $enc, string $helo, bool $verifyPeer): void
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid SMTP port');
        }
        if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
            $enc = 'tls';
        }
        $ctxOpts = [
            'ssl' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ];
        $ctx = stream_context_create($ctxOpts);
        $target = (strpos($host, ':') !== false && $host[0] !== '[') ? '[' . $host . ']' : $host;
        $uri = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $target . ':' . $port;
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($uri, $errno, $errstr, (float)$this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!is_resource($sock)) {
            throw new RuntimeException('SMTP connect failed: ' . ($errstr !== '' ? $errstr : 'error ' . $errno));
        }
        $this->sock = $sock;
        stream_set_timeout($sock, $this->timeout);

        $this->expect([220], 'greeting');
        $name = $helo !== '' ? $helo : (string)gethostname();
        $name = preg_replace('/[^A-Za-z0-9.-]/', '', $name) ?? '';
        if ($name === '') {
            $name = 'localhost';
        }
        $this->ehlo($name);

        if ($enc === 'tls') {
            if (!preg_grep('/^STARTTLS$/i', $this->caps)) {
                throw new RuntimeException('SMTP server does not offer STARTTLS');
            }
            $this->command('STARTTLS', [220], 'STARTTLS');
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (@stream_socket_enable_crypto($sock, true, $method) !== true) {
                throw new RuntimeException('SMTP STARTTLS handshake failed');
            }
            $this->ehlo($name);
        }
    }

    private function ehlo(string $name): void
    {
        $this->write('EHLO ' . $name);
        [$code, $lines] = $this->read();
        if ($code !== 250) {
            $this->write('HELO ' . $name);
            [$code, $lines] = $this->read();
            if ($code !== 250) {
                throw new RuntimeException('SMTP EHLO/HELO rejected: ' . $code . ' ' . ($lines[0] ?? ''));
            }
            $this->caps = [];
            return;
        }
        $caps = [];
        foreach (array_slice($lines, 1) as $line) {
            $caps[] = trim($line);
        }
        $this->caps = $caps;
    }

    private function auth(string $user, string $pass): void
    {
        $mechs = [];
        foreach ($this->caps as $cap) {
            if (preg_match('/^AUTH[ =](.+)$/i', $cap, $m)) {
                foreach (preg_split('/\s+/', strtoupper(trim($m[1]))) ?: [] as $mech) {
                    $mechs[$mech] = true;
                }
            }
        }
        if ($mechs !== [] && !isset($mechs['LOGIN']) && isset($mechs['PLAIN'])) {
            $this->write('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass));
            $this->expect([235], 'authentication');
            return;
        }
        if ($mechs !== [] && !isset($mechs['LOGIN'])) {
            throw new RuntimeException('SMTP server offers no supported AUTH mechanism (LOGIN/PLAIN)');
        }
        $this->command('AUTH LOGIN', [334], 'AUTH');
        $this->write(base64_encode($user));
        $this->expect([334], 'authentication');
        $this->write(base64_encode($pass));
        $this->expect([235], 'authentication');
    }

    /** @param list<int> $codes */
    private function command(string $cmd, array $codes, string $label): void
    {
        $this->write($cmd);
        $this->expect($codes, $label);
    }

    /** @param list<int> $codes */
    private function expect(array $codes, string $label): void
    {
        [$code, $lines] = $this->read();
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP ' . $label . ' failed: ' . $code . ' ' . ($lines[count($lines) - 1] ?? ''));
        }
    }

    private function writeData(string $data): void
    {
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        $data = str_replace("\n", "\r\n", $data);
        $data = preg_replace('/^\./m', '..', $data) ?? $data;
        if (substr($data, -2) !== "\r\n") {
            $data .= "\r\n";
        }
        $this->raw($data . ".\r\n");
    }

    private function write(string $line): void
    {
        $this->raw($line . "\r\n");
    }

    private function raw(string $bytes): void
    {
        if (!is_resource($this->sock)) {
            throw new RuntimeException('SMTP connection is closed');
        }
        $len = strlen($bytes);
        $off = 0;
        while ($off < $len) {
            $n = @fwrite($this->sock, substr($bytes, $off, 8192));
            if ($n === false || $n === 0) {
                $meta = stream_get_meta_data($this->sock);
                throw new RuntimeException(!empty($meta['timed_out']) ? 'SMTP write timeout' : 'SMTP write failed');
            }
            $off += $n;
        }
    }

    /** @return array{0:int,1:list<string>} */
    private function read(): array
    {
        if (!is_resource($this->sock)) {
            throw new RuntimeException('SMTP connection is closed');
        }
        $lines = [];
        for ($i = 0; $i < 200; $i++) {
            $line = fgets($this->sock, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($this->sock);
                throw new RuntimeException(!empty($meta['timed_out']) ? 'SMTP read timeout' : 'SMTP connection closed by server');
            }
            $line = rtrim($line, "\r\n");
            $lines[] = $line;
            if (preg_match('/^(\d{3})(?: |$)/', $line, $m)) {
                return [(int)$m[1], $lines];
            }
        }
        throw new RuntimeException('SMTP response too long');
    }

    private function quit(): void
    {
        try {
            $this->write('QUIT');
            $this->read();
        } catch (Throwable $e) {
            // best effort
        }
        $this->close();
    }

    private function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }
}
