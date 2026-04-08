<?php

declare(strict_types=1);

namespace Drupal\sm_workers\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures SM Workers runtime defaults.
 */
final class SmWorkersSettingsForm extends ConfigFormBase {

  private const REDLIVERY_WARNING_RATIO = 0.8;

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   Typed config manager.
   * @param array<string, array<string, mixed>> $transports
   *   SM transport definitions.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    private array $transports = [],
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->hasParameter('sm.transports') ? (array) $container->getParameter('sm.transports') : [],
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['sm_workers.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sm_workers_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('sm_workers.settings');
    $minRedeliverTimeout = $this->minSqlTransportRedeliverTimeout();
    $defaultTimeoutSeconds = (int) $config->get('execution.default_timeout_seconds');

    $form['help'] = [
      '#type' => 'details',
      '#title' => $this->t('How To Tune These Settings'),
      '#open' => TRUE,
      '#weight' => -20,
    ];
    $form['help']['summary'] = [
      '#type' => 'item',
      '#markup' => $this->t('Use this page for shared worker defaults that apply when a specific worker definition does not override them. Tune execution timeout conservatively so work completes well before SQL transport redelivery.'),
    ];
    $form['help']['breaker_guidance'] = [
      '#type' => 'item',
      '#title' => $this->t('Circuit-breaker guidance'),
      '#markup' => $this->t('Lower the failure threshold for dependencies that fail fast and predictably. Raise cooldown when a downstream service needs longer to recover. Keep the intake pause small so workers avoid hot-loop retries without stalling queue throughput.'),
    ];

    $form['execution'] = [
      '#type' => 'details',
      '#title' => $this->t('Execution defaults'),
      '#open' => TRUE,
    ];
    $form['execution']['default_timeout_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Default timeout (seconds)'),
      '#default_value' => $defaultTimeoutSeconds,
      '#min' => 1,
      '#description' => $this->t('Fallback timeout used when a worker definition does not set its own timeout. Leave room for write-back, breaker pauses, and runtime overhead before transport redelivery occurs.'),
    ];
    if ($minRedeliverTimeout !== NULL) {
      $form['execution']['transport_timeout_notice'] = [
        '#type' => 'item',
        '#title' => $this->t('Transport visibility timeout'),
        '#markup' => $this->t('The smallest configured SQL transport redelivery timeout is @seconds seconds. The current shared default timeout is @timeout seconds, leaving @remaining seconds before transport redelivery.', [
          '@seconds' => $minRedeliverTimeout,
          '@timeout' => $defaultTimeoutSeconds,
          '@remaining' => max(0, $minRedeliverTimeout - $defaultTimeoutSeconds),
        ]),
      ];
      if ($defaultTimeoutSeconds >= (int) floor($minRedeliverTimeout * self::REDLIVERY_WARNING_RATIO)) {
        $form['execution']['transport_timeout_warning'] = [
          '#type' => 'status_messages',
          '#weight' => -10,
        ];
        $this->messenger()->addWarning($this->t('The current default worker timeout is close to the smallest SQL transport redelivery timeout (@seconds seconds). Leave room for downstream write-back and runtime overhead.', [
          '@seconds' => $minRedeliverTimeout,
        ]));
      }
    }
    $form['execution']['default_forward_auth'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Forward authorization by default'),
      '#default_value' => (bool) $config->get('execution.default_forward_auth'),
      '#description' => $this->t('When enabled, strategy-managed workers inherit bearer-token forwarding unless a worker definition overrides it explicitly. Disable this only when downstream services should not receive caller context by default.'),
    ];

    $form['circuit_breakers'] = [
      '#type' => 'details',
      '#title' => $this->t('Circuit breakers'),
      '#open' => TRUE,
    ];
    $form['circuit_breakers']['failure_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Failure threshold'),
      '#default_value' => (int) $config->get('circuit_breakers.failure_threshold'),
      '#min' => 1,
      '#description' => $this->t('Consecutive downstream failures required before a breaker opens automatically. Lower values protect unstable dependencies sooner; higher values tolerate brief transient blips.'),
    ];
    $form['circuit_breakers']['cooldown_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Cooldown (seconds)'),
      '#default_value' => (int) $config->get('circuit_breakers.cooldown_seconds'),
      '#min' => 1,
      '#description' => $this->t('How long an automatically opened breaker remains open before probes are allowed again. Set this to roughly the time a dependency usually needs to recover after an outage.'),
    ];
    $form['circuit_breakers']['intake_pause_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Open-breaker intake pause (seconds)'),
      '#default_value' => (int) $config->get('circuit_breakers.intake_pause_seconds'),
      '#min' => 0,
      '#description' => $this->t('Small delay applied before rejecting work while a breaker is open, to avoid hot-loop retries from workers or cron. Keep this much smaller than both cooldown and transport visibility timeout.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if ((int) $form_state->getValue('default_timeout_seconds') < 1) {
      $form_state->setErrorByName('default_timeout_seconds', $this->t('Default timeout must be at least 1 second.'));
    }
    if ((int) $form_state->getValue('failure_threshold') < 1) {
      $form_state->setErrorByName('failure_threshold', $this->t('Failure threshold must be at least 1.'));
    }
    if ((int) $form_state->getValue('cooldown_seconds') < 1) {
      $form_state->setErrorByName('cooldown_seconds', $this->t('Cooldown must be at least 1 second.'));
    }
    if ((int) $form_state->getValue('intake_pause_seconds') < 0) {
      $form_state->setErrorByName('intake_pause_seconds', $this->t('Open-breaker intake pause cannot be negative.'));
    }

    $minRedeliverTimeout = $this->minSqlTransportRedeliverTimeout();
    $defaultTimeoutSeconds = (int) $form_state->getValue('default_timeout_seconds');
    if ($minRedeliverTimeout !== NULL && $defaultTimeoutSeconds >= $minRedeliverTimeout) {
      $form_state->setErrorByName('default_timeout_seconds', $this->t('Default timeout must be lower than the smallest SQL transport redelivery timeout (@seconds seconds).', [
        '@seconds' => $minRedeliverTimeout,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('sm_workers.settings')
      ->set('execution.default_timeout_seconds', (int) $form_state->getValue('default_timeout_seconds'))
      ->set('execution.default_forward_auth', (bool) $form_state->getValue('default_forward_auth'))
      ->set('circuit_breakers.failure_threshold', (int) $form_state->getValue('failure_threshold'))
      ->set('circuit_breakers.cooldown_seconds', (int) $form_state->getValue('cooldown_seconds'))
      ->set('circuit_breakers.intake_pause_seconds', (int) $form_state->getValue('intake_pause_seconds'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Returns the smallest configured SQL-transport redelivery timeout.
   */
  private function minSqlTransportRedeliverTimeout(): ?int {
    $timeouts = [];
    foreach ($this->transports as $transport) {
      if (!is_array($transport)) {
        continue;
      }
      $dsn = (string) ($transport['dsn'] ?? '');
      if (!str_starts_with($dsn, 'drupal-sql://')) {
        continue;
      }

      $timeout = $transport['options']['redeliver_timeout'] ?? NULL;
      if (is_numeric($timeout)) {
        $timeouts[] = (int) $timeout;
      }
    }

    return $timeouts === [] ? NULL : min($timeouts);
  }

}
