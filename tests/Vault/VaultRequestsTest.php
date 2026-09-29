<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Vault;

use Uengage\PlatformSdk\Exceptions\ConfigException;
use Uengage\PlatformSdk\Vault\SecretValue;
use Uengage\PlatformSdk\Vault\VaultClient;
use Uengage\PlatformSdk\Vault\VaultApiException;
use Uengage\PlatformSdk\Vault\VaultConflictException;
use Uengage\PlatformSdk\Vault\VaultDeniedException;
use Uengage\PlatformSdk\Vault\VaultNotFoundException;
use Uengage\PlatformSdk\Vault\VaultUnavailableException;

/**
 * The wire shape of every handle method: method, path, query and body.
 */
class VaultRequestsTest extends VaultTestCase
{
    const V = self::BASE . '/v1/vault';

    /** @var VaultClient */
    private $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = $this->staticClient(self::vaultJwt('5'));
    }

    private function meta(string $id = 'c1'): array
    {
        return ['id' => $id, 'kind' => 'credential', 'masked' => ['key_secret' => 'rzp_******jiu'], 'version' => 1];
    }

    // ─── credentials ─────────────────────────────────────────────────────

    public function testCreate(): void
    {
        $this->stub->pushJson(201, $this->meta());
        $out = $this->vault->credentials()->create([
            'type' => 'pg.razorpay',
            'value' => ['key_id' => 'rzp_live_1', 'key_secret' => 'shh'],
            'referenceId' => 'outlet-6-razorpay',
            'tags' => ['env' => 'live'],
            'businessId' => 6,
        ]);
        $this->assertSame('c1', $out['id']);
        $call = $this->stub->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::V . '/credentials', $call['url']);
        $this->assertSame('application/json', $call['headers']['content-type']);
        $this->assertArrayHasKey('idempotency-key', $call['headers']);
        $this->assertSame([
            'type' => 'pg.razorpay',
            'value' => ['key_id' => 'rzp_live_1', 'key_secret' => 'shh'],
            'referenceId' => 'outlet-6-razorpay',
            'tags' => ['env' => 'live'],
            'businessId' => '6',
        ], self::jsonBody($call));
    }

    public function testCreateSendsEmptyTagsAsAnObject(): void
    {
        $this->stub->pushJson(201, $this->meta());
        $this->vault->credentials()->create(['type' => 'pg.razorpay', 'value' => ['k' => 'v'], 'tags' => []]);
        $this->assertStringContainsString('"tags":{}', (string) $this->stub->lastCall()['body']);
    }

    public function testGet(): void
    {
        $this->stub->pushJson(200, $this->meta('01J/X'));
        $this->vault->credentials()->get('01J/X');
        $call = $this->stub->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame(self::V . '/credentials/01J%2FX', $call['url']);
        $this->assertNull($call['body']);
        $this->assertArrayNotHasKey('idempotency-key', $call['headers']);
    }

    public function testGetByReference(): void
    {
        $this->stub->pushJson(200, $this->meta());
        $this->stub->pushJson(200, $this->meta());
        $this->vault->credentials()->getByReference('ref 1', 6);
        $this->assertSame(self::V . '/credentials/by-reference/ref%201?businessId=6', $this->stub->lastCall()['url']);
        $this->vault->credentials()->getByReference('ref1');
        $this->assertSame(self::V . '/credentials/by-reference/ref1', $this->stub->lastCall()['url']);
    }

    public function testListSerialisesEveryFilter(): void
    {
        $this->stub->pushJson(200, ['items' => [$this->meta()], 'nextCursor' => 'n1']);
        $page = $this->vault->credentials()->list([
            'type' => 'pg.razorpay',
            'tags' => ['env' => 'live', 'region' => 'north india'],
            'status' => 'active',
            'businessId' => '6',
            'includeOutlets' => true,
            'limit' => 50,
            'cursor' => 'c0',
        ]);
        $this->assertSame('n1', $page['nextCursor']);
        $this->assertCount(1, $page['items']);
        $this->assertSame(
            self::V . '/credentials?type=pg.razorpay&status=active&businessId=6&limit=50&cursor=c0'
            . '&includeOutlets=true&tag.env=live&tag.region=north%20india',
            $this->stub->lastCall()['url']
        );
    }

    public function testListByClassWithoutNextCursor(): void
    {
        $this->stub->pushJson(200, ['items' => []]);
        $page = $this->vault->credentials()->list(['class' => 'pos']);
        $this->assertSame(['items' => []], $page);
        $this->assertSame(self::V . '/credentials?class=pos', $this->stub->lastCall()['url']);
    }

    public function testListNeedsTypeOrClass(): void
    {
        $this->expectException(ConfigException::class);
        $this->vault->credentials()->list(['status' => 'active']);
    }

    public function testListAllFollowsNextCursor(): void
    {
        $this->stub->pushJson(200, ['items' => [$this->meta('a'), $this->meta('b')], 'nextCursor' => 'p2']);
        $this->stub->pushJson(200, ['items' => [$this->meta('c')], 'nextCursor' => 'p3']);
        $this->stub->pushJson(200, ['items' => []]);

        $ids = [];
        foreach ($this->vault->credentials()->listAll(['class' => 'pg', 'limit' => 2]) as $item) {
            $ids[] = $item['id'];
        }

        $this->assertSame(['a', 'b', 'c'], $ids);
        $calls = $this->stub->getCalls();
        $this->assertCount(3, $calls);
        $this->assertSame(self::V . '/credentials?class=pg&limit=2', $calls[0]['url']);
        $this->assertSame(self::V . '/credentials?class=pg&limit=2&cursor=p2', $calls[1]['url']);
        $this->assertSame(self::V . '/credentials?class=pg&limit=2&cursor=p3', $calls[2]['url']);
    }

    public function testListAllIsLazy(): void
    {
        $this->vault->credentials()->listAll(['class' => 'pg']);
        $this->assertCount(0, $this->stub->getCalls());
    }

    public function testVersionsAcceptsAnArrayOrItems(): void
    {
        $history = [['version' => 2, 'masked' => ['k' => '********']], ['version' => 1, 'masked' => ['k' => '********']]];
        $this->stub->pushJson(200, $history);
        $this->stub->pushJson(200, ['items' => $history]);
        $this->assertSame($history, $this->vault->credentials()->versions('c1'));
        $this->assertSame($history, $this->vault->credentials()->versions('c1'));
        $this->assertSame(self::V . '/credentials/c1/versions', $this->stub->lastCall()['url']);
        $this->assertSame('GET', $this->stub->lastCall()['method']);
    }

    public function testRotate(): void
    {
        $this->stub->pushJson(200, $this->meta());
        $this->vault->credentials()->rotate('c1', ['key_secret' => 'new']);
        $call = $this->stub->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::V . '/credentials/c1/rotate', $call['url']);
        $this->assertSame(['value' => ['key_secret' => 'new']], self::jsonBody($call));
    }

    public function testRotateAcceptsSecretValuesBack(): void
    {
        $this->stub->pushJson(200, $this->meta());
        $this->vault->credentials()->rotate('c1', ['key_secret' => new SecretValue('again')]);
        $this->assertSame(['value' => ['key_secret' => 'again']], self::jsonBody($this->stub->lastCall()));
    }

    public function testDisable(): void
    {
        $this->stub->pushJson(200, $this->meta());
        $this->vault->credentials()->disable('c1');
        $call = $this->stub->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::V . '/credentials/c1/disable', $call['url']);
        $this->assertSame('{}', $call['body']);
    }

    public function testDelete(): void
    {
        $this->stub->pushResponse(204, '');
        $this->assertNull($this->vault->credentials()->delete('c 1'));
        $call = $this->stub->lastCall();
        $this->assertSame('DELETE', $call['method']);
        $this->assertSame(self::V . '/credentials/c%201', $call['url']);
        $this->assertNull($call['body']);
        $this->assertArrayHasKey('idempotency-key', $call['headers']);
    }

    public function testDeleteNotFound(): void
    {
        $this->stub->pushJson(404, ['error' => 'not_found']);
        $this->expectException(VaultNotFoundException::class);
        $this->vault->credentials()->delete('c1');
    }

    public function testDeleteRejectsAnEmptyId(): void
    {
        $this->expectException(ConfigException::class);
        $this->vault->credentials()->delete('');
    }

    public function testSetTags(): void
    {
        $this->stub->pushJson(200, $this->meta());
        $this->vault->credentials()->setTags('c1', ['env' => 'test']);
        $call = $this->stub->lastCall();
        $this->assertSame('PATCH', $call['method']);
        $this->assertSame(self::V . '/credentials/c1/tags', $call['url']);
        $this->assertSame(['tags' => ['env' => 'test']], self::jsonBody($call));
    }

    public function testDecryptById(): void
    {
        $this->stub->pushJson(200, ['items' => [['id' => 'c1', 'value' => ['key_secret' => 'plain-1']]]]);
        $out = $this->vault->credentials()->decrypt(['id' => 'c1']);
        $call = $this->stub->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::V . '/credentials/decrypt', $call['url']);
        $this->assertSame(['id' => 'c1'], self::jsonBody($call));
        $this->assertInstanceOf(SecretValue::class, $out['value']['key_secret']);
        $this->assertSame('plain-1', $out['value']['key_secret']->reveal());
        $this->assertSame('c1', $out['id']);
    }

    public function testDecryptByReference(): void
    {
        $this->stub->pushJson(200, ['items' => [['id' => 'c1', 'value' => ['k' => 'v']]]]);
        $this->vault->credentials()->decrypt(['referenceId' => 'ref1', 'businessId' => 6]);
        $this->assertSame(['referenceId' => 'ref1', 'businessId' => '6'], self::jsonBody($this->stub->lastCall()));
    }

    public function testDecryptWithNoItemIsNotFound(): void
    {
        $this->stub->pushJson(200, ['items' => []]);
        $this->expectException(VaultNotFoundException::class);
        $this->vault->credentials()->decrypt(['id' => 'c1']);
    }

    public function testDecryptNeedsARef(): void
    {
        $this->expectException(ConfigException::class);
        $this->vault->credentials()->decrypt(['type' => 'pg.razorpay']);
    }

    public function testDecryptAllFollowsNextCursorWithTheFilterInTheBody(): void
    {
        $this->stub->pushJson(200, [
            'items' => [['id' => 'a', 'value' => ['k' => '1']], ['id' => 'b', 'value' => ['k' => '2']]],
            'nextCursor' => 'p2',
        ]);
        $this->stub->pushJson(200, ['items' => [['id' => 'c', 'value' => ['k' => '3']]]]);

        $seen = [];
        foreach ($this->vault->credentials()->decryptAll([
            'class' => 'pg',
            'tags' => ['env' => 'live'],
            'businessId' => '6',
            'includeOutlets' => true,
        ]) as $item) {
            $seen[$item['id']] = $item['value']['k']->reveal();
        }

        $this->assertSame(['a' => '1', 'b' => '2', 'c' => '3'], $seen);
        $calls = $this->stub->getCalls();
        $this->assertCount(2, $calls);
        $this->assertSame(
            ['class' => 'pg', 'businessId' => '6', 'includeOutlets' => true, 'tags' => ['env' => 'live']],
            self::jsonBody($calls[0])
        );
        $this->assertSame(
            ['class' => 'pg', 'businessId' => '6', 'includeOutlets' => true, 'tags' => ['env' => 'live'], 'cursor' => 'p2'],
            self::jsonBody($calls[1])
        );
        $this->assertSame(self::V . '/credentials/decrypt', $calls[1]['url']);
    }

    public function testDecryptAllNeedsTypeOrClass(): void
    {
        $this->expectException(ConfigException::class);
        foreach ($this->vault->credentials()->decryptAll(['tags' => ['a' => 'b']]) as $_) {
            // unreachable
        }
    }

    // ─── documents ───────────────────────────────────────────────────────

    private function pushCreated(): void
    {
        $this->stub->pushJson(201, [
            'document' => ['id' => 'd1', 'status' => 'pending'],
            'upload' => [
                'url' => 'https://s3.test/bucket',
                'fields' => ['key' => 'quarantine/5/-/d1', 'Content-Type' => 'application/pdf', 'Policy' => 'p'],
                'expiresAt' => 'x',
            ],
        ]);
    }

    public function testUploadCreatesPostsToStorageThenCompletes(): void
    {
        $this->pushCreated();
        $this->stub->pushResponse(204, '');
        $this->stub->pushJson(200, ['id' => 'd1', 'status' => 'uploaded', 'uploadedAt' => 't']);
        $out = $this->vault->documents()->upload([
            'type' => 'kyc.gst_certificate',
            'contentType' => 'application/pdf',
            'referenceId' => 'gst',
            'tags' => ['year' => '2026'],
            'businessId' => 6,
        ], '%PDF-1.7 bytes');
        $this->assertSame('uploaded', $out['status']);

        $calls = $this->stub->getCalls();
        $this->assertCount(3, $calls);
        $this->assertSame(['POST', self::V . '/documents'], [$calls[0]['method'], $calls[0]['url']]);
        $this->assertSame([
            'type' => 'kyc.gst_certificate',
            'contentType' => 'application/pdf',
            'size' => 14,
            'referenceId' => 'gst',
            'tags' => ['year' => '2026'],
            'businessId' => '6',
        ], self::jsonBody($calls[0]));

        $s3 = $calls[1];
        $this->assertSame(['POST', 'https://s3.test/bucket'], [$s3['method'], $s3['url']]);
        $this->assertArrayNotHasKey('authorization', $s3['headers']);
        $this->assertSame(1, preg_match('/^multipart\/form-data; boundary=(.+)$/', $s3['headers']['content-type'], $m));
        $body = (string) $s3['body'];
        $keyAt = strpos($body, 'name="key"');
        $typeAt = strpos($body, 'name="Content-Type"');
        $policyAt = strpos($body, 'name="Policy"');
        $fileAt = strpos($body, 'name="file"; filename="file"');
        $this->assertNotFalse($keyAt);
        $this->assertGreaterThan($keyAt, $typeAt);
        $this->assertGreaterThan($typeAt, $policyAt);
        $this->assertGreaterThan($policyAt, $fileAt, 'file must be the last part');
        $this->assertStringContainsString("Content-Type: application/pdf\r\n\r\n%PDF-1.7 bytes\r\n", $body);
        $this->assertStringEndsWith('--' . $m[1] . "--\r\n", $body);

        $this->assertSame(['POST', self::V . '/documents/d1/complete'], [$calls[2]['method'], $calls[2]['url']]);
        $this->assertNull($calls[2]['body']);
        $this->assertArrayHasKey('authorization', $calls[2]['headers']);
    }

    public function testUploadUsesTheGivenFilename(): void
    {
        $this->pushCreated();
        $this->stub->pushResponse(204, '');
        $this->stub->pushJson(200, ['id' => 'd1', 'status' => 'uploaded']);
        $this->vault->documents()->upload(
            ['type' => 'kyc.pan', 'contentType' => 'image/png', 'filename' => 'pan.png'],
            'abc'
        );
        $calls = $this->stub->getCalls();
        $this->assertStringContainsString('name="file"; filename="pan.png"', (string) $calls[1]['body']);
        $this->assertStringContainsString("Content-Type: image/png\r\n\r\nabc", (string) $calls[1]['body']);
        $this->assertSame(3, self::jsonBody($calls[0])['size']);
    }

    public function testUploadNeedsAContentTypeBeforeAnyRequest(): void
    {
        try {
            $this->vault->documents()->upload(['type' => 'kyc.pan'], 'abc');
            $this->fail('expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('`contentType`', $e->getMessage());
        }
        $this->assertCount(0, $this->stub->getCalls());
    }

    public function testUploadStopsBeforeCompleteWhenStorageRefuses(): void
    {
        $this->pushCreated();
        $this->stub->pushResponse(403, '<Error><Code>AccessDenied</Code></Error>');
        try {
            $this->vault->documents()->upload(['type' => 'kyc.pan', 'contentType' => 'image/png'], 'x');
            $this->fail('expected VaultApiException');
        } catch (VaultApiException $e) {
            $this->assertFalse($e instanceof VaultDeniedException, 'a storage 403 is not a vault denial');
            $this->assertSame(403, $e->getStatus());
            $this->assertSame('upload_failed', $e->getErrorCode());
            $this->assertStringContainsString('storage refused the upload for document d1', $e->getMessage());
        }
        $this->assertCount(2, $this->stub->getCalls());
    }

    public function testUploadStorage5xxIsUnavailable(): void
    {
        $this->pushCreated();
        $this->stub->pushResponse(503, 'SlowDown');
        try {
            $this->vault->documents()->upload(['type' => 'kyc.pan', 'contentType' => 'image/png'], 'x');
            $this->fail('expected VaultUnavailableException');
        } catch (VaultUnavailableException $e) {
            $this->assertSame('upload_failed', $e->getErrorCode());
        }
        $this->assertCount(2, $this->stub->getCalls());
    }

    public function testUploadCompleteConflict(): void
    {
        $this->pushCreated();
        $this->stub->pushResponse(204, '');
        $this->stub->pushJson(409, ['error' => 'upload_mismatch']);
        try {
            $this->vault->documents()->upload(['type' => 'kyc.pan', 'contentType' => 'image/png'], 'x');
            $this->fail('expected VaultConflictException');
        } catch (VaultConflictException $e) {
            $this->assertSame('upload_mismatch', $e->getErrorCode());
        }
    }

    public function testTwoStepUploadApiIsGone(): void
    {
        $this->assertFalse(method_exists($this->vault->documents(), 'createUpload'));
        $this->assertSame(2, (new \ReflectionMethod($this->vault->documents(), 'upload'))->getNumberOfParameters());
    }

    public function testDocumentReads(): void
    {
        $this->stub->pushJson(200, ['id' => 'd1']);
        $this->stub->pushJson(200, ['id' => 'd1']);
        $this->stub->pushJson(200, ['items' => [['id' => 'd1']]]);
        $docs = $this->vault->documents();
        $docs->get('d1');
        $this->assertSame(self::V . '/documents/d1', $this->stub->lastCall()['url']);
        $docs->getByReference('gst', '6');
        $this->assertSame(self::V . '/documents/by-reference/gst?businessId=6', $this->stub->lastCall()['url']);
        $docs->list(['type' => 'kyc.gst_certificate', 'tags' => ['year' => '2026']]);
        $this->assertSame(
            self::V . '/documents?type=kyc.gst_certificate&tag.year=2026',
            $this->stub->lastCall()['url']
        );
    }

    public function testDocumentListFiltersOnStatus(): void
    {
        $this->stub->pushJson(200, ['items' => [['id' => 'd1']], 'nextCursor' => 'n']);
        $this->stub->pushJson(200, ['items' => [['id' => 'd2']]]);
        $ids = [];
        foreach ($this->vault->documents()->listAll(['class' => 'kyc', 'status' => 'pending']) as $doc) {
            $ids[] = $doc['id'];
        }
        $this->assertSame(['d1', 'd2'], $ids);
        $this->assertSame(
            self::V . '/documents?class=kyc&status=pending&cursor=n',
            $this->stub->lastCall()['url']
        );
    }

    public function testDocumentListRefusesScanStatus(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('filter on `status`, not `scanStatus`');
        $this->vault->documents()->list(['class' => 'kyc', 'scanStatus' => 'clean']);
    }

    public function testCredentialListRefusesScanStatus(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('filter on `status`, not `scanStatus`');
        $this->vault->credentials()->list(['class' => 'pg', 'scanStatus' => 'clean']);
    }

    public function testDocumentListAll(): void
    {
        $this->stub->pushJson(200, ['items' => [['id' => 'd1']], 'nextCursor' => 'n']);
        $this->stub->pushJson(200, ['items' => [['id' => 'd2']]]);
        $ids = [];
        foreach ($this->vault->documents()->listAll(['class' => 'kyc']) as $doc) {
            $ids[] = $doc['id'];
        }
        $this->assertSame(['d1', 'd2'], $ids);
        $this->assertSame(self::V . '/documents?class=kyc&cursor=n', $this->stub->lastCall()['url']);
    }

    public function testDocumentSetTagsAndView(): void
    {
        $this->stub->pushJson(200, ['id' => 'd1']);
        $this->stub->pushJson(200, ['url' => 'https://s3.test/get', 'expiresAt' => 'x']);
        $this->stub->pushJson(200, ['url' => 'https://s3.test/get', 'expiresAt' => 'x']);
        $docs = $this->vault->documents();

        $docs->setTags('d1', ['year' => '2027']);
        $this->assertSame('PATCH', $this->stub->lastCall()['method']);
        $this->assertSame(self::V . '/documents/d1/tags', $this->stub->lastCall()['url']);
        $this->assertSame(['tags' => ['year' => '2027']], self::jsonBody($this->stub->lastCall()));

        $view = $docs->view('d1', 'kyc review');
        $this->assertSame('https://s3.test/get', $view['url']);
        $this->assertSame('POST', $this->stub->lastCall()['method']);
        $this->assertSame(self::V . '/documents/d1/view', $this->stub->lastCall()['url']);
        $this->assertSame(['reason' => 'kyc review'], self::jsonBody($this->stub->lastCall()));

        $docs->view('d1');
        $this->assertSame('{}', $this->stub->lastCall()['body']);
    }

    // ─── types ───────────────────────────────────────────────────────────

    public function testTypesRegisterAndList(): void
    {
        $this->stub->pushJson(201, ['type' => 'pg.razorpay']);
        $this->stub->pushJson(200, ['items' => [['type' => 'pg.razorpay']]]);
        $this->stub->pushJson(200, [['type' => 'pos.petpooja']]);
        $types = $this->vault->types();

        $types->register(['type' => 'pg.razorpay', 'mask' => ['prefix' => 4], 'hiddenFields' => ['webhook_secret']]);
        $this->assertSame('POST', $this->stub->lastCall()['method']);
        $this->assertSame(self::V . '/types', $this->stub->lastCall()['url']);
        $this->assertSame(
            ['type' => 'pg.razorpay', 'mask' => ['prefix' => 4], 'hiddenFields' => ['webhook_secret']],
            self::jsonBody($this->stub->lastCall())
        );

        $this->assertSame([['type' => 'pg.razorpay']], $types->list('pg'));
        $this->assertSame(self::V . '/types?class=pg', $this->stub->lastCall()['url']);
        $this->assertSame([['type' => 'pos.petpooja']], $types->list());
        $this->assertSame(self::V . '/types', $this->stub->lastCall()['url']);
    }
}
