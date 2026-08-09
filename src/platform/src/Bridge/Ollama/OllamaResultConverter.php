<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Ollama;

use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\FinishReason\FinishReasonAwareTrait;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\Thinking\ThinkingRepresentation;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\Vector\Vector;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class OllamaResultConverter implements ResultConverterInterface
{
    use FinishReasonAwareTrait;

    public function supports(Model $model): bool
    {
        return $model instanceof Ollama;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        if ($options['stream'] ?? false) {
            return new StreamResult($this->convertStream($result));
        }

        $data = $result->getData();

        return \array_key_exists('embeddings', $data)
            ? $this->doConvertEmbeddings($data)
            : $this->doConvertCompletion($data);
    }

    public function getTokenUsageExtractor(): TokenUsageExtractorInterface
    {
        return new TokenUsageExtractor();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function doConvertCompletion(array $data): ResultInterface
    {
        if (!isset($data['message'])) {
            throw new RuntimeException('Response does not contain message.');
        }

        if (!isset($data['message']['content'])) {
            throw new RuntimeException('Message does not contain content.');
        }

        $results = [];
        if (\is_string($data['message']['thinking'] ?? null) && '' !== $data['message']['thinking']) {
            $results[] = new ThinkingResult($data['message']['thinking'], ThinkingRepresentation::FULL);
        }

        if ('' !== $data['message']['content']) {
            $results[] = new TextResult($data['message']['content']);
        }

        $toolCalls = [];
        foreach ($data['message']['tool_calls'] ?? [] as $id => $toolCall) {
            $toolCalls[] = new ToolCall($id, $toolCall['function']['name'], $toolCall['function']['arguments']);
        }
        if ([] !== $toolCalls) {
            $results[] = new ToolCallResult($toolCalls);
        }

        if ([] === $results) {
            $results[] = new TextResult('');
        }

        $converted = 1 === \count($results) ? $results[0] : new MultiPartResult($results);

        return $this->withFinishReason($converted, FinishReasonMapper::map($data['done_reason'] ?? null));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function doConvertEmbeddings(array $data): ResultInterface
    {
        if ([] === $data['embeddings']) {
            throw new RuntimeException('Response does not contain embeddings.');
        }

        return new VectorResult(
            array_map(
                static fn (array $embedding): Vector => new Vector($embedding),
                $data['embeddings'],
            ),
        );
    }

    private function convertStream(RawResultInterface $result): \Generator
    {
        $toolCalls = [];
        $sawChunk = false;
        $sawDone = false;
        $finishReason = null;
        $thinking = '';
        $thinkingId = null;
        $thinkingBlock = 0;
        foreach ($result->getDataStream() as $data) {
            // Ollama emits {"error": "..."} on HTTP 200 in practice; not part of the
            // documented schema, so this guard is defensive.
            if (isset($data['error'])) {
                throw new RuntimeException(\sprintf('Ollama stream error: "%s".', \is_string($data['error']) ? $data['error'] : 'Unknown error'));
            }

            $sawChunk = true;

            if (isset($data['done']) && true === $data['done']) {
                $sawDone = true;

                if (null !== ($data['done_reason'] ?? null)) {
                    $finishReason = FinishReasonMapper::map($data['done_reason']);
                }
            }

            if ($this->streamIsToolCall($data)) {
                $toolCalls = $this->convertStreamToToolCalls($toolCalls, $data);
            }

            if ($this->hasThinkingDelta($data)) {
                if (null === $thinkingId) {
                    $thinkingId = 'ollama-thinking-'.$thinkingBlock++;
                    yield new ThinkingStart($thinkingId, ThinkingRepresentation::FULL);
                }
                $thinking .= $data['message']['thinking'];
                yield new ThinkingDelta($thinkingId, $data['message']['thinking'], ThinkingRepresentation::FULL);
            }

            if ($this->hasTextDelta($data)) {
                if (null !== $thinkingId) {
                    yield new ThinkingComplete($thinkingId, $thinking, ThinkingRepresentation::FULL);
                    $thinking = '';
                    $thinkingId = null;
                }
                yield new TextDelta($data['message']['content']);
            }

            if ([] !== $toolCalls && $this->isToolCallsStreamFinished($data)) {
                yield new ToolCallComplete($toolCalls);
            }

            if ($this->hasStreamTokenUsage($data)) {
                yield new TokenUsage(
                    promptTokens: $data['prompt_eval_count'],
                    completionTokens: $data['eval_count'],
                );
            }
        }

        if (null !== $thinkingId) {
            yield new ThinkingComplete($thinkingId, $thinking, ThinkingRepresentation::FULL);
        }

        if ($sawChunk && !$sawDone) {
            throw new IncompleteStreamException('Ollama stream ended before a "done" message.');
        }

        if (null !== $finishReason) {
            yield new MetadataDelta('finish_reason', $finishReason);
        }
    }

    /**
     * @param array<string, mixed> $toolCalls
     * @param array<string, mixed> $data
     *
     * @return array<ToolCall>
     */
    private function convertStreamToToolCalls(array $toolCalls, array $data): array
    {
        if (!isset($data['message']['tool_calls'])) {
            return $toolCalls;
        }

        foreach ($data['message']['tool_calls'] ?? [] as $id => $toolCall) {
            $toolCalls[] = new ToolCall($id, $toolCall['function']['name'], $toolCall['function']['arguments']);
        }

        return $toolCalls;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function streamIsToolCall(array $data): bool
    {
        return isset($data['message']['tool_calls']);
    }

    /**
     * @param array<string, mixed> $data^
     */
    private function isToolCallsStreamFinished(array $data): bool
    {
        return isset($data['done']) && true === $data['done'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasStreamTokenUsage(array $data): bool
    {
        return isset($data['prompt_eval_count'], $data['eval_count']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasTextDelta(array $data): bool
    {
        return isset($data['message']['content']) && '' !== $data['message']['content'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasThinkingDelta(array $data): bool
    {
        return isset($data['message']['thinking']) && '' !== $data['message']['thinking'];
    }
}
