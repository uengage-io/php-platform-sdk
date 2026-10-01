<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Config;

/**
 * Checks `Config::SDK_VERSION` is a release-shaped version string.
 *
 * This test used to pin the constant to the JS SDK's `package.json`,
 * reading the two packages' version alignment as an invariant. It is a
 * target rather than an invariant — see {@see Config::SDK_VERSION}: the
 * JS side is bumped when its surface lands and this port follows in a
 * later commit, so JS legitimately sits ahead while a port is in flight.
 * Pinning them made `vendor/bin/phpunit` fail for exactly that normal
 * state, and since the publish action runs the suite before it pushes the
 * mirror, it blocked every PHP release whenever JS was ahead — the
 * opposite of what it was written for.
 *
 * The drift it was meant to catch is caught where it matters anyway. The
 * publish action compares this constant against the tag being released
 * and refuses to publish on a mismatch:
 *
 *     actual=$(php -r 'require "src/Config.php"; echo …::SDK_VERSION;')
 *     [ "$actual" = "$EXPECTED" ] || exit 1
 *
 * That is the authoritative gate, it needs no second package to agree
 * with, and it fires on the one occasion the version has to be right.
 *
 * What is left here is the shape of the constant, and for one case it is
 * the ONLY check. `'v2.3.1'`, a stray space or an unreplaced placeholder
 * would each fail that gate too — late, hours after the commit, but
 * caught. A trailing newline would not: the gate reads the constant
 * through command substitution, which strips trailing newlines, so
 * `"2.3.1\n"` compares equal to `2.3.1` and publishes. It then reaches
 * consumers inside the `User-Agent` this constant builds, where a newline
 * in a header value is malformed at request time in their code, not ours.
 * Hence `\z` rather than `$`, which matches before a final newline.
 */
class ConfigVersionTest extends TestCase
{
    public function testSdkVersionIsReleaseShaped(): void
    {
        $reflected = new \ReflectionClass(Config::class);
        $version = $reflected->getConstant('SDK_VERSION');

        $this->assertIsString($version);
        // `preg_match` rather than `assertMatchesRegularExpression`: this
        // package is tested against PHPUnit 7.5 and 9.6, and that assertion
        // only exists from 9.1. The renaming polyfill is available but no
        // other test in the suite pulls it in, and one file needing a
        // different base class to check a version string is not worth it.
        $this->assertSame(
            1,
            preg_match('/^\d+\.\d+\.\d+\z/', (string) $version),
            'Config::SDK_VERSION must be a bare MAJOR.MINOR.PATCH (got '
                . var_export($version, true)
                . '). A leading "v" or stray space is caught again by the publish action, which '
                . 'compares this constant verbatim against the tag version. A trailing NEWLINE is '
                . 'not: command substitution strips it, so that gate passes and the value ships, '
                . 'reaching a consumer as a malformed User-Agent header. For that case this '
                . 'assertion is the only check there is.'
        );
    }
}
