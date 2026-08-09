<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Tests\Toolbox;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Exception\LogicException;
use Symfony\AI\Agent\Toolbox\StreamListener;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStateDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Thinking\ThinkingProviderState;
use Symfony\AI\Platform\Thinking\ThinkingRepresentation;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;

final class StreamListenerTest extends TestCase
{
    public function testGetContentWithOnlyStringValues()
    {
        $streamResult = new StreamResult((static function (): \Generator {
            yield new TextDelta('Hello ');
            yield new TextDelta('World');
        })());

        $callbackCalled = false;
        $handleToolCallsCallback = static function () use (&$callbackCalled) {
            $callbackCalled = true;
        };

        $streamResult->addListener(new StreamListener($handleToolCallsCallback));
        $result = iterator_to_array($streamResult->getContent());

        $this->assertCount(2, $result);
        $this->assertInstanceOf(TextDelta::class, $result[0]);
        $this->assertSame('Hello ', $result[0]->getText());
        $this->assertInstanceOf(TextDelta::class, $result[1]);
        $this->assertSame('World', $result[1]->getText());
        $this->assertFalse($callbackCalled);
    }

    public function testGetContentWithToolCallCompleteAfterStringValues()
    {
        $toolCallComplete = new ToolCallComplete([new ToolCall('test-id', 'test_tool')]);
        $streamResult = new StreamResult((static function () use ($toolCallComplete): \Generator {
            yield new TextDelta('Initial ');
            yield new TextDelta('content ');
            yield $toolCallComplete;
        })());

        $capturedAssistantMessage = null;
        $capturedToolCallResult = null;
        $handleToolCallsCallback = static function (ToolCallResult $tcr, AssistantMessage $msg) use (&$capturedAssistantMessage, &$capturedToolCallResult) {
            $capturedToolCallResult = $tcr;
            $capturedAssistantMessage = $msg;

            return new TextResult('Tool response');
        };

        $streamResult->addListener(new StreamListener($handleToolCallsCallback));
        $result = iterator_to_array($streamResult->getContent());

        $this->assertInstanceOf(TextDelta::class, $result[0]);
        $this->assertSame('Initial ', $result[0]->getText());
        $this->assertInstanceOf(TextDelta::class, $result[1]);
        $this->assertSame('content ', $result[1]->getText());
        $this->assertInstanceOf(TextDelta::class, $result[2]);
        $this->assertSame('Tool response', $result[2]->getText());
        $this->assertNotNull($capturedAssistantMessage);
        $this->assertSame('Initial content ', $capturedAssistantMessage->asText());
        $this->assertNotNull($capturedToolCallResult);
        $this->assertInstanceOf(ToolCallResult::class, $capturedToolCallResult);
        $this->assertSame('test-id', $capturedToolCallResult->getContent()[0]->getId());
    }

    public function testGetContentWithToolCallCompleteAsFirstValue()
    {
        $streamResult = new StreamResult((static function (): \Generator {
            yield new ToolCallComplete([new ToolCall('test-id', 'test_tool')]);
        })());

        $callbackCalled = false;
        $capturedAssistantMessage = null;
        $handleToolCallsCallback = static function (ToolCallResult $tcr, ?AssistantMessage $msg) use (&$callbackCalled, &$capturedAssistantMessage) {
            $callbackCalled = true;
            $capturedAssistantMessage = $msg;

            return new TextResult('Immediate tool response');
        };

        $streamResult->addListener(new StreamListener($handleToolCallsCallback));
        $result = iterator_to_array($streamResult->getContent());

        $this->assertCount(1, $result);
        $this->assertInstanceOf(TextDelta::class, $result[0]);
        $this->assertSame('Immediate tool response', $result[0]->getText());
        $this->assertTrue($callbackCalled);
        $this->assertInstanceOf(AssistantMessage::class, $capturedAssistantMessage);
        $this->assertTrue($capturedAssistantMessage->hasToolCalls());
        $this->assertNull($capturedAssistantMessage->asText());
    }

    public function testGetContentWithToolCallCompleteReturningGenerator()
    {
        $streamResult = new StreamResult((static function (): \Generator {
            yield new TextDelta('Start');
            yield new ToolCallComplete([new ToolCall('test-id', 'test_tool')]);
        })());

        $capturedAssistantMessage = null;
        $handleToolCallsCallback = static function (ToolCallResult $tcr, AssistantMessage $msg) use (&$capturedAssistantMessage) {
            $capturedAssistantMessage = $msg;

            return new StreamResult((static function (): \Generator {
                yield new TextDelta('Part 1');
                yield new TextDelta('Part 2');
                yield new TextDelta('Part 3');
            })());
        };

        $streamResult->addListener(new StreamListener($handleToolCallsCallback));
        $result = iterator_to_array($streamResult->getContent(), false);

        $this->assertInstanceOf(TextDelta::class, $result[0]);
        $this->assertSame('Start', $result[0]->getText());
        $this->assertInstanceOf(TextDelta::class, $result[1]);
        $this->assertSame('Part 1', $result[1]->getText());
        $this->assertInstanceOf(TextDelta::class, $result[2]);
        $this->assertSame('Part 2', $result[2]->getText());
        $this->assertInstanceOf(TextDelta::class, $result[3]);
        $this->assertSame('Part 3', $result[3]->getText());
        $this->assertSame('Start', $capturedAssistantMessage->asText());
    }

