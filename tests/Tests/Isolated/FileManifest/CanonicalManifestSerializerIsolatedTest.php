<?php

/**
 * Isolated CanonicalManifestSerializer tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\FileManifest;

use OpenEMR\FileManifest\CanonicalManifestSerializer;
use OpenEMR\FileManifest\FileManifest;
use OpenEMR\FileManifest\FileManifestEntry;
use OpenEMR\FileManifest\HashAlgorithm;
use OpenEMR\FileManifest\InvalidManifestException;
use OpenEMR\FileManifest\ManifestSource;
use OpenEMR\FileManifest\OpenEmrRelease;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanonicalManifestSerializerIsolatedTest extends TestCase
{
    private function buildManifest(): FileManifest
    {
        return new FileManifest(
            FileManifest::CURRENT_SCHEMA_VERSION,
            OpenEmrRelease::fromVersion('8.3.0', 0),
            ManifestSource::ReleasePackage,
            HashAlgorithm::Sha256,
            ['sites/**'],
            [
                // deliberately unsorted: FileManifest sorts on construction
                'zz/last.php' => new FileManifestEntry(hash('sha256', 'last'), 4),
                'a/first.php' => new FileManifestEntry(hash('sha256', 'first'), 5),
            ],
        );
    }

    public function testRoundTripPreservesEverything(): void
    {
        $serializer = new CanonicalManifestSerializer();
        $manifest = $this->buildManifest();
        $restored = $serializer->deserialize($serializer->serialize($manifest));

        $this->assertSame($manifest->schemaVersion, $restored->schemaVersion);
        $this->assertSame($manifest->release->version, $restored->release->version);
        $this->assertSame($manifest->release->realpatch, $restored->release->realpatch);
        $this->assertSame($manifest->release->tag, $restored->release->tag);
        $this->assertSame($manifest->source, $restored->source);
        $this->assertSame($manifest->algorithm, $restored->algorithm);
        $this->assertSame($manifest->excluded, $restored->excluded);
        $this->assertSame(array_keys($manifest->files), array_keys($restored->files));
        foreach ($manifest->files as $path => $entry) {
            $restoredEntry = $restored->getEntry($path);
            $this->assertNotNull($restoredEntry);
            $this->assertSame($entry->hash, $restoredEntry->hash);
            $this->assertSame($entry->size, $restoredEntry->size);
        }
    }

    public function testSerializationIsCanonical(): void
    {
        $serializer = new CanonicalManifestSerializer();
        $first = $serializer->serialize($this->buildManifest());
        $second = $serializer->serialize($serializer->deserialize($first));
        $this->assertSame($first, $second);
        // paths are bytewise sorted regardless of construction order
        $this->assertLessThan(strpos($first, 'zz/last.php'), strpos($first, 'a/first.php'));
    }

    public function testEmptyFilesSerializesAsJsonObject(): void
    {
        $manifest = new FileManifest(
            FileManifest::CURRENT_SCHEMA_VERSION,
            OpenEmrRelease::fromVersion('8.3.0', 0),
            ManifestSource::GitTree,
            HashAlgorithm::Sha256,
            [],
            [],
        );
        $json = (new CanonicalManifestSerializer())->serialize($manifest);
        $this->assertStringContainsString('"files":{}', $json);
        $this->assertSame(0, (new CanonicalManifestSerializer())->deserialize($json)->count());
    }

    public function testNumericLookingPathsSurviveRoundTrip(): void
    {
        $manifest = new FileManifest(
            FileManifest::CURRENT_SCHEMA_VERSION,
            OpenEmrRelease::fromVersion('8.3.0', 0),
            ManifestSource::ReleasePackage,
            HashAlgorithm::Sha256,
            [],
            ['12345' => new FileManifestEntry(hash('sha256', 'x'), 1)],
        );
        $serializer = new CanonicalManifestSerializer();
        $json = $serializer->serialize($manifest);
        $restored = $serializer->deserialize($json);
        // PHP coerces numeric-string array keys to int internally; the JSON
        // object key and entry lookups must be unaffected by that.
        $this->assertSame($json, $serializer->serialize($restored));
        $this->assertNotNull($restored->getEntry('12345'));
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidManifestJsonProvider(): array
    {
        $valid = [
            'schemaVersion' => 1,
            'openemr' => ['version' => '8.3.0', 'realpatch' => 0, 'tag' => 'v8_3_0'],
            'source' => 'release-package',
            'algorithm' => 'sha256',
            'excluded' => [],
            'files' => ['a.php' => ['h' => str_repeat('ab', 32), 's' => 10]],
        ];
        $mutate = static function (string $key, mixed $value) use ($valid): string {
            $valid[$key] = $value;
            return json_encode($valid, JSON_THROW_ON_ERROR);
        };

        return [
            'not JSON at all' => ['{nope'],
            'root is not an object' => ['[1,2,3]'],
            'unknown source' => [$mutate('source', 'usb-stick')],
            'unknown algorithm' => [$mutate('algorithm', 'crc32')],
            'schema version from the future' => [$mutate('schemaVersion', 99)],
            'schema version is a string' => [$mutate('schemaVersion', '1')],
            'excluded contains a non-string' => [$mutate('excluded', [42])],
            'file entry is not an object' => [$mutate('files', ['a.php' => 'hash'])],
            'file entry hash is not hex' => [$mutate('files', ['a.php' => ['h' => 'ZZ', 's' => 1]])],
            'file entry digest length mismatches algorithm' => [$mutate('files', ['a.php' => ['h' => 'abcd', 's' => 1]])],
            'file entry size is negative' => [$mutate('files', ['a.php' => ['h' => str_repeat('ab', 32), 's' => -1]])],
            'openemr block missing' => ['{"schemaVersion":1,"source":"release-package","algorithm":"sha256","excluded":[],"files":{}}'],
        ];
    }

    #[DataProvider('invalidManifestJsonProvider')]
    public function testInvalidManifestsAreRejected(string $json): void
    {
        $this->expectException(InvalidManifestException::class);
        (new CanonicalManifestSerializer())->deserialize($json);
    }
}
