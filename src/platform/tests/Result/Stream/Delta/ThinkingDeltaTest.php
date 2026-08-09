<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Tests\Result\Stream\Delta;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStateDelta;
use Symfony\AI\Platform\Thinking\ThinkingProviderState;
use Symfony\AI\Platform\Thinking\ThinkingRepresentation;

final class ThinkingDeltaTest extends TestCase
{
    public function testCompleteCarriesCorrelatedThinkingState()
    {
        $providerState = new ThinkingProviderState(ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, 'sig_abc');
        $delta = new ThinkingComplete('thinking-1', 'Let me think about this...', ThinkingRepresentation::FULL, $providerState);

        $this->assertSame('thinking-1', $delta->getId());
        $this->assertSame('Let me think about this...', $delta->getThinking());
        $this->assertSame(ThinkingRepresentation::FULL, $delta->getRepresentation());
        $this->assertSame($providerState, $delta->getProviderState());
    }

    public function testStateDeltaCarriesCorrelatedProviderState()
    {
        $delta = new ThinkingStateDelta(
            'thinking-1',
            ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE,
            'sig_chunk',
            ThinkingRepresentation::OPAQUE,
        );

        $this->assertSame('thinking-1', $delta->getId());
        $this->assertSame(ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, $delta->getFormat());
        $this->assertSame('sig_chunk', $delta->getPayload());
        $this->assertSame(ThinkingRepresentation::OPAQUE, $delta->getRepresentation());
    }

    /**
     * @param \Closure(string): object $factory
     */
    #[DataProvider('provideThinkingDeltaFactories')]
    public function testThinkingDeltasRejectEmptyId(\Closure $factory)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Thinking stream ID cannot be empty.');

        $factory('');
    }

    public static function provideThinkingDeltaFactories(): iterable
    {
        yield ThinkingStart::class => [static fn (string $id): ThinkingStart => new ThinkingStart($id, ThinkingRepresentation::UNKNOWN)];
        yield ThinkingDelta::class => [static fn (string $id): ThinkingDelta => new ThinkingDelta($id, '', ThinkingRepresentation::UNKNOWN)];
        yield ThinkingStateDelta::class => [static fn (string $id): ThinkingStateDelta => new ThinkingStateDelta($id, ThinkingProviderState::FORMAT_UNKNOWN, 'state', ThinkingRepresentation::UNKNOWN)];
        yield ThinkingComplete::class => [static fn (string $id): ThinkingComplete => new ThinkingComplete($id, '', ThinkingRepresentation::UNKNOWN)];
    }

    #[DataProvider('provideInvalidStateDelta')]
    public function testStateDeltaRejectsEmptyState(string $format, string $payload, string $message)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ThinkingStateDelta('thinking-1', $format, $payload, ThinkingRepresentation::OPAQUE);
    }

    public static function provideInvalidStateDelta(): iterable
    {
        yield 'format' => ['', 'payload', 'Thinking state delta format cannot be empty.'];
        yield 'payload' => [ThinkingProviderState::FORMAT_UNKNOWN, '', 'Thinking state delta payload cannot be empty.'];
    }
}
