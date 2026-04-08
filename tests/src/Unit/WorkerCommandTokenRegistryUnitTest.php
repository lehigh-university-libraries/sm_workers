<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_workers\Unit;

use Drupal\sm_workers\CommandToken\WorkerCommandTokenProviderInterface;
use Drupal\sm_workers\CommandToken\WorkerCommandTokenRegistry;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the command token registry.
 */
final class WorkerCommandTokenRegistryUnitTest extends UnitTestCase {

  /**
   * Tests tokens from all providers are merged.
   */
  public function testTokensFromAllProvidersAreMerged(): void {
    $providerA = $this->createProvider(['%source-uri' => 'http://example.com/file.jpg']);
    $providerB = $this->createProvider(['%destination-uri' => 'http://example.com/thumb.jpg']);

    $registry = new WorkerCommandTokenRegistry([$providerA, $providerB]);
    $tokens = $registry->resolveTokens([]);

    static::assertSame('http://example.com/file.jpg', $tokens['%source-uri']);
    static::assertSame('http://example.com/thumb.jpg', $tokens['%destination-uri']);
  }

  /**
   * Tests a later provider overrides an earlier one for the same key.
   */
  public function testLaterProviderOverridesEarlier(): void {
    $providerA = $this->createProvider(['%args' => 'first']);
    $providerB = $this->createProvider(['%args' => 'second']);

    $registry = new WorkerCommandTokenRegistry([$providerA, $providerB]);
    $tokens = $registry->resolveTokens([]);

    static::assertSame('second', $tokens['%args']);
  }

  /**
   * Tests list-valued tokens pass through unchanged.
   */
  public function testListValuedTokensPassThrough(): void {
    $provider = $this->createProvider(['%args' => ['-quality', '85']]);

    $registry = new WorkerCommandTokenRegistry([$provider]);
    $tokens = $registry->resolveTokens([]);

    static::assertSame(['-quality', '85'], $tokens['%args']);
  }

  /**
   * Tests the context is forwarded to providers.
   */
  public function testContextIsForwardedToProviders(): void {
    $received = [];
    $provider = new class($received) implements WorkerCommandTokenProviderInterface {

      /**
       * Captures the received context.
       */
      public function __construct(private array &$received) {}

      /**
       * {@inheritdoc}
       */
      public function getTokens(array $context): array {
        $this->received = $context;
        return [];
      }

    };

    $registry = new WorkerCommandTokenRegistry([$provider]);
    $registry->resolveTokens(['payload' => 'test-payload', 'metadata' => []]);

    static::assertSame('test-payload', $received['payload']);
  }

  /**
   * Tests non-provider iterables are skipped without error.
   */
  public function testNonProviderIsSkipped(): void {
    $registry = new WorkerCommandTokenRegistry([new \stdClass()]);
    static::assertSame([], $registry->resolveTokens([]));
  }

  /**
   * Creates a simple token provider returning fixed values.
   *
   * @param array<string, string|list<string>> $tokens
   *   Tokens to return.
   */
  private function createProvider(array $tokens): WorkerCommandTokenProviderInterface {
    return new class($tokens) implements WorkerCommandTokenProviderInterface {

      /**
       * Constructs the provider.
       */
      public function __construct(private array $tokens) {}

      /**
       * {@inheritdoc}
       */
      public function getTokens(array $context): array {
        return $this->tokens;
      }

    };
  }

}
