# Security policy

## Supported versions

No stable release has been published. Security fixes are developed against the current default branch.

## Reporting a vulnerability

Do not open a public issue containing an exploit or sensitive project data. Contact `support@localzet.com` with a concise description, affected revision, impact and a safe reproduction. Do not attach secrets, customer artifacts or signing keys.

## Security boundaries

The prototype excludes common local secret files, validates project paths, rejects symlinks and checks the configured micro runtime. These exclusions are not a general secret scanner: review the project tree before building. The local CLI can sign and verify artifacts with Ed25519, but production key management and independent release verification are not implemented. License activation is not implemented.

The CLI is not a sandbox for hostile source trees or untrusted build manifests. Build only trusted source and toolchain inputs in an isolated worker with minimal privileges. PHP code packaged into PHAR remains extractable; the standalone format does not conceal source code.
