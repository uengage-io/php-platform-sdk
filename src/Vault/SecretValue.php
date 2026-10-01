<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

use JsonSerializable;
use LogicException;

/**
 * A decrypted credential field.
 *
 * The plaintext is only reachable through {@see reveal()}. Every other way a
 * value tends to leak - string casts and interpolation, json_encode, var_dump
 * and print_r, serialize() into a cache or session - yields `[REDACTED]` or
 * throws. The plaintext is held inside a closure rather than a property, so
 * var_export() and `(array)` casts of the object do not expose it either.
 *
 * Call reveal() at the last moment, at the point of use (the gateway call),
 * and do not store the result.
 */
final class SecretValue implements JsonSerializable
{
    const REDACTED = '[REDACTED]';

    /** @var array<string, string> Plaintexts by spl_object_hash of their holder. */
    private static $values = [];

    public function __construct(
        #[\SensitiveParameter]
        string $value
    ) {
        self::$values[spl_object_hash($this)] = $value;
    }

    public function __destruct()
    {
        unset(self::$values[spl_object_hash($this)]);
    }

    /** Not clonable: a clone would have no plaintext behind it. */
    private function __clone()
    {
    }

    /** The plaintext. Keep the result out of logs, responses and caches. */
    public function reveal(): string
    {
        $hash = spl_object_hash($this);
        return isset(self::$values[$hash]) ? self::$values[$hash] : '';
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }

    /**
     * @return string
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return self::REDACTED;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo()
    {
        return ['value' => self::REDACTED];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('SecretValue cannot be serialized');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('SecretValue cannot be unserialized');
    }

    /**
     * @return array<int, string>
     */
    public function __sleep()
    {
        throw new LogicException('SecretValue cannot be serialized');
    }

    public function __wakeup()
    {
        throw new LogicException('SecretValue cannot be unserialized');
    }

    /**
     * @param array<string, mixed> $properties
     * @return self
     */
    public static function __set_state(array $properties)
    {
        throw new LogicException('SecretValue cannot be restored from var_export output');
    }
}
