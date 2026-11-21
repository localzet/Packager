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
use RuntimeException;
use Throwable;

final class LicenseRuntime
{
    private const EXIT_LICENSE_REQUIRED = 78;
    private const EXIT_RETRYABLE = 75;
    private const RENEWAL_RETRY_SECONDS = 60;
    private const WORKER_CHECK_SECONDS = 1;

    private readonly LicenseApiClient $client;
    private readonly LicenseStore $store;
    /** @var resource|null */
    private $renewalProcess = null;

    /** @param array{product_id:string,release_id:string,product_version:string,api_url:string,trusted_keys:array<string,string>} $config */
    public function __construct(private readonly array $config, ?LicenseStore $store = null, ?LicenseApiClient $client = null)
    {
        $this->store = $store ?? LicenseStore::forProduct($config);
        $this->client = $client ?? new LicenseApiClient($config['api_url']);
    }

    /** @param list<string> $arguments Returns null when the application should start. */
    public function handleCommand(array $arguments): ?int
    {
        return match ($arguments[1] ?? null) {
            '--license-activate' => $this->activateFromStdin(),
            '--license-release' => $this->release(),
            '--license-renew' => $this->renewInBackground(force: true),
            default => null,
        };
    }

    public function enforce(): void
    {
        $state = $this->store->load();
        if ($state['rejected']) {
            throw new RuntimeException('This license activation was rejected by the License API.');
        }

        $lease = $this->verifiedLease($state, allowExpiredForRenewal: true, allowReleaseChangeForRenewal: true);
        $needsRenewal = $lease->expiresAt() <= time()
            || $lease->releaseId() !== $this->config['release_id']
            || $lease->productVersion() !== $this->config['product_version'];
        if ($needsRenewal) {
            $renewalExitCode = $this->renewInBackground(force: true);
            if ($renewalExitCode !== 0) {
                throw new RuntimeException('This binary needs an online license renewal before it can start. Restore License API access and run --license-renew.');
            }
            $state = $this->store->load();
            if ($state['rejected']) {
                throw new RuntimeException('This license activation was rejected by the License API.');
            }
            $lease = $this->verifiedLease($state);
        }

        \localzet\Server::onWorkerStart(function (): void {
            $state = $this->store->load();
            if ($state['rejected']) {
                \localzet\Server::requestShutdown(self::EXIT_LICENSE_REQUIRED, 'License API rejected this activation.');
                return;
            }
            $lease = $this->verifiedLease($state);
            $expiryTimer = null;
            $scheduledExpiry = null;
            $scheduleExpiry = function (LicenseLease $lease) use (&$expiryTimer, &$scheduledExpiry): void {
                if ($expiryTimer !== null) {
                    \localzet\Timer::del($expiryTimer);
                }
                $scheduledExpiry = $lease->expiresAt();
                $delay = max(0.01, (float)($scheduledExpiry - time()));
                $expiryTimer = \localzet\Timer::add(
                    $delay,
                    function () use (&$scheduleExpiry): void {
                        try {
                            $state = $this->store->load();
                            if ($state['rejected']) {
                                \localzet\Server::requestShutdown(self::EXIT_LICENSE_REQUIRED, 'License API rejected this activation.');
                                return;
                            }
                            $currentLease = $this->verifiedLease($state);
                            if ($currentLease->expiresAt() <= time()) {
                                \localzet\Server::requestShutdown(self::EXIT_LICENSE_REQUIRED, 'The signed license lease expired.');
                                return;
                            }
                            $scheduleExpiry($currentLease);
                        } catch (Throwable $exception) {
                            \localzet\Server::requestShutdown(self::EXIT_LICENSE_REQUIRED, $exception);
                        }
                    },
                    [],
                    false,
                );
            };
            $scheduleExpiry($lease);
            $this->startRenewalProcess();

            \localzet\Timer::add(self::WORKER_CHECK_SECONDS, function () use (&$scheduledExpiry, $scheduleExpiry): void {
                try {
                    $this->pollRenewalProcess();
                    $state = $this->store->load();
                    if ($state['rejected']) {
                        \localzet\Server::requestShutdown(self::EXIT_LICENSE_REQUIRED, 'License API rejected this activation.');
                        return;
                    }
                    $lease = $this->verifiedLease($state);
                    if ($lease->expiresAt() <= time()) {
                        \localzet\Server::requestShutdown(self::EXIT_LICENSE_REQUIRED, 'The signed license lease expired.');
                        return;
                    }
                    if ($lease->expiresAt() !== $scheduledExpiry) {
                        $scheduleExpiry($lease);
                    }
                    if ($lease->shouldRevalidateAt()) {
                        $this->startRenewalProcess();
                    }
                } catch (Throwable $exception) {
                    \localzet\Server::requestShutdown(self::EXIT_LICENSE_REQUIRED, $exception);
                }
            });
        });
    }

