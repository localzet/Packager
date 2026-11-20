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
use Localzet\Packager\LicenseApiClient;
use Localzet\Packager\LicenseLease;
use Localzet\Packager\LicenseStore;
use Localzet\Packager\Manifest;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectLeaseFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
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

/** @return array{int,string} */
function runArtifact(string $path, array $environmentOverrides = []): array
{
    $environment = getenv();
    $environment = is_array($environment) ? array_replace($environment, $environmentOverrides) : $environmentOverrides;
    $process = proc_open([PHP_BINARY, $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the packaged artifact test process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string)$stdout . (string)$stderr];
}

/** @return array{int,string} */
function runExecutable(string $path, array $environmentOverrides = []): array
{
    $environment = getenv();
    $environment = is_array($environment) ? array_replace($environment, $environmentOverrides) : $environmentOverrides;
    $process = proc_open([$path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the standalone executable test process.');
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
$fakeRuntime = json_encode([
    'php' => PHP_VERSION,
    'extensions' => ['curl', 'phar', 'pcntl', 'posix', 'sodium', 'sockets'],
    'functions' => ['proc_open' => true],
], JSON_THROW_ON_ERROR);
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
    $sbomBytes = (string)file_get_contents($result['sbom']);
    $sbom = json_decode($sbomBytes, true, 32, JSON_THROW_ON_ERROR);
    $schemaPath = __DIR__ . '/schemas/spdx-2.3.1.schema.json';
    expect(hash_file('sha256', $schemaPath) === '4126dc29f15e92feec92e9622f4939131ad58b125d6f578aa93e980a6e6f3212', 'Pinned SPDX schema checksum changed.');
    $schema = json_decode((string)file_get_contents($schemaPath), false, 128, JSON_THROW_ON_ERROR);
    $validation = (new Opis\JsonSchema\Validator())->validate(json_decode($sbomBytes, false, 128, JSON_THROW_ON_ERROR), $schema);
    expect($validation->isValid(), 'Generated SBOM does not match the official SPDX 2.3.1 JSON Schema: ' . (string)$validation);
    $invalidSbom = json_decode($sbomBytes, false, 128, JSON_THROW_ON_ERROR);
    unset($invalidSbom->spdxVersion);
    expect(!(new Opis\JsonSchema\Validator())->validate($invalidSbom, $schema)->isValid(), 'SPDX schema check accepted a document with a required field removed.');
    expect($sbom['spdxVersion'] === 'SPDX-2.3', 'SBOM uses an unexpected SPDX version.');
    expect($sbom['documentDescribes'] === ['SPDXRef-Application'], 'SBOM does not identify its described application package.');
    expect($sbom['packages'][1]['externalRefs'][0]['referenceLocator'] === 'pkg:composer/fixture/runtime@1.0.0', 'SBOM is missing the Composer package URL.');
    expect(in_array(['spdxElementId' => 'SPDXRef-Application', 'relationshipType' => 'DEPENDS_ON', 'relatedSpdxElement' => $sbom['packages'][1]['SPDXID']], $sbom['relationships'], true), 'SBOM is missing the application dependency relationship.');
    expect(in_array(['spdxElementId' => $sbom['packages'][1]['SPDXID'], 'relationshipType' => 'DEPENDS_ON', 'relatedSpdxElement' => $sbom['packages'][2]['SPDXID']], $sbom['relationships'], true), 'SBOM is missing the transitive dependency relationship.');

    $signatures = new ArtifactSignature();
    $privateKey = $root . '/release-private.key';
    $publicKey = $root . '/release-public.key';
    $previousUmask = umask(0000);
    try {
        $keyId = $signatures->generateKeypair($privateKey, $publicKey);
        expect(umask() === 0000, 'Key generation did not restore the process umask.');
    } finally {
        umask($previousUmask);
    }
    expect((fileperms($privateKey) & 0777) === 0600, 'Generated private key is not owner-only.');
    expect($keyId === hash('sha256', base64_decode(trim((string)file_get_contents($publicKey)), true)), 'Generated key ID does not match public key.');

    $leasePair = sodium_crypto_sign_keypair();
    $leaseSecretKey = sodium_crypto_sign_secretkey($leasePair);
    $leasePublicKey = sodium_crypto_sign_publickey($leasePair);
    $base64Url = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $leaseKeyId = $base64Url(hash('sha256', $leasePublicKey, true));
    $leaseTrustedKeys = [$leaseKeyId => base64_encode($leasePublicKey)];
    $leaseNow = 2_000_000_000;
    $leaseRenewAfter = $leaseNow + 86_400;
    $leasePayload = [
        'iss' => LicenseLease::ISSUER,
        'aud' => 'localzet/server',
        'sub' => 'license-0123456789abcdef',
        'activation_id' => 'activation-0123456789abcdef',
        'product_id' => 'localzet/server',
        'release_id' => '7.0.0',
        'installation_id' => 'install-0123456789abcdef',
        'product_version' => '7.0.0',
        'iat' => $leaseNow,
        'nbf' => $leaseNow,
        'exp' => $leaseNow + LicenseLease::MAX_TTL_SECONDS,
        'renew_after' => $leaseRenewAfter,
        'jti' => 'lease-0123456789abcdef',
    ];
    $makeLease = static function (array $overrides = [], array $headerOverrides = []) use ($leasePayload, $leaseSecretKey, $leaseKeyId, $base64Url): string {
        $payload = array_replace($leasePayload, $overrides);
        $header = array_replace(['alg' => 'EdDSA', 'kid' => $leaseKeyId, 'typ' => 'JWT'], $headerOverrides);
        $signingInput = $base64Url(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . '.'
            . $base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $signingInput . '.' . $base64Url(sodium_crypto_sign_detached($signingInput, $leaseSecretKey));
    };
    $validLease = LicenseLease::verify(
        $makeLease(),
        $leaseTrustedKeys,
        'localzet/server',
        '7.0.0',
        'install-0123456789abcdef',
        $leaseNow,
    );
    expect($validLease->expiresAt() === $leaseNow + LicenseLease::MAX_TTL_SECONDS, 'A seven-day signed license lease was rejected.');
    expect($validLease->productVersion() === '7.0.0', 'Lease product version was not retained.');
    expect($validLease->activationId() === 'activation-0123456789abcdef', 'Lease activation ID was not retained.');
    expect(!$validLease->shouldRevalidateAt($leaseRenewAfter - 1), 'Lease revalidation became due before its signed jitter deadline.');
    expect($validLease->shouldRevalidateAt($leaseRenewAfter), 'Lease revalidation was not due at its signed jitter deadline.');
    $shortLicenseExpiresAt = $leaseNow + 3_600;
    $shortLicenseLease = LicenseLease::verify(
        $makeLease([
            'iat' => $leaseNow,
            'nbf' => $leaseNow,
            'exp' => $shortLicenseExpiresAt,
            'renew_after' => $shortLicenseExpiresAt,
        ]),
        $leaseTrustedKeys,
        'localzet/server',
        '7.0.0',
        'install-0123456789abcdef',
        $leaseNow,
    );
    expect($shortLicenseLease->renewAfter() === $shortLicenseExpiresAt, 'Lease rejected an API deadline equal to an early license expiry.');
    $upgradeLease = LicenseLease::verify(
        $makeLease(),
        $leaseTrustedKeys,
        'localzet/server',
        '7.1.0',
        'install-0123456789abcdef',
        $leaseNow,
        allowReleaseChangeForRenewal: true,
    );
    expect($upgradeLease->releaseId() === '7.0.0', 'A previous release lease could not be verified for an online upgrade renewal.');
    $skewedLease = $makeLease([
        'iat' => $leaseNow + LicenseLease::FUTURE_ISSUED_AT_SKEW_SECONDS,
        'nbf' => $leaseNow + LicenseLease::FUTURE_ISSUED_AT_SKEW_SECONDS,
        'exp' => $leaseNow + LicenseLease::MAX_TTL_SECONDS,
        'renew_after' => $leaseRenewAfter + LicenseLease::FUTURE_ISSUED_AT_SKEW_SECONDS,
    ]);
    LicenseLease::verify($skewedLease, $leaseTrustedKeys, 'localzet/server', '7.0.0', 'install-0123456789abcdef', $leaseNow);
    expectLeaseFailure(
        static fn() => LicenseLease::verify($makeLease([
            'iat' => $leaseNow + LicenseLease::FUTURE_ISSUED_AT_SKEW_SECONDS,
            'nbf' => $leaseNow + LicenseLease::FUTURE_ISSUED_AT_SKEW_SECONDS,
            'exp' => $leaseNow + LicenseLease::FUTURE_ISSUED_AT_SKEW_SECONDS + LicenseLease::MAX_TTL_SECONDS,
            'renew_after' => $leaseRenewAfter + LicenseLease::FUTURE_ISSUED_AT_SKEW_SECONDS,
        ]), $leaseTrustedKeys, 'localzet/server', '7.0.0', 'install-0123456789abcdef', $leaseNow),
        'Future-issued lease exceeded the seven-day offline window from verification time.',
    );
    expectLeaseFailure(
        static fn() => LicenseLease::verify($makeLease(), $leaseTrustedKeys, 'localzet/other', '7.0.0', 'install-0123456789abcdef', $leaseNow),
        'License lease was accepted for a different product.',
    );
    expectLeaseFailure(
        static fn() => LicenseLease::verify($makeLease(), $leaseTrustedKeys, 'localzet/server', '7.0.1', 'install-0123456789abcdef', $leaseNow),
        'License lease was accepted for a different release.',
    );
    expectLeaseFailure(
        static fn() => LicenseLease::verify($makeLease(), $leaseTrustedKeys, 'localzet/server', '7.0.0', 'install-0123456789abcdef', $leaseNow, '7.0.1'),
        'License lease was accepted for a different product version.',
    );
    expectLeaseFailure(
        static fn() => LicenseLease::verify($makeLease(), $leaseTrustedKeys, 'localzet/server', '7.0.0', 'install-other', $leaseNow),
        'License lease was accepted for a different installation.',
    );
    expectLeaseFailure(
        static fn() => LicenseLease::verify($makeLease(), [], 'localzet/server', '7.0.0', 'install-0123456789abcdef', $leaseNow),
        'License lease was accepted with an unknown signing key.',
    );
    expectLeaseFailure(
        static fn() => LicenseLease::verify($makeLease(['unexpected' => 'value']), $leaseTrustedKeys, 'localzet/server', '7.0.0', 'install-0123456789abcdef', $leaseNow),
        'License lease with an extra payload field was accepted.',
    );
    expectLeaseFailure(
        static fn() => LicenseLease::verify($makeLease(['exp' => $leaseNow + LicenseLease::MAX_TTL_SECONDS + 1]), $leaseTrustedKeys, 'localzet/server', '7.0.0', 'install-0123456789abcdef', $leaseNow),
        'License lease exceeded the seven-day maximum TTL.',
    );
    expectLeaseFailure(
        static fn() => LicenseLease::verify($makeLease([
            'iat' => $leaseNow - 3_600,
            'nbf' => $leaseNow - 3_600,
            'exp' => $leaseNow,
            'renew_after' => $leaseNow - 1,
        ]), $leaseTrustedKeys, 'localzet/server', '7.0.0', 'install-0123456789abcdef', $leaseNow),
        'Expired license lease was accepted.',
    );
    expectLeaseFailure(
        static fn() => LicenseLease::verify($makeLease([
            'iat' => $leaseNow + LicenseLease::FUTURE_ISSUED_AT_SKEW_SECONDS + 1,
            'nbf' => $leaseNow + LicenseLease::FUTURE_ISSUED_AT_SKEW_SECONDS + 1,
        ]), $leaseTrustedKeys, 'localzet/server', '7.0.0', 'install-0123456789abcdef', $leaseNow),
        'License lease with a future issue time was accepted.',
    );
    $tamperedLease = explode('.', $makeLease());
    $tamperedPayload = $leasePayload;
    $tamperedPayload['release_id'] = '7.0.1';
    $tamperedLease[1] = $base64Url(json_encode($tamperedPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    expectLeaseFailure(
        static fn() => LicenseLease::verify(implode('.', $tamperedLease), $leaseTrustedKeys, 'localzet/server', '7.0.1', 'install-0123456789abcdef', $leaseNow),
        'Tampered license lease payload was accepted.',
    );
    $badSignature = explode('.', $makeLease());
    $badSignature[2] = $base64Url(random_bytes(SODIUM_CRYPTO_SIGN_BYTES));
    expectLeaseFailure(
        static fn() => LicenseLease::verify(implode('.', $badSignature), $leaseTrustedKeys, 'localzet/server', '7.0.0', 'install-0123456789abcdef', $leaseNow),
        'Invalid Ed25519 license lease signature was accepted.',
    );
    expectLeaseFailure(
        static fn() => LicenseLease::verify('not.a.valid.jws', $leaseTrustedKeys, 'localzet/server', '7.0.0', 'install-0123456789abcdef', $leaseNow),
        'Malformed license lease payload was accepted.',
    );
    sodium_memzero($leaseSecretKey);
    sodium_memzero($leasePair);

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
    $sbomBackup = $result['sbom'] . '.backup';
    rename($result['sbom'], $sbomBackup);
    if (symlink($sbomBackup, $result['sbom'])) {
        try {
            $signatures->verify($result['binary'], $publicKey);
            throw new RuntimeException('Signature verifier accepted an SBOM symlink.');
        } catch (RuntimeException $exception) {
            expect(str_contains($exception->getMessage(), 'regular non-symlink'), 'SBOM symlink failed for an unexpected reason.');
        } finally {
            unlink($result['sbom']);
            rename($sbomBackup, $result['sbom']);
        }
    } else {
        rename($sbomBackup, $result['sbom']);
        throw new RuntimeException('Test environment cannot create the symlink fixture.');
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

    $licensedComposer = [
        'packages' => [
            ['name' => 'fixture/runtime', 'version' => '1.0.0', 'require' => ['fixture/transitive' => '^1.0']],
            ['name' => 'fixture/transitive', 'version' => '1.2.0'],
            ['name' => 'localzet/server', 'version' => '7.0.0'],
        ],
        'packages-dev' => [],
    ];
    file_put_contents($root . '/project/composer.json', '{"name":"fixture/application","require":{"fixture/runtime":"1.0.0","localzet/server":"^7.0"}}');
    file_put_contents($root . '/project/composer.lock', json_encode($licensedComposer, JSON_THROW_ON_ERROR));
    file_put_contents($root . '/project/vendor/composer/installed.json', json_encode([
        'dev' => false,
        'packages' => [
            ['name' => 'fixture/runtime', 'version' => '1.0.0', 'dev_requirement' => false],
            ['name' => 'fixture/transitive', 'version' => '1.2.0', 'dev_requirement' => false],
            ['name' => 'localzet/server', 'version' => '7.0.0', 'dev_requirement' => false],
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/project/vendor/autoload.php', <<<'PHP'
<?php
namespace localzet;
final class Server
{
    private static array $workerStartCallbacks = [];
    public static function onWorkerStart(callable $callback): void { self::$workerStartCallbacks[] = $callback; }
    public static function requestShutdown(int $code = 0, mixed $log = ''): void {}
    public static function runAll(): void
    {
        $uid = getenv('PACKAGER_TEST_WORKER_UID');
        if (is_string($uid) && $uid !== '') {
            $account = posix_getpwuid((int)$uid);
            if (posix_geteuid() !== 0 || !is_array($account)
                || !posix_setgid((int)$account['gid'])
                || !posix_setuid((int)$account['uid'])
            ) {
                throw new \RuntimeException('Could not simulate the Server worker user.');
            }
        }
        foreach (self::$workerStartCallbacks as $callback) {
            $callback();
        }
    }
}
final class Timer
{
    public static function add(float $interval, callable $callback, ?array $arguments = [], bool $persistent = true): int { return 1; }
    public static function del(int $timerId): bool { return true; }
}
PHP
    );
    $licenseConfig = [
        'product_id' => 'localzet.server',
        'release_id' => '7.0.0',
        'product_version' => '7.0.0',
        'api_url' => 'https://licenses.example.test',
        'trusted_keys' => $leaseTrustedKeys,
    ];
    $licensedManifest = $manifest;
    $licensedManifest['extensions'] = ['curl', 'phar', 'pcntl', 'posix', 'sodium', 'sockets'];
    $licensedManifest['license'] = $licenseConfig;
    $licensedManifest['output'] = 'output/licensed-server.phar';
    $realSpc = getenv('PACKAGER_TEST_SPC');
    $realMicro = getenv('PACKAGER_TEST_MICRO');
    $usesRealMicro = is_string($realSpc) && $realSpc !== '' && is_string($realMicro) && $realMicro !== '';
    if ($usesRealMicro) {
        $licensedManifest['toolchain'] = [
            'spc' => $realSpc,
            'micro' => $realMicro,
            'version' => (string)(getenv('PACKAGER_TEST_SPC_VERSION') ?: '2.8.6'),
        ];
        $licensedManifest['php_version'] = (string)(getenv('PACKAGER_TEST_PHP_VERSION') ?: PHP_VERSION);
    }
    file_put_contents($root . '/project/server.php', "<?php \\localzet\\Server::runAll(); echo 'packaged-ok';\n");
    file_put_contents($root . '/licensed-packager.json', json_encode($licensedManifest, JSON_THROW_ON_ERROR));
    $loadedLicensedManifest = Manifest::load($root . '/licensed-packager.json');
    expect($loadedLicensedManifest->licenseConfig() === $licenseConfig, 'Licensed manifest configuration was not retained.');
    $licensedResult = (new BuildCommand())->run($loadedLicensedManifest);
    if (!$usesRealMicro) {
        $licensedPhar = new Phar($licensedResult['binary']);
        expect($licensedPhar->offsetExists('__localzet/license/LicenseRuntime.php'), 'Licensed archive omitted its runtime enforcement code.');
        expect(str_contains($licensedPhar->getStub(), 'handleCommand'), 'Licensed archive stub omitted its license command handler.');
    }

    $previousLicenseDirectory = getenv('LOCALZET_LICENSE_DIR');
    $runLicensedArtifact = $usesRealMicro ? 'runExecutable' : 'runArtifact';
    putenv('LOCALZET_LICENSE_DIR=' . $root . '/empty-license-state');
    [$unactivatedExit, $unactivatedOutput] = $runLicensedArtifact($licensedResult['binary']);
    expect($unactivatedExit === 78 && str_contains($unactivatedOutput, 'No valid license activation'), 'Licensed artifact started without an activation: ' . $unactivatedOutput);
    expect(!is_dir($root . '/empty-license-state/localzet-license/localzet.server'), 'An unactivated root startup created state owned by the wrong service user.');

    putenv('LOCALZET_LICENSE_DIR=' . $root . '/active-license-state');
    $licenseStore = LicenseStore::forProduct($licenseConfig);
    $nextReleaseConfig = $licenseConfig;
    $nextReleaseConfig['release_id'] = '7.0.1';
    expect(LicenseStore::forProduct($nextReleaseConfig)->directory() === $licenseStore->directory(), 'A release upgrade changed the persistent installation identity path.');
    $runtimeNow = time();
    $runtimeLease = $makeLease([
        'aud' => 'localzet.server',
        'product_id' => 'localzet.server',
        'iat' => $runtimeNow - 1,
        'nbf' => $runtimeNow - 1,
        'exp' => $runtimeNow + 3_600,
        'renew_after' => $runtimeNow + 1_800,
    ]);
    $runtimeVerifiedLease = LicenseLease::verify(
        $runtimeLease,
        $leaseTrustedKeys,
        'localzet.server',
        '7.0.0',
        'install-0123456789abcdef',
        expectedProductVersion: '7.0.0',
    );
    $licenseStore->update(function (array &$state) use ($runtimeLease, $runtimeVerifiedLease): void {
        $state['installation_id'] = 'install-0123456789abcdef';
        $state['access_token'] = 'act_' . str_repeat('A', 40);
        $state['lease'] = $runtimeLease;
        $state['activation_id'] = $runtimeVerifiedLease->activationId();
    });
    expect((fileperms($licenseStore->directory()) & 0777) === 0700, 'License state directory is not owner-only.');
    expect((fileperms($licenseStore->directory() . '/license-state.json') & 0777) === 0600, 'License state file is not owner-only.');
    [$activatedExit, $activatedOutput] = $runLicensedArtifact($licensedResult['binary']);
    expect($activatedExit === 0 && trim($activatedOutput) === 'packaged-ok', 'Licensed artifact rejected a valid signed lease: ' . $activatedOutput);

    if (getenv('PACKAGER_TEST_PRIVILEGED') === '1') {
        $worker = posix_getpwnam('nobody');
        expect(posix_geteuid() === 0 && is_array($worker), 'Privileged worker ownership test requires root and the nobody account.');
        $workerStateBase = $root . '/worker-license-state';
        putenv('LOCALZET_LICENSE_DIR=' . $workerStateBase);
        $workerStore = LicenseStore::forProduct($licenseConfig);
        $workerStore->update(function (array &$state) use ($runtimeLease, $runtimeVerifiedLease): void {
            $state['installation_id'] = 'install-0123456789abcdef';
            $state['access_token'] = 'act_' . str_repeat('A', 40);
            $state['lease'] = $runtimeLease;
            $state['activation_id'] = $runtimeVerifiedLease->activationId();
        });
        chmod($root, 0711);
        foreach ([$workerStateBase, $workerStateBase . '/localzet-license', $workerStore->directory()] as $directory) {
            expect(chown($directory, (int)$worker['uid']) && chgrp($directory, (int)$worker['gid']) && chmod($directory, 0700), 'Could not prepare the worker-owned license directory.');
        }
        foreach ([$workerStore->directory() . '/license-state.json', $workerStore->directory() . '/license-state.lock'] as $file) {
            expect(chown($file, (int)$worker['uid']) && chgrp($file, (int)$worker['gid']) && chmod($file, 0600), 'Could not prepare worker-owned license state files.');
        }
        [$workerExit, $workerOutput] = $runLicensedArtifact($licensedResult['binary'], [
            'LOCALZET_LICENSE_DIR' => $workerStateBase,
            'PACKAGER_TEST_WORKER_UID' => (string)$worker['uid'],
        ]);
        expect($workerExit === 0 && trim($workerOutput) === 'packaged-ok', 'Licensed artifact could not read worker-owned state after dropping privileges: ' . $workerOutput);
    }

    $licenseStore->update(static function (array &$state): void {
        $state['rejected'] = true;
    });
    [$revokedExit, $revokedOutput] = $runLicensedArtifact($licensedResult['binary']);
    expect($revokedExit === 78 && str_contains($revokedOutput, 'rejected by the License API'), 'Licensed artifact ignored a rejected activation.');
    $licenseStore->update(function (array &$state) use ($makeLease, $runtimeNow): void {
        $state['rejected'] = false;
        $state['lease'] = $makeLease([
            'aud' => 'localzet.server',
            'product_id' => 'localzet.server',
            'iat' => $runtimeNow - 3_601,
            'nbf' => $runtimeNow - 3_601,
            'exp' => $runtimeNow - 1,
            'renew_after' => $runtimeNow - 1_800,
        ]);
    });
    [$expiredExit, $expiredOutput] = $runLicensedArtifact($licensedResult['binary']);
    expect($expiredExit === 78 && str_contains($expiredOutput, 'needs an online license renewal'), 'Licensed artifact started with an expired lease.');
    if (is_string($previousLicenseDirectory)) {
        putenv('LOCALZET_LICENSE_DIR=' . $previousLicenseDirectory);
    } else {
        putenv('LOCALZET_LICENSE_DIR');
    }

    file_put_contents($root . '/project/composer.json', '{"name":"fixture/application","require":{"fixture/runtime":"1.0.0"}}');
    file_put_contents($root . '/project/composer.lock', '{"packages":[{"name":"fixture/runtime","version":"1.0.0","require":{"fixture/transitive":"^1.0"}},{"name":"fixture/transitive","version":"1.2.0"}],"packages-dev":[]}');
    file_put_contents($root . '/project/vendor/composer/installed.json', '{"dev":false,"packages":[{"name":"fixture/runtime","version":"1.0.0","dev_requirement":false},{"name":"fixture/transitive","version":"1.2.0","dev_requirement":false}]}');
    file_put_contents($root . '/project/vendor/autoload.php', "<?php\n");

    try {
        new LicenseApiClient('http://licenses.example.test');
        throw new RuntimeException('License API client accepted a non-HTTPS endpoint.');
    } catch (RuntimeException $exception) {
        expect(str_contains($exception->getMessage(), 'HTTPS'), 'API endpoint validation failed for an unexpected reason.');
    }

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
