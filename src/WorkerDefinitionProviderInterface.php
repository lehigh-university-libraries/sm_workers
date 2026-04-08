<?php

namespace Drupal\sm_workers;

/**
 * Provides Symfony Messenger worker definitions.
 */
interface WorkerDefinitionProviderInterface {

  /**
   * Returns worker definitions keyed by worker ID.
   *
   * @return array<string, array<string, mixed>>
   *   Worker definitions.
   */
  public function getWorkerDefinitions(): array;

}
