# Localzet Packager

Localzet Packager builds standalone executables for Localzet Server and Triangle applications with static-php-cli.

## Status

The CLI prototype targets `linux-x86_64`. It validates the manifest and Composer graph, packages project files into a PHAR, probes the PHP version and extensions exposed by `micro.sfx`, and combines the PHAR with the runtime through a pinned static-php-cli version. On 2026-11-04, Packager built the current Server 7 source and the artifact passed HTTP, TLS, two-worker and SIGINT checks in a `scratch` container without system libraries. See the [compatibility report](docs/compatibility-spike.md) for evidence and hashes.

Packager is not ready for broad distribution. The x86-64 `scratch` smoke passed, including TLS with a temporary self-signed certificate; production certificate loading, ARM64, reproducible builds and independent artifact verification remain untested. Licensed PHAR startup enforcement, owner-only lease storage and the HTTPS activation client are implemented and locally exercised. The separate License API exists in a local private checkout, but its production endpoint, ingress and KMS signing key are not deployed, and no customer release has been validated against it. PHAR and micro SAPI packaging do not prevent PHP source extraction.

See the [Russian overview](README.md), [architecture](docs/architecture.en.md), [CLI guide](docs/cli.en.md) and [compatibility report](docs/compatibility-spike.md).

## Build requirements

- PHP CLI with `json`, `phar` and `sodium` extensions.
- A pinned static-php-cli 2.x executable and a matching `micro.sfx` runtime.
- A project with its production `vendor/` tree already installed from `composer.lock`.

The Packager does not run Composer or project scripts. Run production dependency installation in an isolated build environment before packaging:

```sh
composer install --no-dev --no-scripts --no-plugins --prefer-dist --no-interaction
```

## Commands

```sh
composer install --no-interaction
php bin/localzet-packager validate packager.json
php -d phar.readonly=0 bin/localzet-packager build packager.json
```

The manifest pins the project entrypoint, target, PHP version, extension list, static-php-cli path/version, micro runtime and output path. The builder checks the actual runtime version and loaded extensions. It excludes `.env*`, Composer auth files, VCS metadata, IDE settings and `node_modules`, and refuses to overwrite an existing artifact.

Current limitations and the complete manifest reference are documented in [docs/cli.en.md](docs/cli.en.md).

Sign and verify a release bundle with a separately trusted key:

```sh
php bin/localzet-packager keygen /secure/release.key /secure/release.pub
php bin/localzet-packager sign ../dist/my-application /secure/release.key
php bin/localzet-packager verify ../dist/my-application /secure/trusted-release.pub
```

The signature binds the executable, metadata and SBOM. Obtain the verification key through a trusted channel; a signature does not prove source safety or build provenance.

## Security

Do not include secrets, private keys or certificates in the project tree. Build in an isolated directory with only the intended production source and dependencies. Licensed artifacts require `curl`, `pcntl`, `posix`, `sodium`, `proc_open` and Localzet Server 7 or newer. They keep a local access token and signed lease in owner-only files; the license key itself is not stored. A signed lease is valid for no more than seven days, and a release or product-version upgrade requires an online renewal before the new binary starts. The License API is not deployed, so licensed customer releases must wait for a configured endpoint, trusted KMS public key and production verification. Micro SAPI and PHAR packaging do not encrypt source code. No anti-tamper guarantee is made.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
