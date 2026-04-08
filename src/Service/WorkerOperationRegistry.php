<?php

declare(strict_types=1);

namespace Drupal\sm_workers\Service;

use Drupal\sm_workers\WorkerOperationProviderInterface;

/**
 * Collects operator-facing worker commands from tagged providers.
 */
final class WorkerOperationRegistry {

  /**
   * Memoized worker operations from tagged providers.
   *
   * @var array<string, list<array<string, string>>>|null
   */
  private ?array $operations = NULL;

  /**
   * Constructs the registry.
   *
   * @param iterable<\Drupal\sm_workers\WorkerOperationProviderInterface> $providers
   *   Tagged operation providers.
   */
  public function __construct(
    private iterable $providers,
  ) {}

  /**
   * Returns all operations keyed by worker definition ID.
   *
   * @return array<string, list<array<string, string>>>
   *   Normalized operations keyed by worker ID.
   */
  public function all(): array {
    if ($this->operations !== NULL) {
      return $this->operations;
    }

    $operations = [];
    foreach ($this->providers as $provider) {
      if (!$provider instanceof WorkerOperationProviderInterface) {
        continue;
      }

      foreach ($provider->getWorkerOperations() as $workerId => $workerOperations) {
        $workerId = (string) $workerId;
        foreach ((array) $workerOperations as $operation) {
          if (!is_array($operation)) {
            continue;
          }
          $normalized = $this->normalizeOperation($operation);
          if ($normalized === NULL) {
            continue;
          }
          $operations[$workerId][] = $normalized;
        }
      }
    }

    ksort($operations);
    $this->operations = $operations;
    return $this->operations;
  }

  /**
   * Returns operations for one worker definition.
   *
   * @return list<array<string, string>>
   *   Normalized operations.
   */
  public function forWorker(string $workerId): array {
    $operations = $this->all();
    return $operations[$workerId] ?? [];
  }

  /**
   * Normalizes one operation definition.
   *
   * @param array<string, mixed> $operation
   *   Raw operation definition.
   *
   * @return array<string, string>|null
   *   Normalized operation or NULL when invalid.
   */
  private function normalizeOperation(array $operation): ?array {
    $command = trim((string) ($operation['command'] ?? ''));
    if ($command === '') {
      return NULL;
    }

    $label = trim((string) ($operation['label'] ?? $command));
    $description = trim((string) ($operation['description'] ?? ''));

    return [
      'label' => $label !== '' ? $label : $command,
      'description' => $description,
      'command' => $command,
    ];
  }

}
