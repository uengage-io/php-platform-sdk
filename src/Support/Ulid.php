<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Support;

/**
 * ULID generation, shared by the audit and events clients.
 *
 * Lifted out of AuditClient when the events client needed the same
 * encoder plus a monotonic variant. `AuditClient::generateUlid()` still
 * exists and delegates here, so nothing that called it had to change.
 *
 * Spec-compliant: 48-bit millisecond timestamp + 80 bits of randomness,
 * Crockford base32, 26 characters, lexicographically sortable by time.
 */
class Ulid
{
    /** Crockford base32 alphabet (excludes I, L, O, U). */
    const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Last millisecond `monotonic()` minted for, -1 before first use. */
    private static $lastMs = -1;

    /** Raw 10-byte random component of the last `monotonic()` id. */
    private static $lastRandom = '';

    /**
     * A ULID with fresh randomness. Two ids minted in the same
     * millisecond sort arbitrarily relative to each other.
     */
    public static function generate(): string
    {
        $ms = (int) (microtime(true) * 1000);
        return self::encode(self::timeBytes($ms) . random_bytes(10));
    }

    /**
     * A ULID guaranteed to sort strictly after every previous
     * `monotonic()` id from this process.
     *
     * Within one millisecond the random component is incremented rather
     * than redrawn; a clock that steps backwards is pinned to the last
     * millisecond used. Event consumers key ordering and dedupe off this
     * id, so "later call, later id" has to hold even for two events
     * published in the same millisecond.
     */
    public static function monotonic(): string
    {
        $ms = (int) (microtime(true) * 1000);
        if ($ms <= self::$lastMs && self::$lastRandom !== '') {
            $ms = self::$lastMs;
            self::$lastRandom = self::increment(self::$lastRandom);
        } else {
            self::$lastMs = $ms;
            self::$lastRandom = random_bytes(10);
        }
        return self::encode(self::timeBytes($ms) . self::$lastRandom);
    }

    /** Test-only: forget the monotonic state. */
    public static function resetMonotonicState(): void
    {
        self::$lastMs = -1;
        self::$lastRandom = '';
    }

    /** 48-bit big-endian millisecond timestamp. */
    private static function timeBytes(int $timestampMs): string
    {
        $bytes = '';
        for ($i = 5; $i >= 0; $i--) {
            $bytes .= chr(($timestampMs >> ($i * 8)) & 0xff);
        }
        return $bytes;
    }

    /**
     * Big-endian +1 over a byte string. On overflow (all 0xFF - about
     * one chance in 2^80) redraw instead of wrapping to zero, which
     * would break the sort order this whole method exists to preserve.
     */
    private static function increment(string $bytes): string
    {
        for ($i = strlen($bytes) - 1; $i >= 0; $i--) {
            $byte = ord($bytes[$i]);
            if ($byte < 0xff) {
                $bytes[$i] = chr($byte + 1);
                return $bytes;
            }
            $bytes[$i] = chr(0);
        }
        return random_bytes(strlen($bytes));
    }

    /**
     * Encode raw bytes as Crockford base32, pinned to 26 characters
     * (left-padded if the bit string is short).
     */
    private static function encode(string $bytes, int $outputLen = 26): string
    {
        $bin = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $bin .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        // Pad the bit string up so its length is a multiple of 5.
        $pad = (5 - (strlen($bin) % 5)) % 5;
        $bin = str_pad($bin, strlen($bin) + $pad, '0', STR_PAD_LEFT);

        $out = '';
        for ($i = 0; $i < strlen($bin); $i += 5) {
            $out .= self::ALPHABET[bindec(substr($bin, $i, 5))];
        }
        return substr(str_pad($out, $outputLen, '0', STR_PAD_LEFT), -$outputLen);
    }
}
