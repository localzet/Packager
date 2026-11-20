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

use DateTimeImmutable;
use JsonException;
use Throwable;

final class LicenseApiClient
{
    private const MAX_RESPONSE_BYTES = 65_536;
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const REQUEST_TIMEOUT_SECONDS = 10;

    private readonly string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = self::validateBaseUrl($baseUrl);
    }

    /**
     * @return array{access_token:string,lease:string,key_id:string,expires_at:string,renew_after_seconds:int}
     */
    public function activate(
        string $licenseKey,
        string $installationId,
        string $productId,
        string $releaseId,
        string $productVersion,
        string $idempotencyKey,
    ): array {
        self::assertBoundedString($licenseKey, 8, 256, 'License key');
        self::assertIdentifier($installationId, 'Installation ID', 32, 256);
        self::assertIdentifier($productId, 'Product ID', 1, 80);
        self::assertIdentifier($releaseId, 'Release ID', 1, 80);
        self::assertBoundedString($productVersion, 1, 80, 'Product version');
        if (preg_match('/^[A-Za-z0-9_-]{22,128}$/D', $idempotencyKey) !== 1) {
            throw new LicenseApiException('Idempotency key has an invalid format.', false);
        }

        $response = $this->request(
            'POST',
            '/v1/activations',
            [
                'license_key' => $licenseKey,
                'installation_id' => $installationId,
                'product_id' => $productId,
                'release_id' => $releaseId,
                'product_version' => $productVersion,
            ],
            ['Idempotency-Key: ' . $idempotencyKey],
            [201],
        );

        return $this->leaseResponse($response);
    }

    /**
     * @return array{access_token:string,lease:string,key_id:string,expires_at:string,renew_after_seconds:int}
     */
    public function renew(
        string $accessToken,
        string $installationId,
        string $productId,
        string $releaseId,
        string $productVersion,
    ): array {
        if (preg_match('/^act_[A-Za-z0-9_-]{40,64}$/D', $accessToken) !== 1) {
            throw new LicenseApiException('Activation token has an invalid format.', false);
        }
        self::assertIdentifier($installationId, 'Installation ID', 32, 256);
        self::assertIdentifier($productId, 'Product ID', 1, 80);
        self::assertIdentifier($releaseId, 'Release ID', 1, 80);
        self::assertBoundedString($productVersion, 1, 80, 'Product version');

        $response = $this->request(
            'POST',
            '/v1/leases/renew',
            [
                'installation_id' => $installationId,
                'product_id' => $productId,
                'release_id' => $releaseId,
                'product_version' => $productVersion,
            ],
            ['Authorization: Bearer ' . $accessToken],
            [200],
        );

        return $this->leaseResponse($response);
    }

    /** @return array{released:bool} */
    public function release(string $accessToken): array
    {
        if (preg_match('/^act_[A-Za-z0-9_-]{40,64}$/D', $accessToken) !== 1) {
            throw new LicenseApiException('Activation token has an invalid format.', false);
        }

        $response = $this->request(
            'POST',
            '/v1/activations/release',
            null,
            ['Authorization: Bearer ' . $accessToken],
            [200],
        );
        if (($response['released'] ?? null) !== true) {
            throw new LicenseApiException('License API returned an invalid release response.', false);
        }

        return ['released' => true];
    }

    /**
     * @param array<string,string>|null $payload
     * @param list<string> $extraHeaders
     * @param list<int> $expectedStatuses
     * @return array<string,mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $payload,
        array $extraHeaders,
        array $expectedStatuses,
    ): array {
        if (!extension_loaded('curl')) {
            throw new LicenseApiException('The cURL extension is required for online license activation.', false);
        }

        $body = '';
        $tooLarge = false;
        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new LicenseApiException('Could not initialize the License API request.', true);
        }

        $headers = array_merge(
            ['Accept: application/json', 'Content-Type: application/json'],
            $extraHeaders,
        );

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'Localzet-Packager-License-Client/1.0',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];

        if ($payload !== null) {
            try {
                $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                curl_close($handle);
                throw new LicenseApiException('Could not encode the License API request.', false, previous: $exception);
            }
            $options[CURLOPT_POSTFIELDS] = $encodedPayload;
        }

        if (!curl_setopt_array($handle, $options)) {
            curl_close($handle);
            throw new LicenseApiException('Could not configure the License API request.', false);
        }

        try {
            $result = curl_exec($handle);
            $statusCode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($handle);
        } catch (Throwable $exception) {
            curl_close($handle);
            throw new LicenseApiException('License API request failed.', true, previous: $exception);
        }
        curl_close($handle);

        if ($result === false) {
            if ($tooLarge) {
                throw new LicenseApiException('License API response exceeded the size limit.', false, $statusCode);
            }
            $message = $curlError !== '' ? 'License API request failed: ' . $curlError : 'License API request failed.';
            throw new LicenseApiException($message, true, $statusCode > 0 ? $statusCode : null);
        }

        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $retryable = in_array($statusCode, [408, 425, 429], true) || $statusCode >= 500;
            throw new LicenseApiException('License API returned invalid JSON.', $retryable, $statusCode, previous: $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new LicenseApiException('License API returned an invalid JSON object.', false, $statusCode);
        }

        if (!in_array($statusCode, $expectedStatuses, true)) {
            $errorCode = $decoded['error']['code'] ?? null;
            if (!is_string($errorCode) || preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $errorCode) !== 1) {
                $errorCode = null;
            }
            $retryable = $statusCode === 408 || $statusCode === 425 || $statusCode === 429 || $statusCode >= 500;
            $message = match (true) {
                $statusCode === 401 || $statusCode === 403 => 'License API rejected the activation or renewal request.',
                $statusCode === 409 => 'License API rejected the repeated activation request.',
                $retryable => 'License API is temporarily unavailable.',
                default => 'License API rejected the request.',
            };
            throw new LicenseApiException($message, $retryable, $statusCode, $errorCode);
        }

        return $decoded;
    }

    /** @param array<string,mixed> $response @return array{access_token:string,lease:string,key_id:string,expires_at:string,renew_after_seconds:int} */
    private function leaseResponse(array $response): array
    {
        $accessToken = $response['access_token'] ?? null;
        $lease = $response['lease'] ?? null;
        $keyId = $response['key_id'] ?? null;
        $expiresAt = $response['expires_at'] ?? null;
        $renewAfter = $response['renew_after_seconds'] ?? null;

        if (!is_string($accessToken) || preg_match('/^act_[A-Za-z0-9_-]{40,64}$/D', $accessToken) !== 1
            || !is_string($keyId) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $keyId) !== 1
            || !is_string($lease) || strlen($lease) > 16_384 || !$this->isCompactJws($lease, $keyId)
            || !is_string($expiresAt) || !$this->isDateTime($expiresAt)
            || !is_int($renewAfter) || $renewAfter < 0 || $renewAfter > 100_800
        ) {
            throw new LicenseApiException('License API returned an invalid lease response.', false);
        }

        return [
            'access_token' => $accessToken,
            'lease' => $lease,
            'key_id' => $keyId,
            'expires_at' => $expiresAt,
            'renew_after_seconds' => $renewAfter,
        ];
    }

    private function isCompactJws(string $value, string $expectedKeyId): bool
    {
        $parts = explode('.', $value);
        if (count($parts) !== 3) {
            return false;
        }
        foreach ($parts as $part) {
            if ($part === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $part) !== 1) {
                return false;
            }
        }

        $headerBytes = self::decodeBase64Url($parts[0]);
        if (!is_string($headerBytes)) {
            return false;
        }
        try {
            $header = json_decode($headerBytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (!is_array($header) || array_is_list($header)) {
            return false;
        }
        $headerKeys = array_keys($header);
        sort($headerKeys);
        if ($headerKeys !== ['alg', 'kid', 'typ']) {
            return false;
        }

        return $header['alg'] === 'EdDSA'
            && $header['typ'] === 'JWT'
            && is_string($header['kid'])
            && hash_equals($expectedKeyId, $header['kid']);
    }

    private function isDateTime(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return false;
        }

        try {
            new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        } catch (Throwable) {
            return false;
        }
    }

    private static function decodeBase64Url(string $value): string|false
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($decoded)) {
            return false;
        }

        $encoded = rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=');
        return hash_equals($value, $encoded) ? $decoded : false;
    }

    private static function validateBaseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new LicenseApiException('License API URL must be an HTTPS origin without credentials, query or fragment.', false);
        }

        $path = rtrim((string)($parts['path'] ?? ''), '/');
        if (str_contains($path, '..') || str_contains($path, '\\')) {
            throw new LicenseApiException('License API URL contains an invalid path.', false);
        }

        return rtrim($baseUrl, '/');
    }

    private static function assertIdentifier(string $value, string $label, int $minimumLength, int $maximumLength): void
    {
        self::assertBoundedString($value, $minimumLength, $maximumLength, $label);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $value) !== 1) {
            throw new LicenseApiException($label . ' has an invalid format.', false);
        }
    }

    private static function assertBoundedString(string $value, int $minimumLength, int $maximumLength, string $label): void
    {
        $length = strlen($value);
        if ($length < $minimumLength || $length > $maximumLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new LicenseApiException($label . ' has an invalid length or contains control characters.', false);
        }
    }
}
