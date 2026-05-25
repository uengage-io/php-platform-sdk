<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Zones;

use InvalidArgumentException;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\RequestSigner;

/**
 * Client for the platform zones API (`/v1/zones/*`).
 *
 * Mirrors the JS SDK's `platform.zones` namespace: CRUD + batch + the
 * containment primitive. All inputs are validated locally before the
 * HTTP call - bad uuids and out-of-range lat/lng throw
 * InvalidArgumentException instead of round-tripping a 400. Non-2xx
 * responses throw ZonesApiException.
 *
 * Writes (create / update / replace / delete / batch) require a
 * service-actor Bearer JWT. Reads (get / list / containing / contains)
 * accept service OR user JWTs. The SDK does not enforce this - the
 * server returns 403 and we surface it as ZonesApiException.
 */
class ZonesClient
{
    const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var Config */
    private $config;

    /** @var RequestSigner */
    private $signer;

    public function __construct(Config $config, RequestSigner $signer)
    {
        $this->config = $config;
        $this->signer = $signer;
    }

    // ─── CRUD ────────────────────────────────────────────────────────────

    /**
     * POST /v1/zones - create a single zone.
     *
     * @param array $input {geometry: GeoJsonPolygon|GeoJsonMultiPolygon, tags?: array}
     * @return array {zid: string, tags: array, createdAt: string}
     */
    public function create(array $input): array
    {
        $this->assertGeometry('create', $input);
        $body = json_encode($input, JSON_UNESCAPED_SLASHES);
        $path = '/v1/zones';
        $response = $this->signer->send('POST', $path, $this->signer->url($path), $body);
        return $this->expectJson($response, 'create');
    }

    /**
     * GET /v1/zones/{zid} - returns the full zone or throws on 404.
     *
     * @return array {zid, geometry, tags, createdAt, updatedAt}
     */
    public function get(string $zid): array
    {
        $this->assertUuid('get', $zid);
        $path = '/v1/zones/' . $zid;
        $response = $this->signer->send('GET', $path, $this->signer->url($path));
        return $this->expectJson($response, 'get');
    }

    /**
     * PATCH /v1/zones/{zid} - shallow tag merge; null deletes a key.
     *
     * @param array $input {geometry?: array, tags?: array<string, mixed|null>}
     * @return array updated zone
     */
    public function update(string $zid, array $input): array
    {
        $this->assertUuid('update', $zid);
        $body = json_encode($input, JSON_UNESCAPED_SLASHES);
        $path = '/v1/zones/' . $zid;
        $response = $this->signer->send('PATCH', $path, $this->signer->url($path), $body);
        return $this->expectJson($response, 'update');
    }

    /**
     * PUT /v1/zones/{zid} - full replace of geometry + tags.
     *
     * @param array $input {geometry: array, tags?: array}
     * @return array replaced zone
     */
    public function replace(string $zid, array $input): array
    {
        $this->assertUuid('replace', $zid);
        $this->assertGeometry('replace', $input);
        $body = json_encode($input, JSON_UNESCAPED_SLASHES);
        $path = '/v1/zones/' . $zid;
        $response = $this->signer->send('PUT', $path, $this->signer->url($path), $body);
        return $this->expectJson($response, 'replace');
    }

    /**
     * DELETE /v1/zones/{zid} - 404 if not present.
     */
    public function delete(string $zid): void
    {
        $this->assertUuid('delete', $zid);
        $path = '/v1/zones/' . $zid;
        $response = $this->signer->send('DELETE', $path, $this->signer->url($path));
        if ($response->getStatus() === 204) {
            return;
        }
        if (!$response->isOk()) {
            throw new ZonesApiException($response->getStatus(), $response->getBody());
        }
    }

    // ─── Listing ─────────────────────────────────────────────────────────