    private function activateFromStdin(): int
    {
        if (stream_isatty(STDIN)) {
            throw new RuntimeException('Provide the license key through stdin so the key is not exposed in shell history or process arguments.');
        }
        $licenseKey = stream_get_contents(STDIN, 258);
        if (!is_string($licenseKey)) {
            throw new RuntimeException('Could not read the license key from stdin.');
        }
        $licenseKey = rtrim($licenseKey, "\r\n");
        if (strlen($licenseKey) < 8 || strlen($licenseKey) > 256 || preg_match('/[\x00-\x1F\x7F]/', $licenseKey) === 1) {
            throw new RuntimeException('The license key has an invalid length or contains control characters.');
        }

        $activation = $this->store->update(function (array &$state): array {
            if ($state['lease'] !== null && !$state['rejected']) {
                throw new RuntimeException('This installation already has an active license.');
            }
            $state['installation_id'] ??= 'install_' . self::base64Url(random_bytes(32));
            $state['pending_idempotency_key'] ??= self::base64Url(random_bytes(32));
            return [
                'installation_id' => $state['installation_id'],
                'idempotency_key' => $state['pending_idempotency_key'],
            ];
        });

        try {
            $response = $this->client->activate(
                $licenseKey,
                $activation['installation_id'],
                $this->config['product_id'],
                $this->config['release_id'],
                $this->config['product_version'],
                $activation['idempotency_key'],
            );
        } finally {
            sodium_memzero($licenseKey);
        }

        $lease = $this->verifyResponseLease($response, $activation['installation_id']);
        $this->store->update(function (array &$state) use ($activation, $response, $lease): void {
            if ($state['installation_id'] !== $activation['installation_id']
                || $state['pending_idempotency_key'] !== $activation['idempotency_key']
            ) {
                throw new RuntimeException('License state changed during activation; retry with the same license key.');
            }
            $state['access_token'] = $response['access_token'];
            $state['lease'] = $response['lease'];
            $state['activation_id'] = $lease->activationId();
            $state['pending_idempotency_key'] = null;
            $state['next_renewal_attempt'] = 0;
            $state['rejected'] = false;
        });

        fwrite(STDOUT, 'License activated. Valid until ' . gmdate('Y-m-d H:i:s', $lease->expiresAt()) . " UTC.\n");
        return 0;
    }

    private function release(): int
    {
        $state = $this->store->load();
        if (!is_string($state['access_token'])) {
            throw new RuntimeException('This installation has no stored activation to release.');
        }
        $this->client->release($state['access_token']);
        $this->store->update(function (array &$current) use ($state): void {
            if (!is_string($current['access_token']) || !hash_equals($state['access_token'], $current['access_token'])) {
                throw new RuntimeException('License state changed during release; inspect the activation before retrying.');
            }
            $current['access_token'] = null;
            $current['lease'] = null;
            $current['activation_id'] = null;
            $current['pending_idempotency_key'] = null;
            $current['next_renewal_attempt'] = 0;
            $current['rejected'] = false;
        });
        fwrite(STDOUT, "License activation released.\n");
        return 0;
    }

    private function renewInBackground(bool $force = false): int
    {
        $state = $this->store->load();
        try {
            $lease = $this->verifiedLease(
                $state,
                allowExpiredForRenewal: true,
                allowReleaseChangeForRenewal: true,
            );
        } catch (Throwable) {
            return self::EXIT_LICENSE_REQUIRED;
        }
        if (!is_string($state['access_token']) || $state['rejected']) {
            return self::EXIT_LICENSE_REQUIRED;
        }
        if (!$force && !$lease->shouldRevalidateAt()) {
            return 0;
        }

        try {
            $response = $this->client->renew(
                $state['access_token'],
                $state['installation_id'],
                $this->config['product_id'],
                $this->config['release_id'],
                $this->config['product_version'],
            );
            $renewedLease = $this->verifyResponseLease($response, $state['installation_id']);
            $this->store->update(function (array &$current) use ($state, $response, $renewedLease): void {
                if ($current['activation_id'] !== $state['activation_id']
                    || !is_string($current['access_token'])
                    || !hash_equals($state['access_token'], $current['access_token'])
                ) {
                    return;
                }
                $current['access_token'] = $response['access_token'];
                $current['lease'] = $response['lease'];
                $current['activation_id'] = $renewedLease->activationId();
                $current['next_renewal_attempt'] = 0;
                $current['rejected'] = false;
            });
            fwrite(STDOUT, 'License renewed. Valid until ' . gmdate('Y-m-d H:i:s', $renewedLease->expiresAt()) . " UTC.\n");
            return 0;
        } catch (LicenseApiException $exception) {
            $this->recordRenewalFailure($state, $exception->isRetryable());
            return $exception->isRetryable() ? self::EXIT_RETRYABLE : self::EXIT_LICENSE_REQUIRED;
        } catch (Throwable) {
            $this->recordRenewalFailure($state, false);
            return self::EXIT_LICENSE_REQUIRED;
        }
    }

