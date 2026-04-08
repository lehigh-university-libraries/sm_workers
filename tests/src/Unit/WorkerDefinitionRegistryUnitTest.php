<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_workers\Unit;

use Drupal\sm_workers\Service\WorkerDefinitionRegistry;
use Drupal\sm_workers\WorkerDefinitionProviderInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\sm_workers\Unit\Support\TestWorkerDefinitionProvider;

/**
 * Unit tests for worker definition collection.
 */
final class WorkerDefinitionRegistryUnitTest extends UnitTestCase {

  /**
   * Tests worker definitions are normalized and sorted.
   */
  public function testAllNormalizesDefinitions(): void {
    $registry = new WorkerDefinitionRegistry([
      new TestWorkerDefinitionProvider([
        'z_worker' => [
          'label' => 'Z worker',
          'transports' => ['async'],
          'options' => ['time-limit' => 3600],
        ],
      ]),
      new TestWorkerDefinitionProvider([
        'a_worker' => [
          'description' => 'Consumes all receivers except failed.',
          'all' => TRUE,
          'exclude_receivers' => ['failed', ''],
          'queues' => ['fasttrack', ''],
          'options' => 'not-an-array',
        ],
      ]),
    ]);

    $definitions = $registry->all();

    static::assertSame(['a_worker', 'z_worker'], array_keys($definitions));
    static::assertSame('a_worker', $definitions['a_worker']['id']);
    static::assertSame('a_worker', $definitions['a_worker']['label']);
    static::assertSame('Consumes all receivers except failed.', $definitions['a_worker']['description']);
    static::assertTrue($definitions['a_worker']['all']);
    static::assertSame(['failed'], $definitions['a_worker']['exclude_receivers']);
    static::assertSame(['fasttrack'], $definitions['a_worker']['queues']);
    static::assertSame([], $definitions['a_worker']['options']);
    static::assertSame(['async'], $definitions['z_worker']['transports']);
    static::assertSame(['time-limit' => 3600], $definitions['z_worker']['options']);
  }

  /**
   * Tests the execution block is normalized from a full definition.
   */
  public function testExecutionBlockIsNormalized(): void {
    $registry = new WorkerDefinitionRegistry([
      new TestWorkerDefinitionProvider([
        'cmd_worker' => [
          'label' => 'Command worker',
          'transports' => ['async'],
          'execution' => [
            'mode' => 'command',
            'cmd' => 'magick',
            'args' => ['-', '%args', 'image:-'],
            'forward_auth' => FALSE,
          ],
        ],
      ]),
    ]);

    $definition = $registry->get('cmd_worker');
    static::assertNotNull($definition);
    static::assertSame('command', $definition['execution']['mode']);
    static::assertSame('magick', $definition['execution']['cmd']);
    static::assertSame(['-', '%args', 'image:-'], $definition['execution']['args']);
    static::assertFalse($definition['execution']['forward_auth']);
  }

  /**
   * Tests execution block defaults when the key is absent.
   */
  public function testExecutionBlockDefaultsWhenAbsent(): void {
    $registry = new WorkerDefinitionRegistry([
      new TestWorkerDefinitionProvider([
        'plain_worker' => [
          'label' => 'Plain worker',
          'transports' => ['async'],
        ],
      ]),
    ]);

    $definition = $registry->get('plain_worker');
    static::assertNotNull($definition);
    static::assertSame('', $definition['execution']['mode']);
    static::assertSame('', $definition['execution']['cmd']);
    static::assertSame([], $definition['execution']['args']);
    static::assertTrue($definition['execution']['forward_auth']);
  }

  /**
   * Tests invalid execution blocks are rejected early.
   */
  public function testExecutionArgsWithoutCommandAreRejected(): void {
    $registry = new WorkerDefinitionRegistry([
      new TestWorkerDefinitionProvider([
        'invalid_worker' => [
          'label' => 'Invalid worker',
          'transports' => ['async'],
          'execution' => [
            'mode' => 'command',
            'args' => ['%source-uri'],
          ],
        ],
      ]),
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $registry->all();
  }

  /**
   * Tests a single worker definition can be fetched by ID.
   */
  public function testGetReturnsDefinitionById(): void {
    $registry = new WorkerDefinitionRegistry([
      new TestWorkerDefinitionProvider([
        'example.worker' => [
          'label' => 'Example worker',
          'transports' => ['async'],
        ],
      ]),
    ]);

    static::assertSame('Example worker', $registry->get('example.worker')['label']);
    static::assertNull($registry->get('missing.worker'));
  }

  /**
   * Tests definitions are memoized after the first tagged-iterator traversal.
   */
  public function testAllMemoizesSinglePassProviders(): void {
    $counter = new \stdClass();
    $counter->calls = 0;
    $provider = new class($counter) implements WorkerDefinitionProviderInterface {

      /**
       * Tracks how often definitions are requested.
       */
      public function __construct(private \stdClass $counter) {}

      /**
       * {@inheritdoc}
       */
      public function getWorkerDefinitions(): array {
        $this->counter->calls++;
        return [
          'memoized.worker' => [
            'transports' => ['async'],
          ],
        ];
      }

    };

    $registry = new WorkerDefinitionRegistry((static function () use ($provider): \Generator {
      yield $provider;
    })());

    static::assertCount(1, $registry->all());
    static::assertCount(1, $registry->all());
    static::assertSame('memoized.worker', $registry->get('memoized.worker')['id']);
    static::assertSame(1, $counter->calls);
  }

}
