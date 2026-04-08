<?php

declare(strict_types=1);

namespace Drupal\sm_workers\ExecutionStrategy;

/**
 * Executes one worker mode.
 */
interface WorkerExecutionStrategyInterface {

  /**
   * Returns whether this strategy handles the given execution mode.
   */
  public function supports(string $mode): bool;

  /**
   * Executes one payload in a typed worker execution context.
   */
  public function execute(WorkerExecutionContext $context): WorkerExecutionResult;

}
