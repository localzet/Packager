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

require dirname(__DIR__) . '/vendor/autoload.php';

use Localzet\Packager\ArtifactSignature;
use Localzet\Packager\BuildCommand;
use Localzet\Packager\Manifest;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $arguments @return array{int,string} */
function runCli(array $arguments): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/localzet-packager', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start Packager CLI test process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string)$stdout . (string)$stderr];
}

$root = sys_get_temp_dir() . '/localzet-packager-test-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
mkdir($root . '/project', 0700);
mkdir($root . '/project/vendor/composer', 0700, true);
mkdir($root . '/output', 0700);
file_put_contents($root . '/project/server.php', "<?php echo 'packaged-ok';\n");
file_put_contents($root . '/project/.env', "SECRET=must-not-be-packaged\n");
file_put_contents($root . '/project/composer.json', '{"name":"fixture/application","require":{"fixture/runtime":"1.0.0"}}');
file_put_contents($root . '/project/composer.lock', '{"packages":[{"name":"fixture/runtime","version":"1.0.0","require":{"fixture/transitive":"^1.0"}},{"name":"fixture/transitive","version":"1.2.0"}],"packages-dev":[]}');
file_put_contents($root . '/project/vendor/autoload.php', "<?php\n");
file_put_contents($root . '/project/vendor/composer/installed.json', '{"dev":false,"packages":[{"name":"fixture/runtime","version":"1.0.0","dev_requirement":false},{"name":"fixture/transitive","version":"1.2.0","dev_requirement":false}]}');
$fakeRuntime = json_encode(['php' => PHP_VERSION, 'extensions' => ['phar', 'pcntl', 'posix', 'sockets']], JSON_THROW_ON_ERROR);
file_put_contents($root . '/micro.sfx', "#!/bin/sh\necho 'LOCALZET_PACKAGER_RUNTIME={$fakeRuntime}'\nexit 0\n");
chmod($root . '/micro.sfx', 0700);
file_put_contents($root . '/fake-spc', <<<'PHP'
#!/usr/bin/env php
<?php
if (($argv[1] ?? '') === '--version') {
    echo "static-php-cli 2.8.6\n";
    exit(0);
}
$output = '';
$input = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) $output = substr($argument, 9);
    if (str_ends_with($argument, '.phar')) $input = $argument;
}
if ($output === '' || $input === '') exit(2);
copy($input, $output);
PHP
);
chmod($root . '/fake-spc', 0700);
$manifest = [
    'schema_version' => 1,
    'project' => ['root' => 'project', 'entrypoint' => 'server.php'],
    'target' => 'linux-x86_64',
    'php_version' => PHP_VERSION,
    'extensions' => ['phar', 'pcntl', 'posix', 'sockets'],
    'toolchain' => ['spc' => 'fake-spc', 'micro' => 'micro.sfx', 'version' => '2.8.6'],
    'output' => 'output/example.phar',
];
file_put_contents($root . '/packager.json', json_encode($manifest, JSON_THROW_ON_ERROR));

