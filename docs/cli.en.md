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

### Licensed builds

Add a `license` object to the manifest when the executable must require online activation. Product and release IDs allow letters, digits, `.`, `_` and `-`; `product_version` identifies the application version. `api_url` is an HTTPS origin without a path. `trusted_keys` maps the License API `kid` to the raw Ed25519 public key in standard base64:

```jsonc
"license": {
  "product_id": "my-application",
  "release_id": "7.1.0",
  "product_version": "7.1.0",
  "api_url": "https://license-api.example.com",
  "trusted_keys": {
    "KID_FROM_SIGNER_PUBLIC_KEY_COMMAND": "BASE64_RAW_ED25519_PUBLIC_KEY"
  }
}
```

The values in `trusted_keys` are placeholders. Obtain them from the License API `signer:public-key` command and verify their source through a trusted channel. The `kid` is SHA-256 of the raw public key encoded as unpadded base64url; the command's `public_key` field uses standard base64. Never put a license key, access token, HMAC secret or signing private key in the manifest.

The licensed target runtime must include `curl`, `pcntl`, `posix` and `sodium`, with `proc_open` enabled. The Composer lock must include Localzet Server 7 or newer. The License API currently exists in a local private checkout only; its production endpoint, ingress and KMS are not configured, so a real service exchange has not been verified.

Activate the executable first. The license key is read from stdin so it is not placed in process arguments or shell history:

```bash
read -r -s -p "License key: " LICENSE_KEY
printf '\n'
printf '%s\n' "$LICENSE_KEY" | ./dist/my-application --license-activate
unset LICENSE_KEY
```

Activation creates a random installation ID and stores the access token and signed lease in `$XDG_STATE_HOME/localzet-license/<product_id>` or `$HOME/.local/state/localzet-license/<product_id>`. Set `LOCALZET_LICENSE_DIR` to choose another base directory. The final directory uses mode `0700` and state files use `0600`; keep the location stable across binary upgrades.

Run the executable as the same system user that owns its license directory. If the master starts as root and Server later drops privileges through `Server::$user`, pre-create the license directory for the worker account and activate as that account. Do not activate as root for a worker running under a different UID.

For this setup, choose a stable base directory and activate the product as the worker account:

```bash
sudo install -d -o appuser -g appgroup -m 0700 /var/lib/my-application
read -r -s -p "License key: " LICENSE_KEY
printf '\n'
printf '%s\n' "$LICENSE_KEY" | sudo -u appuser env LOCALZET_LICENSE_DIR=/var/lib/my-application ./dist/my-application --license-activate
unset LICENSE_KEY
```

Set the same `LOCALZET_LICENSE_DIR` in the master process environment. A first startup without activation does not create the state directory, so the license can still be activated under the correct account after startup is denied.

The lease permits at most seven days offline. The client starts renewal in a separate process at the signed renewal time, keeping network waits out of the server event loop. Workers check API denial and lease expiry, then request a graceful Server shutdown. If a lease has expired or the new release/product version needs rebinding, the executable renews synchronously before starting the application. After restoring connectivity, you can also run `./dist/my-application --license-renew`; `./dist/my-application --license-release` revokes the activation through the API. Startup is denied when the lease is expired or the API rejects the activation. Keep the target host clock synchronized.

Changing the release ID or product version requires an online renewal before the new binary starts. The old lease is bound to the previous version; state remains keyed by product ID, so the installation identity and activation slot survive upgrades.

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
- Ed25519 artifact signatures and integrity verification are implemented; tests validate SBOM documents against the official JSON Schema. Licensed PHAR activation, JWS verification, private state storage and runtime expiry enforcement are locally tested. A production API exchange, KMS signing path, semantic SPDX validation, provenance attestations and reproducible independent builds remain unverified.
- PHAR and micro SAPI do not encrypt PHP source or prevent reverse engineering.
- Linux x86-64 has a Server runtime acceptance report, including HTTP/TLS and a `scratch` container without system libraries. Production certificates, ARM64 and reverse-proxy compatibility remain unverified.
