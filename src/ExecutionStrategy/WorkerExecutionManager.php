<?php

declare(strict_types=1);

namespace Drupal\sm_workers\ExecutionStrategy;

/**
 * Selects and executes the matching tagged worker execution strategy.
 */
class WorkerExecutionManager {

  /**
   * Constructs the execution manager.
   *
   * @param iterable<\Drupal\sm_workers\ExecutionStrategy\WorkerExecutionStrategyInterface> $strategies
   *   Tagged strategies checked in registration order.
   */
  public function __construct(
    private iterable $strategies,
  ) {}

  /**
   * Executes one strategy-supported worker mode.
   */
  public function execute(string $mode, WorkerExecutionContext $context): WorkerExecutionResult {
    foreach ($this->strategies as $strategy) {
      if (
        !$strategy instanceof WorkerExecutionStrategyInterface
        || !$strategy->supports($mode)
      ) {
        continue;
      }

      return $strategy->execute($context);
    }

    throw new \RuntimeException(sprintf(
      'No worker execution strategy is registered for mode "%s".',
      $mode,
    ));
  }

}
