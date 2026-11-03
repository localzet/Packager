<?php

/**
 * @package Localzet Packager
 * @link https://github.com/localzet/Packager
 * @author Ivan Zorin <creator@localzet.com>
 * @copyright Copyright © 2016-2026 Localzet Group. All rights reserved.
 * @license https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Localzet\Packager;

use RuntimeException;

final class ArtifactSignature
{
    public function generateKeypair(string $privateKeyPath, string $publicKeyPath): string
    {
        $this->assertSodium();
        if ($privateKeyPath === $publicKeyPath || file_exists($privateKeyPath) || file_exists($publicKeyPath)) {
            throw new RuntimeException('Refusing to overwrite a key file.');
        }
        $privateDirectory = dirname($privateKeyPath);
        $publicDirectory = dirname($publicKeyPath);
        if (!is_dir($privateDirectory) || !is_dir($publicDirectory)) {
            throw new RuntimeException('Key output directories must already exist.');
        }

        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n";
        $public = base64_encode(sodium_crypto_sign_publickey($pair)) . "\n";
        sodium_memzero($pair);

        try {
            $this->writeExclusive($publicKeyPath, $public, 0644);
            $this->writeExclusive($privateKeyPath, $secret, 0600);
        } finally {
            sodium_memzero($secret);
        }

        return hash('sha256', base64_decode(trim($public), true));
    }

    public function sign(string $artifactPath, string $privateKeyPath): string
    {
        $this->assertSodium();
        $files = $this->artifactFiles($artifactPath);
        if (PHP_OS_FAMILY !== 'Windows') {
            $permissions = fileperms($privateKeyPath);
            if (!is_int($permissions) || ($permissions & 0077) !== 0) {
                throw new RuntimeException('Private key permissions must not allow group or other access.');
            }
        }
        $secretKey = $this->readKey($privateKeyPath, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'private');
        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
        $payload = $this->payload($files, hash('sha256', $publicKey));
        try {
            $signature = sodium_crypto_sign_detached($this->canonical($payload), $secretKey);
        } finally {
            sodium_memzero($secretKey);
        }
        $document = $payload + ['signature' => base64_encode($signature)];
        $signaturePath = $artifactPath . '.sig.json';
        if (file_exists($signaturePath)) {
            throw new RuntimeException('Refusing to overwrite an existing artifact signature.');
        }
        $this->writeJsonExclusive($signaturePath, $document);
        return $signaturePath;
    }

    public function verify(string $artifactPath, string $publicKeyPath): string
    {
        $this->assertSodium();
        $files = $this->artifactFiles($artifactPath);
        $publicKey = $this->readKey($publicKeyPath, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'public');
        $signaturePath = $artifactPath . '.sig.json';
        $signatureBytes = $this->readRegularFile($signaturePath, 65_536);
        try {
            $document = json_decode($signatureBytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Artifact signature document is not valid JSON.', 0, $exception);
        }
        if (!is_array($document)
            || ($document['schema_version'] ?? null) !== 1
            || ($document['algorithm'] ?? null) !== 'Ed25519'
            || !is_string($document['key_id'] ?? null)
            || !is_string($document['artifact_sha256'] ?? null)
            || !is_string($document['metadata_sha256'] ?? null)
            || !is_string($document['sbom_sha256'] ?? null)
            || !is_string($document['signature'] ?? null)
        ) {
            throw new RuntimeException('Artifact signature document has an unsupported format.');
        }

        $payload = [
            'schema_version' => 1,
            'algorithm' => 'Ed25519',
            'key_id' => hash('sha256', $publicKey),
            'artifact_sha256' => $files['artifact_sha256'],
            'metadata_sha256' => $files['metadata_sha256'],
            'sbom_sha256' => $files['sbom_sha256'],
        ];
        foreach ($payload as $name => $value) {
            $matches = is_string($value)
                && is_string($document[$name])
                ? hash_equals($value, $document[$name])
                : $value === $document[$name];
            if (!$matches) {
                throw new RuntimeException("Artifact signature mismatch for {$name}.");
            }
        }
        $signature = base64_decode($document['signature'], true);
        if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $this->canonical($payload), $publicKey)
        ) {
            throw new RuntimeException('Artifact signature verification failed.');
        }
        return $signaturePath;
    }

    /** @return array{artifact_sha256:string,metadata_sha256:string,sbom_sha256:string} */
    private function artifactFiles(string $artifactPath): array
    {
        if (!str_starts_with($artifactPath, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Artifact path must be absolute.');
        }
        $metadataPath = $artifactPath . '.manifest.json';
        $sbomPath = $artifactPath . '.spdx.json';
        return [
            'artifact_sha256' => $this->hashRegularFile($artifactPath),
            'metadata_sha256' => $this->hashRegularFile($metadataPath),
            'sbom_sha256' => $this->hashRegularFile($sbomPath),
        ];
    }

    private function readKey(string $path, int $expectedBytes, string $kind): string
    {
        $encoded = trim($this->readRegularFile($path, 256));
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== $expectedBytes) {
            throw new RuntimeException("The {$kind} key must be base64-encoded raw Ed25519 key material.");
        }
        return $key;
    }

    private function hashRegularFile(string $path): string
    {
        $handle = $this->openRegularFile($path);
        try {
            $context = hash_init('sha256');
            if (hash_update_stream($context, $handle) === false) {
                throw new RuntimeException("Cannot read file for SHA-256: {$path}");
            }
            return hash_final($context);
        } finally {
            fclose($handle);
        }
    }

    private function readRegularFile(string $path, int $maxBytes): string
    {
        $handle = $this->openRegularFile($path, $maxBytes);
        try {
            $contents = stream_get_contents($handle, $maxBytes + 1);
            if (!is_string($contents) || strlen($contents) > $maxBytes) {
                throw new RuntimeException("File exceeds the {$maxBytes}-byte read limit: {$path}");
            }
            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function openRegularFile(string $path, ?int $maxBytes = null)
    {
        $before = @lstat($path);
        $regularMode = 0100000;
        if (!is_array($before) || (($before['mode'] & 0170000) !== $regularMode)
            || ($maxBytes !== null && $before['size'] > $maxBytes)
        ) {
            throw new RuntimeException("Expected a regular non-symlink file" . ($maxBytes === null ? '' : " no larger than {$maxBytes} bytes") . ": {$path}");
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Cannot open file: {$path}");
        }
        $opened = fstat($handle);
        $current = @lstat($path);
        if (!is_array($opened) || !is_array($current)
            || (($opened['mode'] & 0170000) !== $regularMode)
            || (($current['mode'] & 0170000) !== $regularMode)
            || $before['dev'] !== $opened['dev']
            || $before['ino'] !== $opened['ino']
            || $opened['dev'] !== $current['dev']
            || $opened['ino'] !== $current['ino']
            || ($maxBytes !== null && $opened['size'] > $maxBytes)
        ) {
            fclose($handle);
            throw new RuntimeException("Expected a regular non-symlink file" . ($maxBytes === null ? '' : " no larger than {$maxBytes} bytes") . ": {$path}");
        }
        return $handle;
    }

    /** @param array{artifact_sha256:string,metadata_sha256:string,sbom_sha256:string} $files
     *  @return array{schema_version:int,algorithm:string,key_id:string,artifact_sha256:string,metadata_sha256:string,sbom_sha256:string}
     */
    private function payload(array $files, string $keyId): array
    {
        return [
            'schema_version' => 1,
            'algorithm' => 'Ed25519',
            'key_id' => $keyId,
            'artifact_sha256' => $files['artifact_sha256'],
            'metadata_sha256' => $files['metadata_sha256'],
            'sbom_sha256' => $files['sbom_sha256'],
        ];
    }

    /** @param array<string,int|string> $payload */
    private function canonical(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function writeJsonExclusive(string $path, array $document): void
    {
        $this->writeExclusive($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 0644);
    }

    private function writeExclusive(string $path, string $contents, int $mode): void
    {
        $handle = @fopen($path, 'x');
        if (!is_resource($handle)) {
            throw new RuntimeException("Cannot create file without overwriting: {$path}");
        }
        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException("Cannot write file: {$path}");
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException("Cannot write file: {$path}");
            }
        } finally {
            fclose($handle);
        }
        if (!chmod($path, $mode)) {
            @unlink($path);
            throw new RuntimeException("Cannot set file permissions: {$path}");
        }
    }

    private function assertSodium(): void
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('The PHP Sodium extension is required for artifact signatures.');
        }
    }
}
