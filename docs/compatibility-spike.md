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

## Packager revalidation — 2026-11-04

Packager built the current Server source at commit `e82db87e618e4582d2904479edcf7d8a91714451` using a temporary copy with production-only Composer dependencies, static-php-cli `2.8.6`, and the same `micro.sfx` hash listed above. The executable was run as an unprivileged UID in a Docker `scratch` image with a read-only root filesystem and a writable `/tmp` tmpfs. The image contained no system PHP or shared libraries; the executable is statically linked.

| Output | Value |
| --- | --- |
| Packager-built Server executable SHA-256 | `62201339c8811e6b5f52e9a5bf1fa7597797fb104eeab4c54f24c78260b52167` |
| Metadata SHA-256 | `3a4d3704738395a995d8bd0633fb5ee745962fa9bd521a3a66552fb7cfdc29d7` |
| SPDX SBOM SHA-256 | `b9665bbff9252d5995f99c023123e57f75669bb1728a581d7fa1a4a26d6daae5` |

Observed checks: 20 HTTP requests returned successfully across two worker PIDs; a TLS request with a temporary self-signed certificate returned HTTP 200; SIGINT stopped the process with exit code 0. The bundle was also signed and verified with a temporary Ed25519 key pair; the private key had mode `0600`. This checks the Packager CLI's signing path, not an independently maintained verifier or a production key ceremony.

The scratch container used host networking because the fixture binds to loopback. The test did not exercise outbound isolation, production certificates, a reverse proxy, arbitrary TCP/UDP protocols, or ARM64. It is an x86-64 runtime smoke, not a deployment or release qualification.

## Not yet verified

- Production certificate loading, plus ARM64 build/runtime.
- Reverse-proxy and arbitrary TCP/UDP behavior in this Packager artifact.
- Reproducible artifact hash across independent builders.
- Packager tests validate a generated fixture SBOM against the official [SPDX 2.3.1 JSON Schema](https://github.com/spdx/spdx-spec/blob/6f2cb47d13db19b12c23885573b22ef32ec1ada5/schemas/spdx-schema.json), and reject a malformed document. The schema and validator are pinned in the repository; semantic SPDX validation remains open. Signature tests verify Ed25519 over the executable, metadata and SBOM and reject tampering and symlink substitution; the Packager-built Server bundle was signed and verified by the same CLI, but not by a separate release consumer.
- License activation, lease expiry/revocation and offline grace.

Do not describe the packager as production-ready until these gates and the cases in [architecture readiness](architecture.md#delivery-stages-and-readiness) pass.
