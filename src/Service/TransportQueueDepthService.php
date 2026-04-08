<?php

namespace Drupal\sm_workers\Service;

use Drupal\Core\Queue\QueueFactory;

/**
 * Reports point-in-time queue depth for SQL-backed Messenger transports.
 */
final class TransportQueueDepthService {

  /**
   * Constructs the queue-depth service.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   Queue factory used to inspect SQL-backed transport depths.
   * @param array<string, mixed> $transportDefinitions
   *   Messenger transport definitions keyed by transport name.
   */
  public function __construct(
    private QueueFactory $queueFactory,
    private array $transportDefinitions = [],
  ) {}

  /**
   * Returns queue depth per SQL-backed transport.
   *
   * @return array<string, int>
   *   Queue depth keyed by transport name.
   */
  public function queueDepths(): array {
    if ($this->transportDefinitions === []) {
      return [];
    }

    $depths = [];
    foreach ($this->transportDefinitions as $transportName => $definition) {
      $depths[(string) $transportName] = 0;
      $dsn = is_array($definition) ? (string) ($definition['dsn'] ?? '') : '';
      if (!str_starts_with($dsn, 'drupal-sql://')) {
        continue;
      }

      try {
        $depths[(string) $transportName] = (int) $this->queueFactory
          ->get((string) $transportName)
          ->numberOfItems();
      }
      catch (\Throwable) {
        $depths[(string) $transportName] = 0;
      }
    }

    return $depths;
  }

}
