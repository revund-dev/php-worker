<?php
// GENERATED CODE -- DO NOT EDIT!

// Original file comments:
// worker.proto — the universal AST-worker contract.
//
// # Public API
//
// This file defines the wire contract every Revund AST
// sidecar speaks. It is designed for STABILITY since the
// contract may eventually be published as an open-source
// interface that third-party language sidecars target.
//
// Compatibility rules:
//
//   - Adding fields to existing messages: ALLOWED (proto3
//     ignores unknown fields gracefully).
//   - Adding new RPCs: ALLOWED (clients negotiate capability
//     via Describe).
//   - Removing or renaming fields: BREAKING — bump the
//     package version (v1 → v2). Old clients keep speaking v1.
//   - Changing field types: BREAKING.
//
// # Sidecar contract
//
// A Revund worker is any process implementing this service.
// It can be:
//
//   - A first-party reference implementation we maintain
//     (ts-worker, php-worker, ruby-worker)
//   - A community-built sidecar for any language
//   - A proprietary worker a customer builds for their
//     in-house DSL
//
// The bot dials the worker by host:port, calls `Describe`
// to learn which languages + capabilities it advertises,
// and routes Parse RPCs based on the response. The bot
// does NOT hardcode language-to-worker mappings — every
// worker self-identifies.
//
// # Minimum viable worker
//
// Implement only `Describe`, `Health`, and `Parse`. Set
// `capabilities = ["parse"]`. The bot uses that worker for
// its advertised languages and skips the rest gracefully.
//
// # Symbol resolution / diagnostics (optional)
//
// Capabilities are advisory. Workers that ALSO support
// symbol resolution (returning declarations of identifiers
// referenced in changed code) advertise
// `capabilities = ["parse", "resolve_symbols"]`. Workers
// that run language-specific type-checkers (TypeScript's
// `tsc --noEmit`, PHP's PHPStan, Ruby's Sorbet) advertise
// `["parse", "diagnostics"]`. The bot uses these features
// when present, falls back to "just parse" when not.
//
namespace Revund\Worker\V1;

/**
 */
class WorkerClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Describe identifies the worker. The bot calls this
     * first to learn what languages + capabilities it
     * supports. Idempotent, side-effect-free.
     * @param \Revund\Worker\V1\DescribeRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Revund\Worker\V1\DescribeResponse>
     */
    public function Describe(\Revund\Worker\V1\DescribeRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/revund.worker.v1.Worker/Describe',
        $argument,
        ['\Revund\Worker\V1\DescribeResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Health is a standard k8s-style liveness probe.
     * @param \Revund\Worker\V1\HealthRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Revund\Worker\V1\HealthResponse>
     */
    public function Health(\Revund\Worker\V1\HealthRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/revund.worker.v1.Worker/Health',
        $argument,
        ['\Revund\Worker\V1\HealthResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Parse returns a minimal AST view (imports + decls +
     * functions + concerns) for the requested files. Per-
     * file parse errors are returned INSIDE the response,
     * never as RPC errors. REQUIRED capability — every
     * healthy worker implements this.
     * @param \Revund\Worker\V1\ParseRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Revund\Worker\V1\ParseResponse>
     */
    public function Parse(\Revund\Worker\V1\ParseRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/revund.worker.v1.Worker/Parse',
        $argument,
        ['\Revund\Worker\V1\ParseResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * ResolveSymbols returns the declarations of identifiers
     * referenced in a diff but defined elsewhere in the
     * repo. Used by the bot's ingest layer to enrich the
     * LLM bundle with cross-file type information.
     *
     * OPTIONAL capability — workers advertise
     * "resolve_symbols" in Describe.Capabilities when
     * implemented. Workers that don't implement it return
     * UNIMPLEMENTED; the bot's caller checks the capability
     * list and skips the call when unsupported.
     * @param \Revund\Worker\V1\ResolveRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Revund\Worker\V1\ResolveResponse>
     */
    public function ResolveSymbols(\Revund\Worker\V1\ResolveRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/revund.worker.v1.Worker/ResolveSymbols',
        $argument,
        ['\Revund\Worker\V1\ResolveResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * RunDiagnostics runs the language's native type-checker
     * (tsc --noEmit for TypeScript, PHPStan for PHP, Sorbet
     * for Ruby, etc.) and returns errors touching the
     * changed files. Empty when the project has no errors
     * or the worker doesn't run a type-checker — never an
     * RPC error.
     *
     * OPTIONAL capability — workers advertise "diagnostics"
     * in Describe.Capabilities when implemented.
     * @param \Revund\Worker\V1\DiagnosticsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Revund\Worker\V1\DiagnosticsResponse>
     */
    public function RunDiagnostics(\Revund\Worker\V1\DiagnosticsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/revund.worker.v1.Worker/RunDiagnostics',
        $argument,
        ['\Revund\Worker\V1\DiagnosticsResponse', 'decode'],
        $metadata, $options);
    }

}
