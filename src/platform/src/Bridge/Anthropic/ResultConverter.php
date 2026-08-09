<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Anthropic;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\MalformedToolCallException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\FinishReason\FinishReasonAwareTrait;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\CodeExecutionResult;
use Symfony\AI\Platform\Result\ExecutableCodeResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStateDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\Thinking\ThinkingProviderState;
use Symfony\AI\Platform\Thinking\ThinkingRepresentation;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class ResultConverter implements ResultConverterInterface
{
    use FinishReasonAwareTrait;

    public function supports(Model $model): bool
    {
        return $model instanceof Claude;
    }

    public function convert(RawHttpResult|RawResultInterface $result, array $options = []): ResultInterface
    {
        $response = $result->getObject();

        if (401 === $response->getStatusCode()) {
            $errorMessage = json_decode($response->getContent(false), true)['error']['message'] ?? 'Unauthorized';
            throw new AuthenticationException($errorMessage);
        }

        if (400 === $response->getStatusCode()) {
            $errorMessage = json_decode($response->getContent(false), true)['error']['message'] ?? 'Bad Request';

            if (str_contains($errorMessage, 'prompt is too long')) {
                throw new ExceedContextSizeException($errorMessage);
            }

            throw new BadRequestException($errorMessage);
        }

        if (429 === $response->getStatusCode()) {
            $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
            $retryAfterValue = $retryAfter ? (int) $retryAfter : null;
            $errorMessage = json_decode($response->getContent(false), true)['error']['message'] ?? null;
            throw new RateLimitExceededException($retryAfterValue, $errorMessage);
        }

        if (($code = $response->getStatusCode()) >= 500) {
            $errorMessage = json_decode($response->getContent(false), true)['error']['message'] ?? null;
            throw new ServerException($code, $errorMessage);
        }

        if ($options['stream'] ?? false) {
            if (($code = $response->getStatusCode()) >= 400) {
                throw new RuntimeException(\sprintf('Unexpected response code %d: "%s"', $code, $response->getContent(false)));
            }

            return new StreamResult($this->convertStream($result, $this->thinkingRepresentation($options)));
        }

        $data = $result->getData();

        if (isset($data['type']) && 'error' === $data['type']) {
            $type = $data['error']['type'] ?? 'Unknown';
            $message = $data['error']['message'] ?? 'An unknown error occurred.';

            if ('rate_limit_error' === $type) {
                throw new RateLimitExceededException(null, \sprintf('API Error [%s]: "%s"', $type, $message));
            }

            if (\in_array($type, ['overloaded_error', 'api_error'], true)) {
                throw new ServerException(null, \sprintf('API Error [%s]: "%s"', $type, $message));
            }

            throw new RuntimeException(\sprintf('API Error [%s]: "%s"', $type, $message));
        }

        if (!isset($data['content']) || [] === $data['content']) {
            throw new RuntimeException('Response does not contain any content.');
        }

        $results = [];
        foreach ($data['content'] as $content) {
            if ('tool_use' === $content['type']) {
                $results[] = new ToolCallResult([new ToolCall($content['id'], $content['name'], $content['input'])]);
                continue;
            }

            if ('text' === $content['type']) {
                $results[] = new TextResult($content['text']);
            } elseif ('server_tool_use' === $content['type']) {
                if ('bash_code_execution' === $content['name']) {
                    $results[] = new ExecutableCodeResult($content['input']['command'], 'bash', $content['id']);
                } elseif ('text_editor_code_execution' === $content['name']) {
                    $results[] = new ExecutableCodeResult($content['input']['file_text'] ?? $content['input']['command'], null, $content['id']);
                }
            } elseif ('bash_code_execution_tool_result' === $content['type']) {
                $results[] = new CodeExecutionResult(
                    0 === ($content['content']['return_code'] ?? 0),
                    ($content['content']['stdout'] ?? '').($content['content']['stderr'] ?? '') ?: null,
                    $content['tool_use_id'],
                );
            } elseif ('text_editor_code_execution_tool_result' === $content['type']) {
                $results[] = new CodeExecutionResult(true, null, $content['tool_use_id']);
            } elseif ('thinking' === $content['type']) {
                $thinking = $content['thinking'] ?? '';
                $signature = $content['signature'] ?? null;
                $results[] = new ThinkingResult(
                    $thinking,
                    '' === $thinking ? ThinkingRepresentation::OPAQUE : $this->thinkingRepresentation($options),
                    \is_string($signature) && '' !== $signature ? new ThinkingProviderState(ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, $signature) : null,
                );
            } elseif ('redacted_thinking' === $content['type']) {
                $payload = $content['data'] ?? null;
                $results[] = new ThinkingResult(
                    '',
                    ThinkingRepresentation::OPAQUE,
                    \is_string($payload) && '' !== $payload ? new ThinkingProviderState(ThinkingProviderState::FORMAT_ANTHROPIC_REDACTED, $payload) : null,
                );
            }
        }

        if ([] === $results) {
            throw new RuntimeException('Response content does not contain any supported content.');
        }

        return $this->withFinishReason(
            1 === \count($results) ? $results[0] : new MultiPartResult($results),
            FinishReasonMapper::map($data['stop_reason'] ?? null),
        );
    }

    public function getTokenUsageExtractor(): TokenUsageExtractor
    {
        return new TokenUsageExtractor();
    }

    private function convertStream(RawResultInterface $result, ThinkingRepresentation $readableRepresentation): \Generator
    {
        $toolCalls = [];
        $currentToolCall = null;
        $currentToolCallJson = '';
        $currentThinking = null;
        $currentThinkingState = null;
        $currentThinkingRepresentation = null;
        $currentThinkingId = null;
        $inMessage = false;
        $stopReason = null;
        $outputTokens = null;

        foreach ($result->getDataStream() as $data) {
            $type = $data['type'] ?? '';

            if ('error' === $type) {
                $message = $data['error']['message'] ?? 'Unknown Anthropic stream error.';

                if ('rate_limit_error' === ($data['error']['type'] ?? null)) {
                    throw new RateLimitExceededException(null, $message);
                }

                if (\in_array($data['error']['type'] ?? null, ['overloaded_error', 'api_error'], true)) {
                    throw new ServerException(null, $message);
                }

                throw new RuntimeException($message);
            }

            if ('message_start' === $type) {
                $inMessage = true;
            }

            // Anthropic reports usage in both message_start and message_delta:
            // message_start carries the prompt and cache token counts plus a
            // provisional output_tokens, and message_delta repeats the same
            // cumulative prompt/cache counts with the final output_tokens. As
            // the stream aggregation sums every yielded usage, emitting the full
            // payload from both events would double-count input and cache tokens.
            // Yield the prompt/cache counts once (message_start, without the
            // provisional output) and the final output once (message_delta).
            if ('message_start' === $type && isset($data['message']['usage'])) {
                $usage = $data['message']['usage'];
                unset($usage['output_tokens']);
                yield $this->getTokenUsageExtractor()->extractFromArray($usage);
            }

            if ('message_delta' === $type) {
                $stopReason = $data['delta']['stop_reason'] ?? $stopReason;

                if (isset($data['usage'])) {
                    $outputTokens = $data['usage']['output_tokens'] ?? $outputTokens;
                    yield $this->getTokenUsageExtractor()->extractFromArray([
                        'output_tokens' => $outputTokens ?? 0,
                    ]);
                }
            }

            // Handle text content deltas
            if ('content_block_delta' === $type && isset($data['delta']['text'])) {
                yield new TextDelta($data['delta']['text']);
                continue;
            }

            if ('content_block_start' === $type && 'thinking' === ($data['content_block']['type'] ?? null)) {
                $currentThinking = \is_string($data['content_block']['thinking'] ?? null) ? $data['content_block']['thinking'] : '';
                $signature = $data['content_block']['signature'] ?? null;
                $currentThinkingState = \is_string($signature) && '' !== $signature ? new ThinkingProviderState(ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, $signature) : null;
                $currentThinkingRepresentation = '' !== $currentThinking ? $readableRepresentation : (null !== $currentThinkingState ? ThinkingRepresentation::OPAQUE : null);
                $currentThinkingId = 'anthropic-thinking-'.($data['index'] ?? 0);

                if (null !== $currentThinkingRepresentation) {
                    yield new ThinkingStart($currentThinkingId, $currentThinkingRepresentation);
                    if ('' !== $currentThinking) {
                        yield new ThinkingDelta($currentThinkingId, $currentThinking, $currentThinkingRepresentation);
                    }
                    if (null !== $currentThinkingState) {
                        yield new ThinkingStateDelta($currentThinkingId, $currentThinkingState->getFormat(), $currentThinkingState->getPayload(), $currentThinkingRepresentation);
                    }
                }
                continue;
            }

            if ('content_block_start' === $type && 'redacted_thinking' === ($data['content_block']['type'] ?? null)) {
                $currentThinking = '';
                $payload = $data['content_block']['data'] ?? null;
                $currentThinkingState = \is_string($payload) && '' !== $payload ? new ThinkingProviderState(ThinkingProviderState::FORMAT_ANTHROPIC_REDACTED, $payload) : null;
                $currentThinkingRepresentation = ThinkingRepresentation::OPAQUE;
                $currentThinkingId = 'anthropic-thinking-'.($data['index'] ?? 0);
                yield new ThinkingStart($currentThinkingId, $currentThinkingRepresentation);
                if (null !== $currentThinkingState) {
                    yield new ThinkingStateDelta($currentThinkingId, $currentThinkingState->getFormat(), $currentThinkingState->getPayload(), $currentThinkingRepresentation);
                }
                continue;
            }

            if ('content_block_delta' === $type && 'thinking_delta' === ($data['delta']['type'] ?? null)) {
                $currentThinkingId ??= 'anthropic-thinking-'.($data['index'] ?? 0);
                if (null === $currentThinkingRepresentation) {
                    $currentThinkingRepresentation = $readableRepresentation;
                    yield new ThinkingStart($currentThinkingId, $currentThinkingRepresentation);
                }
                $thinking = $data['delta']['thinking'] ?? '';
                $currentThinking = ($currentThinking ?? '').$thinking;
                yield new ThinkingDelta($currentThinkingId, $thinking, $currentThinkingRepresentation);
                continue;
            }

            if ('content_block_delta' === $type && 'signature_delta' === ($data['delta']['type'] ?? null)) {
                $currentThinkingId ??= 'anthropic-thinking-'.($data['index'] ?? 0);
                if (null === $currentThinkingRepresentation) {
                    $currentThinkingRepresentation = ThinkingRepresentation::OPAQUE;
                    yield new ThinkingStart($currentThinkingId, $currentThinkingRepresentation);
                }
                $signature = $data['delta']['signature'] ?? '';
                if ('' !== $signature) {
                    $payload = ($currentThinkingState?->getPayload() ?? '').$signature;
                    $currentThinkingState = new ThinkingProviderState(ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, $payload);
                    yield new ThinkingStateDelta($currentThinkingId, ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, $signature, $currentThinkingRepresentation);
                }
                continue;
            }

            // Handle tool_use content block start
            if ('content_block_start' === $type
                && isset($data['content_block']['type'])
                && 'tool_use' === $data['content_block']['type']
            ) {
                $currentToolCall = [
                    'id' => $data['content_block']['id'],
                    'name' => $data['content_block']['name'],
                ];
                $currentToolCallJson = '';
                yield new ToolCallStart($data['content_block']['id'], $data['content_block']['name']);
                continue;
            }

            // Handle tool_use input JSON deltas
            if ('content_block_delta' === $type
                && isset($data['delta']['type'])
                && 'input_json_delta' === $data['delta']['type']
            ) {
                $partialJson = $data['delta']['partial_json'] ?? '';
                $currentToolCallJson .= $partialJson;
                if (null !== $currentToolCall) {
                    yield new ToolInputDelta($currentToolCall['id'], $currentToolCall['name'], $partialJson);
                }
                continue;
            }

            // Handle content block stop - finalize current thinking or tool call
            if ('content_block_stop' === $type) {
                if (null !== $currentThinkingRepresentation && null !== $currentThinkingId) {
                    yield new ThinkingComplete($currentThinkingId, $currentThinking ?? '', $currentThinkingRepresentation, $currentThinkingState);
                    $currentThinking = null;
                    $currentThinkingState = null;
                    $currentThinkingRepresentation = null;
                    $currentThinkingId = null;
                    continue;
                }

                if (null !== $currentToolCall) {
                    $input = [];
                    if ('' !== $currentToolCallJson) {
                        try {
                            $input = json_decode($currentToolCallJson, true, flags: \JSON_THROW_ON_ERROR);
                        } catch (\JsonException $e) {
                            throw new MalformedToolCallException(\sprintf('Anthropic returned malformed JSON arguments for the "%s" tool: "%s"', $currentToolCall['name'], $e->getMessage()), 0, $e);
                        }
                    }
                    $toolCalls[] = new ToolCall(
                        $currentToolCall['id'],
                        $currentToolCall['name'],
                        $input
                    );
                    $currentToolCall = null;
                    $currentToolCallJson = '';
                    continue;
                }
            }

            // Handle message stop - yield tool calls if any were collected
            if ('message_stop' === $type) {
                $inMessage = false;

                if ('max_tokens' === $stopReason) {
                    $message = 'Anthropic truncated the response after reaching the output token limit. Raise the output token budget (max_tokens) or reduce the request scope.';
                    if (null !== $outputTokens) {
                        $message = \sprintf('Anthropic truncated the response after reaching the maximum of %d output tokens. Raise the output token budget (max_tokens) or reduce the request scope.', $outputTokens);
                    }

                    throw new MaxOutputTokensException($message);
                }

                if ([] !== $toolCalls) {
                    yield new ToolCallComplete($toolCalls);
                }
            }
        }

        if ($inMessage) {
            throw new IncompleteStreamException('Anthropic stream ended before message_stop.');
        }

        // Anthropic reports the stop reason on message_delta, before message_stop. A `max_tokens`
        // truncation has already thrown above, so any reason reaching here is a normal completion.
        if (null !== $stopReason) {
            yield new MetadataDelta('finish_reason', FinishReasonMapper::map($stopReason));
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function thinkingRepresentation(array $options): ThinkingRepresentation
    {
        $model = $options[Contract::CONTEXT_MODEL] ?? null;
        if ($model instanceof Model && $model->supports(Capability::OUTPUT_THINKING_FULL)) {
            return ThinkingRepresentation::FULL;
        }
        if ($model instanceof Model && $model->supports(Capability::OUTPUT_THINKING_SUMMARY)) {
            return ThinkingRepresentation::SUMMARY;
        }

        return ThinkingRepresentation::UNKNOWN;
    }
}
