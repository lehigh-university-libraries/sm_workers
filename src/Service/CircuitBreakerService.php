<?php

declare(strict_types=1);

namespace Drupal\sm_workers\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\sm_workers\Exception\CircuitBreakerOpenException;
use Psr\Log\LoggerInterface;

/**
 * Stores and evaluates downstream circuit breaker state.
 *
 * Breakers are persisted in Drupal State and write paths are serialized with
 * Drupal's lock backend so concurrent worker/web requests do not lose updates.
 */
class CircuitBreakerService {

  private const STATE_KEY = 'sm_workers.circuit_breakers';
  private const METRICS_KEY = 'sm_workers.circuit_breaker_metrics';
  private const LOCK_NAME = 'sm_workers.circuit_breakers';
  private const LOCK_TTL_SECONDS = 5.0;
  private const COOLDOWN_JITTER_PERCENT = 20;

  /**
   * Constructs a circuit breaker service.
   */
  public function __construct(
    private StateInterface $state,
    private ConfigFactoryInterface $configFactory,
    private TimeInterface $time,
    private LoggerInterface $logger,
    private LockBackendInterface $lock,
  ) {}

  /**
   * Asserts that a breaker allows outbound work.
   */
  public function assertAllows(string $breakerId, string $label): void {
    $breaker = $this->getBreaker($breakerId);
    $status = (string) ($breaker['status'] ?? 'closed');
    $openUntil = (int) ($breaker['open_until'] ?? 0);
    $now = $this->time->getRequestTime();

    if ($status === 'manual_open') {
      $this->pauseIntake();
      $this->withBreakerLock(function () use ($breakerId): void {
        $this->incrementMetricState('sm_workers_circuit_breaker_short_circuit_total', $breakerId);
      });
      throw new CircuitBreakerOpenException(sprintf(
        'Circuit breaker "%s" is manually open. Reset it from the SM Workers admin UI before retrying outbound requests.',
        $label
      ), TRUE);
    }

    if ($status === 'open' && $openUntil > $now) {
      $this->pauseIntake(min($openUntil - $now, $this->intakePauseSeconds()));
      $this->withBreakerLock(function () use ($breakerId): void {
        $this->incrementMetricState('sm_workers_circuit_breaker_short_circuit_total', $breakerId);
      });
      throw new CircuitBreakerOpenException(sprintf(
        'Circuit breaker "%s" is open until %s after repeated downstream failures.',
        $label,
        gmdate(DATE_ATOM, $openUntil)
      ), FALSE, $openUntil);
    }
  }

  /**
   * Records a successful outbound request.
   */
  public function recordSuccess(string $breakerId): void {
    $result = $this->withBreakerLock(function () use ($breakerId): array {
      $breaker = $this->getBreaker($breakerId);
      $wasOpen = in_array((string) ($breaker['status'] ?? 'closed'), ['open', 'manual_open'], TRUE);
      $breaker['status'] = 'closed';
      $breaker['consecutive_failures'] = 0;
      $breaker['open_until'] = 0;
      $breaker['last_success_at'] = $this->time->getRequestTime();
      $breaker['last_error'] = '';
      $this->saveBreakerState($breakerId, $breaker);
      return [$wasOpen, (string) ($breaker['label'] ?? $breakerId)];
    });

    if ($result[0]) {
      $this->logger->notice('Circuit breaker {breaker_id} ({label}) closed after downstream recovery.', [
        'breaker_id' => $breakerId,
        'label' => $result[1],
      ]);
    }
  }

  /**
   * Records a failed outbound request.
   */
  public function recordFailure(string $breakerId, string $label, string $message): void {
    $opened = $this->withBreakerLock(function () use ($breakerId, $label, $message): array {
      $breaker = $this->getBreaker($breakerId);
      $previousStatus = (string) ($breaker['status'] ?? 'closed');
      $breaker['label'] = $label;
      $breaker['consecutive_failures'] = (int) ($breaker['consecutive_failures'] ?? 0) + 1;
      $breaker['last_failure_at'] = $this->time->getRequestTime();
      $breaker['last_error'] = $message;

      if ($breaker['consecutive_failures'] >= $this->failureThreshold()) {
        $breaker['status'] = 'open';
        $breaker['open_until'] = $this->time->getRequestTime() + $this->cooldownSecondsWithJitter();
      }
      else {
        $breaker['status'] = 'closed';
      }

      $this->saveBreakerState($breakerId, $breaker);

      if ($previousStatus !== 'open' && $breaker['status'] === 'open') {
        $this->incrementMetricState('sm_workers_circuit_breaker_open_total', $breakerId);
        return [TRUE, (int) $breaker['open_until']];
      }

      return [FALSE, 0];
    });

    if ($opened[0]) {
      $this->logger->warning('Circuit breaker {breaker_id} ({label}) opened after repeated downstream failures. It will remain open until {open_until}. Last error: {message}', [
        'breaker_id' => $breakerId,
        'label' => $label,
        'open_until' => gmdate(DATE_ATOM, $opened[1]),
        'message' => $message,
      ]);
    }
  }

