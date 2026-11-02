<?php

/**
 * The complete set of files (and their expected hashes) shipped in one OpenEMR release.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\FileManifest;

final readonly class FileManifest
{
    public const CURRENT_SCHEMA_VERSION = 1;

    /** @var array<string, FileManifestEntry> keyed by relative path, sorted bytewise */
    public array $files;

    /**
     * @param list<string> $excluded glob patterns the generator skipped
     * @param array<string, FileManifestEntry> $files keyed by relative path
     */
    public function __construct(
        public int $schemaVersion,
        public OpenEmrRelease $release,
        public ManifestSource $source,
        public HashAlgorithm $algorithm,
        public array $excluded,
        array $files,
    ) {
        if ($schemaVersion !== self::CURRENT_SCHEMA_VERSION) {
            throw new \DomainException('Unsupported manifest schema version');
        }
        foreach ($files as $path => $entry) {
            if (strlen($entry->hash) !== $algorithm->hexDigestLength()) {
                throw new \DomainException(sprintf(
                    'Manifest entry for "%s" has a digest length that does not match the manifest algorithm',
                    $path
                ));
            }
        }
        ksort($files, SORT_STRING);
        $this->files = $files;
    }

    public function getEntry(string $path): ?FileManifestEntry
    {
        return $this->files[$path] ?? null;
    }

    public function count(): int
    {
        return count($this->files);
    }
}
