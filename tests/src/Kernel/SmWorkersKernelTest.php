<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_workers\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionManager;
use Drupal\sm_workers\ExecutionStrategy\WorkerRuntimeDefaults;
use Drupal\sm_workers\Service\CircuitBreakerService;
use Drupal\sm_workers\Service\WorkerCommandBuilder;
use Drupal\sm_workers\Service\WorkerDefinitionRegistry;
use Drupal\sm_workers\Service\WorkerOperationRegistry;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies tagged worker definition providers are wired into the container.
 */
#[RunTestsInSeparateProcesses]
final class SmWorkersKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'sm',
    'sm_workers',
    'sm_workers_test',
  ];

  /**
   * Tests the registry resolves tagged providers from the live container.
   */
  public function testTaggedProvidersAreCollected(): void {
    $registry = $this->container->get(WorkerDefinitionRegistry::class);
    $builder = $this->container->get(WorkerCommandBuilder::class);
    $operations = $this->container->get(WorkerOperationRegistry::class);
    $executionManager = $this->container->get(WorkerExecutionManager::class);

    $definition = $registry->get('sm_workers_test.example');

    static::assertNotNull($definition);
    static::assertSame('Test worker', $definition['label']);
    static::assertSame(['async_high', 'async_low'], $definition['transports']);
    static::assertSame(
      'drush sm:consume async_high async_low --time-limit=3600',
      $builder->buildConsumeCommand($definition),
    );
    static::assertSame(
      'drush sm:consume async_high async_low --time-limit=3600 --stop-when-empty',
      $operations->forWorker('sm_workers_test.example')[0]['command'],
    );
    static::assertInstanceOf(WorkerExecutionManager::class, $executionManager);
  }

  /**
   * Tests the shared circuit breaker service and default config are wired.
   */
  public function testCircuitBreakerServiceAndDefaultConfigAreAvailable(): void {
    $this->installConfig(['sm_workers']);
    $breakers = $this->container->get(CircuitBreakerService::class);
    $runtimeDefaults = $this->container->get(WorkerRuntimeDefaults::class);

    static::assertInstanceOf(CircuitBreakerService::class, $breakers);
    static::assertInstanceOf(WorkerRuntimeDefaults::class, $runtimeDefaults);
    static::assertSame(5, (int) $this->config('sm_workers.settings')->get('circuit_breakers.failure_threshold'));
    static::assertSame(300, (int) $this->config('sm_workers.settings')->get('circuit_breakers.cooldown_seconds'));
    static::assertSame(5, (int) $this->config('sm_workers.settings')->get('circuit_breakers.intake_pause_seconds'));
    static::assertSame(300, $runtimeDefaults->defaultTimeoutSeconds());
    static::assertTrue($runtimeDefaults->defaultForwardAuth());

    $breakers->ensure('test:breaker', 'Test breaker');
    static::assertSame('Test breaker', $breakers->getBreaker('test:breaker')['label']);
  }

}
