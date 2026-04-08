<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_workers\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\sm_workers\Exception\CircuitBreakerOpenException;
use Drupal\sm_workers\Service\CircuitBreakerService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for circuit breaker state management.
 */
final class CircuitBreakerServiceUnitTest extends UnitTestCase {

  /**
   * Tests manual opens block outbound work with the shared UI message.
   */
  public function testAssertAllowsRejectsManualOpenBreaker(): void {
    $stateValues = [
      'sm_workers.circuit_breakers' => [
        'derivative:houdini' => [
          'status' => 'manual_open',
          'label' => 'Derivative queue houdini',
          'consecutive_failures' => 0,
          'open_until' => 0,
          'last_failure_at' => 0,
          'last_success_at' => 0,
          'last_error' => '',
        ],
      ],
    ];
    $service = $this->createService($stateValues, 1700000000);

    $this->expectException(CircuitBreakerOpenException::class);
    $this->expectExceptionMessage('SM Workers admin UI');
    $service->assertAllows('derivative:houdini', 'Derivative queue houdini');
  }

  /**
   * Tests open breakers expose the retry timestamp through the exception.
   */
  public function testAssertAllowsRejectsOpenBreakerWithRetryTime(): void {
    $stateValues = [
      'sm_workers.circuit_breakers' => [
        'index:fedora' => [
          'status' => 'open',
          'label' => 'Index target Fedora/fcrepo',
          'consecutive_failures' => 5,
          'open_until' => 1700000300,
          'last_failure_at' => 1700000000,
          'last_success_at' => 0,
          'last_error' => 'boom',
        ],
      ],
    ];
    $service = $this->createService($stateValues, 1700000000);

    try {
      $service->assertAllows('index:fedora', 'Index target Fedora/fcrepo');
      self::fail('Expected breaker-open exception was not thrown.');
    }
    catch (CircuitBreakerOpenException $exception) {
      self::assertFalse($exception->isManualOpen());
      self::assertSame(1700000300, $exception->retryAfter());
      self::assertStringContainsString('open until', $exception->getMessage());
    }
  }

  /**
   * Tests failures open the breaker and emit the shared metric key.
   */
  public function testRecordFailureOpensBreakerAndIncrementsMetric(): void {
    $stateValues = [];
    $state = $this->createState($stateValues);

    $service = $this->createServiceFromDoubles(
      $state,
      $this->createConfigFactory(1, 300, 0),
      $this->createTime(1700000000),
      $this->createMock(LoggerInterface::class),
    );

    $service->recordFailure('index:fedora', 'Index target fedora', 'Downstream failed.');

    self::assertSame('open', $stateValues['sm_workers.circuit_breakers']['index:fedora']['status']);
    self::assertGreaterThan(1700000000, $stateValues['sm_workers.circuit_breakers']['index:fedora']['open_until']);
    self::assertSame(1, $stateValues['sm_workers.circuit_breaker_metrics']['sm_workers_circuit_breaker_open_total']['index:fedora']);
  }

  /**
   * Tests reset clears the open state and retained error.
   */
  public function testResetClosesBreaker(): void {
    $stateValues = [
      'sm_workers.circuit_breakers' => [
        'index:fedora' => [
          'status' => 'open',
          'label' => 'Index target fedora',
          'consecutive_failures' => 4,
          'open_until' => 1700000300,
          'last_failure_at' => 1700000000,
          'last_success_at' => 0,
          'last_error' => 'boom',
        ],
      ],
    ];

    $service = $this->createService($stateValues, 1700000000);
    $service->reset('index:fedora');

    self::assertSame('closed', $stateValues['sm_workers.circuit_breakers']['index:fedora']['status']);
    self::assertSame(0, $stateValues['sm_workers.circuit_breakers']['index:fedora']['consecutive_failures']);
    self::assertSame('', $stateValues['sm_workers.circuit_breakers']['index:fedora']['last_error']);
  }

  /**
   * Creates the service with default shared config.
   *
   * @param array<string, mixed> $stateValues
   *   Initial state values.
   * @param int $requestTime
   *   Request timestamp returned by the time service double.
   */
  private function createService(array &$stateValues, int $requestTime): CircuitBreakerService {
    return $this->createServiceFromDoubles(
      $this->createState($stateValues),
      $this->createConfigFactory(5, 300, 0),
      $this->createTime($requestTime),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Creates the service from explicit test doubles.
   */
  private function createServiceFromDoubles(
    StateInterface $state,
    ConfigFactoryInterface $configFactory,
    TimeInterface $time,
    LoggerInterface $logger,
  ): CircuitBreakerService {
    return new CircuitBreakerService(
      $state,
      $configFactory,
      $time,
      $logger,
      $this->createLock(),
    );
  }

  /**
   * Creates a mutable in-memory state double.
   *
   * @param array<string, mixed> $values
   *   Backing state values.
   */
  private function createState(array &$values): StateInterface {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->willReturnCallback(static function (string $key, mixed $default = NULL) use (&$values): mixed {
        return $values[$key] ?? $default;
      });
    $state->method('set')
      ->willReturnCallback(static function (string $key, mixed $value) use (&$values): void {
        $values[$key] = $value;
      });

    return $state;
  }

  /**
   * Creates the shared config factory double.
   *
   * @param int $failureThreshold
   *   Number of failures required to open the breaker.
   * @param int $cooldownSeconds
   *   Time to keep the breaker open before retry is allowed.
   * @param int $intakePauseSeconds
   *   How long open breakers should pause worker intake.
   */
  private function createConfigFactory(
    int $failureThreshold,
    int $cooldownSeconds,
    int $intakePauseSeconds,
  ): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnMap([
        ['circuit_breakers.failure_threshold', $failureThreshold],
        ['circuit_breakers.cooldown_seconds', $cooldownSeconds],
        ['circuit_breakers.intake_pause_seconds', $intakePauseSeconds],
      ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('sm_workers.settings')
      ->willReturn($config);

    return $configFactory;
  }

  /**
   * Creates a time double.
   *
   * @param int $requestTime
   *   Request timestamp returned by the double.
   */
  private function createTime(int $requestTime): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($requestTime);
    return $time;
  }

  /**
   * Creates a permissive lock backend double.
   */
  private function createLock(): LockBackendInterface {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $lock->method('release')->willReturn(TRUE);
    return $lock;
  }

}