    public function testGetContentStopsAfterToolCallComplete()
    {
        $streamResult = new StreamResult((static function (): \Generator {
            yield new TextDelta('Before');
            yield new ToolCallComplete([new ToolCall('test-id', 'test_tool')]);
            yield new TextDelta('After'); // This should not be yielded
        })());

        $innerResult = new TextResult('Tool output');
        $handleToolCallsCallback = static fn () => $innerResult;

        $streamResult->addListener(new StreamListener($handleToolCallsCallback));
        $result = iterator_to_array($streamResult->getContent(), false);

        $this->assertInstanceOf(TextDelta::class, $result[0]);
        $this->assertSame('Before', $result[0]->getText());
        $this->assertInstanceOf(TextDelta::class, $result[1]);
        $this->assertSame('Tool output', $result[1]->getText());
    }

    public function testThinkingDeltasAreAssociatedByIdAndPreserveStreamOrder()
    {
        $toolCall = new ToolCall('call_1', 'tool_1');
        $streamResult = new StreamResult((static function () use ($toolCall): \Generator {
            yield new TextDelta('');
            yield new ThinkingStart('first', ThinkingRepresentation::FULL);
            yield new ThinkingDelta('first', 'First ', ThinkingRepresentation::FULL);
            yield new TextDelta('Between.');
            yield new ToolCallStart($toolCall->getId(), $toolCall->getName());
            yield new ThinkingStart('second', ThinkingRepresentation::SUMMARY);
            yield new ThinkingDelta('first', 'thought.', ThinkingRepresentation::FULL);
            yield new ThinkingDelta('second', 'Summary.', ThinkingRepresentation::SUMMARY);
            yield new ThinkingStateDelta('first', ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, 'sig_', ThinkingRepresentation::FULL);
            yield new ThinkingStateDelta('second', ThinkingProviderState::FORMAT_UNKNOWN, 'summary_state', ThinkingRepresentation::SUMMARY);
            yield new ThinkingStateDelta('first', ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, '123', ThinkingRepresentation::FULL);
            yield new ThinkingComplete('second', 'Summary.', ThinkingRepresentation::SUMMARY);
            yield new ThinkingComplete('first', 'First thought.', ThinkingRepresentation::FULL);
            yield new ToolCallComplete([$toolCall]);
        })());

        $capturedAssistantMessage = null;
        $streamResult->addListener(new StreamListener(static function (ToolCallResult $toolCalls, AssistantMessage $message) use (&$capturedAssistantMessage): TextResult {
            $capturedAssistantMessage = $message;

            return new TextResult('Tool response');
        }));
        $result = iterator_to_array($streamResult->getContent(), false);

        $textDeltas = array_values(array_filter($result, static fn (object $delta): bool => $delta instanceof TextDelta));
        $this->assertCount(2, $textDeltas);
        $this->assertSame('Between.', $textDeltas[0]->getText());
        $this->assertSame('Tool response', $textDeltas[1]->getText());

        $this->assertInstanceOf(AssistantMessage::class, $capturedAssistantMessage);
        $content = $capturedAssistantMessage->getContent();
        $this->assertCount(4, $content);

        $this->assertInstanceOf(Thinking::class, $content[0]);
        $this->assertSame('First thought.', $content[0]->getContent());
        $this->assertSame(ThinkingRepresentation::FULL, $content[0]->getRepresentation());
        $this->assertSame(ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, $content[0]->getProviderState()->getFormat());
        $this->assertSame('sig_123', $content[0]->getProviderState()->getPayload());

        $this->assertInstanceOf(Text::class, $content[1]);
        $this->assertSame('Between.', $content[1]->getText());

        $this->assertSame($toolCall, $content[2]);

        $this->assertInstanceOf(Thinking::class, $content[3]);
        $this->assertSame('Summary.', $content[3]->getContent());
        $this->assertSame(ThinkingRepresentation::SUMMARY, $content[3]->getRepresentation());
        $this->assertSame(ThinkingProviderState::FORMAT_UNKNOWN, $content[3]->getProviderState()->getFormat());
        $this->assertSame('summary_state', $content[3]->getProviderState()->getPayload());
    }

