<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Business;

use InvalidArgumentException;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\HttpResponse;
use Uengage\PlatformSdk\Http\RequestSigner;

/**
 * Read-only access to the business catalog (`/v1/businesses/*`).
 *
 * Mirrors the JS `platform.business` namespace: single-id lookup and
 * bulk fetch by id list. The `groups` option intersects the requested
 * group set with the caller's authorisation; unauthorised groups are
 * silently dropped server-side.
 */
class BusinessClient
{
    const BULK_MAX_IDS = 100;

    /** @var Config */
    private $config;

    /** @var RequestSigner */
    private $signer;

    public function __construct(Config $config, RequestSigner $signer)
    {
        $this->config = $config;
        $this->signer = $signer;
    }

    /**
     * GET /v1/businesses/{id} - single record (or BusinessApiException on 4xx).
     *
     * @param int $id positive integer
     * @param string[]|null $groups optional explicit group list
     * @return array business record
     */
    public function get(int $id, ?array $groups = null): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(sprintf('business.get: id must be a positive integer (got %d)', $id));
        }
        $path = '/v1/businesses/' . $id;
        $query = $this->buildGroupsQuery($groups);
        $response = $this->signer->send('GET', $path, $this->signer->url($path, $query));
        return $this->expectJsonArray($response, 'get');
    }

    /**
     * GET /v1/businesses?ids=... - returns matched records (missing ids dropped).
     *
     * @param int[] $ids non-empty list of positive integers, capped at BULK_MAX_IDS
     * @param string[]|null $groups
     * @return array[] list of business records
     */
    public function bulk(array $ids, ?array $groups = null): array
    {
        if (count($ids) === 0) {
            throw new InvalidArgumentException('business.bulk: ids must not be empty');
        }
        if (count($ids) > self::BULK_MAX_IDS) {
            throw new InvalidArgumentException(sprintf(
                'business.bulk: too many ids (got %d, max %d)',
                count($ids),
                self::BULK_MAX_IDS
            ));
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'business.bulk: every id must be a positive integer (got %s)',
                    var_export($id, true)
                ));
            }
        }
        $params = ['ids' => implode(',', $ids)];
        if ($groups !== null && count($groups) > 0) {
            $params['groups'] = implode(',', $groups);
        }
        $path = '/v1/businesses';
        $response = $this->signer->send(
            'GET',
            $path,
            $this->signer->url($path, '?' . http_build_query($params))
        );
        return $this->expectJsonArray($response, 'bulk');
    }

    private function buildGroupsQuery(?array $groups): string
    {
        if ($groups === null || count($groups) === 0) {
            return '';
        }
        return '?' . http_build_query(['groups' => implode(',', $groups)]);
    }

    /**
     * @return array
     */
    private function expectJsonArray(HttpResponse $response, string $label): array
    {
        if (!$response->isOk()) {
            throw new BusinessApiException($response->getStatus(), $response->getBody());
        }
        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new BusinessApiException(
                $response->getStatus(),
                sprintf('business.%s: response was not valid JSON: %s', $label, $response->getBody())
            );
        }
        return $decoded;
    }
}
