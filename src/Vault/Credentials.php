<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

use Generator;
use Uengage\PlatformSdk\Exceptions\ConfigException;

/**
 * `/v1/vault/credentials` for one tenant handle.
 *
 * Reads return CredentialMeta arrays with `masked` values only. Plaintext
 * comes back only from {@see decrypt()} / {@see decryptAll()}, with each
 * field wrapped in a {@see SecretValue}.
 */
class Credentials
{
    /** @var VaultTransport */
    private $transport;

    public function __construct(VaultTransport $transport)
    {
        $this->transport = $transport;
    }

    /**
     * POST /credentials. Needs `vault.<class>:write`.
     *
     * @param array{type:string,value:array<string,string>,referenceId?:string,tags?:array<string,string>,businessId?:string|int} $input
     * @return array<string, mixed> CredentialMeta
     */
    public function create(
        #[\SensitiveParameter]
        array $input
    ): array {
        if (!isset($input['type']) || !is_string($input['type'])) {
            throw new ConfigException('vault: credentials.create needs a `type`');
        }
        if (!isset($input['value']) || !is_array($input['value'])) {
            throw new ConfigException('vault: credentials.create needs a `value` array of string fields');
        }
        $body = ['type' => $input['type'], 'value' => self::valueObject($input['value'])];
        if (isset($input['referenceId'])) {
            $body['referenceId'] = (string) $input['referenceId'];
        }
        if (isset($input['tags'])) {
            $body['tags'] = Filters::tagsObject($input['tags']);
        }
        if (isset($input['businessId'])) {
            $body['businessId'] = VaultGrant::normaliseId($input['businessId'], 'businessId');
        }
        return $this->transport->request('POST', '/credentials', '', $body);
    }

    /**
     * GET /credentials/{id}.
     *
     * @return array<string, mixed> CredentialMeta
     */
    public function get(string $id): array
    {
        return $this->transport->request('GET', '/credentials/' . Filters::segment($id, 'id'));
    }

    /**
     * GET /credentials/by-reference/{referenceId}?businessId=
     *
     * @param string|int|null $businessId
     * @return array<string, mixed> CredentialMeta
     */
    public function getByReference(string $referenceId, $businessId = null): array
    {
        return $this->transport->request(
            'GET',
            '/credentials/by-reference/' . Filters::segment($referenceId, 'referenceId'),
            Filters::simpleQuery([
                'businessId' => $businessId === null ? null : VaultGrant::normaliseId($businessId, 'businessId'),
            ])
        );
    }

    /**
     * GET /credentials - one page. `status` (active|disabled) filters by
     * state; the retired `scanStatus` throws a ConfigException.
     *
     * @param array<string, mixed> $filter See {@see Filters}.
     * @return array{items: array<int, array<string, mixed>>, nextCursor?: string}
     */
    public function list(array $filter): array
    {
        return Filters::page($this->transport->request('GET', '/credentials', Filters::toQuery($filter)));
    }

    /**
     * Every matching credential, following `nextCursor` page by page.
     *
     * @param array<string, mixed> $filter
     * @return Generator<int, array<string, mixed>>
     */
    public function listAll(array $filter): Generator
    {
        $cursor = isset($filter['cursor']) ? $filter['cursor'] : null;
        do {
            $pageFilter = $filter;
            unset($pageFilter['cursor']);
            if ($cursor !== null) {
                $pageFilter['cursor'] = $cursor;
            }
            $page = $this->list($pageFilter);
            foreach ($page['items'] as $item) {
                yield $item;
            }
            $cursor = isset($page['nextCursor']) ? $page['nextCursor'] : null;
        } while ($cursor !== null);
    }

    /**
     * GET /credentials/{id}/versions - masked history.
     *
     * @return array<int, array<string, mixed>>
     */
    public function versions(string $id): array
    {
        return Filters::listOf(
            $this->transport->request('GET', '/credentials/' . Filters::segment($id, 'id') . '/versions')
        );
    }

    /**
     * POST /credentials/{id}/rotate - adds a version.
     *
     * @param array<string, string> $value
     * @return array<string, mixed> CredentialMeta
     */
    public function rotate(
        string $id,
        #[\SensitiveParameter]
        array $value
    ): array {
        return $this->transport->request(
            'POST',
            '/credentials/' . Filters::segment($id, 'id') . '/rotate',
            '',
            ['value' => self::valueObject($value)]
        );
    }

