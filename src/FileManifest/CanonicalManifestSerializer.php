<?php

/**
 * Byte-reproducible JSON serialization for file manifests.
 *
 * Serialization is canonical: fixed key order, bytewise-sorted paths and
 * exclusion patterns, no insignificant whitespace, unescaped slashes and
 * unicode. Two independent runs over the same tree therefore produce
 * identical bytes, and ed25519 signatures are computed over exactly these
 * bytes (uncompressed — the gzip container is not signed).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\FileManifest;

final class CanonicalManifestSerializer
{
    public function serialize(FileManifest $manifest): string
    {
        $files = [];
        foreach ($manifest->files as $path => $entry) {
            $files[$path] = ['h' => $entry->hash, 's' => $entry->size];
        }

        $excluded = $manifest->excluded;
        sort($excluded, SORT_STRING);

        $payload = [
            'schemaVersion' => $manifest->schemaVersion,
            'openemr' => [
                'version' => $manifest->release->version,
                'realpatch' => $manifest->release->realpatch,
                'tag' => $manifest->release->tag,
            ],
            'source' => $manifest->source->value,
            'algorithm' => $manifest->algorithm->value,
            'excluded' => $excluded,
            'files' => $files === [] ? new \stdClass() : $files,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function deserialize(string $json): FileManifest
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidManifestException('Manifest is not valid JSON', 0, $e);
        }
        if (!is_array($data)) {
            throw new InvalidManifestException('Manifest root must be a JSON object');
        }

        $openemr = $this->requireArray($data, 'openemr');
        $sourceRaw = $this->requireString($data, 'source');
        $source = ManifestSource::tryFrom($sourceRaw)
            ?? throw new InvalidManifestException('Manifest declares an unknown source');
        $algorithmRaw = $this->requireString($data, 'algorithm');
        $algorithm = HashAlgorithm::tryFrom($algorithmRaw)
            ?? throw new InvalidManifestException('Manifest declares an unsupported hash algorithm');

        try {
            $release = new OpenEmrRelease(
                $this->requireString($openemr, 'version'),
                $this->requireInt($openemr, 'realpatch'),
                $this->requireString($openemr, 'tag'),
            );
            return new FileManifest(
                $this->requireInt($data, 'schemaVersion'),
                $release,
                $source,
                $algorithm,
                $this->requireStringList($data, 'excluded'),
                $this->parseFiles($data),
            );
        } catch (\DomainException $e) {
            throw new InvalidManifestException('Manifest content failed validation', 0, $e);
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, FileManifestEntry>
     */
    private function parseFiles(array $data): array
    {
        $filesRaw = $this->requireArray($data, 'files');
        $files = [];
        foreach ($filesRaw as $path => $node) {
            if (!is_array($node)) {
                throw new InvalidManifestException('Manifest file entries must be JSON objects');
            }
            try {
                $entry = new FileManifestEntry(
                    $this->requireString($node, 'h'),
                    $this->requireInt($node, 's'),
                );
            } catch (\DomainException $e) {
                throw new InvalidManifestException('Manifest file entry failed validation', 0, $e);
            }
            // JSON object keys that look numeric decode as PHP int keys; restore them.
            $files[is_int($path) ? (string) $path : $path] = $entry;
        }
        return $files;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidManifestException(sprintf('Manifest key "%s" must be a string', $key));
        }
        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function requireInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidManifestException(sprintf('Manifest key "%s" must be an integer', $key));
        }
        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function requireArray(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new InvalidManifestException(sprintf('Manifest key "%s" must be a JSON object or array', $key));
        }
        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    private function requireStringList(array $data, string $key): array
    {
        $value = $this->requireArray($data, $key);
        $list = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidManifestException(sprintf('Manifest key "%s" must contain only strings', $key));
            }
            $list[] = $item;
        }
        return $list;
    }
}
