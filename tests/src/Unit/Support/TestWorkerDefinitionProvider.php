<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_workers\Unit\Support;

use Drupal\sm_workers\WorkerDefinitionProviderInterface;

/**
 * Test worker definition provider.
 */
final class TestWorkerDefinitionProvider implements WorkerDefinitionProviderInterface {

  /**
   * Constructs the provider.
   *
   * @param array<string, array<string, mixed>> $definitions
   *   Worker definitions.
   */
  public function __construct(
    private array $definitions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getWorkerDefinitions(): array {
    return $this->definitions;
  }

}