try {
    $loaded = Manifest::load($root . '/packager.json');
    expect($loaded->target() === 'linux-x86_64', 'Manifest target was not loaded.');
    $result = (new BuildCommand())->run($loaded);
    expect(is_executable($result['binary']), 'Builder did not produce an executable artifact.');
    expect(hash_file('sha256', $result['binary']) === $result['sha256'], 'Artifact SHA-256 does not match.');
    $metadata = json_decode((string)file_get_contents($result['manifest']), true, 16, JSON_THROW_ON_ERROR);
    expect($metadata['artifact']['sha256'] === $result['sha256'], 'Artifact manifest hash does not match.');
    expect($metadata['composer_lock_sha256'] === hash_file('sha256', $root . '/project/composer.lock'), 'Composer lockfile hash was not recorded.');
    expect(is_file($result['sbom']), 'Builder did not produce an SPDX SBOM.');
    expect(hash_file('sha256', $result['sbom']) === $metadata['sbom']['sha256'], 'SBOM SHA-256 does not match artifact metadata.');
    $sbom = json_decode((string)file_get_contents($result['sbom']), true, 32, JSON_THROW_ON_ERROR);
    expect($sbom['spdxVersion'] === 'SPDX-2.3', 'SBOM uses an unexpected SPDX version.');
    expect($sbom['documentDescribes'] === ['SPDXRef-Application'], 'SBOM does not identify its described application package.');
    expect($sbom['packages'][1]['externalRefs'][0]['referenceLocator'] === 'pkg:composer/fixture/runtime@1.0.0', 'SBOM is missing the Composer package URL.');
    expect(in_array(['spdxElementId' => 'SPDXRef-Application', 'relationshipType' => 'DEPENDS_ON', 'relatedSpdxElement' => $sbom['packages'][1]['SPDXID']], $sbom['relationships'], true), 'SBOM is missing the application dependency relationship.');
    expect(in_array(['spdxElementId' => $sbom['packages'][1]['SPDXID'], 'relationshipType' => 'DEPENDS_ON', 'relatedSpdxElement' => $sbom['packages'][2]['SPDXID']], $sbom['relationships'], true), 'SBOM is missing the transitive dependency relationship.');

    $signatures = new ArtifactSignature();
    $privateKey = $root . '/release-private.key';
    $publicKey = $root . '/release-public.key';
    $keyId = $signatures->generateKeypair($privateKey, $publicKey);
    expect((fileperms($privateKey) & 0077) === 0, 'Generated private key is accessible to group or other users.');
    expect($keyId === hash('sha256', base64_decode(trim((string)file_get_contents($publicKey)), true)), 'Generated key ID does not match public key.');
    $signaturePath = $signatures->sign($result['binary'], $privateKey);
    expect($signaturePath === $result['binary'] . '.sig.json', 'Signature path has an unexpected name.');
    $signatures->verify($result['binary'], $publicKey);
    $artifactBytes = file_get_contents($result['binary']);
    file_put_contents($result['binary'], (string)$artifactBytes . "tampered");
    try {
        $signatures->verify($result['binary'], $publicKey);
        throw new RuntimeException('Signature verification accepted a modified executable.');
    } catch (RuntimeException $exception) {
        expect(str_contains($exception->getMessage(), 'artifact_sha256'), 'Artifact tampering failed for an unexpected reason.');
    }
    file_put_contents($result['binary'], (string)$artifactBytes);
    $sbomBytes = file_get_contents($result['sbom']);
    file_put_contents($result['sbom'], (string)$sbomBytes . "tampered");
    try {
        $signatures->verify($result['binary'], $publicKey);
        throw new RuntimeException('Signature verification accepted a modified SBOM.');
    } catch (RuntimeException $exception) {
        expect(str_contains($exception->getMessage(), 'sbom_sha256'), 'SBOM tampering failed for an unexpected reason.');
    }
    file_put_contents($result['sbom'], (string)$sbomBytes);
    $metadataBytes = file_get_contents($result['manifest']);
    file_put_contents($result['manifest'], (string)$metadataBytes . "tampered");
    try {
        $signatures->verify($result['binary'], $publicKey);
        throw new RuntimeException('Signature verification accepted modified metadata.');
    } catch (RuntimeException $exception) {
        expect(str_contains($exception->getMessage(), 'metadata_sha256'), 'Metadata tampering failed for an unexpected reason.');
    }
    file_put_contents($result['manifest'], (string)$metadataBytes);
    $wrongPair = sodium_crypto_sign_keypair();
    file_put_contents($root . '/wrong-public.key', base64_encode(sodium_crypto_sign_publickey($wrongPair)) . "\n");
    try {
        $signatures->verify($result['binary'], $root . '/wrong-public.key');
        throw new RuntimeException('Signature verification accepted an untrusted public key.');
    } catch (RuntimeException $exception) {
        expect(str_contains($exception->getMessage(), 'key_id'), 'Wrong public key failed for an unexpected reason.');
    }
    try {
        $signatures->sign($result['binary'], $privateKey);
        throw new RuntimeException('Signer overwrote an existing signature.');
    } catch (RuntimeException $exception) {
        expect(str_contains($exception->getMessage(), 'Refusing to overwrite'), 'Existing signature failed for an unexpected reason.');
    }
    $cliArtifact = $root . '/output/cli-artifact';
    copy($result['binary'], $cliArtifact);
    copy($result['manifest'], $cliArtifact . '.manifest.json');
    copy($result['sbom'], $cliArtifact . '.spdx.json');
    [$keygenExit, $keygenOutput] = runCli(['keygen', $root . '/cli-private.key', $root . '/cli-public.key']);
    expect($keygenExit === 0 && str_contains($keygenOutput, 'Key ID'), 'CLI key generation failed.');
    [$signExit, $signOutput] = runCli(['sign', $cliArtifact, $root . '/cli-private.key']);
    expect($signExit === 0 && str_contains($signOutput, '.sig.json'), 'CLI artifact signing failed.');
    [$verifyExit, $verifyOutput] = runCli(['verify', $cliArtifact, $root . '/cli-public.key']);
    expect($verifyExit === 0 && str_contains($verifyOutput, 'Verified artifact signature'), 'CLI artifact verification failed.');

    $phar = new Phar($result['binary']);
    expect($phar->offsetExists('server.php'), 'Packaged archive is missing its entrypoint.');
    expect(!$phar->offsetExists('.env'), 'Packaged archive unexpectedly contains environment secrets.');
    expect(str_contains($phar->getStub(), 'server.php'), 'Packaged archive stub does not launch its entrypoint.');
    $run = proc_open([PHP_BINARY, $result['binary']], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($run) === 0 && trim((string)$stdout) === 'packaged-ok', 'Packaged PHAR did not execute: ' . (string)$stderr);

    $manifest['extensions'][] = 'ctype';
    $manifest['output'] = 'output/missing-extension';
    file_put_contents($root . '/packager.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    try {
        (new BuildCommand())->run(Manifest::load($root . '/packager.json'));
        throw new RuntimeException('Builder accepted a runtime missing a declared extension.');
    } catch (RuntimeException $exception) {
        expect(str_contains($exception->getMessage(), 'missing declared extensions'), 'Builder failed for an unexpected reason.');
    }

    file_put_contents($root . '/project/vendor/composer/installed.json', '{"dev":false,"packages":[{"name":"fixture/runtime","version":"2.0.0","dev_requirement":false},{"name":"fixture/transitive","version":"1.2.0","dev_requirement":false}]}');
    try {
        Manifest::load($root . '/packager.json');
        throw new RuntimeException('Manifest accepted an installed package version that differs from composer.lock.');
    } catch (InvalidArgumentException $exception) {
        expect(str_contains($exception->getMessage(), 'does not match composer.lock'), 'Composer lock mismatch failed for an unexpected reason.');
    }
    file_put_contents($root . '/project/vendor/composer/installed.json', '{"dev":false,"packages":[{"name":"fixture/runtime","version":"1.0.0","dev_requirement":false},{"name":"fixture/transitive","version":"1.2.0","dev_requirement":false}]}');

    file_put_contents($root . '/slow-spc', "#!/usr/bin/env php\n<?php usleep(5_000_000);\n");
    chmod($root . '/slow-spc', 0700);
    $manifest['toolchain']['spc'] = 'slow-spc';
    file_put_contents($root . '/packager.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    try {
        (new BuildCommand(1))->run(Manifest::load($root . '/packager.json'));
        throw new RuntimeException('Builder did not stop a hung toolchain process.');
    } catch (RuntimeException $exception) {
        expect(str_contains($exception->getMessage(), 'timed out after 1 seconds'), 'Subprocess timeout failed for an unexpected reason.');
    }
    $manifest['toolchain']['spc'] = 'fake-spc';

    $manifest['project']['entrypoint'] = '../outside.php';
    file_put_contents($root . '/packager.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    try {
        Manifest::load($root . '/packager.json');
        throw new RuntimeException('Manifest accepted an entrypoint outside the project root.');
    } catch (InvalidArgumentException) {
    }

    file_put_contents($root . '/project/vendor/composer/installed.json', '{"dev":false,"packages":[{"name":"fixture/dev-tool","version":"1.0.0","dev_requirement":true}]}');
    $manifest['project']['entrypoint'] = 'server.php';
    file_put_contents($root . '/packager.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    try {
        Manifest::load($root . '/packager.json');
        throw new RuntimeException('Manifest accepted a Composer vendor tree with development dependencies.');
    } catch (InvalidArgumentException $exception) {
        expect(str_contains($exception->getMessage(), 'development dependencies'), 'Composer validation failed for an unexpected reason.');
    }

    echo "Packager manifest, PHAR assembly, execution and traversal checks passed.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}
