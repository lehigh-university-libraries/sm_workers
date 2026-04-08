<?php

namespace Drupal\sm_workers\Service;

use Drupal\sm_workers\WorkerDefinitionProviderInterface;

/**
 * Collects worker definitions from tagged providers.
 */
final class WorkerDefinitionRegistry {

  /**
   * Memoized worker definitions from tagged providers.
   *
   * Tagged iterators may be lazy single-pass iterables, so the resolved
   * definitions are cached after the first traversal.
   *
   * @var array<string, array<string, mixed>>|null
   */
  private ?array $definitions = NULL;

  /**
   * Constructs the registry.
   *
   * @param iterable<\Drupal\sm_workers\WorkerDefinitionProviderInterface> $providers
   *   Tagged worker definition providers.
   */
  public function __construct(
    private iterable $providers,
  ) {}

  /**
   * Returns all worker definitions keyed by worker ID.
   *
   * @return array<string, array<string, mixed>>
   *   Normalized worker definitions.
   */
  public function all(): array {
    if ($this->definitions !== NULL) {
      return $this->definitions;
    }

    $definitions = [];

    foreach ($this->providers as $provider) {
      if (!$provider instanceof WorkerDefinitionProviderInterface) {
        continue;
      }

      foreach ($provider->getWorkerDefinitions() as $workerId => $definition) {
        $definitions[(string) $workerId] = $this->normalizeDefinition((string) $workerId, $definition);
      }
    }

    ksort($definitions);
    $this->definitions = $definitions;
    return $this->definitions;
  }

  /**
   * Returns a single worker definition.
   */
  public function get(string $workerId): ?array {
    $definitions = $this->all();
    return $definitions[$workerId] ?? NULL;
  }

  /**
   * Normalizes one worker definition.
   *
   * @param string $workerId
   *   Worker definition ID.
   * @param array<string, mixed> $definition
   *   Raw worker definition.
   *
   * @return array<string, mixed>
   *   Normalized worker definition.
   */
  private function normalizeDefinition(string $workerId, array $definition): array {
    $transports = array_values(array_filter(
      array_map('strval', (array) ($definition['transports'] ?? [])),
      static fn (string $value): bool => $value !== '',
    ));
    $excludeReceivers = array_values(array_filter(
      array_map('strval', (array) ($definition['exclude_receivers'] ?? [])),
      static fn (string $value): bool => $value !== '',
    ));
    $queues = array_values(array_filter(
      array_map('strval', (array) ($definition['queues'] ?? [])),
      static fn (string $value): bool => $value !== '',
    ));
    $options = $definition['options'] ?? [];
    $options = is_array($options) ? $options : [];

    return [
      'id' => $workerId,
      'label' => (string) ($definition['label'] ?? $workerId),
      'description' => (string) ($definition['description'] ?? ''),
      'all' => (bool) ($definition['all'] ?? FALSE),
      'transports' => $transports,
      'exclude_receivers' => $excludeReceivers,
      'queues' => $queues,
      'options' => $options,
      'execution' => $this->normalizeExecutionConfig(
        is_array($definition['execution'] ?? NULL) ? $definition['execution'] : [],
      ),
    ];
  }

  /**
   * Normalizes a worker execution configuration block.
   *
   * The execution block declares how worker-mode payload handling should run
   * when a consumer module opts into strategy-managed execution. It provides a
   * mode string for strategy selection, a command binary when applicable, and
   * tokenized args resolved by tagged token providers.
   *
   * @param array<string, mixed> $execution
   *   Raw execution config from the worker definition.
   *
   * @return array<string, mixed>
   *   Normalized execution config.
   */
  private function normalizeExecutionConfig(array $execution): array {
    $mode = trim((string) ($execution['mode'] ?? ''));
    $cmd = trim((string) ($execution['cmd'] ?? ''));
    $args = array_map(
      static fn (mixed $v): string => (string) $v,
      (array) ($execution['args'] ?? []),
    );

    if ($mode !== '' && $cmd === '' && $args !== []) {
      throw new \InvalidArgumentException(sprintf(
        'Worker execution mode "%s" cannot define args without a command binary.',
        $mode,
      ));
    }

    return [
      'mode' => $mode,
      'cmd' => $cmd,
      'args' => $args,
      'forward_auth' => isset($execution['forward_auth'])
        ? (bool) $execution['forward_auth']
        : TRUE,
    ];
  }

}
