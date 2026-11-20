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

final class LicenseStore
{
    private const STATE_FILE = 'license-state.json';
    private const LOCK_FILE = 'license-state.lock';

    public function __construct(private readonly string $directory)
    {
        if (!str_starts_with($directory, DIRECTORY_SEPARATOR) || str_contains($directory, "\0")) {
            throw new RuntimeException('License state directory must be an absolute path.');
        }
    }

    /** @param array{product_id:string} $config */
    public static function forProduct(array $config): self
    {
        $base = getenv('LOCALZET_LICENSE_DIR');
        if (!is_string($base) || $base === '') {
            $base = getenv('XDG_STATE_HOME');
        }
        if (!is_string($base) || $base === '') {
            $home = getenv('HOME');
            if (!is_string($home) || $home === '') {
                throw new RuntimeException('Set LOCALZET_LICENSE_DIR or HOME to store the license state securely.');
            }
            $base = rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.local' . DIRECTORY_SEPARATOR . 'state';
        }
        if (!str_starts_with($base, DIRECTORY_SEPARATOR) || str_contains($base, "\0")) {
            throw new RuntimeException('License state directory must be an absolute path.');
        }

        return new self(rtrim($base, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'localzet-license'
            . DIRECTORY_SEPARATOR . $config['product_id']);
    }

    /** @return array{version:int,installation_id:?string,access_token:?string,lease:?string,activation_id:?string,pending_idempotency_key:?string,next_renewal_attempt:int,rejected:bool} */
    public function load(): array
    {
        if (!file_exists($this->directory) && !is_link($this->directory)) {
            return self::emptyState();
        }
        self::ensurePrivateDirectory($this->directory);

        return $this->withLock(LOCK_SH, fn (): array => $this->readState());
    }

    /** @template T @param callable(array<string,mixed>&):T $operation @return T */
    public function update(callable $operation, bool $nonBlocking = false): mixed
    {
        self::ensurePrivateDirectory($this->directory);
        $lock = $this->openLock();
        $operationMode = LOCK_EX | ($nonBlocking ? LOCK_NB : 0);
        if (!flock($lock, $operationMode)) {
            fclose($lock);
            return null;
        }

        try {
            $state = $this->readState();
            $result = $operation($state);
            $this->writeState($state);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** @return array{version:int,installation_id:?string,access_token:?string,lease:?string,activation_id:?string,pending_idempotency_key:?string,next_renewal_attempt:int,rejected:bool} */
    private function readState(): array
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . self::STATE_FILE;
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            return self::emptyState();
        }
        self::assertOwnedRegularFile($path, 'License state');
        if ((fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('License state file permissions must be 0600 or stricter.');
        }

        try {
            $state = json_decode((string)file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('License state file is invalid.', 0, $exception);
        }

        if (!is_array($state) || array_is_list($state) || !self::hasExactKeys($state, [
            'version', 'installation_id', 'access_token', 'lease', 'activation_id',
            'pending_idempotency_key', 'next_renewal_attempt', 'rejected',
        ]) || $state['version'] !== 1
            || !self::isNullableString($state['installation_id'], 16, 128)
            || !self::isNullableString($state['access_token'], 8, 128)
            || !self::isNullableString($state['lease'], 16, 16_384)
            || !self::isNullableString($state['activation_id'], 8, 128)
            || !self::isNullableString($state['pending_idempotency_key'], 22, 128)
            || !is_int($state['next_renewal_attempt']) || $state['next_renewal_attempt'] < 0
            || !is_bool($state['rejected'])
        ) {
            throw new RuntimeException('License state file has an unsupported structure.');
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . self::STATE_FILE;
        if (is_link($path)) {
            throw new RuntimeException('License state file must not be a symlink.');
        }
        $this->assertDirectoryOwner();
        try {
            $bytes = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode license state.', 0, $exception);
        }

        $temporary = tempnam($this->directory, '.license-state-');
        if (!is_string($temporary)) {
            throw new RuntimeException('Could not create a temporary license state file.');
        }
        try {
            $handle = fopen($temporary, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Could not open the temporary license state file.');
            }
            try {
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($handle, substr($bytes, $offset));
                    if (!is_int($written) || $written < 1) {
                        throw new RuntimeException('Could not write the license state file.');
                    }
                    $offset += $written;
                }
                if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                    throw new RuntimeException('Could not flush the license state file.');
                }
            } finally {
                fclose($handle);
            }
            if (!chmod($temporary, 0600) || !rename($temporary, $path)) {
                throw new RuntimeException('Could not atomically publish the license state file.');
            }
            $directoryOwner = fileowner($this->directory);
            if (function_exists('posix_geteuid') && posix_geteuid() === 0 && is_int($directoryOwner)
                && fileowner($path) !== $directoryOwner
            ) {
                $directoryGroup = filegroup($this->directory);
                if (!chown($path, $directoryOwner)
                    || (is_int($directoryGroup) && !chgrp($path, $directoryGroup))
                    || !chmod($path, 0600)
                ) {
                    throw new RuntimeException('Could not preserve the license state owner.');
                }
            }
            clearstatcache(true, $path);
            self::assertOwnedRegularFile($path, 'License state');
            if ((fileperms($path) & 0077) !== 0) {
                throw new RuntimeException('License state file permissions must be 0600 or stricter.');
            }
        } finally {
            if (is_file($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @template T @param callable():T $operation @return T */
    private function withLock(int $mode, callable $operation): mixed
    {
        $lock = $this->openLock();
        if (!flock($lock, $mode)) {
            fclose($lock);
            throw new RuntimeException('Could not lock the license state.');
        }
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource */
    private function openLock()
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        if (is_link($path)) {
            throw new RuntimeException('License state lock must not be a symlink.');
        }
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open the license state lock.');
        }
        if (!chmod($path, 0600)) {
            fclose($handle);
            throw new RuntimeException('Could not protect the license state lock.');
        }
        $directoryOwner = fileowner($this->directory);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && is_int($directoryOwner)
            && fileowner($path) !== $directoryOwner
        ) {
            $directoryGroup = filegroup($this->directory);
            if (!chown($path, $directoryOwner)
                || (is_int($directoryGroup) && !chgrp($path, $directoryGroup))
                || !chmod($path, 0600)
            ) {
                fclose($handle);
                throw new RuntimeException('Could not preserve the license state lock owner.');
            }
        }
        self::assertOwnedRegularFile($path, 'License state lock');
        return $handle;
    }

    private function assertDirectoryOwner(): void
    {
        clearstatcache(true, $this->directory);
        if (is_link($this->directory) || !is_dir($this->directory)
            || (fileperms($this->directory) & 0077) !== 0
            || !function_exists('posix_geteuid')
            || (posix_geteuid() !== 0 && fileowner($this->directory) !== posix_geteuid())
        ) {
            throw new RuntimeException('License state directory must be owned by this user and have mode 0700.');
        }
    }

    private static function assertOwnedRegularFile(string $path, string $label): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)
            || !function_exists('posix_geteuid')
            || (posix_geteuid() !== 0 && fileowner($path) !== posix_geteuid())
        ) {
            throw new RuntimeException($label . ' must be a regular file owned by this user.');
        }
    }

    private static function ensurePrivateDirectory(string $directory): void
    {
        if (!str_starts_with($directory, DIRECTORY_SEPARATOR) || str_contains($directory, "\0")) {
            throw new RuntimeException('License state directory must be an absolute path.');
        }
        $current = DIRECTORY_SEPARATOR;
        $parts = explode(DIRECTORY_SEPARATOR, trim($directory, DIRECTORY_SEPARATOR));
        foreach ($parts as $index => $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('License state directory contains an unsafe path segment.');
            }
            $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new RuntimeException('License state path must not contain symlinks.');
            }
            if (!is_dir($current) && !mkdir($current, 0700)) {
                throw new RuntimeException('Could not create the license state directory.');
            }
            $realPath = realpath($current);
            if ($realPath === false || $realPath !== $current) {
                throw new RuntimeException('License state path must use canonical directories.');
            }
            if ($index === array_key_last($parts)) {
                if (!chmod($current, 0700) || !function_exists('posix_geteuid')
                    || (posix_geteuid() !== 0 && fileowner($current) !== posix_geteuid())
                ) {
                    throw new RuntimeException('License state directory must be owned by this user and have mode 0700.');
                }
            }
        }
        if ($parts === []) {
            throw new RuntimeException('License state directory must be owned by this user and have mode 0700.');
        }
    }

    /** @return array{version:int,installation_id:?string,access_token:?string,lease:?string,activation_id:?string,pending_idempotency_key:?string,next_renewal_attempt:int,rejected:bool} */
    private static function emptyState(): array
    {
        return [
            'version' => 1,
            'installation_id' => null,
            'access_token' => null,
            'lease' => null,
            'activation_id' => null,
            'pending_idempotency_key' => null,
            'next_renewal_attempt' => 0,
            'rejected' => false,
        ];
    }

    private static function isNullableString(mixed $value, int $minimum, int $maximum): bool
    {
        return $value === null || (is_string($value) && strlen($value) >= $minimum && strlen($value) <= $maximum);
    }

    /** @param array<string,mixed> $data @param list<string> $expected */
    private static function hasExactKeys(array $data, array $expected): bool
    {
        $actual = array_keys($data);
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }
}
