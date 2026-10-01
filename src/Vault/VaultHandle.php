<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

/**
 * Vault operations bound to one token source - and so to one tenant.
 * Obtained from `$platform->vault->forTenant(...)`.
 */
class VaultHandle
{
    /** @var array{parentId:string,businessId?:string}|null */
    private $tenant;

    /** @var Credentials */
    private $credentials;

    /** @var Documents */
    private $documents;

    /** @var Types */
    private $types;

    /**
     * @param array{parentId:string,businessId?:string}|null $tenant
     */
    public function __construct(VaultTransport $transport, ?array $tenant, ?Types $types = null)
    {
        $this->tenant = $tenant;
        $this->credentials = new Credentials($transport);
        $this->documents = new Documents($transport);
        $this->types = $types !== null ? $types : new Types($transport);
    }

    /**
     * The tenant this handle was built for, or null when it relies on
     * whatever tenant the static token carries.
     *
     * @return array{parentId:string,businessId?:string}|null
     */
    public function tenant(): ?array
    {
        return $this->tenant;
    }

    public function credentials(): Credentials
    {
        return $this->credentials;
    }

    public function documents(): Documents
    {
        return $this->documents;
    }

    public function types(): Types
    {
        return $this->types;
    }
}
