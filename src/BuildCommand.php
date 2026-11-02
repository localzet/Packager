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

use Phar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

final class BuildCommand
{
    private const MAX_INPUT_BYTES = 2_147_483_648;

    public function __construct(private readonly int $processTimeoutSeconds = 300)
    {
        if ($processTimeoutSeconds < 1) {
            throw new RuntimeException('Process timeout must be at least one second.');
        }
    }

    /** @return array{binary:string,sha256:string,manifest:string,sbom:string,sbom_sha256:string} */
    public function run(Manifest $manifest): array
    {
        if (!class_exists(Phar::class) || !extension_loaded('phar')) {
            throw new RuntimeException('The PHP Phar extension is required.');
        }
        if (file_exists($manifest->outputPath()) && is_dir($manifest->outputPath())) {
            throw new RuntimeException('Output path must name the executable file.');
        }
        if (file_exists($manifest->outputPath())
            || file_exists($manifest->outputPath() . '.manifest.json')
            || file_exists($manifest->outputPath() . '.spdx.json')
        ) {
            throw new RuntimeException('Refusing to overwrite an existing artifact or its metadata.');
        }
        $this->assertToolchainVersion($manifest);

        $outputDirectory = dirname($manifest->outputPath());
        $temporaryDirectory = $outputDirectory . DIRECTORY_SEPARATOR . '.localzet-packager-' . bin2hex(random_bytes(8));
        if (!mkdir($temporaryDirectory, 0700)) {
            throw new RuntimeException('Cannot create a private temporary build directory.');
        }

        try {
            $pharPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'application.phar';
            $binaryPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'application';
            $runtime = $this->assertMicroRuntime($manifest, $temporaryDirectory . DIRECTORY_SEPARATOR . 'micro-probe');
            $this->createPhar($manifest, $pharPath);
            $this->combine($manifest, $pharPath, $binaryPath);
            if (!is_file($binaryPath) || filesize($binaryPath) === 0) {
                throw new RuntimeException('static-php-cli did not produce an executable.');
            }

            $hash = hash_file('sha256', $binaryPath);
            if (!is_string($hash)) {
                throw new RuntimeException('Cannot calculate artifact SHA-256.');
            }

            $target = $manifest->outputPath();
            $microHash = hash_file('sha256', $manifest->microPath());
            if (!is_string($microHash)) {
                throw new RuntimeException('Cannot calculate micro runtime SHA-256.');
            }
            $metadata = [
                'schema_version' => 1,
                'target' => $manifest->target(),
                'php_version' => $runtime['php'],
                'extensions' => $runtime['extensions'],
                'required_extensions' => $manifest->extensions(),
                'toolchain' => ['static-php-cli' => $manifest->toolchainVersion(), 'process_timeout_seconds' => $this->processTimeoutSeconds],
                'micro_sha256' => $microHash,
                'composer_lock_sha256' => $manifest->composerLockHash(),
                'artifact' => ['name' => basename($target), 'sha256' => $hash],
            ];
            $metadataPath = $target . '.manifest.json';
            $sbomPath = $target . '.spdx.json';
            $metadataTemp = $temporaryDirectory . DIRECTORY_SEPARATOR . 'artifact.manifest.json';
            $sbomTemp = $temporaryDirectory . DIRECTORY_SEPARATOR . 'artifact.spdx.json';
            $sbom = (new SpdxSbom())->encode($manifest, $target, $hash);
            $sbomHash = hash('sha256', $sbom);
            $metadata['sbom'] = ['name' => basename($sbomPath), 'sha256' => $sbomHash, 'format' => 'SPDX-2.3'];
            if (file_put_contents($metadataTemp, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
                throw new RuntimeException('Cannot write artifact metadata.');
            }
            if (file_put_contents($sbomTemp, $sbom, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write SPDX SBOM.');
            }

            if (!rename($sbomTemp, $sbomPath)) {
                throw new RuntimeException("Cannot publish SPDX SBOM to {$sbomPath}.");
            }
            if (!rename($metadataTemp, $metadataPath)) {
                @unlink($sbomPath);
                throw new RuntimeException("Cannot publish artifact metadata to {$metadataPath}.");
            }
            if (!rename($binaryPath, $target)) {
                @unlink($metadataPath);
                @unlink($sbomPath);
                throw new RuntimeException("Cannot publish executable to {$target}.");
            }
            chmod($target, 0755);
            chmod($metadataPath, 0644);
            chmod($sbomPath, 0644);

            return ['binary' => $target, 'sha256' => $hash, 'manifest' => $metadataPath, 'sbom' => $sbomPath, 'sbom_sha256' => $sbomHash];
        } finally {
            $this->removeTree($temporaryDirectory);
        }
    }

    private function createPhar(Manifest $manifest, string $pharPath): void
    {
        ini_set('phar.readonly', '0');
        try {
            $phar = new Phar($pharPath, 0, 'application.phar');
            $phar->startBuffering();
            $root = $manifest->projectRoot();
            $totalBytes = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo) {
                    continue;
                }
                if ($file->isLink()) {
                    throw new RuntimeException('Symlinks are not supported in packaged projects.');
                }
                if ($file->isDir()) {
                    continue;
                }
                if (!$file->isFile()) {
                    throw new RuntimeException('Only regular files can be included in a package.');
                }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
                if ($this->isExcluded($relative)) {
                    continue;
                }
                $realFile = realpath($file->getPathname());
                if ($realFile === false || !str_starts_with($realFile, $root . DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException("Project file resolves outside project root: {$relative}");
                }
                $totalBytes += $file->getSize();
                if ($totalBytes > self::MAX_INPUT_BYTES) {
                    throw new RuntimeException('Project files exceed the 2 GiB packaging limit.');
                }
                $phar->addFile($realFile, $relative);
            }

            $entrypoint = str_replace(DIRECTORY_SEPARATOR, '/', substr($manifest->entrypoint(), strlen($root) + 1));
            if (!$phar->offsetExists($entrypoint)) {
                throw new RuntimeException('Project entrypoint was excluded from the PHAR.');
            }
            $phar->setStub($phar->createDefaultStub($entrypoint));
            $phar->stopBuffering();
        } catch (Throwable $exception) {
            @unlink($pharPath);
            throw $exception;
        }
    }

    private function combine(Manifest $manifest, string $pharPath, string $binaryPath): void
    {
        $command = [
            $manifest->spcPath(),
            'micro:combine',
            '--with-micro=' . $manifest->microPath(),
            '--output=' . $binaryPath,
            $pharPath,
        ];
        [$exitCode, $stdout, $stderr] = $this->runProcess($command, 'static-php-cli build');
        if ($exitCode !== 0) {
            throw new RuntimeException("static-php-cli failed ({$exitCode}): " . trim((string)$stderr . "\n" . (string)$stdout));
        }
    }

    private function assertToolchainVersion(Manifest $manifest): void
    {
        [$exitCode, $stdout, $stderr] = $this->runProcess([$manifest->spcPath(), '--version'], 'static-php-cli version check');
        $output = trim($stdout . ' ' . $stderr);
        if ($exitCode !== 0
            || preg_match('/static-php-cli\s+(\d+\.\d+\.\d+)/', $output, $match) !== 1
            || $match[1] !== $manifest->toolchainVersion()
        ) {
            throw new RuntimeException("static-php-cli version mismatch: expected {$manifest->toolchainVersion()}, got " . ($output !== '' ? $output : 'unknown'));
        }
    }

    /** @return array{php:string,extensions:list<string>} */
    private function assertMicroRuntime(Manifest $manifest, string $probePath): array
    {
        if (!copy($manifest->microPath(), $probePath)
            || file_put_contents($probePath, "\n<?php echo 'LOCALZET_PACKAGER_RUNTIME=' . json_encode(['php' => PHP_VERSION, 'extensions' => get_loaded_extensions()], JSON_THROW_ON_ERROR); ?>\n", FILE_APPEND) === false
        ) {
            throw new RuntimeException('Cannot prepare the micro runtime capability probe.');
        }
        chmod($probePath, 0700);

        [$exitCode, $stdout, $stderr] = $this->runProcess([$probePath], 'micro runtime capability probe');
        if ($exitCode !== 0 || preg_match('/^LOCALZET_PACKAGER_RUNTIME=(\{.*\})$/m', $stdout, $match) !== 1) {
            throw new RuntimeException('The micro runtime did not return capability data: ' . trim($stderr . "\n" . $stdout));
        }

        $runtime = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR);
        if (($runtime['php'] ?? null) !== $manifest->phpVersion()) {
            throw new RuntimeException('Micro runtime PHP version mismatch: expected ' . $manifest->phpVersion() . ', got ' . ($runtime['php'] ?? 'unknown'));
        }
        if (!is_array($runtime['extensions'] ?? null)) {
            throw new RuntimeException('The micro runtime returned an invalid extension list.');
        }
        $loadedExtensions = array_map('strtolower', $runtime['extensions']);
        $missingExtensions = array_values(array_diff(array_map('strtolower', $manifest->extensions()), $loadedExtensions));
        if ($missingExtensions !== []) {
            throw new RuntimeException('Micro runtime is missing declared extensions: ' . implode(', ', $missingExtensions));
        }
        return [
            'php' => (string)$runtime['php'],
            'extensions' => array_values($runtime['extensions']),
        ];
    }

    /** @param list<string> $command @return array{int,string,string} */
    private function runProcess(array $command, string $label): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Cannot start {$label}.");
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->processTimeoutSeconds;
        try {
            while (true) {
                $stdout .= (string)stream_get_contents($pipes[1]);
                $stderr .= (string)stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $stdout .= (string)stream_get_contents($pipes[1]);
                    $stderr .= (string)stream_get_contents($pipes[2]);
                    return [(int)$status['exitcode'], $stdout, $stderr];
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process, 15);
                    usleep(100_000);
                    $status = proc_get_status($process);
                    if ($status['running']) {
                        proc_terminate($process, 9);
                    }
                    throw new RuntimeException("{$label} timed out after {$this->processTimeoutSeconds} seconds.");
                }
                usleep(20_000);
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    private function isExcluded(string $path): bool
    {
        $parts = explode('/', $path);
        if (array_intersect($parts, ['.git', '.idea', '.vscode', 'node_modules', '.svn'])) {
            return true;
        }
        $name = basename($path);
        return $name === '.env'
            || str_starts_with($name, '.env.')
            || in_array($name, ['auth.json', 'composer-auth.json'], true);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
