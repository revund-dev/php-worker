# revund/php-worker

The PHP AST sidecar for [Revund](https://revund.dev). A gRPC server that
implements `revund.worker.v1.Worker` — the universal contract every
Revund worker speaks. Built on
[nikic/PHP-Parser](https://github.com/nikic/PHP-Parser).

## Install

```bash
composer global require revund/php-worker
```

You also need the `grpc` PECL extension and the [`revund`](https://revund.dev/install)
CLI itself. The CLI discovers `revund-php-worker` on `PATH` and spawns it
on demand.

```bash
pecl install grpc            # one-time, builds the PHP gRPC extension
```

## Usage

The worker is normally launched by the Revund CLI. To run it standalone (for
debugging or to share a single instance across reviews):

```bash
revund-php-worker                              # binds 0.0.0.0:50052
REVUND_PHP_WORKER_PORT=0 revund-php-worker     # OS-assigned port; prints "ready: 0.0.0.0:<port>" on stdout
```

Point the CLI at it via the `REVUND_WORKERS` env var (plain
`host:port` — the CLI calls `Describe` to learn the worker's
languages and capabilities):

```bash
REVUND_WORKERS=localhost:50052 revund review
```

## What it does

The worker advertises one capability via the `Describe` RPC:

| Capability | RPC | Purpose |
|---|---|---|
| `parse` | `Parse` | Returns the universal `ParsedFile` shape — `use` imports, top-level decls (`class` / `interface` / `trait` / `enum` / `function`), method+function bodies (with cyclomatic complexity, hash, canonical hash, blocks), and concern evidence (Presentation/State/Network/IO/Config/Business/DataAccess/Transport). |

`ResolveSymbols` and `RunDiagnostics` are not implemented yet — the Revund CLI checks the capability list from `Describe` and skips RPCs the worker hasn't advertised.

## Environment

| Variable | Default | Purpose |
|---|---|---|
| `REVUND_PHP_WORKER_PORT` | `50052` | Bind port. Use `0` for OS-assigned (recommended when the CLI spawns the worker). |

## Contract

The wire contract is defined in
[`proto/worker.proto`](./proto/worker.proto), vendored inside the
package. The canonical source lives at
[`revund-dev/proto/worker/v1/worker.proto`](https://github.com/revund-dev/proto/blob/main/worker/v1/worker.proto)
and is re-vendored on every release.

Generated PHP stubs land under `proto/Revund/Worker/V1/` (built
from the .proto via the `composer proto` script).

## License

Apache-2.0
