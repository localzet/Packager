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

[$script, $certificate, $privateKeyPath, $signingSecretPath, $signingPublicPath, $recordsPath, $readyPath] = $argv;
$context = stream_context_create(['ssl' => [
    'local_cert' => $certificate,
    'local_pk' => $privateKeyPath,
    'verify_peer' => false,
    'allow_self_signed' => true,
]]);
$server = stream_socket_server('tls://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!is_resource($server)) {
    file_put_contents($readyPath, 'ERROR: ' . $errorMessage . ' (' . $errorCode . ')');
    exit(1);
}
$address = stream_socket_get_name($server, false);
if (!is_string($address)) {
    file_put_contents($readyPath, 'ERROR: Could not read the bound HTTPS address.');
    exit(1);
}
file_put_contents($readyPath, $address);
$secretKey = (string)file_get_contents($signingSecretPath);
$publicKey = (string)file_get_contents($signingPublicPath);
$keyId = rtrim(strtr(base64_encode(hash('sha256', $publicKey, true)), '+/', '-_'), '=');
$seenIdempotencyKeys = [];

while (($connection = @stream_socket_accept($server, 20)) !== false) {
    stream_set_timeout($connection, 5);
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($connection, 8192);
        if (!is_string($chunk) || $chunk === '') break;
        $request .= $chunk;
    }
    [$head, $body] = array_pad(explode("\r\n\r\n", $request, 2), 2, '');
    preg_match('/^POST ([^ ]+)/', $head, $pathMatch);
    preg_match('/^Content-Length:\s*(\d+)/im', $head, $lengthMatch);
    $length = (int)($lengthMatch[1] ?? 0);
    while (strlen($body) < $length) {
        $chunk = fread($connection, min(8192, $length - strlen($body)));
        if (!is_string($chunk) || $chunk === '') break;
        $body .= $chunk;
    }
    $path = $pathMatch[1] ?? '';
    $payload = $body === '' ? [] : json_decode($body, true);
    preg_match('/^Idempotency-Key:\s*(\S+)/im', $head, $idempotencyMatch);
    preg_match('/^Authorization:\s*Bearer\s+(\S+)/im', $head, $authorizationMatch);
    $record = [
        'path' => $path,
        'idempotency_key' => $idempotencyMatch[1] ?? null,
        'authorization' => $authorizationMatch[1] ?? null,
        'payload' => is_array($payload) ? $payload : [],
    ];
    file_put_contents($recordsPath, json_encode($record, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

    if ($path === '/v1/activations') {
        $idempotencyKey = (string)($record['idempotency_key'] ?? '');
        if (!isset($seenIdempotencyKeys[$idempotencyKey])) {
            $seenIdempotencyKeys[$idempotencyKey] = true;
            fclose($connection);
            continue;
        }
        $activationId = 'activation-0123456789abcdef';
        $accessToken = 'act_' . str_repeat('A', 40);
    } elseif ($path === '/v1/leases/renew') {
        $activationId = 'activation-0123456789abcdef';
        $accessToken = 'act_' . str_repeat('B', 40);
    } elseif ($path === '/v1/activations/release') {
        $response = ['released' => true];
        $status = 200;
        $responseBytes = json_encode($response, JSON_THROW_ON_ERROR);
        fwrite($connection, "HTTP/1.1 {$status} OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($responseBytes) . "\r\nConnection: close\r\n\r\n" . $responseBytes);
        fclose($connection);
        continue;
    } else {
        fclose($connection);
        continue;
    }

    $now = time();
    $installationId = (string)($record['payload']['installation_id'] ?? '');
    $productId = (string)($record['payload']['product_id'] ?? 'localzet.server');
    $releaseId = (string)($record['payload']['release_id'] ?? '7.0.0');
    $productVersion = (string)($record['payload']['product_version'] ?? '7.0.0');
    $payload = [
        'iss' => 'localzet-license-api',
        'aud' => $productId,
        'sub' => 'license-0123456789abcdef',
        'activation_id' => $activationId,
        'product_id' => $productId,
        'release_id' => $releaseId,
        'installation_id' => $installationId,
        'product_version' => $productVersion,
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + 604800,
        'renew_after' => $now + 86400,
        'jti' => 'lease-' . bin2hex(random_bytes(16)),
    ];
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $signingInput = $encode(json_encode(['alg' => 'EdDSA', 'kid' => $keyId, 'typ' => 'JWT'], JSON_THROW_ON_ERROR)) . '.'
        . $encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $lease = $signingInput . '.' . $encode(sodium_crypto_sign_detached($signingInput, $secretKey));
    $response = [
        'access_token' => $accessToken,
        'lease' => $lease,
        'key_id' => $keyId,
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $payload['exp']),
        'renew_after_seconds' => 86400,
    ];
    $responseBytes = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $status = $path === '/v1/activations' ? '201 Created' : '200 OK';
    fwrite($connection, "HTTP/1.1 {$status}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($responseBytes) . "\r\nConnection: close\r\n\r\n" . $responseBytes);
    fclose($connection);
}
fclose($server);
