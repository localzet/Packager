# CLI guide

Packager accepts a JSON manifest, validates the project inputs, creates a PHAR and combines it with a prepared `micro.sfx` runtime through static-php-cli. The current target is `linux-x86_64`.

## Prepare the project

Install production dependencies from the project's lockfile in an isolated build environment:

```sh
composer install --no-dev --no-scripts --no-plugins --prefer-dist --no-interaction
```

Packager itself does not run Composer, project scripts or application code. Build a `micro.sfx` runtime with the required extensions using the pinned static-php-cli release.

## Manifest

```json
{
  "schema_version": 1,
  "project": {
    "root": "../my-application",
    "entrypoint": "server.php"
  },
  "target": "linux-x86_64",
  "php_version": "8.4.27",
  "extensions": ["phar", "pcntl", "posix", "sockets", "openssl", "ctype"],
  "toolchain": {
    "spc": "/opt/static-php-cli/bin/spc",
    "micro": "/opt/static-php-cli/buildroot/bin/micro.sfx",
    "version": "2.8.6"
  },
  "output": "../dist/my-application"
}
```

Paths for the project, toolchain and output are resolved relative to the manifest. The entrypoint must be inside the project root. Composer projects must provide a valid `composer.lock`, `vendor/autoload.php` and `vendor/composer/installed.json`. Production package names, versions and available source/dist references in `vendor/` are checked against the lockfile; development dependencies and mismatches are rejected. The lockfile SHA-256 is written to metadata. Output cannot be placed inside the source tree, and existing artifacts or metadata are never overwritten.

## Validate and build

```sh
composer install --no-interaction
php bin/localzet-packager validate packager.json
php -d phar.readonly=0 bin/localzet-packager build packager.json
```

Build writes an executable, a neighboring JSON metadata file, and an SPDX 2.3 SBOM (`<artifact>.spdx.json`). The SBOM describes the application and production packages from `composer.lock`, adds Composer PURLs and known dependency relationships, and uses `NOASSERTION` for unverified license data. It is a lockfile-based inventory, not an independent source analysis. Metadata records hashes for the artifact, SBOM, micro runtime, and Composer lockfile where applicable. The CLI temporarily appends a capability probe to a copy of `micro.sfx` and checks that the runtime can execute it before packaging. Child processes have a 300-second timeout recorded in metadata.

## Sign and verify an artifact

Generate a dedicated Ed25519 key pair. Existing files are never overwritten; the private key is created with mode `0600`:

```sh
php bin/localzet-packager keygen /secure/release.key /secure/release.pub
```

Keep the private key outside the source tree and build worker. Sign the executable and its neighboring `.manifest.json` and `.spdx.json` files:

```sh
php bin/localzet-packager sign ../dist/my-application /secure/release.key
```

This creates `my-application.sig.json` with an Ed25519 signature, key ID, and hashes for all three files. Verify the complete set using a public key delivered through a trusted channel:

```sh
php bin/localzet-packager verify ../dist/my-application /secure/trusted-release.pub
```

The public key is not included in the bundle and is not trusted automatically. A valid signature proves bundle integrity and possession of the matching private key; it does not establish source safety, key-owner authority, or build-environment provenance. Rotate a compromised key through the trusted distribution channel. Signing does not encrypt PHP source.

## Limits

- A prebuilt `micro.sfx` is required; Packager does not compile PHP.
- `.git`, IDE settings, `node_modules`, `.env*`, `auth.json` and `composer-auth.json` are excluded. Other project files, including tests, may be packaged.
- Existing `vendor/` contents are included after Composer metadata checks. Only package trusted source and production dependencies.
- Ed25519 artifact signatures and integrity verification are implemented; tests validate SBOM documents against the official JSON Schema. Semantic SPDX validation, provenance attestations, activation, license enforcement and reproducible independent builds are not implemented.
- PHAR and micro SAPI do not encrypt PHP source or prevent reverse engineering.
- Only Linux x86-64 has a real Server runtime acceptance report. TLS, ARM64 and clean-host compatibility have not been verified.
