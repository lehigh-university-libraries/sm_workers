<?php

declare(strict_types=1);

namespace Drupal\sm_workers\ExecutionStrategy;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Provides shared config-driven runtime defaults for worker execution.
 */
class WorkerRuntimeDefaults {

  /**
   * Constructs the runtime defaults provider.
   */
  public function __construct(
    private ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the default timeout in seconds.
   */
  public function defaultTimeoutSeconds(): int {
    $value = (int) $this->configFactory->get('sm_workers.settings')
      ->get('execution.default_timeout_seconds');
    return max(1, $value ?: 300);
  }

  /**
   * Returns whether auth forwarding is enabled by default.
   */
  public function defaultForwardAuth(): bool {
    return (bool) $this->configFactory->get('sm_workers.settings')
      ->get('execution.default_forward_auth');
  }

}
