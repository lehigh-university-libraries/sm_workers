<?php

declare(strict_types=1);

namespace Drupal\sm_workers\Exception;

/**
 * Signals that outbound work was short-circuited by an open breaker.
 */
final class CircuitBreakerOpenException extends \RuntimeException {

  /**
   * Constructs the exception.
   */
  public function __construct(
    string $message,
    private readonly bool $manualOpen = FALSE,
    private readonly int $retryAfter = 0,
  ) {
    parent::__construct($message);
  }

  /**
   * Returns whether the breaker was manually opened.
   */
  public function isManualOpen(): bool {
    return $this->manualOpen;
  }

  /**
   * Returns the earliest retry timestamp when known.
   */
  public function retryAfter(): int {
    return $this->retryAfter;
  }

}
