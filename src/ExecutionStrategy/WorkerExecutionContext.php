<?php

declare(strict_types=1);

namespace Drupal\sm_workers\ExecutionStrategy;

/**
 * Immutable execution context passed to worker strategies.
 */
final class WorkerExecutionContext {

  /**
   * Constructs the execution context.
   *
   * @param string $payload
   *   Normalized payload string for the current unit of work.
   * @param array<string, mixed> $metadata
   *   Strategy-specific metadata for the current unit of work.
   * @param array<string, mixed> $worker
   *   Resolved worker/runtime configuration.
   * @param string|null $authorization
   *   Optional authorization header value.
   * @param callable|null $heartbeat
   *   Optional callback invoked to extend in-progress liveness.
   */
  public function __construct(
    private string $payload,
    private array $metadata,
    private array $worker,
    private ?string $authorization = NULL,
    private $heartbeat = NULL,
  ) {}

  /**
   * Returns the normalized payload string.
   */
  public function payload(): string {
    return $this->payload;
  }

  /**
   * Returns strategy-specific metadata for this work item.
   *
   * @return array<string, mixed>
   *   Metadata.
   */
  public function metadata(): array {
    return $this->metadata;
  }

  /**
   * Returns resolved worker configuration.
   *
   * @return array<string, mixed>
   *   Worker configuration.
   */
  public function worker(): array {
    return $this->worker;
  }

  /**
   * Returns the optional authorization header value.
   */
  public function authorization(): ?string {
    return $this->authorization;
  }

  /**
   * Invokes the optional liveness heartbeat callback.
   */
  public function heartbeat(): void {
    if (is_callable($this->heartbeat)) {
      ($this->heartbeat)();
    }
  }

}
