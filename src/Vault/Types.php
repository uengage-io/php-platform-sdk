<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

use Uengage\PlatformSdk\Exceptions\ConfigException;

/**
 * `/v1/vault/types` - the runtime type registry. Types are global (not
 * per tenant) and immutable in v1. Needs `vault.types:read` / `:write`,
 * which a plain client_credentials service token may also carry.
 */
class Types
{
    /** @var VaultTransport */
    private $transport;

    public function __construct(VaultTransport $transport)
    {
        $this->transport = $transport;
    }

    /**
     * POST /types. 409 ({@see VaultConflictException}) if it already exists.
     *
     * @param array{type:string,mask?:array{prefix?:int,suffix?:int,minLength?:int},hiddenFields?:array<int,string>} $input
     * @return array<string, mixed>
     */
    public function register(array $input): array
    {
        if (!isset($input['type']) || !is_string($input['type']) || $input['type'] === '') {
            throw new ConfigException('vault: types.register needs a `type` like `pg.razorpay`');
        }
        $body = ['type' => $input['type']];
        if (isset($input['mask'])) {
            $body['mask'] = (object) $input['mask'];
        }
        if (isset($input['hiddenFields'])) {
            $body['hiddenFields'] = array_values($input['hiddenFields']);
        }
        return $this->transport->request('POST', '/types', '', $body);
    }

    /**
     * GET /types?class=
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $class = null): array
    {
        return Filters::listOf($this->transport->request('GET', '/types', Filters::simpleQuery(['class' => $class])));
    }
}