    private function startRenewalProcess(): void
    {
        if ($this->renewalProcess !== null) {
            $this->pollRenewalProcess();
            if ($this->renewalProcess !== null) {
                return;
            }
        }
        $claimed = $this->store->update(function (array &$state): bool {
            if (!is_string($state['lease']) || $state['rejected'] || $state['next_renewal_attempt'] > time()) {
                return false;
            }
            $lease = $this->verifiedLease($state);
            if (!$lease->shouldRevalidateAt()) {
                return false;
            }
            $state['next_renewal_attempt'] = time() + self::RENEWAL_RETRY_SECONDS;
            return true;
        }, nonBlocking: true);
        if ($claimed !== true) {
            return;
        }

        try {
            if (!function_exists('proc_open')) {
                throw new RuntimeException('proc_open is required to renew licenses without blocking the server event loop.');
            }
            $script = self::currentExecutable();
            $command = is_executable($script)
                ? [$script, '--license-renew']
                : [PHP_BINARY, $script, '--license-renew'];
            $process = proc_open(
                $command,
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', '/dev/null', 'a'],
                    2 => ['file', '/dev/null', 'a'],
                ],
                $pipes,
                null,
                null,
                ['bypass_shell' => true],
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start the background license renewal process.');
            }
            $this->renewalProcess = $process;
        } catch (Throwable) {
            $this->store->update(function (array &$state): void {
                $state['next_renewal_attempt'] = time() + self::RENEWAL_RETRY_SECONDS;
            });
        }
    }

    private function pollRenewalProcess(): void
    {
        if (!is_resource($this->renewalProcess)) {
            $this->renewalProcess = null;
            return;
        }
        $status = proc_get_status($this->renewalProcess);
        if (!$status['running']) {
            proc_close($this->renewalProcess);
            $this->renewalProcess = null;
        }
    }

    /** @param array<string,mixed> $state */
    private function recordRenewalFailure(array $state, bool $retryable): void
    {
        $this->store->update(function (array &$current) use ($state, $retryable): void {
            if ($current['activation_id'] !== $state['activation_id']
                || !is_string($current['access_token'])
                || !hash_equals($state['access_token'], $current['access_token'])
            ) {
                return;
            }
            $current['next_renewal_attempt'] = $retryable ? time() + self::RENEWAL_RETRY_SECONDS : 0;
            $current['rejected'] = !$retryable;
        });
    }

    /** @param array<string,mixed> $state */
    private function verifiedLease(
        array $state,
        bool $allowExpiredForRenewal = false,
        bool $allowReleaseChangeForRenewal = false,
    ): LicenseLease
    {
        if (!is_string($state['installation_id']) || !is_string($state['lease'])
            || !is_string($state['access_token']) || !is_string($state['activation_id'])
        ) {
            throw new RuntimeException('No valid license activation is stored. Run --license-activate before starting this application.');
        }
        $lease = LicenseLease::verify(
            $state['lease'],
            $this->config['trusted_keys'],
            $this->config['product_id'],
            $this->config['release_id'],
            $state['installation_id'],
            expectedProductVersion: $allowReleaseChangeForRenewal ? null : $this->config['product_version'],
            allowExpiredForRenewal: $allowExpiredForRenewal,
            allowReleaseChangeForRenewal: $allowReleaseChangeForRenewal,
        );
        if (!hash_equals($state['activation_id'], $lease->activationId())) {
            throw new RuntimeException('Stored license activation does not match its signed lease.');
        }
        return $lease;
    }

    /** @param array{access_token:string,lease:string,key_id:string,expires_at:string,renew_after_seconds:int} $response */
    private function verifyResponseLease(array $response, string $installationId): LicenseLease
    {
        $lease = LicenseLease::verify(
            $response['lease'],
            $this->config['trusted_keys'],
            $this->config['product_id'],
            $this->config['release_id'],
            $installationId,
            expectedProductVersion: $this->config['product_version'],
        );
        try {
            $expiresAt = (new DateTimeImmutable($response['expires_at']))->getTimestamp();
        } catch (Throwable $exception) {
            throw new RuntimeException('License API expiry did not match the signed lease.', 0, $exception);
        }
        if ($expiresAt !== $lease->expiresAt()) {
            throw new RuntimeException('License API expiry did not match the signed lease.');
        }
        return $lease;
    }

    private static function currentExecutable(): string
    {
        $candidate = $_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? null);
        if (is_string($candidate) && $candidate !== '') {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_file($resolved)) {
                return $resolved;
            }
        }
        throw new RuntimeException('Could not resolve the running licensed executable.');
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
