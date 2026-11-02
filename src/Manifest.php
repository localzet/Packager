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

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class Manifest
{
    private string $manifestPath;
    private string $projectRoot;
    private string $entrypoint;
    private string $target;
    private string $phpVersion;
    /** @var list<string> */
    private array $extensions;
    private string $spcPath;
    private string $microPath;
    private string $toolchainVersion;
    private string $outputPath;
    private ?string $composerLockHash;

    /** @param list<string> $extensions */
    private function __construct(
        string $manifestPath,
        string $projectRoot,
        string $entrypoint,
        string $target,
        string $phpVersion,
        array $extensions,
        string $spcPath,
        string $microPath,
        string $toolchainVersion,
        string $outputPath,
        ?string $composerLockHash,
    ) {
        $this->manifestPath = $manifestPath;
        $this->projectRoot = $projectRoot;
        $this->entrypoint = $entrypoint;
        $this->target = $target;
        $this->phpVersion = $phpVersion;
        $this->extensions = $extensions;
        $this->spcPath = $spcPath;
        $this->microPath = $microPath;
        $this->toolchainVersion = $toolchainVersion;
        $this->outputPath = $outputPath;
        $this->composerLockHash = $composerLockHash;
    }

    public static function load(string $path): self
    {
        $manifestPath = realpath($path);
        if ($manifestPath === false || !is_file($manifestPath)) {
            throw new RuntimeException("Manifest does not exist: {$path}");
        }

        try {
            $data = json_decode((string)file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Manifest must contain valid JSON.', previous: $exception);
        }

        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Manifest schema_version must be 1.');
        }

        $project = $data['project'] ?? null;
        $toolchain = $data['toolchain'] ?? null;
        if (!is_array($project) || !is_array($toolchain)) {
            throw new InvalidArgumentException('Manifest requires project and toolchain objects.');
        }

        $manifestDir = dirname($manifestPath);
        $projectRoot = self::resolveDirectory($manifestDir, self::requiredString($project, 'root'));
        $entrypoint = self::resolveFile($projectRoot, self::requiredString($project, 'entrypoint'));
        if (!self::isWithin($entrypoint, $projectRoot)) {
            throw new InvalidArgumentException('Project entrypoint must stay inside project.root.');
        }

        $target = self::requiredString($data, 'target');
        if ($target !== 'linux-x86_64') {
            throw new InvalidArgumentException('This build currently supports only linux-x86_64.');
        }

        $extensions = $data['extensions'] ?? null;
        if (!is_array($extensions) || !array_is_list($extensions) || $extensions === []) {
            throw new InvalidArgumentException('extensions must be a non-empty list of extension names.');
        }
        foreach ($extensions as $extension) {
            if (!is_string($extension) || preg_match('/^[a-z][a-z0-9_]*$/i', $extension) !== 1) {
                throw new InvalidArgumentException('Each extension must be a PHP extension name.');
            }
        }

        $spcPath = self::resolveFile($manifestDir, self::requiredString($toolchain, 'spc'));
        $microPath = self::resolveFile($manifestDir, self::requiredString($toolchain, 'micro'));
        if (!is_executable($spcPath)) {
            throw new InvalidArgumentException('toolchain.spc must be executable.');
        }
        if (!is_executable($microPath)) {
            throw new InvalidArgumentException('toolchain.micro must be executable.');
        }
        $toolchainVersion = self::requiredString($toolchain, 'version');
        $phpVersion = self::requiredString($data, 'php_version');
        if (preg_match('/^\d+\.\d+\.\d+$/', $phpVersion) !== 1) {
            throw new InvalidArgumentException('php_version must use an exact major.minor.patch version.');
        }
        $composerLockHash = self::validateComposerProject($projectRoot);
        $outputPath = self::resolveOutput($manifestDir, self::requiredString($data, 'output'));
        if (self::isWithin($outputPath, $projectRoot)) {
            throw new InvalidArgumentException('Build output must be outside the packaged project tree.');
        }

        return new self(
            $manifestPath,
            $projectRoot,
            $entrypoint,
            $target,
            $phpVersion,
            array_values(array_unique($extensions)),
            $spcPath,
            $microPath,
            $toolchainVersion,
            $outputPath,
            $composerLockHash,
        );
    }

    public function manifestPath(): string { return $this->manifestPath; }
    public function projectRoot(): string { return $this->projectRoot; }
    public function entrypoint(): string { return $this->entrypoint; }
    public function target(): string { return $this->target; }
    public function phpVersion(): string { return $this->phpVersion; }
    /** @return list<string> */
    public function extensions(): array { return $this->extensions; }
    public function spcPath(): string { return $this->spcPath; }
    public function microPath(): string { return $this->microPath; }
    public function toolchainVersion(): string { return $this->toolchainVersion; }
    public function outputPath(): string { return $this->outputPath; }
    public function composerLockHash(): ?string { return $this->composerLockHash; }

    private static function validateComposerProject(string $root): ?string
    {
        $composerJson = $root . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerJson)) {
            return null;
        }
        $lockPath = $root . DIRECTORY_SEPARATOR . 'composer.lock';
        $autoloadPath = $root . DIRECTORY_SEPARATOR . 'vendor/autoload.php';
        $installedPath = $root . DIRECTORY_SEPARATOR . 'vendor/composer/installed.json';
        if (!is_file($lockPath) || !is_file($autoloadPath) || !is_file($installedPath)) {
            throw new InvalidArgumentException('Composer projects need composer.lock and an installed vendor tree.');
        }
        try {
            $projectConfig = json_decode((string)file_get_contents($composerJson), true, 64, JSON_THROW_ON_ERROR);
            $lock = json_decode((string)file_get_contents($lockPath), true, 64, JSON_THROW_ON_ERROR);
            $installed = json_decode((string)file_get_contents($installedPath), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Composer lock and installed package metadata must be valid JSON.', previous: $exception);
        }
        if (!is_array($projectConfig) || !is_array($lock) || !is_array($installed)) {
            throw new InvalidArgumentException('Composer lock and installed package metadata must be JSON objects.');
        }
        if (is_array($installed) && array_key_exists('dev', $installed) && $installed['dev'] === true) {
            throw new InvalidArgumentException('Vendor tree was installed with development dependencies; use a production Composer install.');
        }
        if (isset($installed['dev']) && $installed['dev'] !== false) {
            throw new InvalidArgumentException('Composer installed metadata must confirm a production dependency install.');
        }
        $lockedPackages = $lock['packages'] ?? null;
        $installedPackages = $installed['packages'] ?? $installed;
        if (!is_array($lockedPackages) || !array_is_list($lockedPackages)
            || !is_array($installedPackages) || !array_is_list($installedPackages)
        ) {
            throw new InvalidArgumentException('Composer lock and installed package lists must be valid lists.');
        }
        $lockedByName = self::indexPackages($lockedPackages, 'composer.lock');
        $installedByName = self::indexPackages($installedPackages, 'installed vendor tree');
        foreach ($installedByName as $name => $package) {
            if (($package['dev_requirement'] ?? false) === true) {
                throw new InvalidArgumentException('Vendor tree contains development dependencies; install production dependencies before packaging.');
            }
            if (!isset($lockedByName[$name])) {
                throw new InvalidArgumentException("Installed Composer package is missing from composer.lock: {$name}");
            }
            $lockedVersion = self::normalizePackageVersion($lockedByName[$name]['version']);
            $installedVersion = self::normalizePackageVersion($package['version'] ?? $package['pretty_version'] ?? '');
            if ($installedVersion === '' || $installedVersion !== $lockedVersion) {
                throw new InvalidArgumentException("Installed Composer package version does not match composer.lock: {$name}");
            }
            $lockedReference = self::packageReference($lockedByName[$name]);
            $installedReference = self::packageReference($package);
            if (is_string($lockedReference) && $lockedReference !== ''
                && is_string($installedReference) && $installedReference !== ''
                && $lockedReference !== $installedReference
            ) {
                throw new InvalidArgumentException("Installed Composer package reference does not match composer.lock: {$name}");
            }
        }
        if (array_diff_key($lockedByName, $installedByName) !== []) {
            $missing = array_key_first(array_diff_key($lockedByName, $installedByName));
            throw new InvalidArgumentException("Locked Composer package is missing from the installed vendor tree: {$missing}");
        }
        return hash_file('sha256', $lockPath) ?: throw new RuntimeException('Cannot hash composer.lock.');
    }

    /** @param list<mixed> $packages @return array<string, array<string, mixed>> */
    private static function indexPackages(array $packages, string $source): array
    {
        $indexed = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null) || !is_string($package['version'] ?? null)) {
                throw new InvalidArgumentException("Invalid package entry in {$source}.");
            }
            $name = strtolower($package['name']);
            if (isset($indexed[$name])) {
                throw new InvalidArgumentException("Duplicate Composer package in {$source}: {$name}");
            }
            $indexed[$name] = $package;
        }
        return $indexed;
    }

    private static function normalizePackageVersion(string $version): string
    {
        return strtolower(ltrim(trim($version), 'vV'));
    }

    /** @param array<string, mixed> $package */
    private static function packageReference(array $package): ?string
    {
        foreach (['source', 'dist'] as $origin) {
            $reference = $package[$origin]['reference'] ?? null;
            if (is_string($reference) && $reference !== '') {
                return $reference;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Manifest field '{$key}' must be a non-empty string.");
        }
        return $value;
    }

    private static function resolveDirectory(string $base, string $path): string
    {
        $resolved = realpath(self::join($base, $path));
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException("Project directory does not exist: {$path}");
        }
        return $resolved;
    }

    private static function resolveFile(string $base, string $path): string
    {
        $resolved = realpath(self::join($base, $path));
        if ($resolved === false || !is_file($resolved)) {
            throw new InvalidArgumentException("Required file does not exist: {$path}");
        }
        return $resolved;
    }

    private static function resolveOutput(string $base, string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Output path contains an invalid byte.');
        }
        $output = self::join($base, $path);
        $parent = realpath(dirname($output));
        if ($parent === false || !is_dir($parent)) {
            throw new InvalidArgumentException('Output parent directory must exist.');
        }
        return $parent . DIRECTORY_SEPARATOR . basename($output);
    }

    private static function join(string $base, string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Manifest paths must be non-empty.');
        }
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : $base . DIRECTORY_SEPARATOR . $path;
    }

    private static function isWithin(string $path, string $directory): bool
    {
        $prefix = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return $path === $directory || str_starts_with($path, $prefix);
    }
}
