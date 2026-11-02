<?php

/**
 * An ed25519 keypair for manifest signing, base64-encoded for storage.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\FileManifest;

final readonly class ManifestKeypair
{
    private function __construct(
        public string $publicKeyBase64,
        #[\SensitiveParameter] public string $secretKeyBase64,
    ) {
    }

    public static function generate(): self
    {
        $pair = sodium_crypto_sign_keypair();
        return new self(
            base64_encode(sodium_crypto_sign_publickey($pair)),
            base64_encode(sodium_crypto_sign_secretkey($pair)),
        );
    }
}
