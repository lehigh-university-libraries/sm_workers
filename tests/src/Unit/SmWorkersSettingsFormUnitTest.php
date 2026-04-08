<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_workers\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\sm_workers\Form\SmWorkersSettingsForm;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the SM Workers settings form.
 */
final class SmWorkersSettingsFormUnitTest extends UnitTestCase {

  /**
   * Tests validation rejects timeouts that meet transport redelivery timeout.
   */
  public function testValidateRejectsTimeoutAtOrAboveTransportRedelivery(): void {
    $form = new SmWorkersSettingsForm(
      $this->createReadonlyConfigFactory([
        'execution.default_timeout_seconds' => 300,
        'execution.default_forward_auth' => TRUE,
        'circuit_breakers.failure_threshold' => 5,
        'circuit_breakers.cooldown_seconds' => 300,
        'circuit_breakers.intake_pause_seconds' => 5,
      ]),
      $this->createMock(TypedConfigManagerInterface::class),
      [
        'islandora_derivatives' => [
          'dsn' => 'drupal-sql://default?queue_name=islandora_derivatives',
          'options' => ['redeliver_timeout' => 600],
        ],
      ],
    );
    $form->setStringTranslation($this->getStringTranslationStub());

    $formState = (new FormState())
      ->setValue('default_timeout_seconds', 600)
      ->setValue('default_forward_auth', TRUE)
      ->setValue('failure_threshold', 5)
      ->setValue('cooldown_seconds', 300)
      ->setValue('intake_pause_seconds', 5);

    $formArray = [];
    $form->validateForm($formArray, $formState);

    static::assertArrayHasKey('default_timeout_seconds', $formState->getErrors());
  }

  /**
   * Tests submit stores worker runtime settings.
   */
  public function testSubmitPersistsSettings(): void {
    $saved = [];
    $form = new SmWorkersSettingsForm(
      $this->createWritableConfigFactory([
        'execution.default_timeout_seconds' => 300,
        'execution.default_forward_auth' => TRUE,
        'circuit_breakers.failure_threshold' => 5,
        'circuit_breakers.cooldown_seconds' => 300,
        'circuit_breakers.intake_pause_seconds' => 5,
      ], $saved),
      $this->createMock(TypedConfigManagerInterface::class),
      [],
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->createMock(MessengerInterface::class));

    $formState = (new FormState())
      ->setValue('default_timeout_seconds', 450)
      ->setValue('default_forward_auth', FALSE)
      ->setValue('failure_threshold', 7)
      ->setValue('cooldown_seconds', 120)
      ->setValue('intake_pause_seconds', 9);

    $formArray = [];
    $form->submitForm($formArray, $formState);

    static::assertSame(450, $saved['execution.default_timeout_seconds']);
    static::assertFalse($saved['execution.default_forward_auth']);
    static::assertSame(7, $saved['circuit_breakers.failure_threshold']);
    static::assertSame(120, $saved['circuit_breakers.cooldown_seconds']);
    static::assertSame(9, $saved['circuit_breakers.intake_pause_seconds']);
  }

  /**
   * Creates a readonly config factory.
   *
   * @param array<string, mixed> $values
   *   Config values keyed by name.
   */
  private function createReadonlyConfigFactory(array $values): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static fn (string $key): mixed => $values[$key] ?? NULL);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('sm_workers.settings')
      ->willReturn($config);

    return $factory;
  }

  /**
   * Creates a writable config factory.
   *
   * @param array<string, mixed> $values
   *   Initial config values.
   * @param array<string, mixed> $saved
   *   Collected saved values.
   */
  private function createWritableConfigFactory(array $values, array &$saved): ConfigFactoryInterface {
    $factory = $this->createReadonlyConfigFactory($values);
    $editable = $this->createMock(Config::class);
    $editable->method('set')
      ->willReturnCallback(function (string $key, mixed $value) use (&$saved, $editable): Config {
        $saved[$key] = $value;
        return $editable;
      });
    $editable->expects($this->once())->method('save');

    $factory->method('getEditable')
      ->with('sm_workers.settings')
      ->willReturn($editable);

    return $factory;
  }

}
