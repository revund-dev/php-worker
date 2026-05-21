<?php

declare(strict_types=1);

namespace Revund\PHPWorker;

use Grpc\Server as GrpcServer;
use Grpc\ServerCredentials;

/**
 * gRPC server entrypoint for php-worker.
 *
 * Implements the universal {@see \Revund\Worker\V1\WorkerStub}
 * contract — the same one ts-worker and ruby-worker speak.
 *
 * Lifecycle:
 *   1. Bind 0.0.0.0:$port (insecure — the bot is in the same
 *      private network).
 *   2. Register the WorkerService implementation.
 *   3. Write "ready: <addr>" to stdout so a spawning parent
 *      (when running in sidecar mode) can synchronize.
 *   4. Run the gRPC event loop.
 *
 * The Describe RPC self-advertises:
 *   - name         = "php-worker"
 *   - version      = self::VERSION
 *   - languages    = ["php"]
 *   - capabilities = ["parse"]
 *
 * ResolveSymbols and RunDiagnostics are not yet implemented;
 * they return UNIMPLEMENTED and the bot's caller (which checks
 * the capabilities list first) skips them.
 */
final class Server
{
    public const VERSION = '0.1.0';

    public function __construct(private int $port) {}

    public function run(): void
    {
        $server = new GrpcServer([]);
        $server->addHttp2Port(
            sprintf('0.0.0.0:%d', $this->port),
            ServerCredentials::createInsecure()
        );

        // PHPWorkerService is the implementation class — it
        // extends the generated abstract stub
        // (\Revund\Worker\V1\WorkerStub) and forwards Parse
        // to src/Parser.php. Describe/Health are answered
        // inline.
        $server->handle(new PHPWorkerService(new Parser()));

        // Liveness ping for parent processes that spawn the
        // worker as a sidecar.
        fwrite(STDOUT, sprintf("ready: 0.0.0.0:%d\n", $this->port));
        fflush(STDOUT);

        $server->run();
    }
}