  /**
   * Manually opens a breaker from the UI.
   */
  public function manuallyTrip(string $breakerId, string $label): void {
    $this->withBreakerLock(function () use ($breakerId, $label): void {
      $breaker = $this->getBreaker($breakerId);
      $breaker['status'] = 'manual_open';
      $breaker['label'] = $label;
      $breaker['open_until'] = 0;
      $breaker['last_failure_at'] = $this->time->getRequestTime();
      $breaker['last_error'] = 'Manually opened from admin UI.';
      $this->saveBreakerState($breakerId, $breaker);
      $this->incrementMetricState('sm_workers_circuit_breaker_manual_open_total', $breakerId);
    });
    $this->logger->warning('Circuit breaker {breaker_id} ({label}) was manually opened from the admin UI.', [
      'breaker_id' => $breakerId,
      'label' => $label,
    ]);
  }

  /**
   * Resets a breaker from the UI.
   */
  public function reset(string $breakerId): void {
    $label = $this->withBreakerLock(function () use ($breakerId): string {
      $breaker = $this->getBreaker($breakerId);
      $breaker['status'] = 'closed';
      $breaker['consecutive_failures'] = 0;
      $breaker['open_until'] = 0;
      $breaker['last_error'] = '';
      $this->saveBreakerState($breakerId, $breaker);
      return (string) ($breaker['label'] ?? $breakerId);
    });
    $this->logger->notice('Circuit breaker {breaker_id} ({label}) was reset from the admin UI.', [
      'breaker_id' => $breakerId,
      'label' => $label,
    ]);
  }

  /**
   * Ensures a breaker exists and returns its state.
   *
   * @return array<string, mixed>
   *   Breaker state.
   */
  public function ensure(string $breakerId, string $label): array {
    return $this->withBreakerLock(function () use ($breakerId, $label): array {
      $breaker = $this->getBreaker($breakerId);
      $breaker['label'] = $label;
      $this->saveBreakerState($breakerId, $breaker);
      return $breaker;
    });
  }

  /**
   * Returns all known breakers.
   *
   * @return array<string, array<string, mixed>>
   *   Breaker data keyed by ID.
   */
  public function all(): array {
    $breakers = $this->state->get(self::STATE_KEY, []);
    return is_array($breakers) ? $breakers : [];
  }

  /**
   * Returns one breaker state.
   *
   * @return array<string, mixed>
   *   Breaker state.
   */
  public function getBreaker(string $breakerId): array {
    $breakers = $this->all();
    return is_array($breakers[$breakerId] ?? NULL) ? $breakers[$breakerId] : [
      'status' => 'closed',
      'label' => $breakerId,
      'consecutive_failures' => 0,
      'open_until' => 0,
      'last_failure_at' => 0,
      'last_success_at' => 0,
      'last_error' => '',
    ];
  }

  /**
   * Saves breaker state.
   *
   * @param string $breakerId
   *   The breaker identifier.
   * @param array<string, mixed> $breaker
   *   Breaker state.
   */
  private function saveBreakerState(string $breakerId, array $breaker): void {
    $breakers = $this->all();
    $breakers[$breakerId] = $breaker;
    $this->state->set(self::STATE_KEY, $breakers);
  }

  /**
   * Increments one monotonic breaker metric.
   */
  private function incrementMetricState(string $metricName, string $breakerId): void {
    $metrics = $this->state->get(self::METRICS_KEY, []);
    $metrics = is_array($metrics) ? $metrics : [];
    $values = $metrics[$metricName] ?? [];
    $values = is_array($values) ? $values : [];
    $values[$breakerId] = ((int) ($values[$breakerId] ?? 0)) + 1;
    $metrics[$metricName] = $values;
    $this->state->set(self::METRICS_KEY, $metrics);
  }

  /**
   * Runs one mutation while holding the shared circuit-breaker lock.
   */
  private function withBreakerLock(callable $callback): mixed {
    if (!$this->lock->acquire(self::LOCK_NAME, self::LOCK_TTL_SECONDS)) {
      throw new \RuntimeException('Unable to acquire the SM Workers circuit breaker lock.');
    }

    try {
      return $callback();
    }
    finally {
      $this->lock->release(self::LOCK_NAME);
    }
  }

  /**
   * Returns failure threshold from config.
   */
  private function failureThreshold(): int {
    $value = (int) $this->configFactory->get('sm_workers.settings')
      ->get('circuit_breakers.failure_threshold');
    return max(1, $value ?: 5);
  }

  /**
   * Returns cooldown duration from config.
   */
  private function cooldownSeconds(): int {
    $value = (int) $this->configFactory->get('sm_workers.settings')
      ->get('circuit_breakers.cooldown_seconds');
    return max(1, $value ?: 300);
  }

  /**
   * Returns worker intake pause while a breaker is open.
   */
  private function intakePauseSeconds(): int {
    $value = (int) $this->configFactory->get('sm_workers.settings')
      ->get('circuit_breakers.intake_pause_seconds');
    return max(0, $value);
  }

  /**
   * Returns cooldown duration with jitter to avoid coordinated recovery probes.
   */
  private function cooldownSecondsWithJitter(): int {
    $base = $this->cooldownSeconds();
    $jitterPercent = random_int(-self::COOLDOWN_JITTER_PERCENT, self::COOLDOWN_JITTER_PERCENT);
    $adjusted = (int) round($base * (1 + ($jitterPercent / 100)));
    return max(1, $adjusted);
  }

  /**
   * Applies a small pause before rejecting intake against an open breaker.
   */
  private function pauseIntake(?int $seconds = NULL): void {
    $seconds ??= $this->intakePauseSeconds();
    if ($seconds <= 0) {
      return;
    }

    usleep($seconds * 1000000);
  }

}
