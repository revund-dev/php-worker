<?php

declare(strict_types=1);

namespace Revund\PHPWorker;

use Grpc\Status as GrpcStatus;
use Revund\Worker\V1\DescribeRequest;
use Revund\Worker\V1\DescribeResponse;
use Revund\Worker\V1\HealthRequest;
use Revund\Worker\V1\HealthResponse;
use Revund\Worker\V1\ParseRequest;
use Revund\Worker\V1\ParseResponse;
use Revund\Worker\V1\WorkerStub;

/**
 * PHPWorkerService implements the universal Worker contract
 * by extending the generated stub. Each RPC handler forwards
 * to the matching domain object:
 *
 *   Describe       → self-identification (name + version +
 *                    languages + capabilities)
 *   Health         → returns version + always-OK
 *   Parse          → forwards to Parser.php which walks the AST
 *   ResolveSymbols → not implemented; bot skips when the
 *                    "resolve_symbols" capability is absent
 *   RunDiagnostics → not implemented; bot skips when the
 *                    "diagnostics" capability is absent
 *
 * Keeping the handler thin: this class owns ONLY the gRPC
 * wire-shape translation. Anything that requires thinking
 * about PHP semantics lives in Parser.php.
 */
final class PHPWorkerService extends WorkerStub
{
    private const NAME = 'php-worker';
    private const LANGUAGES = ['php'];
    private const CAPABILITIES = ['parse', 'self_fetch'];

    /** AUTH_HEADER mirrors the Go-side constant in pkg/worker/auth.go. */
    private const AUTH_HEADER = 'x-revund-worker-token';

    /** Env var the worker reads its bearer secret from. */
    private const AUTH_SECRET_ENV = 'REVUND_WORKER_SECRET';

    public function __construct(private Parser $parser) {}

    /**
     * Validate the incoming RPC's bearer header against the
     * configured secret. Returns null on success, or a Status
     * object with UNAUTHENTICATED that the caller surfaces back
     * to the bot.
     *
     * Empty / unset REVUND_WORKER_SECRET means "no enforcement"
     * — appropriate for CLI / local-dev where the worker runs as
     * a localhost subprocess.
     */
    private function authorized(\Grpc\ServerContext $context): ?GrpcStatus
    {
        $expected = getenv(self::AUTH_SECRET_ENV) ?: '';
        if ($expected === '') {
            return null;
        }
        $md = $context->clientMetadata() ?? [];
        $got = $md[self::AUTH_HEADER][0] ?? '';
        if ($got !== $expected) {
            return GrpcStatus::status(
                \Grpc\STATUS_UNAUTHENTICATED,
                'missing or invalid x-revund-worker-token',
            );
        }
        return null;
    }

    /**
     * Resolve the local repo path from either dispatch mode:
     *   - shared-FS path mode: returns repo_path verbatim
     *   - self-fetch mode: hands the RepoSource to the fetcher,
     *     returns the local cached checkout path.
     */
    private function resolveRepoPath(ParseRequest $request): string
    {
        $src = $request->getRepoSource();
        if ($src instanceof \Revund\Worker\V1\RepoSource && $src->getUrl() !== '') {
            return Fetcher::fetchOrCache([
                'url'        => $src->getUrl(),
                'ref'        => $src->getRef(),
                'auth_token' => $src->getAuthToken(),
                'auth_user'  => $src->getAuthUser(),
            ]);
        }
        return $request->getRepoPath();
    }

    public function Describe(DescribeRequest $request, \Grpc\ServerContext $context): DescribeResponse
    {
        if ($denied = $this->authorized($context)) {
            $context->setStatus($denied);
            return new DescribeResponse();
        }
        $resp = new DescribeResponse();
        $resp->setName(self::NAME);
        $resp->setVersion(Server::VERSION);
        $resp->setLanguages(self::LANGUAGES);
        $resp->setCapabilities(self::CAPABILITIES);
        return $resp;
    }

    public function Health(HealthRequest $request, \Grpc\ServerContext $context): HealthResponse
    {
        if ($denied = $this->authorized($context)) {
            $context->setStatus($denied);
            return new HealthResponse();
        }
        $resp = new HealthResponse();
        $resp->setVersion(Server::VERSION);
        return $resp;
    }

    public function Parse(ParseRequest $request, \Grpc\ServerContext $context): ParseResponse
    {
        if ($denied = $this->authorized($context)) {
            $context->setStatus($denied);
            return new ParseResponse();
        }
        $repoPath = $this->resolveRepoPath($request);
        $files = iterator_to_array($request->getFiles());
        $parsed = $this->parser->parseFiles($repoPath, $files);

        $resp = new ParseResponse();
        $resp->setFiles($parsed);
        return $resp;
    }
}
