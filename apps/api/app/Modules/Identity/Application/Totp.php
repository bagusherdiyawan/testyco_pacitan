<?php

namespace App\Modules\Identity\Application;

/** RFC4648 Base32 and RFC6238 SHA1/6digits/30seconds. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(): string
    {
        $bits = '';
        foreach (unpack('C*', random_bytes(20)) as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }$s = '';
        foreach (str_split($bits, 5) as $b) {
            $s .= self::ALPHABET[bindec(str_pad($b, 5, '0'))];
        }

        return $s;
    }

    public static function code(string $secret, int $counter): string
    {
        $bits = '';
        foreach (str_split($secret) as $c) {
            $p = strpos(self::ALPHABET, $c);
            if ($p === false) {
                throw new \InvalidArgumentException('Invalid Base32 secret.');
            }$bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }$key = '';
        foreach (str_split($bits, 8) as $b) {
            if (strlen($b) === 8) {
                $key .= chr(bindec($b));
            }
        }$hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
        $offset = ord($hash[19]) & 15;
        $n = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function counter(string $secret, string $code, int $last = -1): ?int
    {
        if (! preg_match('/^[0-9]{6}$/D', $code)) {
            return null;
        }$now = intdiv(now()->timestamp, 30);
        foreach ([$now, $now - 1, $now + 1] as $c) {
            if ($c > $last && hash_equals(self::code($secret, $c), $code)) {
                return $c;
            }
        }

        return null;
    }
}
