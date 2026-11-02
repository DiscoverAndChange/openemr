<?php

/**
 * CLI command that generates the signed per-release file manifest.
 *
 * Runs without a database (bin/console --skip-globals). Points at an
 * extracted release package (or any OpenEMR tree), hashes every shipped file
 * through Flysystem, and writes a canonical, byte-reproducible manifest as
 * gzipped JSON plus a detached ed25519 signature over the uncompressed
 * canonical bytes. See docs/file-integrity-monitoring-design.md.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Common\Command;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use OpenEMR\FileManifest\CanonicalManifestSerializer;
use OpenEMR\FileManifest\HashAlgorithm;
use OpenEMR\FileManifest\ManifestGenerator;
use OpenEMR\FileManifest\ManifestSigner;
use OpenEMR\FileManifest\ManifestSource;
use OpenEMR\FileManifest\OpenEmrRelease;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CreateFileManifestCommand extends Command
{
    private const DEFAULT_EXCLUDES = ['sites/**'];

    protected function configure(): void
    {
        $this
            ->setName('openemr:create-file-manifest')
            ->setDescription('Generate the signed file-integrity manifest for an OpenEMR release tree')
            ->setDefinition(
                new InputDefinition([
                    new InputOption('tree', 't', InputOption::VALUE_REQUIRED, 'Root of the OpenEMR tree to hash (an extracted release package)', dirname(__DIR__, 3)),
                    new InputOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output path for the manifest (.json.gz); defaults to the release-derived name in the current directory'),
                    new InputOption('sign-key', 'k', InputOption::VALUE_REQUIRED, 'Path to a base64-encoded ed25519 secret key file (see openemr:create-manifest-keypair)'),
                    new InputOption('no-sign', null, InputOption::VALUE_NONE, 'Explicitly produce an unsigned manifest (local/dev use only)'),
                    new InputOption('exclude', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional glob pattern to exclude (repeatable); "sites/**" is always excluded'),
                    new InputOption('source', 's', InputOption::VALUE_REQUIRED, 'What the tree is: "release-package" or "git-tree"', ManifestSource::ReleasePackage->value),
                    new InputOption('version-override', null, InputOption::VALUE_REQUIRED, 'Release version (e.g. "8.3.0") when the tree\'s version.php should not be consulted'),
                    new InputOption('realpatch', null, InputOption::VALUE_REQUIRED, 'Release realpatch number; overrides the value read from version.php'),
                ])
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $treeOption = $input->getOption('tree');
        if (!is_string($treeOption) || $treeOption === '') {
            $io->error('The --tree option must be a directory path.');
            return Command::FAILURE;
        }
        $tree = realpath($treeOption);
        if ($tree === false || !is_dir($tree)) {
            $io->error(sprintf('Tree directory "%s" does not exist.', $treeOption));
            return Command::FAILURE;
        }

        $sourceRaw = $input->getOption('source');
        $source = is_string($sourceRaw) ? ManifestSource::tryFrom($sourceRaw) : null;
        if ($source === null) {
            $io->error('The --source option must be "release-package" or "git-tree".');
            return Command::FAILURE;
        }

        $noSign = $input->getOption('no-sign') === true;
        $signKeyPath = $input->getOption('sign-key');
        if (!$noSign && !is_string($signKeyPath)) {
            $io->error('Pass --sign-key <file> to sign the manifest, or --no-sign to explicitly produce an unsigned one.');
            return Command::FAILURE;
        }
        $signKey = null;
        if (!$noSign && is_string($signKeyPath)) {
            $signKey = is_file($signKeyPath) ? file_get_contents($signKeyPath) : false;
            if ($signKey === false) {
                $io->error(sprintf('Could not read signing key file "%s".', $signKeyPath));
                return Command::FAILURE;
            }
        }

        try {
            $release = $this->resolveRelease($input, $io, $tree);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $outputOption = $input->getOption('output');
        $outputPath = is_string($outputOption) && $outputOption !== ''
            ? $outputOption
            : ((getcwd() ?: '.') . '/' . $release->manifestFileName());

        $excludes = self::DEFAULT_EXCLUDES;
        $excludeOption = $input->getOption('exclude');
        foreach (is_array($excludeOption) ? $excludeOption : [] as $pattern) {
            if (is_string($pattern) && $pattern !== '') {
                $excludes[] = $pattern;
            }
        }

        $io->text(sprintf('Hashing tree %s as release %s (realpatch %d) ...', $tree, $release->version, $release->realpatch));

        $filesystem = new Filesystem(new LocalFilesystemAdapter(
            $tree,
            linkHandling: LocalFilesystemAdapter::SKIP_LINKS,
        ));
        $generator = new ManifestGenerator($filesystem, HashAlgorithm::Sha256);
        $progress = $io->createProgressBar();
        $started = hrtime(true);

        try {
            $manifest = $generator->generate(
                $release,
                $source,
                $excludes,
                static function (string $path) use ($progress): void {
                    $progress->advance();
                },
            );
            $progress->finish();
            $io->newLine(2);

            $payload = (new CanonicalManifestSerializer())->serialize($manifest);
            $compressed = gzencode($payload, 9);
            if ($compressed === false) {
                $io->error('Failed to gzip-compress the manifest.');
                return Command::FAILURE;
            }
            if (file_put_contents($outputPath, $compressed) === false) {
                $io->error(sprintf('Failed to write manifest to "%s".', $outputPath));
                return Command::FAILURE;
            }

            $signaturePath = null;
            if ($signKey !== null) {
                $signaturePath = $outputPath . '.sig';
                $signature = (new ManifestSigner())->sign($payload, $signKey);
                if (file_put_contents($signaturePath, $signature . "\n") === false) {
                    $io->error(sprintf('Failed to write signature to "%s".', $signaturePath));
                    return Command::FAILURE;
                }
            }
        } catch (\Throwable $e) {
            $progress->clear();
            $io->error('Manifest generation failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;
        $io->definitionList(
            ['Release' => sprintf('%s (realpatch %d, tag %s)', $release->version, $release->realpatch, $release->tag)],
            ['Files hashed' => (string) $manifest->count()],
            ['Excluded patterns' => implode(', ', $excludes)],
            ['Manifest' => $outputPath],
            ['Signature' => $signaturePath ?? 'UNSIGNED (--no-sign)'],
            ['Duration' => sprintf('%.1fs', $elapsedSeconds)],
        );
        $io->success('Manifest generated.');
        return Command::SUCCESS;
    }

    /**
     * @throws \RuntimeException when release identity cannot be determined
     */
    private function resolveRelease(InputInterface $input, SymfonyStyle $io, string $tree): OpenEmrRelease
    {
        $realpatchOption = $input->getOption('realpatch');
        $realpatchOverride = null;
        if (is_string($realpatchOption)) {
            if (!ctype_digit($realpatchOption)) {
                throw new \RuntimeException('The --realpatch option must be a non-negative integer.');
            }
            $realpatchOverride = (int) $realpatchOption;
        }

        $versionOverride = $input->getOption('version-override');
        if (is_string($versionOverride) && $versionOverride !== '') {
            try {
                return OpenEmrRelease::fromVersion($versionOverride, $realpatchOverride ?? 0);
            } catch (\DomainException $e) {
                throw new \RuntimeException('The --version-override option must be in major.minor.patch form.', 0, $e);
            }
        }

        $versionFile = $tree . '/version.php';
        if (!is_file($versionFile)) {
            throw new \RuntimeException(sprintf(
                'No version.php found in "%s" — is this an OpenEMR tree? Pass --version-override to set the release explicitly.',
                $tree
            ));
        }
        $vars = (static function (string $openemrVersionFilePath): array {
            require $openemrVersionFilePath;
            unset($openemrVersionFilePath);
            return get_defined_vars();
        })($versionFile);

        $major = $this->requireVersionComponent($vars, 'v_major');
        $minor = $this->requireVersionComponent($vars, 'v_minor');
        $patch = $this->requireVersionComponent($vars, 'v_patch');
        $realpatch = $realpatchOverride ?? $this->requireVersionComponent($vars, 'v_realpatch');

        $tag = $vars['v_tag'] ?? null;
        if (is_string($tag) && $tag !== '') {
            $io->warning(sprintf(
                'This tree identifies as a non-production build (v_tag "%s"). Manifests for distribution must be generated from a release package.',
                $tag
            ));
        }

        try {
            return OpenEmrRelease::fromVersion(sprintf('%d.%d.%d', $major, $minor, $patch), $realpatch);
        } catch (\DomainException $e) {
            throw new \RuntimeException('version.php did not yield a valid release version.', 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $vars
     * @throws \RuntimeException when the component is missing or non-numeric
     */
    private function requireVersionComponent(array $vars, string $name): int
    {
        $value = $vars[$name] ?? null;
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new \RuntimeException(sprintf('version.php does not define a numeric $%s.', $name));
    }
}
