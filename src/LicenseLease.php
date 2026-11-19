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

use JsonException;
use RuntimeException;

final class LicenseLease
{
    public const MAX_TTL_SECONDS = 604_800;
    public const REVALIDATION_INTERVAL_SECONDS = 86_400;
    public const FUTURE_ISSUED_AT_SKEW_SECONDS = 300;

    private function __construct(
        private readonly string $productId,
        private readonly string $releaseId,
        private readonly string $installationId,
        private readonly string $keyId,
        private readonly int $issuedAt,
        private readonly int $expiresAt,
    ) {
    }

    /**
     * @param array<string, string> $trustedKeys Ed25519 public keys, keyed by SHA-256 key ID and base64-encoded.
     */
    public static function verify(
        string $document,
        array $trustedKeys,
        string $expectedProductId,
        string $expectedReleaseId,
        string $expectedInstallationId,
        ?int $now = null,
    ): self {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('The Sodium extension is required to verify license leases.');
        }
        if (strlen($document) > 16_384) {
            throw new RuntimeException('License lease document exceeds the 16384-byte limit.');
        }

        try {
            $envelope = json_decode($document, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new RuntimeException('License lease document is not valid JSON.', 0, $exception);
        }

        if (!is_array($envelope) || array_is_list($envelope) || !self::hasExactKeys($envelope, ['payload', 'signature'])) {
            throw new RuntimeException('License lease document has an unsupported format.');
        }
        $payload = $envelope['payload'];
        if (!is_array($payload) || array_is_list($payload)
            || !self::hasExactKeys($payload, [
                'schema_version',
                'algorithm',
                'key_id',
                'product_id',
                'release_id',
                'installation_id',
                'issued_at',
                'expires_at',
            ])
        ) {
            throw new RuntimeException('License lease payload has an unsupported format.');
        }

        if ($payload['schema_version'] !== 1 || $payload['algorithm'] !== 'Ed25519'
            || !self::isIdentifier($payload['product_id'])
            || !self::isIdentifier($payload['release_id'])
            || !self::isIdentifier($payload['installation_id'])
            || !is_string($payload['key_id'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['key_id']) !== 1
            || !is_int($payload['issued_at'])
            || !is_int($payload['expires_at'])
        ) {
            throw new RuntimeException('License lease payload contains invalid values.');
        }

        if (!hash_equals($expectedProductId, $payload['product_id'])
            || !hash_equals($expectedReleaseId, $payload['release_id'])
            || !hash_equals($expectedInstallationId, $payload['installation_id'])
        ) {
            throw new RuntimeException('License lease identity does not match this product, release and installation.');
        }

        $publicKeyEncoded = $trustedKeys[$payload['key_id']] ?? null;
        $publicKey = is_string($publicKeyEncoded) ? base64_decode($publicKeyEncoded, true) : false;
        if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !hash_equals($payload['key_id'], hash('sha256', $publicKey))
        ) {
            throw new RuntimeException('License lease was signed by an untrusted key.');
        }

        $signatureEncoded = $envelope['signature'];
        $signature = is_string($signatureEncoded) ? base64_decode($signatureEncoded, true) : false;
        if (!is_string($signature) || base64_encode($signature) !== $signatureEncoded
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, self::canonicalPayload($payload), $publicKey)
        ) {
            throw new RuntimeException('License lease signature verification failed.');
        }

        $now ??= time();
        $issuedAt = $payload['issued_at'];
        $expiresAt = $payload['expires_at'];
        if ($issuedAt < 0 || $expiresAt <= $issuedAt
            || $expiresAt - $issuedAt > self::MAX_TTL_SECONDS
            || $issuedAt > $now + self::FUTURE_ISSUED_AT_SKEW_SECONDS
            || ($expiresAt > $now && $expiresAt - $now > self::MAX_TTL_SECONDS)
        ) {
            throw new RuntimeException('License lease validity period is invalid.');
        }
        if ($now >= $expiresAt) {
            throw new RuntimeException('License lease has expired.');
        }

        return new self(
            $payload['product_id'],
            $payload['release_id'],
            $payload['installation_id'],
            $payload['key_id'],
            $issuedAt,
            $expiresAt,
        );
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function releaseId(): string
    {
        return $this->releaseId;
    }

    public function installationId(): string
    {
        return $this->installationId;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function issuedAt(): int
    {
        return $this->issuedAt;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    public function revalidationDueAt(): int
    {
        return $this->issuedAt + self::REVALIDATION_INTERVAL_SECONDS;
    }

    public function shouldRevalidateAt(?int $now = null): bool
    {
        return ($now ?? time()) >= $this->revalidationDueAt();
    }

    /** @param array<string, mixed> $payload @param list<string> $keys */
    private static function hasExactKeys(array $payload, array $keys): bool
    {
        $actual = array_keys($payload);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }

    private static function isIdentifier(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= 128
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D', $value) === 1;
    }

    /** @param array<string, mixed> $payload */
    private static function canonicalPayload(array $payload): string
    {
        $canonical = [
            'schema_version' => 1,
            'algorithm' => 'Ed25519',
            'key_id' => $payload['key_id'],
            'product_id' => $payload['product_id'],
            'release_id' => $payload['release_id'],
            'installation_id' => $payload['installation_id'],
            'issued_at' => $payload['issued_at'],
            'expires_at' => $payload['expires_at'],
        ];
        return json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
