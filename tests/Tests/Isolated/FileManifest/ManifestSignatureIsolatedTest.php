<?php

/**
 * Isolated tests for manifest keypair generation, signing, and verification.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\FileManifest;

use OpenEMR\FileManifest\ManifestKeypair;
use OpenEMR\FileManifest\ManifestSigner;
use OpenEMR\FileManifest\ManifestVerifier;
use PHPUnit\Framework\TestCase;

class ManifestSignatureIsolatedTest extends TestCase
{
    public function testSignedPayloadVerifiesWithMatchingKey(): void
    {
        $keypair = ManifestKeypair::generate();
        $payload = '{"schemaVersion":1}';
        $signature = (new ManifestSigner())->sign($payload, $keypair->secretKeyBase64);
        $this->assertTrue(
            (new ManifestVerifier())->isSignedByAny($payload, $signature, [$keypair->publicKeyBase64])
        );
    }

    public function testTamperedPayloadFailsVerification(): void
    {
        $keypair = ManifestKeypair::generate();
        $signature = (new ManifestSigner())->sign('original', $keypair->secretKeyBase64);
        $this->assertFalse(
            (new ManifestVerifier())->isSignedByAny('tampered', $signature, [$keypair->publicKeyBase64])
        );
    }

    public function testWrongKeyFailsVerification(): void
    {
        $signer = ManifestKeypair::generate();
        $other = ManifestKeypair::generate();
        $signature = (new ManifestSigner())->sign('payload', $signer->secretKeyBase64);
        $this->assertFalse(
            (new ManifestVerifier())->isSignedByAny('payload', $signature, [$other->publicKeyBase64])
        );
    }

    public function testAnyPinnedKeyMayVerify(): void
    {
        $retired = ManifestKeypair::generate();
        $current = ManifestKeypair::generate();
        $signature = (new ManifestSigner())->sign('payload', $current->secretKeyBase64);
        $this->assertTrue(
            (new ManifestVerifier())->isSignedByAny(
                'payload',
                $signature,
                [$retired->publicKeyBase64, $current->publicKeyBase64]
            )
        );
    }

    public function testMalformedSignatureReturnsFalse(): void
    {
        $keypair = ManifestKeypair::generate();
        $verifier = new ManifestVerifier();
        $this->assertFalse($verifier->isSignedByAny('payload', 'not-base64!!', [$keypair->publicKeyBase64]));
        $this->assertFalse($verifier->isSignedByAny('payload', base64_encode('short'), [$keypair->publicKeyBase64]));
    }

    public function testMalformedPinnedKeyThrows(): void
    {
        $keypair = ManifestKeypair::generate();
        $signature = (new ManifestSigner())->sign('payload', $keypair->secretKeyBase64);
        $this->expectException(\InvalidArgumentException::class);
        (new ManifestVerifier())->isSignedByAny('payload', $signature, ['not a key']);
    }

    public function testEmptyPinnedKeyListThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ManifestVerifier())->isSignedByAny('payload', 'sig', []);
    }

    public function testMalformedSigningKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ManifestSigner())->sign('payload', 'not a key');
    }
}
