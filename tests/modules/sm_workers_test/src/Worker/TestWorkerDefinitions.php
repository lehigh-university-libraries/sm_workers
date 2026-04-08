<?php

declare(strict_types=1);

namespace Drupal\sm_workers_test\Worker;

use Drupal\sm_workers\WorkerDefinitionProviderInterface;

/**
 * Provides test worker definitions for kernel coverage.
 */
final class TestWorkerDefinitions implements WorkerDefinitionProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getWorkerDefinitions(): array {
    return [
      'sm_workers_test.example' => [
        'label' => 'Test worker',
        'description' => 'Kernel test worker definition.',
        'transports' => ['async_high', 'async_low'],
        'options' => [
          'time-limit' => 3600,
        ],
      ],
    ];
  }

}
