# Compatibility spike: Server 7 on static micro

## Environment

- static-php-cli 2.8.6
- PHP 8.4.27
- Linux x86-64, Alpine builder image
- static extensions: `zlib`, `phar`, `posix`, `pcntl`, `sockets`, `openssl`, `ctype`
- source-based micro build completed with SPC's `build ... --build-micro --no-strip`

## Observed result

A PHAR containing Server 7 source, Composer autoloader and production runtime dependencies was combined with `micro.sfx` using `spc micro:combine`. The resulting standalone executable started the HTTP fixture with two worker processes, returned HTTP 200 for `/packager-acceptance` from both workers, and exited with code 0 after SIGINT. The test invoked the artifact directly; it did not use the system PHP CLI to run the application.

The first run reported a PHP 8.4 deprecation from reading `E_STRICT`; Server now maps the legacy bit value directly and has a regression assertion. An HTTP smoke then exposed that Server uses `ctype_digit()`/`ctype_xdigit()` while its Composer requirements omitted `ext-ctype`; the runtime now includes `ctype` and Server declares the extension requirement.

The current Packager CLI was tested with a production-only dependency tree. Its runtime probe verified PHP `8.4.27` and every declared module. Artifact size was 33,828,748 bytes. The source revision was Server commit `e82db87`; Packager manifest metadata records the artifact, micro runtime and Composer lock hashes.

| Input or artifact | SHA-256 |
| --- | --- |
| `micro.sfx` | `5d8fb34b1dd04e0eb8252631bdfa0b8b9c6f972c4b171b0c5e1f1408e1546100` |
| Server production `composer.lock` | `30ece369d73ce4f9f38aa5ee6cb8132d1a4a15f3eede723babb5959686e9cbd2` |
| Standalone Server HTTP artifact | `6c66ca7ecb99a4f0ddbcbb91ef2b78afc5f5eb86fc4875c4ad4356283ad858c5` |

## Not yet verified

- TLS handshake and production certificate loading.
- Clean-host run without system PHP libraries, plus ARM64 build/runtime.
- Reproducible artifact hash across independent builders.
- Packager tests validate a generated fixture SBOM against the official [SPDX 2.3.1 JSON Schema](https://github.com/spdx/spdx-spec/blob/6f2cb47d13db19b12c23885573b22ef32ec1ada5/schemas/spdx-schema.json), and reject a malformed document. The schema and validator are pinned in the repository; semantic SPDX validation remains open. Signature tests verify Ed25519 over the executable, metadata and SBOM and reject tampering and symlink substitution; the archived Server artifact has not been signed and checked by a separate release consumer.
- License activation, lease expiry/revocation and offline grace.

Do not describe the packager as production-ready until these gates and the cases in [architecture readiness](architecture.md#delivery-stages-and-readiness) pass.
