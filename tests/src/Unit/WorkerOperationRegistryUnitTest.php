<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_workers\Unit;

use Drupal\sm_workers\Service\WorkerOperationRegistry;
use Drupal\sm_workers\WorkerOperationProviderInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for worker operation collection.
 */
final class WorkerOperationRegistryUnitTest extends UnitTestCase {

  /**
   * Tests operations are normalized and grouped by worker ID.
   */
  public function testAllNormalizesOperations(): void {
    $registry = new WorkerOperationRegistry([
      new class() implements WorkerOperationProviderInterface {

        /**
         * {@inheritdoc}
         */
        public function getWorkerOperations(): array {
          return [
            'example.worker' => [
              [
                'label' => 'Drain once',
                'description' => 'Consume until empty.',
                'command' => 'drush sm:consume example --stop-when-empty',
              ],
              [
                'label' => 'Missing command',
              ],
            ],
          ];
        }

      },
    ]);

    $operations = $registry->all();

    self::assertArrayHasKey('example.worker', $operations);
    self::assertCount(1, $operations['example.worker']);
    self::assertSame('Drain once', $operations['example.worker'][0]['label']);
    self::assertSame('Consume until empty.', $operations['example.worker'][0]['description']);
    self::assertSame('drush sm:consume example --stop-when-empty', $operations['example.worker'][0]['command']);
  }

  /**
   * Tests worker-level lookups return an empty list when nothing is registered.
   */
  public function testForWorkerReturnsEmptyListWhenMissing(): void {
    $registry = new WorkerOperationRegistry([]);
    self::assertSame([], $registry->forWorker('missing.worker'));
  }

}
