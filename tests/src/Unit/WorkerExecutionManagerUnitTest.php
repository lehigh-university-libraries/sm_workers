<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_workers\Unit;

use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionContext;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionManager;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionResult;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionStrategyInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for strategy selection in the execution manager.
 */
final class WorkerExecutionManagerUnitTest extends UnitTestCase {

  /**
   * Tests the first supporting strategy is executed.
   */
  public function testExecuteUsesMatchingStrategy(): void {
    $context = new WorkerExecutionContext('payload', ['key' => 'value'], ['queue' => 'example'], 'Bearer token');

    $strategy = $this->createMock(WorkerExecutionStrategyInterface::class);
    $strategy->expects($this->once())
      ->method('supports')
      ->with('http')
      ->willReturn(TRUE);
    $strategy->expects($this->once())
      ->method('execute')
      ->with($context)
      ->willReturn(new WorkerExecutionResult('body', 'text/plain'));

    $manager = new WorkerExecutionManager([$strategy]);
    $result = $manager->execute('http', $context);

    static::assertSame('body', $result->body());
    static::assertSame('text/plain', $result->contentType());
  }

  /**
   * Tests an unknown mode raises a clear runtime exception.
   */
  public function testExecuteThrowsForUnknownMode(): void {
    $strategy = $this->createMock(WorkerExecutionStrategyInterface::class);
    $strategy->method('supports')->with('missing')->willReturn(FALSE);

    $manager = new WorkerExecutionManager([$strategy]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No worker execution strategy is registered');
    $manager->execute('missing', new WorkerExecutionContext('payload', [], []));
  }

}
