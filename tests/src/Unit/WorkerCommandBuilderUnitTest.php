<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_workers\Unit;

use Drupal\sm_workers\Service\WorkerCommandBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for worker command rendering.
 */
final class WorkerCommandBuilderUnitTest extends UnitTestCase {

  /**
   * Tests a transport-based worker command is rendered correctly.
   */
  public function testBuildConsumeCommandForTransportWorker(): void {
    $builder = new WorkerCommandBuilder();

    $command = $builder->buildConsumeCommand([
      'id' => 'example.derivatives',
      'label' => 'Example derivatives',
      'transports' => ['async_high', 'async_low'],
      'options' => [
        'time-limit' => 3600,
        'memory-limit' => '256M',
      ],
    ]);

    static::assertSame(
      'drush sm:consume async_high async_low --time-limit=3600 --memory-limit=256M',
      $command,
    );
  }

  /**
   * Tests an all-receivers worker command is rendered correctly.
   */
  public function testBuildConsumeCommandForAllReceiversWorker(): void {
    $builder = new WorkerCommandBuilder();

    $command = $builder->buildConsumeCommand([
      'id' => 'example.all',
      'label' => 'Example all receivers',
      'all' => TRUE,
      'exclude_receivers' => ['failed'],
      'queues' => ['fasttrack'],
      'options' => [
        'keepalive' => 5,
        'verbose' => TRUE,
      ],
    ]);

    static::assertSame(
      'drush sm:consume --all --exclude-receivers=failed --queues=fasttrack --keepalive=5 --verbose',
      $command,
    );
  }

  /**
   * Tests buildCommandArgv with scalar token substitution.
   */
  public function testBuildCommandArgvWithScalarTokens(): void {
    $builder = new WorkerCommandBuilder();

    $definition = [
      'execution' => [
        'cmd' => 'magick',
        'args' => ['%source-uri', '-thumbnail', '200x200', '%destination-uri'],
      ],
    ];

    $argv = $builder->buildCommandArgv($definition, [
      '%source-uri' => '/tmp/input.jpg',
      '%destination-uri' => '/tmp/output.jpg',
    ]);

    static::assertSame(
      ['magick', '/tmp/input.jpg', '-thumbnail', '200x200', '/tmp/output.jpg'],
      $argv,
    );
  }

  /**
   * Tests buildCommandArgv expands a list token into multiple args.
   */
  public function testBuildCommandArgvExpandsListToken(): void {
    $builder = new WorkerCommandBuilder();

    $definition = [
      'execution' => [
        'cmd' => 'magick',
        'args' => ['-', '%args', 'image:-'],
      ],
    ];

    $argv = $builder->buildCommandArgv($definition, [
      '%args' => ['-quality', '85', '-strip'],
    ]);

    static::assertSame(
      ['magick', '-', '-quality', '85', '-strip', 'image:-'],
      $argv,
    );
  }

  /**
   * Tests buildCommandArgv returns empty when no cmd is set.
   */
  public function testBuildCommandArgvReturnsEmptyWithoutCmd(): void {
    $builder = new WorkerCommandBuilder();

    static::assertSame([], $builder->buildCommandArgv(['execution' => ['cmd' => '']]));
    static::assertSame([], $builder->buildCommandArgv([]));
  }

  /**
   * Tests an empty list token produces no args at that position.
   */
  public function testBuildCommandArgvEmptyListTokenProducesNoArgs(): void {
    $builder = new WorkerCommandBuilder();

    $definition = [
      'execution' => [
        'cmd' => 'magick',
        'args' => ['-', '%args', 'image:-'],
      ],
    ];

    $argv = $builder->buildCommandArgv($definition, ['%args' => []]);

    static::assertSame(['magick', '-', 'image:-'], $argv);
  }

}
