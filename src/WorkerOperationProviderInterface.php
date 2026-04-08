<?php

declare(strict_types=1);

namespace Drupal\sm_workers;

/**
 * Contributes operator-facing commands related to one or more workers.
 */
interface WorkerOperationProviderInterface {

  /**
   * Returns worker operations keyed by worker definition ID.
   *
   * Each operation is metadata for a concrete command that operators may run
   * alongside the canonical `sm:consume` command for that worker. Operations
   * are defined in PHP so they can stay close to the module code and transport
   * semantics they depend on.
   *
   * @return array<string, list<array<string, mixed>>>
   *   Operations keyed by worker ID. Each operation may contain:
   *   - `label`: short human-readable name
   *   - `description`: optional explanation
   *   - `command`: concrete Drush command string
   */
  public function getWorkerOperations(): array;

}
