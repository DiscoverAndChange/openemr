<?php

/**
 * Isolated OpenEmrRelease tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\FileManifest;

use OpenEMR\FileManifest\OpenEmrRelease;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OpenEmrReleaseIsolatedTest extends TestCase
{
    public function testFromVersionDerivesTag(): void
    {
        $release = OpenEmrRelease::fromVersion('8.3.0', 0);
        $this->assertSame('8.3.0', $release->version);
        $this->assertSame(0, $release->realpatch);
        $this->assertSame('v8_3_0', $release->tag);
    }

    public function testManifestFileNamesWithoutPatch(): void
    {
        $release = OpenEmrRelease::fromVersion('8.3.0', 0);
        $this->assertSame('openemr-file-manifest-v8_3_0.json.gz', $release->manifestFileName());
        $this->assertSame('openemr-file-manifest-v8_3_0.json.gz.sig', $release->signatureFileName());
    }

    public function testManifestFileNamesWithPatch(): void
    {
        $release = OpenEmrRelease::fromVersion('8.3.0', 2);
        $this->assertSame('openemr-file-manifest-v8_3_0-patch2.json.gz', $release->manifestFileName());
    }

    /**
     * @return array<string, array{string, int, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidReleaseProvider(): array
    {
        return [
            'version missing patch component' => ['8.3', 0, 'v8_3_0'],
            'version with tag suffix' => ['8.3.0-dev', 0, 'v8_3_0'],
            'negative realpatch' => ['8.3.0', -1, 'v8_3_0'],
            'malformed tag' => ['8.3.0', 0, '8_3_0'],
        ];
    }

    #[DataProvider('invalidReleaseProvider')]
    public function testInvalidReleasesAreRejected(string $version, int $realpatch, string $tag): void
    {
        $this->expectException(\DomainException::class);
        new OpenEmrRelease($version, $realpatch, $tag);
    }
}
