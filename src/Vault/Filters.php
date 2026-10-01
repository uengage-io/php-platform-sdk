<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

use Uengage\PlatformSdk\Exceptions\ConfigException;

/**
 * @internal List-filter handling shared by credentials and documents.
 *
 * Filter keys: `type` or `class` (one is required), `tags`
 * (`['key' => 'value']`, sent as `tag.<key>=<value>`), `businessId`,
 * `includeOutlets` (bool), `limit`, `cursor`, and `status`:
 *
 *   credentials  active | disabled
 *   documents    pending | uploaded
 *
 * `scanStatus` is the retired document filter. The server ignores it, which
 * would silently widen the result, so passing it throws a ConfigException.
 */
final class Filters
{
    const SCALAR_KEYS = ['type', 'class', 'status', 'businessId', 'limit', 'cursor'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $filter
     */
    public static function assertTypeOrClass(array $filter): void
    {
        $hasType = isset($filter['type']) && $filter['type'] !== '';
        $hasClass = isset($filter['class']) && $filter['class'] !== '';
        if (!$hasType && !$hasClass) {
            throw new ConfigException('vault: a list filter needs `type` or `class`');
        }
    }

    /**
     * @param array<string, mixed> $filter
     */
    public static function toQuery(array $filter): string
    {
        self::assertTypeOrClass($filter);
        if (isset($filter['scanStatus'])) {
            throw new ConfigException('vault: filter on `status`, not `scanStatus`');
        }
        $pairs = [];
        foreach (self::SCALAR_KEYS as $key) {
            if (isset($filter[$key]) && $filter[$key] !== '') {
                $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) $filter[$key]);
            }
        }
        if (!empty($filter['includeOutlets'])) {
            $pairs[] = 'includeOutlets=true';
        }
        if (isset($filter['tags'])) {
            if (!is_array($filter['tags'])) {
                throw new ConfigException('vault: tags must be an array of key => value');
            }
            foreach ($filter['tags'] as $key => $value) {
                $pairs[] = 'tag.' . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
            }
        }
        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    /**
     * @param array<string, string|int|null> $params
     */
    public static function simpleQuery(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== null && $value !== '') {
                $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
            }
        }
        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    /**
     * Tags must go over the wire as a JSON object, even when empty.
     *
     * @param array<string, string> $tags
     */
    public static function tagsObject(array $tags): \stdClass
    {
        $obj = new \stdClass();
        foreach ($tags as $key => $value) {
            $obj->{(string) $key} = $value;
        }
        return $obj;
    }

    /**
     * @param mixed $decoded
     * @return array<int, mixed>
     */
    public static function listOf($decoded): array
    {
        if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
            return $decoded['items'];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $decoded
     * @return array{items: array<int, mixed>, nextCursor?: string}
     */
    public static function page($decoded): array
    {
        $page = ['items' => is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])
            ? $decoded['items']
            : []];
        if (is_array($decoded) && isset($decoded['nextCursor']) && is_string($decoded['nextCursor'])
            && $decoded['nextCursor'] !== '') {
            $page['nextCursor'] = $decoded['nextCursor'];
        }
        return $page;
    }

    public static function segment(string $value, string $label): string
    {
        if ($value === '') {
            throw new ConfigException(sprintf('vault: %s must not be empty', $label));
        }
        return rawurlencode($value);
    }
}