    public function testThinkingWithoutStartUsesItsOwnIdRepresentationAndProviderState()
    {
        $toolCall = new ToolCall('call_1', 'tool_1');
        $redactedState = new ThinkingProviderState(ThinkingProviderState::FORMAT_ANTHROPIC_REDACTED, 'redacted_data');
        $streamResult = new StreamResult((static function () use ($redactedState, $toolCall): \Generator {
            yield new ThinkingDelta('opaque', 'Opaque thought.', ThinkingRepresentation::OPAQUE);
            yield new ThinkingStateDelta('opaque', ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, 'replace', ThinkingRepresentation::OPAQUE);
            yield new ToolCallStart($toolCall->getId(), $toolCall->getName());
            yield new ThinkingStateDelta('opaque', ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, 'ment', ThinkingRepresentation::OPAQUE);
            yield new ThinkingComplete('redacted', '', ThinkingRepresentation::OPAQUE, $redactedState);
            yield new ThinkingComplete('opaque', 'Opaque thought.', ThinkingRepresentation::OPAQUE);
            yield new ToolCallComplete([$toolCall]);
        })());

        $capturedAssistantMessage = null;
        $streamResult->addListener(new StreamListener(static function (ToolCallResult $toolCalls, AssistantMessage $message) use (&$capturedAssistantMessage): TextResult {
            $capturedAssistantMessage = $message;

            return new TextResult('Tool response');
        }));
        iterator_to_array($streamResult->getContent(), false);

        $this->assertInstanceOf(AssistantMessage::class, $capturedAssistantMessage);
        $content = $capturedAssistantMessage->getContent();
        $this->assertCount(3, $content);

        $this->assertInstanceOf(Thinking::class, $content[0]);
        $this->assertSame('Opaque thought.', $content[0]->getContent());
        $this->assertSame(ThinkingRepresentation::OPAQUE, $content[0]->getRepresentation());
        $this->assertSame(ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, $content[0]->getProviderState()->getFormat());
        $this->assertSame('replacement', $content[0]->getProviderState()->getPayload());

        $this->assertSame($toolCall, $content[1]);

        $this->assertInstanceOf(Thinking::class, $content[2]);
        $this->assertSame('', $content[2]->getContent());
        $this->assertSame(ThinkingRepresentation::OPAQUE, $content[2]->getRepresentation());
        $this->assertSame($redactedState, $content[2]->getProviderState());
    }

    public function testEmptyThinkingWithoutProviderStateIsNotReplayed()
    {
        $toolCall = new ToolCall('call_1', 'tool_1');
        $streamResult = new StreamResult((static function () use ($toolCall): \Generator {
            yield new ThinkingStart('empty', ThinkingRepresentation::FULL);
            yield new ToolCallComplete([$toolCall]);
        })());

        $capturedAssistantMessage = null;
        $streamResult->addListener(new StreamListener(static function (ToolCallResult $toolCalls, AssistantMessage $message) use (&$capturedAssistantMessage): TextResult {
            $capturedAssistantMessage = $message;

            return new TextResult('Tool response');
        }));
        iterator_to_array($streamResult->getContent(), false);

        $this->assertInstanceOf(AssistantMessage::class, $capturedAssistantMessage);
        $this->assertSame([$toolCall], $capturedAssistantMessage->getContent());
    }

    public function testThinkingStateFormatCannotChangeWithinABlock()
    {
        $streamResult = new StreamResult((static function (): \Generator {
            yield new ThinkingStateDelta('thinking', ThinkingProviderState::FORMAT_UNKNOWN, 'first', ThinkingRepresentation::FULL);
            yield new ThinkingStateDelta('thinking', ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, 'second', ThinkingRepresentation::FULL);
        })());
        $streamResult->addListener(new StreamListener(static fn () => new TextResult('unused')));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Thinking block "thinking" changed provider state format from "unknown" to "anthropic-signature".');

        iterator_to_array($streamResult->getContent(), false);
    }

    public function testThinkingCompleteCannotChangeProviderStateFormat()
    {
        $streamResult = new StreamResult((static function (): \Generator {
            yield new ThinkingStateDelta('thinking', ThinkingProviderState::FORMAT_UNKNOWN, 'first', ThinkingRepresentation::FULL);
            yield new ThinkingComplete(
                'thinking',
                'done',
                ThinkingRepresentation::FULL,
                new ThinkingProviderState(ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, 'second'),
            );
        })());
        $streamResult->addListener(new StreamListener(static fn () => new TextResult('unused')));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Thinking block "thinking" changed provider state format from "unknown" to "anthropic-signature".');

        iterator_to_array($streamResult->getContent(), false);
    }

