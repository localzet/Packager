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
    public const ISSUER = 'localzet-license-api';
    public const MAX_TTL_SECONDS = 604_800;
    public const MIN_REVALIDATION_DELAY_SECONDS = 72_000;
    public const MAX_REVALIDATION_DELAY_SECONDS = 100_800;
    public const FUTURE_ISSUED_AT_SKEW_SECONDS = 300;

    private function __construct(
        private readonly string $productId,
        private readonly string $releaseId,
        private readonly string $installationId,
        private readonly string $productVersion,
        private readonly string $activationId,
        private readonly string $keyId,
        private readonly int $issuedAt,
        private readonly int $notBefore,
        private readonly int $expiresAt,
        private readonly int $renewAfter,
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
        ?string $expectedProductVersion = null,
        bool $allowExpiredForRenewal = false,
        bool $allowReleaseChangeForRenewal = false,
    ): self {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('The Sodium extension is required to verify license leases.');
        }
        if (strlen($document) > 16_384) {
            throw new RuntimeException('License lease document exceeds the 16384-byte limit.');
        }

        $segments = explode('.', $document);
        if (count($segments) !== 3) {
            throw new RuntimeException('License lease is not a compact JWS.');
        }
        [$headerSegment, $payloadSegment, $signatureSegment] = $segments;
        $headerBytes = self::decodeBase64Url($headerSegment);
        $payloadBytes = self::decodeBase64Url($payloadSegment);
        $signature = self::decodeBase64Url($signatureSegment);
        if ($headerBytes === null || $payloadBytes === null || $signature === null) {
            throw new RuntimeException('License lease contains invalid base64url data.');
        }

        try {
            $header = json_decode($headerBytes, true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $payload = json_decode($payloadBytes, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new RuntimeException('License lease contains invalid JSON.', 0, $exception);
        }

        if (!is_array($header) || array_is_list($header)
            || !self::hasExactKeys($header, ['alg', 'kid', 'typ'])
            || $header['alg'] !== 'EdDSA'
            || $header['typ'] !== 'JWT'
            || !is_string($header['kid'])
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $header['kid']) !== 1
        ) {
            throw new RuntimeException('License lease header has an unsupported format.');
        }

        $publicKeyEncoded = $trustedKeys[$header['kid']] ?? null;
        $publicKey = is_string($publicKeyEncoded) ? base64_decode($publicKeyEncoded, true) : false;
        if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !hash_equals($header['kid'], self::base64Url(hash('sha256', $publicKey, true)))
        ) {
            throw new RuntimeException('License lease was signed by an untrusted key.');
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $headerSegment . '.' . $payloadSegment, $publicKey)
        ) {
            throw new RuntimeException('License lease signature verification failed.');
        }

        $claimKeys = [
            'iss', 'aud', 'sub', 'activation_id', 'product_id', 'release_id',
            'installation_id', 'product_version', 'iat', 'nbf', 'exp', 'renew_after', 'jti',
        ];
        if (!is_array($payload) || array_is_list($payload) || !self::hasExactKeys($payload, $claimKeys)) {
            throw new RuntimeException('License lease claims have an unsupported format.');
        }

        if ($payload['iss'] !== self::ISSUER
            || !is_string($payload['aud'])
            || !hash_equals($expectedProductId, $payload['aud'])
            || !self::isIdentifier($payload['sub'])
            || !self::isIdentifier($payload['activation_id'])
            || !self::isIdentifier($payload['jti'])
            || !self::isIdentifier($payload['product_id'])
            || !self::isIdentifier($payload['release_id'])
            || !self::isIdentifier($payload['installation_id'])
            || !is_string($payload['product_version'])
            || $payload['product_version'] === ''
            || strlen($payload['product_version']) > 128
            || !is_int($payload['iat'])
            || !is_int($payload['nbf'])
            || !is_int($payload['exp'])
            || !is_int($payload['renew_after'])
        ) {
            throw new RuntimeException('License lease claims contain invalid values.');
        }

        if (!hash_equals($expectedProductId, $payload['product_id'])
            || (!$allowReleaseChangeForRenewal && !hash_equals($expectedReleaseId, $payload['release_id']))
            || !hash_equals($expectedInstallationId, $payload['installation_id'])
            || ($expectedProductVersion !== null && !hash_equals($expectedProductVersion, $payload['product_version']))
        ) {
            throw new RuntimeException('License lease identity does not match this product, release and installation.');
        }

        $now ??= time();
        $issuedAt = $payload['iat'];
        $notBefore = $payload['nbf'];
        $expiresAt = $payload['exp'];
        $renewAfter = $payload['renew_after'];
        $ttl = $expiresAt - $issuedAt;
        $renewalDelay = $renewAfter - $issuedAt;
        if ($issuedAt < 0 || $expiresAt <= $issuedAt
            || $ttl > self::MAX_TTL_SECONDS
            || $issuedAt > $now + self::FUTURE_ISSUED_AT_SKEW_SECONDS
            || $notBefore > $now + self::FUTURE_ISSUED_AT_SKEW_SECONDS
            || $notBefore < $issuedAt - self::FUTURE_ISSUED_AT_SKEW_SECONDS
            || $renewAfter <= $issuedAt
            || $renewAfter > $expiresAt
            || ($ttl > self::MAX_REVALIDATION_DELAY_SECONDS
                && ($renewalDelay < self::MIN_REVALIDATION_DELAY_SECONDS
                    || $renewalDelay > self::MAX_REVALIDATION_DELAY_SECONDS))
            || ($ttl <= self::MAX_REVALIDATION_DELAY_SECONDS && $renewalDelay > $ttl)
            || ($expiresAt > $now && $expiresAt - $now > self::MAX_TTL_SECONDS)
        ) {
            throw new RuntimeException('License lease validity period is invalid.');
        }
        if (!$allowExpiredForRenewal && $now >= $expiresAt) {
            throw new RuntimeException('License lease has expired.');
        }

        return new self(
            $payload['product_id'],
            $payload['release_id'],
            $payload['installation_id'],
            $payload['product_version'],
            $payload['activation_id'],
            $header['kid'],
            $issuedAt,
            $notBefore,
            $expiresAt,
            $renewAfter,
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

    public function productVersion(): string
    {
        return $this->productVersion;
    }

    public function activationId(): string
    {
        return $this->activationId;
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

    public function notBefore(): int
    {
        return $this->notBefore;
    }

    public function renewAfter(): int
    {
        return $this->renewAfter;
    }

    public function revalidationDueAt(): int
    {
        return $this->renewAfter;
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

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decodeBase64Url(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1 || strlen($encoded) % 4 === 1) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        if (!is_string($decoded) || self::encodeBase64Url($decoded) !== $encoded) {
            return null;
        }

        return $decoded;
    }

    private static function encodeBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
