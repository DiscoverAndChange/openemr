<?php

/**
 * Produces detached ed25519 signatures over canonical manifest bytes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\FileManifest;

final class ManifestSigner
{
    /**
     * @return string base64-encoded detached signature
     */
    public function sign(string $payload, #[\SensitiveParameter] string $secretKeyBase64): string
    {
        $key = base64_decode(trim($secretKeyBase64), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('Signing key is not a valid base64-encoded ed25519 secret key');
        }
        return base64_encode(sodium_crypto_sign_detached($payload, $key));
    }
}
