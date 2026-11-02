<?php
declare(strict_types=1);

/**
 * OpenSSL-compatible AES-256-CBC with PBKDF2
 * (`openssl enc -aes-256-cbc -pbkdf2 -iter 100000`).
 */
final class BackupCrypto
{
    public const ITERATIONS = 100000;
    public const CHUNK = 1024 * 1024; // 1 MiB plaintext chunks (must be multiple of 16 except last)

    /**
     * @param array<string,mixed> $state
     * @return array{done:bool,state:array<string,mixed>,bytes_in:int,bytes_out:int}
     */
    public static function encryptTick(string $inPath, string $outPath, string $passphrase, array $state, int $budgetSec = 40): array
    {
        if ($passphrase === '') {
            throw new InvalidArgumentException('Empty passphrase');
        }
        if (!is_file($inPath)) {
            throw new RuntimeException('Encrypt source missing');
        }
        $started = empty($state['started']);
        $offset = (int)($state['offset'] ?? 0);
        $inSize = (int)filesize($inPath);

        $in = fopen($inPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot open encrypt source');
        }
        if ($offset > 0) {
            fseek($in, $offset);
        }

        if ($started) {
            $salt = random_bytes(8);
            [$key, $iv] = self::derive($passphrase, $salt);
            $out = fopen($outPath, 'wb');
            if ($out === false) {
                fclose($in);
                throw new RuntimeException('Cannot open encrypt dest');
            }
            fwrite($out, 'Salted__' . $salt);
            $state['key'] = base64_encode($key);
            $state['iv'] = base64_encode($iv);
            $state['started'] = 1;
            $state['bytes_out'] = 16;
        } else {
            $key = base64_decode((string)($state['key'] ?? ''), true);
            $iv = base64_decode((string)($state['iv'] ?? ''), true);
            if ($key === false || $iv === false || strlen($key) !== 32 || strlen($iv) !== 16) {
                fclose($in);
                throw new RuntimeException('Corrupt encrypt state');
            }
            $out = fopen($outPath, 'ab');
            if ($out === false) {
                fclose($in);
                throw new RuntimeException('Cannot append encrypt dest');
            }
        }

        $t0 = microtime(true);
        $bytesIn = 0;
        $bytesOut = 0;
        $done = false;

        while ((microtime(true) - $t0) < $budgetSec) {
            $remaining = $inSize - $offset;
            if ($remaining <= 0) {
                $done = true;
                break;
            }
            $readSize = min(self::CHUNK, $remaining);
            // Keep all but last chunk multiple of 16 for ZERO_PADDING path
            $isLast = ($offset + $readSize) >= $inSize;
            if (!$isLast) {
                $readSize -= ($readSize % 16);
                if ($readSize <= 0) {
                    $readSize = 16;
                }
            }
            $plain = fread($in, $readSize);
            if ($plain === false || $plain === '') {
                $done = true;
                break;
            }
            $chunkLen = strlen($plain);
            $offset += $chunkLen;
            $bytesIn += $chunkLen;

            if ($isLast) {
                $ct = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
                if ($ct === false) {
                    fclose($in);
                    fclose($out);
                    throw new RuntimeException('openssl_encrypt failed');
                }
                fwrite($out, $ct);
                $bytesOut += strlen($ct);
                $done = true;
                break;
            }
            $ct = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
            if ($ct === false) {
                fclose($in);
                fclose($out);
                throw new RuntimeException('openssl_encrypt failed');
            }
            fwrite($out, $ct);
            $bytesOut += strlen($ct);
            $iv = substr($ct, -16);
            $state['iv'] = base64_encode($iv);
        }

        fclose($in);
        fclose($out);

        $state['offset'] = $offset;
        $state['bytes_out'] = (int)($state['bytes_out'] ?? 0) + $bytesOut;
        if ($done) {
            unset($state['key'], $state['iv']);
        }

        return [
            'done' => $done,
            'state' => $state,
            'bytes_in' => $bytesIn,
            'bytes_out' => $bytesOut,
        ];
    }

    public static function decryptFile(string $inPath, string $outPath, string $passphrase): void
    {
        if ($passphrase === '') {
            throw new InvalidArgumentException('Empty passphrase');
        }
        $in = fopen($inPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot open ciphertext');
        }
        $magic = fread($in, 8);
        $salt = fread($in, 8);
        if ($magic !== 'Salted__' || $salt === false || strlen($salt) !== 8) {
            fclose($in);
            throw new RuntimeException('Not an openssl Salted__ file');
        }
        [$key, $iv] = self::derive($passphrase, $salt);
        $out = fopen($outPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Cannot open decrypt dest');
        }

        $carry = '';
        while (!feof($in)) {
            $chunk = fread($in, self::CHUNK + 16);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data = $carry . $chunk;
            // Keep last 16+ bytes for final PKCS7 block detection — decrypt all but trailing incomplete block set
            if (strlen($data) < 32) {
                $carry = $data;
                continue;
            }
            $process = substr($data, 0, strlen($data) - 16);
            $processLen = strlen($process) - (strlen($process) % 16);
            if ($processLen <= 0) {
                $carry = $data;
                continue;
            }
            $block = substr($process, 0, $processLen);
            $carry = substr($data, $processLen);
            $pt = openssl_decrypt($block, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
            if ($pt === false) {
                fclose($in);
                fclose($out);
                throw new RuntimeException('openssl_decrypt failed');
            }
            fwrite($out, $pt);
            $iv = substr($block, -16);
        }
        // Final block with PKCS7
        if ($carry !== '') {
            if (strlen($carry) % 16 !== 0) {
                fclose($in);
                fclose($out);
                throw new RuntimeException('Invalid ciphertext length');
            }
            $pt = openssl_decrypt($carry, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if ($pt === false) {
                fclose($in);
                fclose($out);
                throw new RuntimeException('Bad passphrase or corrupt file');
            }
            fwrite($out, $pt);
        }
        fclose($in);
        fclose($out);
    }

    /** @return array{0:string,1:string} key, iv */
    private static function derive(string $passphrase, string $salt): array
    {
        $derived = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 48, true);
        return [substr($derived, 0, 32), substr($derived, 32, 16)];
    }
}
