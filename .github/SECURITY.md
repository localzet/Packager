# Security policy

## Supported versions

No stable release has been published. Security fixes are developed against the current default branch.

## Reporting a vulnerability

Do not open a public issue containing an exploit or sensitive project data. Contact `support@localzet.com` with a concise description, affected revision, impact and a safe reproduction. Do not attach secrets, customer artifacts or signing keys.

## Security boundaries

The builder excludes common local secret files, validates project paths, rejects symlinks and probes the configured micro runtime. These exclusions are not a general secret scanner: review the project tree before building. The CLI can sign and verify artifacts with Ed25519, but production release-key custody and independent verification are not implemented. Licensed builds validate pinned Ed25519 leases and store activation state in owner-only files. The separate License API has not been deployed or tested with production KMS credentials.

The CLI is not a sandbox for hostile source trees or untrusted build manifests. Build only trusted source and toolchain inputs in an isolated worker with minimal privileges. PHP code packaged into PHAR remains extractable; the standalone format does not conceal source code. License enforcement is not an anti-tamper guarantee and cannot stop a sufficiently privileged host owner from patching the executable or changing the system clock.
