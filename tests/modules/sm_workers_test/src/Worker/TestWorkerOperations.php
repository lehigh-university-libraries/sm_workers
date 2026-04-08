<?php

declare(strict_types=1);

namespace Drupal\sm_workers_test\Worker;

use Drupal\sm_workers\WorkerOperationProviderInterface;

/**
 * Provides test worker operations for kernel coverage.
 */
final class TestWorkerOperations implements WorkerOperationProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getWorkerOperations(): array {
    return [
      'sm_workers_test.example' => [
        [
          'label' => 'Drain test transport once',
          'description' => 'Kernel test worker operation.',
          'command' => 'drush sm:consume async_high async_low --time-limit=3600 --stop-when-empty',
        ],
      ],
    ];
  }

}
