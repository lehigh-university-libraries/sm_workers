<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_workers\Unit;

use Drupal\sm_workers\CircuitBreakerPrimerInterface;
use Drupal\sm_workers\Controller\CircuitBreakerController;
use Drupal\sm_workers\Service\CircuitBreakerService;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the shared circuit breaker admin overview.
 */
final class CircuitBreakerControllerUnitTest extends UnitTestCase {

  /**
   * Tests primers are invoked before the breaker table is rendered.
   */
  public function testOverviewPrimesBreakersAndBuildsRoutes(): void {
    $circuitBreakers = $this->createMock(CircuitBreakerService::class);
    $circuitBreakers->expects($this->once())
      ->method('ensure')
      ->with('derivative:houdini', 'Derivative queue houdini');
    $circuitBreakers->expects($this->once())
      ->method('all')
      ->willReturn([
        'derivative:houdini' => [
          'label' => 'Derivative queue houdini',
          'status' => 'closed',
          'consecutive_failures' => 0,
          'open_until' => 0,
          'last_error' => '',
        ],
      ]);

    $primer = new class implements CircuitBreakerPrimerInterface {

      /**
       * {@inheritdoc}
       */
      public function prime(CircuitBreakerService $breakers): void {
        $breakers->ensure('derivative:houdini', 'Derivative queue houdini');
      }

    };

    $controller = new CircuitBreakerController($circuitBreakers, [$primer]);
    $controller->setStringTranslation($this->getStringTranslationStub());

    $build = $controller->overview();

    self::assertSame('table', $build['table']['#type']);
    self::assertCount(1, $build['table']['#rows']);
    self::assertSame('Derivative queue houdini', $build['table']['#rows'][0]['data'][0]);
    self::assertSame('sm_workers.circuit_breaker_trip', $build['table']['#rows'][0]['data'][6]->getUrl()->getRouteName());
    self::assertSame(['breaker_id' => 'derivative:houdini'], $build['table']['#rows'][0]['data'][6]->getUrl()->getRouteParameters());
    self::assertSame('sm_workers.circuit_breaker_reset', $build['table']['#rows'][0]['data'][7]->getUrl()->getRouteName());
    self::assertSame(['breaker_id' => 'derivative:houdini'], $build['table']['#rows'][0]['data'][7]->getUrl()->getRouteParameters());
  }

}
