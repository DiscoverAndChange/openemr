<?php

/**
 * Isolated ManifestGenerator tests against an in-memory Flysystem.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\FileManifest;

use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use OpenEMR\FileManifest\CanonicalManifestSerializer;
use OpenEMR\FileManifest\FileManifest;
use OpenEMR\FileManifest\ManifestGenerator;
use OpenEMR\FileManifest\ManifestSource;
use OpenEMR\FileManifest\OpenEmrRelease;
use PHPUnit\Framework\TestCase;

class ManifestGeneratorIsolatedTest extends TestCase
{
    /** @var array<string, string> path => content */
    private const TREE = [
        'index.php' => '<?php echo "front";',
        'library/options.inc.php' => '<?php // options',
        'sites/default/sqlconf.php' => '<?php $secret = true;',
        'vendor/pkg/src/Deep.php' => '<?php final class Deep {}',
    ];

    private function generate(): FileManifest
    {
        $filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        foreach (self::TREE as $path => $content) {
            $filesystem->write($path, $content);
        }
        return (new ManifestGenerator($filesystem))->generate(
            OpenEmrRelease::fromVersion('8.3.0', 0),
            ManifestSource::ReleasePackage,
            ['sites/**'],
        );
    }

    public function testHashesAndSizesMatchContent(): void
    {
        $manifest = $this->generate();
        foreach (['index.php', 'library/options.inc.php', 'vendor/pkg/src/Deep.php'] as $path) {
            $entry = $manifest->getEntry($path);
            $this->assertNotNull($entry, $path . ' should be in the manifest');
            $this->assertSame(hash('sha256', self::TREE[$path]), $entry->hash);
            $this->assertSame(strlen(self::TREE[$path]), $entry->size);
        }
    }

    public function testExcludedPatternsAreSkippedAndRecorded(): void
    {
        $manifest = $this->generate();
        $this->assertNull($manifest->getEntry('sites/default/sqlconf.php'));
        $this->assertSame(3, $manifest->count());
        $this->assertSame(['sites/**'], $manifest->excluded);
    }

    public function testPathsAreSorted(): void
    {
        $manifest = $this->generate();
        $paths = array_keys($manifest->files);
        $sorted = $paths;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $paths);
    }

    public function testGenerationIsByteReproducible(): void
    {
        $serializer = new CanonicalManifestSerializer();
        $this->assertSame(
            $serializer->serialize($this->generate()),
            $serializer->serialize($this->generate()),
        );
    }

    public function testProgressCallbackSeesEveryIncludedFile(): void
    {
        $filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        foreach (self::TREE as $path => $content) {
            $filesystem->write($path, $content);
        }
        $seen = [];
        (new ManifestGenerator($filesystem))->generate(
            OpenEmrRelease::fromVersion('8.3.0', 0),
            ManifestSource::ReleasePackage,
            ['sites/**'],
            static function (string $path) use (&$seen): void {
                $seen[] = $path;
            },
        );
        sort($seen, SORT_STRING);
        $this->assertSame(['index.php', 'library/options.inc.php', 'vendor/pkg/src/Deep.php'], $seen);
    }

    public function testEmptyTreeYieldsEmptyManifest(): void
    {
        $filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $manifest = (new ManifestGenerator($filesystem))->generate(
            OpenEmrRelease::fromVersion('8.3.0', 0),
            ManifestSource::GitTree,
        );
        $this->assertSame(0, $manifest->count());
    }
}
