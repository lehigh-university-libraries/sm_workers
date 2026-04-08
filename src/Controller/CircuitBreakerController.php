<?php

declare(strict_types=1);

namespace Drupal\sm_workers\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\sm_workers\CircuitBreakerPrimerInterface;
use Drupal\sm_workers\Service\CircuitBreakerService;

/**
 * Builds a circuit breaker overview page.
 */
class CircuitBreakerController extends ControllerBase {

  /**
   * Constructs a circuit breaker controller.
   *
   * @param \Drupal\sm_workers\Service\CircuitBreakerService $circuitBreakers
   *   Circuit breaker state service.
   * @param iterable<\Drupal\sm_workers\CircuitBreakerPrimerInterface> $primers
   *   Tagged circuit breaker primers.
   */
  public function __construct(
    private CircuitBreakerService $circuitBreakers,
    private iterable $primers,
  ) {}

  /**
   * Builds the breaker page.
   */
  public function overview(): array {
    foreach ($this->primers as $primer) {
      if ($primer instanceof CircuitBreakerPrimerInterface) {
        $primer->prime($this->circuitBreakers);
      }
    }

    $rows = [];
    foreach ($this->circuitBreakers->all() as $breakerId => $breaker) {
      $rows[] = [
        'data' => [
          $breaker['label'] ?? $breakerId,
          $breakerId,
          $breaker['status'] ?? 'closed',
          (int) ($breaker['consecutive_failures'] ?? 0),
          ((int) ($breaker['open_until'] ?? 0)) > 0 ? gmdate(DATE_ATOM, (int) $breaker['open_until']) : '-',
          (string) ($breaker['last_error'] ?? ''),
          Link::fromTextAndUrl($this->t('Trip'), Url::fromRoute('sm_workers.circuit_breaker_trip', ['breaker_id' => $breakerId])),
          Link::fromTextAndUrl($this->t('Reset'), Url::fromRoute('sm_workers.circuit_breaker_reset', ['breaker_id' => $breakerId])),
        ],
      ];
    }

    return [
      'help' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Circuit breakers protect downstream integrations when repeated failures occur.'),
          $this->t('Trip opens a breaker immediately from the UI. Reset closes it and allows outbound requests again.'),
          $this->t('Automatic opens, manual trips, resets, and recovery closes are written to Drupal logs for historical review.'),
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Label'),
          $this->t('Breaker ID'),
          $this->t('Status'),
          $this->t('Consecutive failures'),
          $this->t('Open until'),
          $this->t('Last error'),
          $this->t('Trip'),
          $this->t('Reset'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No circuit breakers have been registered yet. Breakers appear after first use of a downstream integration.'),
      ],
    ];
  }

}
