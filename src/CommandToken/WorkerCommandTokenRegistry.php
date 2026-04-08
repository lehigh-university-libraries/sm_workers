<?php

declare(strict_types=1);

namespace Drupal\sm_workers\CommandToken;

/**
 * Collects and resolves command tokens from tagged providers.
 *
 * Providers are registered via the sm_workers.command_token_provider service
 * tag. When multiple providers return the same token key, later registrations
 * win.
 *
 * @see \Drupal\sm_workers\CommandToken\WorkerCommandTokenProviderInterface
 */
final class WorkerCommandTokenRegistry {

  /**
   * Constructs the registry.
   *
   * @param iterable<\Drupal\sm_workers\CommandToken\WorkerCommandTokenProviderInterface> $providers
   *   Tagged command token providers.
   */
  public function __construct(
    private iterable $providers,
  ) {}

  /**
   * Resolves all token values for the given execution context.
   *
   * @param array<string, mixed> $context
   *   Execution context forwarded to each provider.
   *
   * @return array<string, string|list<string>>
   *   Merged token map from all providers. Later providers override earlier
   *   ones for the same key.
   */
  public function resolveTokens(array $context): array {
    $tokens = [];
    foreach ($this->providers as $provider) {
      if (!$provider instanceof WorkerCommandTokenProviderInterface) {
        continue;
      }
      foreach ($provider->getTokens($context) as $token => $value) {
        $tokens[(string) $token] = $value;
      }
    }
    return $tokens;
  }

}
