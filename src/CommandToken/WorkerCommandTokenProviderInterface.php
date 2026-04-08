<?php

declare(strict_types=1);

namespace Drupal\sm_workers\CommandToken;

/**
 * Contributes token values for command argument substitution.
 *
 * Implementations are collected by WorkerCommandTokenRegistry via the
 * sm_workers.command_token_provider service tag. This is the extension point
 * for injecting context-specific values into command argv templates without
 * coupling the execution machinery to domain-specific knowledge.
 *
 * @see \Drupal\sm_workers\CommandToken\WorkerCommandTokenRegistry
 * @see \Drupal\sm_workers\Service\WorkerCommandBuilder::buildCommandArgv()
 */
interface WorkerCommandTokenProviderInterface {

  /**
   * Returns token replacements for command argument substitution.
   *
   * @param array<string, mixed> $context
   *   Execution context passed by the caller. Providers should document which
   *   keys they consume and must tolerate any key being absent. Common
   *   conventions:
   *   - 'payload': normalized payload string
   *   - 'metadata': array<string, mixed> work-item metadata
   *   - 'worker': array<string, mixed> resolved worker configuration
   *   - 'authorization': optional bearer token string.
   *
   * @return array<string, string|list<string>>
   *   Token → replacement map. String values are substituted inline wherever
   *   the token appears in an arg template. When the value is a list<string>
   *   and the token is the entire arg template (not embedded in a larger
   *   string), the arg expands into multiple args — one per list element. If
   *   a list value appears as part of a larger template string it is joined
   *   with a single space.
   */
  public function getTokens(array $context): array;

}
