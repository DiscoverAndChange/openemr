<?php

/**
 * Verifies detached ed25519 manifest signatures against pinned public keys.
 *
 * Malformed signature material returns false (it is untrusted input);
 * malformed pinned keys throw (they are deployment configuration).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\FileManifest;

final class ManifestVerifier
{
    /**
     * @param list<string> $publicKeysBase64 pinned keys; any single match verifies
     */
    public function isSignedByAny(string $payload, string $signatureBase64, array $publicKeysBase64): bool
    {
        if ($publicKeysBase64 === []) {
            throw new \InvalidArgumentException('At least one pinned public key is required');
        }
        $signature = base64_decode(trim($signatureBase64), true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        foreach ($publicKeysBase64 as $publicKeyBase64) {
            $publicKey = base64_decode(trim($publicKeyBase64), true);
            if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new \InvalidArgumentException('Pinned public key is not a valid base64-encoded ed25519 public key');
            }
            if (sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
                return true;
            }
        }
        return false;
    }
}
