<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_workers\Unit;

use Drupal\sm_workers\Service\WorkerCommandExecutor;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for shell-free worker command execution.
 */
final class WorkerCommandExecutorUnitTest extends UnitTestCase {

  /**
   * Tests stdout is returned for a successful command.
   */
  public function testRunReturnsStdout(): void {
    $executor = new WorkerCommandExecutor($this->createMock(LoggerInterface::class));

    $stdout = $executor->run(
      ['php', '-r', 'fwrite(STDOUT, strtoupper(stream_get_contents(STDIN)));'],
      'ok',
    );

    static::assertSame('OK', $stdout);
  }

  /**
   * Tests long-running commands are terminated at the configured timeout.
   */
  public function testRunTimesOutLongRunningCommand(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $executor = new WorkerCommandExecutor($logger);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('timed out after 1 seconds');
    $executor->run(
      ['php', '-r', 'sleep(2);'],
      '',
      [],
      1,
    );
  }

  /**
   * Tests heartbeat callbacks are invoked while the subprocess is running.
   */
  public function testRunInvokesHeartbeatForLongRunningCommand(): void {
    $executor = new WorkerCommandExecutor($this->createMock(LoggerInterface::class));
    $heartbeats = 0;

    $stdout = $executor->run(
      ['php', '-r', 'usleep(1200000); fwrite(STDOUT, "done");'],
      '',
      [],
      3,
      NULL,
      NULL,
      function () use (&$heartbeats): void {
        $heartbeats++;
      },
      1,
    );

    static::assertSame('done', $stdout);
    static::assertGreaterThanOrEqual(1, $heartbeats);
  }

  /**
   * Tests stderr is included in failure exceptions for operator visibility.
   */
  public function testRunIncludesStderrInFailureMessage(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $executor = new WorkerCommandExecutor($logger);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('exit code 2: boom');
    $executor->run(
      ['php', '-r', 'fwrite(STDERR, "boom"); exit(2);'],
      '',
    );
  }

}
