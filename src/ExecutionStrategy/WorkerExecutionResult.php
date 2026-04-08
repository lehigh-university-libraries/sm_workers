<?php

declare(strict_types=1);

namespace Drupal\sm_workers\ExecutionStrategy;

/**
 * Immutable execution result returned by worker strategies.
 */
final class WorkerExecutionResult {

  /**
   * Constructs the execution result.
   */
  public function __construct(
    private string $body,
    private string $contentType,
  ) {}

  /**
   * Returns the produced body.
   */
  public function body(): string {
    return $this->body;
  }

  /**
   * Returns the produced content type.
   */
  public function contentType(): string {
    return $this->contentType;
  }

}
