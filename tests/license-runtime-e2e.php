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

/** @param callable(bool,string):void $assert */
function runPackagedLicenseRuntimeE2E(string $root, array $manifest, callable $assert): void
{
    $directory = $root . '/license-e2e';
    mkdir($directory, 0700);
    $certificate = $directory . '/tls.crt';
    $tlsKey = $directory . '/tls.key';
    $certificateProcess = proc_open([
        'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
        '-keyout', $tlsKey, '-out', $certificate, '-subj', '/CN=127.0.0.1',
        '-addext', 'subjectAltName=IP:127.0.0.1',
    ], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $certificatePipes);
    if (!is_resource($certificateProcess)) {
        throw new RuntimeException('Could not start the local TLS certificate generator.');
    }
    $certificateOutput = stream_get_contents($certificatePipes[1]) . stream_get_contents($certificatePipes[2]);
    fclose($certificatePipes[1]);
    fclose($certificatePipes[2]);
    $assert(proc_close($certificateProcess) === 0 && is_file($certificate) && is_file($tlsKey), 'Local TLS certificate generation failed: ' . $certificateOutput);

    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = sodium_crypto_sign_publickey($pair);
    $secretPath = $directory . '/lease-signing.key';
    $publicPath = $directory . '/lease-signing.pub';
    file_put_contents($secretPath, $secret, LOCK_EX);
    file_put_contents($publicPath, $public, LOCK_EX);
    chmod($secretPath, 0600);
    $trustedKeyId = rtrim(strtr(base64_encode(hash('sha256', $public, true)), '+/', '-_'), '=');
    $trustedKeys = [$trustedKeyId => base64_encode($public)];

    $readyPath = $directory . '/ready';
    $recordsPath = $directory . '/requests.jsonl';
    $serverStderrPath = $directory . '/server.stderr';
    $server = proc_open([
        PHP_BINARY,
        __DIR__ . '/fixtures/license-api-https.php',
        $certificate,
        $tlsKey,
        $secretPath,
        $publicPath,
        $recordsPath,
        $readyPath,
    ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', $serverStderrPath, 'a']], $serverPipes);
    if (!is_resource($server)) {
        $serverErrors = trim((string)@file_get_contents($serverStderrPath));
        throw new RuntimeException('Could not start the isolated HTTPS License API fixture.'
            . ($serverErrors !== '' ? ' stderr: ' . $serverErrors : ''));
    }
    try {
        $deadline = microtime(true) + 20;
        while (!is_file($readyPath) && microtime(true) < $deadline) usleep(20_000);
        $readyAddress = is_file($readyPath) ? trim((string)file_get_contents($readyPath)) : '';
        $serverStatus = proc_get_status($server);
        $serverErrors = trim((string)@file_get_contents($serverStderrPath));
        $assert(preg_match('/^127\.0\.0\.1:\d+$/D', $readyAddress) === 1 && $serverStatus['running'],
            'Isolated HTTPS License API failed to become ready: ' . ($readyAddress !== '' ? $readyAddress : 'readiness timeout')
                . ($serverErrors !== '' ? '; stderr: ' . $serverErrors : ''));

        $manifest['license']['api_url'] = 'https://' . $readyAddress;
        $manifest['license']['trusted_keys'] = $trustedKeys;
        $manifest['output'] = 'output/license-runtime-e2e.phar';
        $manifestPath = $root . '/license-runtime-e2e-packager.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        $artifact = (new \Localzet\Packager\BuildCommand())->run(\Localzet\Packager\Manifest::load($manifestPath))['binary'];
        $stateBase = $directory . '/state';
        $environment = getenv();
        $environment = is_array($environment) ? array_replace($environment, [
            'LOCALZET_LICENSE_DIR' => $stateBase,
        ]) : [];
        $run = static function (array $arguments = [], string $stdin = '') use ($artifact, $environment, $certificate): array {
            $process = proc_open([PHP_BINARY, '-d', 'curl.cainfo=' . $certificate, $artifact, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
            if (!is_resource($process)) throw new RuntimeException('Could not execute the packaged licensing PHAR.');
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), (string)$stdout, (string)$stderr];
        };
        $key = 'test-license-key-123456';
        [$failedExit, $failedOut, $failedErr] = $run(['--license-activate'], $key . "\n");
        $assert($failedExit === 78 && $failedOut === '' && str_contains($failedErr, 'Empty reply from server'), 'Dropped activation response was not reported as a failed activation: ' . $failedErr);
        $statePath = $stateBase . '/localzet-license/localzet.server/license-state.json';
        $pendingState = json_decode((string)file_get_contents($statePath), true, 16, JSON_THROW_ON_ERROR);
        $assert(!str_contains((string)file_get_contents($statePath), $key), 'License key was persisted in activation state.');
        $firstRequest = json_decode(trim((string)file_get_contents($recordsPath)), true, 16, JSON_THROW_ON_ERROR);
        $assert(($pendingState['pending_idempotency_key'] ?? null) === ($firstRequest['idempotency_key'] ?? null), 'Activation retry state did not preserve the first idempotency key.');

        [$activationExit, $activationOut, $activationErr] = $run(['--license-activate'], $key . "\n");
        $assert($activationExit === 0 && str_contains($activationOut, 'License activated.') && $activationErr === '', 'Packaged online activation failed: ' . $activationErr);
        $stateBytes = (string)file_get_contents($statePath);
        $state = json_decode($stateBytes, true, 16, JSON_THROW_ON_ERROR);
        $assert((fileperms(dirname($statePath)) & 0777) === 0700 && (fileperms($statePath) & 0777) === 0600, 'Online activation state permissions are not private.');
        $assert(!str_contains($stateBytes, $key) && $state['pending_idempotency_key'] === null, 'Successful activation retained secret input or pending idempotency state.');
        [$startupExit, $startupOut, $startupErr] = $run();
        $assert($startupExit === 0 && trim($startupOut) === 'packaged-ok' && $startupErr === '', 'Packaged startup rejected the online lease: ' . $startupErr);
        [$renewExit, $renewOut, $renewErr] = $run(['--license-renew']);
        $assert($renewExit === 0 && str_contains($renewOut, 'License renewed.') && $renewErr === '', 'Packaged lease renewal failed: ' . $renewErr);
        [$releaseExit, $releaseOut, $releaseErr] = $run(['--license-release']);
        $assert($releaseExit === 0 && str_contains($releaseOut, 'License activation released.') && $releaseErr === '', 'Packaged activation release failed: ' . $releaseErr);
        [$afterReleaseExit, $afterReleaseOut, $afterReleaseErr] = $run();
        $assert($afterReleaseExit === 78 && $afterReleaseOut === '' && str_contains($afterReleaseErr, 'No valid license activation'), 'Released activation still allowed application startup.');

        $records = array_map(static fn (string $line): array => json_decode($line, true, 16, JSON_THROW_ON_ERROR), file($recordsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $activationRequests = array_values(array_filter($records, static fn (array $record): bool => $record['path'] === '/v1/activations'));
        $assert(count($activationRequests) === 2 && $activationRequests[0]['idempotency_key'] === $activationRequests[1]['idempotency_key'], 'Activation retry did not reuse one idempotency key.');
        $assert($activationRequests[0]['payload']['license_key'] === $key, 'Activation request did not deliver the supplied key to the HTTPS API.');
        $assert(count(array_filter($records, static fn (array $record): bool => $record['path'] === '/v1/leases/renew')) === 1, 'Expected one online renewal request.');
        $assert(count(array_filter($records, static fn (array $record): bool => $record['path'] === '/v1/activations/release')) === 1, 'Expected one online release request.');
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
}
