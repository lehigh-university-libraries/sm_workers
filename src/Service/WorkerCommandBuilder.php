<?php

namespace Drupal\sm_workers\Service;

/**
 * Builds canonical Drush Symfony Messenger consume commands.
 *
 * Worker definitions are trusted module-defined input. The rendered command is
 * for operator inspection and process-manager configuration, not for passing to
 * a shell parser such as `sh -c`.
 */
final class WorkerCommandBuilder {

  /**
   * Builds a worker command from one definition.
   *
   * @param array<string, mixed> $definition
   *   Worker definition.
   */
  public function buildConsumeCommand(array $definition): string {
    $parts = ['drush', 'sm:consume'];

    if (!empty($definition['all'])) {
      $parts[] = '--all';
    }
    else {
      foreach ((array) ($definition['transports'] ?? []) as $transport) {
        $parts[] = (string) $transport;
      }
    }

    foreach ((array) ($definition['exclude_receivers'] ?? []) as $receiver) {
      $parts[] = '--exclude-receivers=' . $receiver;
    }

    foreach ((array) ($definition['queues'] ?? []) as $queue) {
      $parts[] = '--queues=' . $queue;
    }

    foreach ((array) ($definition['options'] ?? []) as $option => $value) {
      $option = (string) $option;
      if ($option === '') {
        continue;
      }

      if (is_bool($value)) {
        if ($value) {
          $parts[] = '--' . $option;
        }
        continue;
      }

      if ($value === NULL || $value === '') {
        continue;
      }

      $parts[] = '--' . $option . '=' . $value;
    }

    return implode(' ', $parts);
  }

  /**
   * Builds a shell-free command argv from an execution definition and tokens.
   *
   * Each element in the definition's 'args' list is resolved as follows:
   * - If the entire arg string matches a token key and the token value is a
   *   list<string>, the single arg is expanded into multiple argv entries.
   * - If the entire arg string matches a token key with a string value, it is
   *   replaced by that string as a single argv entry.
   * - Otherwise, every token key that appears anywhere in the arg string is
   *   substituted with its string value (lists are joined with a space for
   *   inline occurrences).
   *
   * @param array<string, mixed> $definition
   *   Normalized worker definition containing an 'execution' key.
   * @param array<string, string|list<string>> $tokens
   *   Resolved tokens from WorkerCommandTokenRegistry::resolveTokens().
   *
   * @return list<string>
   *   Fully resolved argv, or an empty list when no cmd is configured.
   */
  public function buildCommandArgv(array $definition, array $tokens = []): array {
    $execution = is_array($definition['execution'] ?? NULL) ? $definition['execution'] : [];
    $cmd = trim((string) ($execution['cmd'] ?? ''));
    if ($cmd === '') {
      return [];
    }

    $argv = [$cmd];

    // Pre-compute scalar tokens for inline substitution.
    $scalarTokens = [];
    foreach ($tokens as $key => $value) {
      $scalarTokens[(string) $key] = is_array($value)
        ? implode(' ', $value)
        : (string) $value;
    }

    foreach ((array) ($execution['args'] ?? []) as $template) {
      $template = (string) $template;

      // Standalone token — may expand to multiple args.
      if (array_key_exists($template, $tokens)) {
        $value = $tokens[$template];
        if (is_array($value)) {
          foreach ($value as $expanded) {
            $argv[] = (string) $expanded;
          }
        }
        else {
          $argv[] = (string) $value;
        }
        continue;
      }

      // Inline substitution for partial matches.
      $argv[] = strtr($template, $scalarTokens);
    }

    return $argv;
  }

}
