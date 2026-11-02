<?php

/**
 * A single file's expected hash and size within a file manifest.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\FileManifest;

final readonly class FileManifestEntry
{
    public function __construct(
        public string $hash,
        public int $size,
    ) {
        if (preg_match('/^[0-9a-f]+$/', $hash) !== 1) {
            throw new \DomainException('Manifest entry hash must be a lowercase hex digest');
        }
        if ($size < 0) {
            throw new \DomainException('Manifest entry size must be zero or positive');
        }
    }
}