    /**
     * GET /v1/zones?tags=...&cursor=...&limit=... - tag-filtered, keyset-paginated.
     *
     * @param array $opts {tags?: array, cursor?: string, limit?: int}
     * @return array {zones: array, nextCursor: string|null}
     */
    public function list(array $opts = []): array
    {
        $params = [];
        if (array_key_exists('tags', $opts) && $opts['tags'] !== null) {
            $params['tags'] = json_encode($opts['tags'], JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('cursor', $opts) && $opts['cursor'] !== null) {
            $params['cursor'] = (string) $opts['cursor'];
        }
        if (array_key_exists('limit', $opts) && $opts['limit'] !== null) {
            if (!is_int($opts['limit']) || $opts['limit'] <= 0) {
                throw new InvalidArgumentException(
                    sprintf('zones.list: limit must be a positive integer (got %s)', var_export($opts['limit'], true))
                );
            }
            $params['limit'] = (string) $opts['limit'];
        }
        $query = empty($params) ? '' : '?' . http_build_query($params);
        $path = '/v1/zones';
        $response = $this->signer->send('GET', $path, $this->signer->url($path, $query));
        return $this->expectJson($response, 'list');
    }

    // ─── Batch ───────────────────────────────────────────────────────────

    /**
     * POST /v1/zones/batch/create - bounded array; returned in input order.
     *
     * @param array[] $zones each item: {geometry, tags?}
     * @return array {zones: array<{zid: string}>}
     */
    public function createMany(array $zones): array
    {
        if (count($zones) === 0) {
            throw new InvalidArgumentException('zones.createMany: zones must be a non-empty array');
        }
        foreach ($zones as $i => $z) {
            if (!is_array($z)) {
                throw new InvalidArgumentException(sprintf('zones.createMany[%d]: each item must be an array', $i));
            }
            $this->assertGeometry(sprintf('createMany[%d]', $i), $z);
        }
        $body = json_encode(['zones' => $zones], JSON_UNESCAPED_SLASHES);
        $path = '/v1/zones/batch/create';
        $response = $this->signer->send('POST', $path, $this->signer->url($path), $body);
        return $this->expectJson($response, 'createMany');
    }

    /**
     * POST /v1/zones/batch/delete - returns the count actually deleted.
     *
     * @param string[] $zids
     * @return array {deleted: int}
     */
    public function deleteMany(array $zids): array
    {
        if (count($zids) === 0) {
            throw new InvalidArgumentException('zones.deleteMany: zids must be a non-empty array');
        }
        foreach ($zids as $zid) {
            $this->assertUuid('deleteMany', $zid);
        }
        $body = json_encode(['zids' => array_values($zids)], JSON_UNESCAPED_SLASHES);
        $path = '/v1/zones/batch/delete';
        $response = $this->signer->send('POST', $path, $this->signer->url($path), $body);
        return $this->expectJson($response, 'deleteMany');
    }

    // ─── Containment ─────────────────────────────────────────────────────

    /**
     * POST /v1/zones/containing - the spatial primitive.
     *
     * @param array $input {point: {lat, lng}, ids?: string[], tags?: array}
     * @return array<int, array> matching zones (each {zid, tags})
     */
    public function containing(array $input): array
    {
        if (!isset($input['point']) || !is_array($input['point'])) {
            throw new InvalidArgumentException('zones.containing: input must include `point`');
        }
        $this->assertPoint('containing', $input['point']);
        if (isset($input['ids'])) {
            if (!is_array($input['ids'])) {
                throw new InvalidArgumentException('zones.containing: ids must be an array of uuid strings');
            }
            foreach ($input['ids'] as $zid) {
                $this->assertUuid('containing', $zid);
            }
        }
        $body = json_encode($input, JSON_UNESCAPED_SLASHES);
        $path = '/v1/zones/containing';
        $response = $this->signer->send('POST', $path, $this->signer->url($path), $body);
        $decoded = $this->expectJson($response, 'containing');
        return isset($decoded['zones']) && is_array($decoded['zones']) ? $decoded['zones'] : [];
    }

    /**
     * Sugar over containing({point, ids: [zid]}).
     */
    public function contains(string $zid, array $point): bool
    {
        $this->assertUuid('contains', $zid);
        $matches = $this->containing(['point' => $point, 'ids' => [$zid]]);
        return count($matches) > 0;
    }

    // ─── Validators + helpers ───────────────────────────────────────────

    private function assertUuid(string $label, $value): void
    {
        if (!is_string($value) || !preg_match(self::UUID_REGEX, $value)) {
            throw new InvalidArgumentException(sprintf(
                'zones.%s: zid must be a uuid (got %s)',
                $label,
                var_export($value, true)
            ));
        }
    }

    /**
     * @param array $point
     */
    private function assertPoint(string $label, $point): void
    {
        if (!is_array($point) || !isset($point['lat']) || !isset($point['lng'])) {
            throw new InvalidArgumentException(sprintf(
                'zones.%s: point must be {lat: number, lng: number}',
                $label
            ));
        }
        $lat = $point['lat'];
        $lng = $point['lng'];
        if (!is_numeric($lat) || $lat < -90 || $lat > 90) {
            throw new InvalidArgumentException(sprintf('zones.%s: lat must be in [-90, 90] (got %s)', $label, var_export($lat, true)));
        }
        if (!is_numeric($lng) || $lng < -180 || $lng > 180) {
            throw new InvalidArgumentException(sprintf('zones.%s: lng must be in [-180, 180] (got %s)', $label, var_export($lng, true)));
        }
    }

    private function assertGeometry(string $label, array $input): void
    {
        if (!isset($input['geometry']) || !is_array($input['geometry'])) {
            throw new InvalidArgumentException(sprintf('zones.%s: `geometry` is required', $label));
        }
        $type = isset($input['geometry']['type']) ? $input['geometry']['type'] : null;
        if ($type !== 'Polygon' && $type !== 'MultiPolygon') {
            throw new InvalidArgumentException(sprintf(
                'zones.%s: geometry.type must be Polygon or MultiPolygon (got %s)',
                $label,
                var_export($type, true)
            ));
        }
    }

    /**
     * @return array
     */
    private function expectJson(\Uengage\PlatformSdk\Http\HttpResponse $response, string $label): array
    {
        if (!$response->isOk()) {
            throw new ZonesApiException($response->getStatus(), $response->getBody());
        }
        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new ZonesApiException(
                $response->getStatus(),
                sprintf('zones.%s: response was not valid JSON: %s', $label, $response->getBody())
            );
        }
        return $decoded;
    }
}
