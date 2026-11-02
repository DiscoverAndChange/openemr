<?php

/**
 * Identifies the OpenEMR release a file manifest describes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\FileManifest;

final readonly class OpenEmrRelease
{
    public function __construct(
        public string $version,
        public int $realpatch,
        public string $tag,
    ) {
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new \DomainException('Release version must be in major.minor.patch form');
        }
        if ($realpatch < 0) {
            throw new \DomainException('Release realpatch must be zero or positive');
        }
        if (preg_match('/^v\d+_\d+_\d+$/', $tag) !== 1) {
            throw new \DomainException('Release tag must be in v<major>_<minor>_<patch> form');
        }
    }

    public static function fromVersion(string $version, int $realpatch): self
    {
        return new self($version, $realpatch, 'v' . str_replace('.', '_', $version));
    }

    /**
     * Base name shared by the manifest artifact and its signature file,
     * e.g. "openemr-file-manifest-v8_3_0" or "openemr-file-manifest-v8_3_0-patch2".
     */
    public function manifestBaseName(): string
    {
        $patchSuffix = $this->realpatch > 0 ? '-patch' . $this->realpatch : '';
        return 'openemr-file-manifest-' . $this->tag . $patchSuffix;
    }

    public function manifestFileName(): string
    {
        return $this->manifestBaseName() . '.json.gz';
    }

    public function signatureFileName(): string
    {
        return $this->manifestFileName() . '.sig';
    }
}