    /**
     * POST /credentials/{id}/disable.
     *
     * @return array<string, mixed> CredentialMeta
     */
    public function disable(string $id): array
    {
        return $this->transport->request(
            'POST',
            '/credentials/' . Filters::segment($id, 'id') . '/disable',
            '',
            new \stdClass()
        );
    }

    /**
     * DELETE /credentials/{id} - 204, no body. Permanent: removes every
     * version and frees the referenceId. Works on active or disabled
     * credentials; use disable() for a reversible off-switch. Needs
     * `vault.<class>:write` for the credential's class.
     */
    public function delete(string $id): void
    {
        $this->transport->request('DELETE', '/credentials/' . Filters::segment($id, 'id'));
    }

    /**
     * PATCH /credentials/{id}/tags - replaces the whole tag set.
     *
     * @param array<string, string> $tags
     * @return array<string, mixed> CredentialMeta
     */
    public function setTags(string $id, array $tags): array
    {
        return $this->transport->request(
            'PATCH',
            '/credentials/' . Filters::segment($id, 'id') . '/tags',
            '',
            ['tags' => Filters::tagsObject($tags)]
        );
    }

    /**
     * POST /credentials/decrypt for one credential. Needs `vault.<class>:decrypt`.
     *
     * @param array{id:string}|array{referenceId:string,businessId?:string|int} $ref
     * @return array<string, mixed> Credential with `value` => array<string, SecretValue>
     */
    public function decrypt(array $ref): array
    {
        if (isset($ref['id'])) {
            $body = ['id' => (string) $ref['id']];
        } elseif (isset($ref['referenceId'])) {
            $body = ['referenceId' => (string) $ref['referenceId']];
            if (isset($ref['businessId'])) {
                $body['businessId'] = VaultGrant::normaliseId($ref['businessId'], 'businessId');
            }
        } else {
            throw new ConfigException('vault: decrypt needs [\'id\' => ...] or [\'referenceId\' => ...]');
        }
        $page = Filters::page($this->transport->request('POST', '/credentials/decrypt', '', $body));
        if (count($page['items']) === 0) {
            throw new VaultNotFoundException(404, '{"error":"not_found"}');
        }
        return self::wrap($page['items'][0]);
    }

    /**
     * POST /credentials/decrypt with a filter, following `nextCursor` until
     * the last page. No overall cap: iterate lazily.
     *
     * @param array<string, mixed> $filter `type` or `class`, `tags`, `businessId`, `includeOutlets`, `cursor`.
     * @return Generator<int, array<string, mixed>>
     */
    public function decryptAll(array $filter): Generator
    {
        Filters::assertTypeOrClass($filter);
        $body = [];
        foreach (['type', 'class', 'cursor'] as $key) {
            if (isset($filter[$key]) && $filter[$key] !== '') {
                $body[$key] = (string) $filter[$key];
            }
        }
        if (isset($filter['businessId'])) {
            $body['businessId'] = VaultGrant::normaliseId($filter['businessId'], 'businessId');
        }
        if (!empty($filter['includeOutlets'])) {
            $body['includeOutlets'] = true;
        }
        if (isset($filter['tags'])) {
            $body['tags'] = Filters::tagsObject($filter['tags']);
        }
        do {
            $page = Filters::page($this->transport->request('POST', '/credentials/decrypt', '', $body));
            foreach ($page['items'] as $item) {
                yield self::wrap($item);
            }
            if (isset($page['nextCursor'])) {
                $body['cursor'] = $page['nextCursor'];
            }
        } while (isset($page['nextCursor']));
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function wrap(
        #[\SensitiveParameter]
        array $item
    ): array {
        $wrapped = [];
        if (isset($item['value']) && is_array($item['value'])) {
            foreach ($item['value'] as $field => $plain) {
                $wrapped[$field] = new SecretValue((string) $plain);
            }
        }
        $item['value'] = $wrapped;
        return $item;
    }

    /**
     * @param array<string, string> $value
     */
    private static function valueObject(
        #[\SensitiveParameter]
        array $value
    ): \stdClass {
        $obj = new \stdClass();
        foreach ($value as $field => $plain) {
            if ($plain instanceof SecretValue) {
                $plain = $plain->reveal();
            }
            if (!is_string($plain)) {
                throw new ConfigException(sprintf('vault: value field %s must be a string', (string) $field));
            }
            $obj->{(string) $field} = $plain;
        }
        return $obj;
    }
}
