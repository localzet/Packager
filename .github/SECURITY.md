# Security policy

## Supported versions

No stable release has been published. Security fixes are developed against the current default branch.

## Reporting a vulnerability

Do not open a public issue containing an exploit or sensitive project data. Contact `support@localzet.com` with a concise description, affected revision, impact and a safe reproduction. Do not attach secrets, customer artifacts or signing keys.

## Security boundaries

The prototype excludes common local secret files, validates project paths, rejects symlinks and checks the configured micro runtime. It is not a sandbox for hostile source trees. Build only trusted source in an isolated worker. PHP code packaged into PHAR remains extractable. License activation, signed artifacts and a production key-management service are not implemented.