    public function testThinkingBlockCannotChangeRepresentation()
    {
        $streamResult = new StreamResult((static function (): \Generator {
            yield new ThinkingStart('thinking', ThinkingRepresentation::FULL);
            yield new ThinkingDelta('thinking', 'summary', ThinkingRepresentation::SUMMARY);
        })());
        $streamResult->addListener(new StreamListener(static fn () => new TextResult('unused')));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Thinking block "thinking" changed representation from "full" to "summary".');

        iterator_to_array($streamResult->getContent(), false);
    }

    public function testMetadataPropagationFromTextResult()
    {
        $streamResult = new StreamResult((static function (): \Generator {
            yield new TextDelta('Before tool');
            yield new ToolCallComplete([new ToolCall('test-id', 'test_tool')]);
        })());
        $streamResult->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 100, completionTokens: 10, totalTokens: 110));

        $innerResult = new TextResult('Tool response');
        $innerResult->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 200, completionTokens: 20, totalTokens: 220));

        $streamResult->addListener(new StreamListener(static fn () => $innerResult));
        iterator_to_array($streamResult->getContent());

        $this->assertTrue($streamResult->getMetadata()->has('token_usage'));
        $this->assertInstanceOf(TokenUsageAggregation::class, $usage = $streamResult->getMetadata()->get('token_usage'));
        $this->assertSame(330, $usage->getTotalTokens());
    }

    public function testMetadataPropagationFromNestedStreamResultWithEagerMetadata()
    {
        $innerTokenUsage = new TokenUsage(promptTokens: 200, completionTokens: 20, totalTokens: 220);

        $streamResult = new StreamResult((static function (): \Generator {
            yield new TextDelta('Before tool');
            yield new ToolCallComplete([new ToolCall('test-id', 'test_tool')]);
        })());
        $streamResult->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 100, completionTokens: 10, totalTokens: 110));

        $innerResult = new StreamResult((static function (): \Generator {
            yield new TextDelta('Part 1');
            yield new TextDelta('Part 2');
        })());
        $innerResult->getMetadata()->add('token_usage', $innerTokenUsage);

        $streamResult->addListener(new StreamListener(static fn () => $innerResult));
        iterator_to_array($streamResult->getContent());

        $this->assertTrue($streamResult->getMetadata()->has('token_usage'));
        $this->assertInstanceOf(TokenUsageAggregation::class, $usage = $streamResult->getMetadata()->get('token_usage'));
        $this->assertSame(330, $usage->getTotalTokens());
    }

    public function testMetadataPropagationFromNestedStreamResultWithLazyMetadata()
    {
        $streamResult = new StreamResult((static function (): \Generator {
            yield new TextDelta('Before tool');
            yield new ToolCallComplete([new ToolCall('test-id', 'test_tool')]);
        })());
        $streamResult->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 100, completionTokens: 10, totalTokens: 110));

        $innerResult = new StreamResult((static function (): \Generator {
            yield new TextDelta('Part 1');
            yield new TextDelta('Part 2');
        })());
        $innerResult->addListener(new class extends AbstractStreamListener {
            public function onDelta(DeltaEvent $event): void
            {
                $delta = $event->getDelta();
                if ($delta instanceof TextDelta && 'Part 2' === $delta->getText()) {
                    $event->getResult()->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 200, completionTokens: 20, totalTokens: 220));
                }
            }
        });

        $streamResult->addListener(new StreamListener(static fn () => $innerResult));
        iterator_to_array($streamResult->getContent());

        $this->assertTrue($streamResult->getMetadata()->has('token_usage'));
        $this->assertInstanceOf(TokenUsageAggregation::class, $usage = $streamResult->getMetadata()->get('token_usage'));
        $this->assertSame(330, $usage->getTotalTokens());
    }

    public function testMetadataFromPreviousRunDoesNotLeakIntoNextStreamRun()
    {
        $firstInnerResult = new TextResult('First response');
        $firstInnerResult->getMetadata()->add('foo', 'bar');
        $listener = new StreamListener(static fn () => $firstInnerResult);

        $firstStreamResult = new StreamResult((static function (): \Generator {
            yield new ToolCallComplete([new ToolCall('first-id', 'first_tool')]);
        })());
        $firstStreamResult->addListener($listener);
        iterator_to_array($firstStreamResult->getContent());

        $secondStreamResult = new StreamResult((static function (): \Generator {
            yield new TextDelta('No tool call');
        })());
        $secondStreamResult->addListener($listener);
        iterator_to_array($secondStreamResult->getContent());

        $this->assertFalse($secondStreamResult->getMetadata()->has('foo'));
    }
}
