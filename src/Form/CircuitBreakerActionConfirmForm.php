<?php

declare(strict_types=1);

namespace Drupal\sm_workers\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\sm_workers\Service\CircuitBreakerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms breaker trip/reset actions.
 */
class CircuitBreakerActionConfirmForm extends ConfirmFormBase {

  /**
   * Current breaker action.
   */
  protected string $action = '';

  /**
   * Current breaker ID.
   */
  protected string $breakerId = '';

  /**
   * Constructs the confirm form.
   */
  public function __construct(
    private CircuitBreakerService $circuitBreakers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('sm_workers.circuit_breaker'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sm_workers_circuit_breaker_action_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return $this->action === 'trip'
      ? (string) $this->t('Trip circuit breaker %id?', ['%id' => $this->breakerId])
      : (string) $this->t('Reset circuit breaker %id?', ['%id' => $this->breakerId]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->action === 'trip'
      ? (string) $this->t('This will immediately stop outbound requests guarded by this breaker until it is reset.')
      : (string) $this->t('This will close the breaker and allow outbound requests again.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return $this->action === 'trip'
      ? (string) $this->t('Trip')
      : (string) $this->t('Reset');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('sm_workers.circuit_breakers');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $breaker_id = '', string $action = ''): array {
    $this->breakerId = $breaker_id;
    $this->action = $action;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $breaker = $this->circuitBreakers->getBreaker($this->breakerId);
    $label = (string) ($breaker['label'] ?? $this->breakerId);

    if ($this->action === 'trip') {
      $this->circuitBreakers->manuallyTrip($this->breakerId, $label);
      $this->messenger()->addStatus($this->t('Circuit breaker %label was opened.', ['%label' => $label]));
    }
    else {
      $this->circuitBreakers->reset($this->breakerId);
      $this->messenger()->addStatus($this->t('Circuit breaker %label was reset.', ['%label' => $label]));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
