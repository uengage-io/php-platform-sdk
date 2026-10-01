<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Token;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Token\FileTokenCache;

class FileTokenCacheTest extends TestCase
{
    /** @var string */
    private $tempDir;

    /** @var FileTokenCache */
    private $cache;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/uengage-sdk-test-' . bin2hex(random_bytes(4));
        $this->cache = new FileTokenCache($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
    }

    public function testSetGetRoundTrip(): void
    {
        $this->cache->set('k', 'cached-token', 60);
        $this->assertSame('cached-token', $this->cache->get('k'));
    }

    public function testGetReturnsNullForUnknownKey(): void
    {
        $this->assertNull($this->cache->get('missing'));
    }

    public function testGetEvictsExpiredEntry(): void
    {
        $this->cache->set('k', 'value', -1);
        $this->assertNull($this->cache->get('k'));
    }

    public function testDeleteRemovesEntry(): void
    {
        $this->cache->set('k', 'v', 60);
        $this->cache->delete('k');
        $this->assertNull($this->cache->get('k'));
    }

    public function testFilePermissionsAreLocked(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX-only permission check');
        }
        $this->cache->set('k', 'v', 60);
        $files = glob($this->tempDir . '/*');
        $this->assertNotEmpty($files);
        $perms = fileperms($files[0]) & 0777;
        // Only the running user should read the cache file.
        $this->assertSame(0600, $perms);
    }

    public function testKeyHashingPreventsPathTraversal(): void
    {
        // A pathological key with slashes and dots must not escape the cache dir.
        $this->cache->set('../../etc/passwd', 'should-be-isolated', 60);
        $this->assertSame('should-be-isolated', $this->cache->get('../../etc/passwd'));
        // The cache file lives under the cache dir, not at the relative path.
        $this->assertFalse(file_exists($this->tempDir . '/../etc/passwd'));
    }
}
