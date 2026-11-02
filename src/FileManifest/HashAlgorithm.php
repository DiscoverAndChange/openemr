<?php

/**
 * Hash algorithm used for file manifest entries.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\FileManifest;

enum HashAlgorithm: string
{
    case Sha256 = 'sha256';

    public function hexDigestLength(): int
    {
        return match ($this) {
            self::Sha256 => 64,
        };
    }
}
