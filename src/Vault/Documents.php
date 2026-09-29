<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

use Generator;
use Uengage\PlatformSdk\Exceptions\ConfigException;

/**
 * `/v1/vault/documents` for one tenant handle (the `kyc` class).
 *
 * {@see upload()} stores a document in one call: it registers the document,
 * POSTs the bytes straight to storage, and confirms the upload. A document
 * is `pending` until confirmed, then `uploaded`; only `uploaded` documents
 * can be viewed.
 */
class Documents
{
    /** @var VaultTransport */
    private $transport;

    public function __construct(VaultTransport $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Store a document: POST /documents (a presigned upload for exactly these
     * bytes), a multipart POST of the bytes to storage (no platform token),
     * then POST /documents/{id}/complete. Needs `vault.kyc:write`.
     *
     * If storage refuses, a {@see VaultApiException} with error code
     * `upload_failed` is thrown (a {@see VaultUnavailableException} for 5xx or
     * no answer), complete is not called, and the document stays `pending`.
     * complete answers 409 `upload_not_found` / `upload_mismatch`
     * ({@see VaultConflictException}) when storage does not hold the bytes.
     *
     * @param array{type:string,contentType:string,filename?:string,referenceId?:string,tags?:array<string,string>,businessId?:string|int} $input
     * @param string $contents The file bytes (e.g. file_get_contents($path)).
     * @return array<string, mixed> DocumentMeta, `status` = `uploaded`.
     */
    public function upload(array $input, string $contents): array
    {
        foreach (['type', 'contentType'] as $key) {
            if (!isset($input[$key]) || !is_string($input[$key]) || $input[$key] === '') {
                throw new ConfigException(sprintf('vault: documents.upload needs `%s`', $key));
            }
        }
        if ($contents === '') {
            throw new ConfigException('vault: documents.upload needs non-empty contents');
        }
        $filename = isset($input['filename']) && $input['filename'] !== '' ? (string) $input['filename'] : 'file';

        $body = ['type' => $input['type'], 'contentType' => $input['contentType'], 'size' => strlen($contents)];
        if (isset($input['referenceId'])) {
            $body['referenceId'] = (string) $input['referenceId'];
        }
        if (isset($input['tags'])) {
            $body['tags'] = Filters::tagsObject($input['tags']);
        }
        if (isset($input['businessId'])) {
            $body['businessId'] = VaultGrant::normaliseId($input['businessId'], 'businessId');
        }
        $created = $this->transport->request('POST', '/documents', '', $body);
        if (!is_array($created) || !isset($created['document']['id'], $created['upload']['url'])
            || !is_string($created['document']['id']) || !is_string($created['upload']['url'])) {
            throw new VaultUnavailableException(201, 'vault: POST /documents returned no document id or upload url');
        }
        $id = $created['document']['id'];
        $fields = isset($created['upload']['fields']) && is_array($created['upload']['fields'])
            ? $created['upload']['fields']
            : [];

        $this->transport->uploadMultipart(
            $created['upload']['url'],
            $fields,
            $contents,
            $input['contentType'],
            $filename,
            $id
        );

        return $this->transport->request('POST', '/documents/' . Filters::segment($id, 'id') . '/complete');
    }

    /**
     * @return array<string, mixed> DocumentMeta
     */
    public function get(string $id): array
    {
        return $this->transport->request('GET', '/documents/' . Filters::segment($id, 'id'));
    }

    /**
     * @param string|int|null $businessId
     * @return array<string, mixed> DocumentMeta
     */
    public function getByReference(string $referenceId, $businessId = null): array
    {
        return $this->transport->request(
            'GET',
            '/documents/by-reference/' . Filters::segment($referenceId, 'referenceId'),
            Filters::simpleQuery([
                'businessId' => $businessId === null ? null : VaultGrant::normaliseId($businessId, 'businessId'),
            ])
        );
    }

    /**
     * GET /documents - one page. Filter by upload state with `status`
     * (pending|uploaded); the retired `scanStatus` throws a ConfigException.
     *
     * @param array<string, mixed> $filter See {@see Filters}.
     * @return array{items: array<int, array<string, mixed>>, nextCursor?: string}
     */
    public function list(array $filter): array
    {
        return Filters::page($this->transport->request(
            'GET',
            '/documents',
            Filters::toQuery($filter)
        ));
    }

    /**
     * Every matching document, following `nextCursor`. Same filter as {@see list()}.
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
     * @param array<string, string> $tags
     * @return array<string, mixed> DocumentMeta
     */
    public function setTags(string $id, array $tags): array
    {
        return $this->transport->request(
            'PATCH',
            '/documents/' . Filters::segment($id, 'id') . '/tags',
            '',
            ['tags' => Filters::tagsObject($tags)]
        );
    }

    /**
     * POST /documents/{id}/view - a presigned GET valid for 5 minutes.
     * Needs `vault.kyc:view`; a document that is not `uploaded` answers 409
     * `document_not_uploaded` ({@see VaultConflictException}). `reason` is audited.
     *
     * @return array{url:string,expiresAt:string}
     */
    public function view(string $id, ?string $reason = null): array
    {
        $body = new \stdClass();
        if ($reason !== null && $reason !== '') {
            $body->reason = $reason;
        }
        return $this->transport->request('POST', '/documents/' . Filters::segment($id, 'id') . '/view', '', $body);
    }
}
