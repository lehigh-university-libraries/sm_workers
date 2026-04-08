<?php

namespace Drupal\sm_workers\Commands;

use Drupal\sm_workers\Service\WorkerCommandBuilder;
use Drupal\sm_workers\Service\WorkerDefinitionRegistry;
use Drupal\sm_workers\Service\WorkerOperationRegistry;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Symfony Messenger worker definitions.
 */
final class SmWorkersCommands extends DrushCommands {

  /**
   * Constructs the commands object.
   */
  public function __construct(
    private WorkerDefinitionRegistry $registry,
    private WorkerCommandBuilder $commandBuilder,
    private WorkerOperationRegistry $operationRegistry,
  ) {}

  /**
   * Lists registered worker definitions.
   */
  #[CLI\Command(name: 'sm-workers:list')]
  #[CLI\Help(description: 'List registered Symfony Messenger worker definitions and their canonical consume commands.')]
  public function listWorkers(): void {
    $definitions = $this->registry->all();

    if ($definitions === []) {
      $this->output()->writeln('No Symfony Messenger worker definitions are registered.');
      return;
    }

    foreach ($definitions as $definition) {
      $this->output()->writeln(sprintf('<info>%s</info> (%s)', $definition['label'], $definition['id']));
      if ($definition['description'] !== '') {
        $this->output()->writeln('  ' . $definition['description']);
      }
      $this->output()->writeln('  ' . $this->commandBuilder->buildConsumeCommand($definition));
      foreach ($this->operationRegistry->forWorker((string) $definition['id']) as $operation) {
        $this->output()->writeln(sprintf('  [%s] %s', $operation['label'], $operation['command']));
        if ($operation['description'] !== '') {
          $this->output()->writeln('    ' . $operation['description']);
        }
      }
      $this->output()->writeln('');
    }
  }

  /**
   * Prints the consume command for one worker definition.
   */
  #[CLI\Command(name: 'sm-workers:command')]
  #[CLI\Argument(name: 'worker-id', description: 'Worker definition ID.')]
  #[CLI\Help(description: 'Print the canonical drush sm:consume command for one worker definition.')]
  public function commandForWorker(string $workerId): void {
    $definition = $this->registry->get($workerId);
    if ($definition === NULL) {
      throw new \InvalidArgumentException(sprintf('Unknown worker definition "%s".', $workerId));
    }

    $this->output()->writeln($this->commandBuilder->buildConsumeCommand($definition));
  }

  /**
   * Prints operator-facing commands associated with one worker definition.
   */
  #[CLI\Command(name: 'sm-workers:operations')]
  #[CLI\Argument(name: 'worker-id', description: 'Worker definition ID.')]
  #[CLI\Help(description: 'Print operator-facing commands associated with one worker definition.')]
  public function operationsForWorker(string $workerId): void {
    $definition = $this->registry->get($workerId);
    if ($definition === NULL) {
      throw new \InvalidArgumentException(sprintf('Unknown worker definition "%s".', $workerId));
    }

    $operations = $this->operationRegistry->forWorker($workerId);
    if ($operations === []) {
      $this->output()->writeln(sprintf('No additional operations are registered for "%s".', $workerId));
      return;
    }

    foreach ($operations as $operation) {
      $this->output()->writeln(sprintf('<info>%s</info>', $operation['label']));
      if ($operation['description'] !== '') {
        $this->output()->writeln('  ' . $operation['description']);
      }
      $this->output()->writeln('  ' . $operation['command']);
      $this->output()->writeln('');
    }
  }

}
