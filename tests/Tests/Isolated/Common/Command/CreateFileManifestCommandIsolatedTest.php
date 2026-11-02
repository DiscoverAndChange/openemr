<?php

/**
 * Isolated end-to-end tests for the create-file-manifest console command.
 *
 * Builds a miniature OpenEMR tree in a temp directory, runs the command, and
 * verifies the emitted artifact: gzip container, canonical payload, release
 * identity parsed from version.php, exclusions, and the detached signature.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Common\Command;

use OpenEMR\Common\Command\CreateFileManifestCommand;
use OpenEMR\FileManifest\CanonicalManifestSerializer;
use OpenEMR\FileManifest\ManifestKeypair;
use OpenEMR\FileManifest\ManifestVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CreateFileManifestCommandIsolatedTest extends TestCase
{
    private string $workDir;
    private string $tree;

    protected function setUp(): void
    {
        $workDir = sys_get_temp_dir() . '/fim-cmd-test-' . bin2hex(random_bytes(6));
        $this->workDir = $workDir;
        $this->tree = $workDir . '/tree';
        foreach (['/tree/library', '/tree/sites/default'] as $dir) {
            if (!mkdir($workDir . $dir, 0o777, true)) {
                $this->fail('Could not create test directory ' . $workDir . $dir);
            }
        }
        $versionPhp = <<<'PHP'
            <?php
            $v_major = '8';
            $v_minor = '3';
            $v_patch = '0';
            $v_tag = '';
            $v_realpatch = '0';
            PHP;
        file_put_contents($this->tree . '/version.php', $versionPhp);
        file_put_contents($this->tree . '/library/foo.php', '<?php // shipped code');
        file_put_contents($this->tree . '/sites/default/sqlconf.php', '<?php // site secret');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->workDir);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options): CommandTester
    {
        $tester = new CommandTester(new CreateFileManifestCommand());
        $tester->execute($options);
        return $tester;
    }

    public function testGeneratesSignedManifestFromVersionPhp(): void
    {
        $keypair = ManifestKeypair::generate();
        $keyFile = $this->workDir . '/signing.key';
        file_put_contents($keyFile, $keypair->secretKeyBase64 . "\n");
        $output = $this->workDir . '/manifest.json.gz';

        $tester = $this->runCommand([
            '--tree' => $this->tree,
            '--output' => $output,
            '--sign-key' => $keyFile,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $compressed = file_get_contents($output);
        $this->assertNotFalse($compressed);
        $payload = gzdecode($compressed);
        $this->assertNotFalse($payload);

        $manifest = (new CanonicalManifestSerializer())->deserialize($payload);
        $this->assertSame('8.3.0', $manifest->release->version);
        $this->assertSame(0, $manifest->release->realpatch);
        $this->assertSame('v8_3_0', $manifest->release->tag);
        $this->assertNotNull($manifest->getEntry('version.php'));
        $this->assertNotNull($manifest->getEntry('library/foo.php'));
        $this->assertNull($manifest->getEntry('sites/default/sqlconf.php'));

        $signature = file_get_contents($output . '.sig');
        $this->assertNotFalse($signature);
        $this->assertTrue(
            (new ManifestVerifier())->isSignedByAny($payload, $signature, [$keypair->publicKeyBase64])
        );
    }

    public function testRefusesToRunWithoutSigningDecision(): void
    {
        $tester = $this->runCommand(['--tree' => $this->tree]);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('--no-sign', $tester->getDisplay());
    }

    public function testNoSignProducesUnsignedManifestWithoutSignatureFile(): void
    {
        $output = $this->workDir . '/manifest.json.gz';
        $tester = $this->runCommand([
            '--tree' => $this->tree,
            '--output' => $output,
            '--no-sign' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileExists($output);
        $this->assertFileDoesNotExist($output . '.sig');
    }

    public function testVersionOverrideBypassesVersionPhp(): void
    {
        $output = $this->workDir . '/manifest.json.gz';
        $tester = $this->runCommand([
            '--tree' => $this->tree,
            '--output' => $output,
            '--no-sign' => true,
            '--version-override' => '9.1.2',
            '--realpatch' => '3',
        ]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $compressed = file_get_contents($output);
        $this->assertNotFalse($compressed);
        $payload = gzdecode($compressed);
        $this->assertNotFalse($payload);
        $manifest = (new CanonicalManifestSerializer())->deserialize($payload);
        $this->assertSame('9.1.2', $manifest->release->version);
        $this->assertSame(3, $manifest->release->realpatch);
        $this->assertSame('v9_1_2', $manifest->release->tag);
    }

    public function testMissingTreeFails(): void
    {
        $tester = $this->runCommand([
            '--tree' => $this->workDir . '/does-not-exist',
            '--no-sign' => true,
        ]);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
