<?php

/**
 * Walks a filesystem tree and produces the FileManifest describing it.
 *
 * All traversal and hashing goes through Flysystem — no raw PHP filesystem
 * calls — so the same generator runs against a LocalFilesystemAdapter today
 * and any future core filesystem service. Files are streamed through an
 * incremental hash context, so large files are never loaded into memory.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\FileManifest;

use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

final class ManifestGenerator
{
    public function __construct(
        private readonly FilesystemOperator $filesystem,
        private readonly HashAlgorithm $algorithm = HashAlgorithm::Sha256,
    ) {
    }

    /**
     * @param list<string> $excludedPatterns glob patterns (PathMatcher syntax) to skip
     * @param (\Closure(string): void)|null $onFile invoked with each path after it is hashed
     */
    public function generate(
        OpenEmrRelease $release,
        ManifestSource $source,
        array $excludedPatterns = [],
        ?\Closure $onFile = null,
    ): FileManifest {
        $matcher = new PathMatcher($excludedPatterns);
        $entries = [];
        try {
            foreach ($this->filesystem->listContents('', true) as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                $path = $item->path();
                if ($matcher->matches($path)) {
                    continue;
                }
                $entries[$path] = new FileManifestEntry(
                    $this->hashFile($path),
                    $this->sizeOf($item, $path),
                );
                if ($onFile !== null) {
                    $onFile($path);
                }
            }
        } catch (FilesystemException $e) {
            throw new \RuntimeException('Failed walking the target tree', 0, $e);
        }

        return new FileManifest(
            FileManifest::CURRENT_SCHEMA_VERSION,
            $release,
            $source,
            $this->algorithm,
            $excludedPatterns,
            $entries,
        );
    }

    private function hashFile(string $path): string
    {
        try {
            $stream = $this->filesystem->readStream($path);
        } catch (FilesystemException $e) {
            throw new \RuntimeException(sprintf('Failed reading "%s" for hashing', $path), 0, $e);
        }
        try {
            $context = hash_init($this->algorithm->value);
            hash_update_stream($context, $stream);
            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function sizeOf(StorageAttributes $item, string $path): int
    {
        if ($item instanceof FileAttributes) {
            $size = $item->fileSize();
            if ($size !== null) {
                return $size;
            }
        }
        try {
            return $this->filesystem->fileSize($path);
        } catch (FilesystemException $e) {
            throw new \RuntimeException(sprintf('Failed reading size of "%s"', $path), 0, $e);
        }
    }
}
