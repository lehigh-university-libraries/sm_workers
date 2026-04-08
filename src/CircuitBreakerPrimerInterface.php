<?php

declare(strict_types=1);

namespace Drupal\sm_workers;

use Drupal\sm_workers\Service\CircuitBreakerService;

/**
 * Primes known circuit breakers before the admin overview renders.
 */
interface CircuitBreakerPrimerInterface {

  /**
   * Ensures module-defined breakers exist before rendering.
   */
  public function prime(CircuitBreakerService $breakers): void;

}
