<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Bedrock\Anthropic;

use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\FinishReasonMapper;
use Symfony\AI\Platform\Bridge\Bedrock\RawBedrockResult;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\FinishReason\FinishReasonAwareTrait;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\Thinking\ThinkingProviderState;
use Symfony\AI\Platform\Thinking\ThinkingRepresentation;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * @author Björn Altmann
 */
final class ClaudeResultConverter implements ResultConverterInterface
{
    use FinishReasonAwareTrait;

    public function supports(Model $model): bool
    {
        return $model instanceof Claude;
    }

    public function convert(RawResultInterface|RawBedrockResult $result, array $options = []): ResultInterface
    {
        $data = $result->getData();

        if (!isset($data['content']) || [] === $data['content']) {
            throw new RuntimeException('Response does not contain any content.');
        }

        $results = [];
        foreach ($data['content'] as $content) {
            $type = $content['type'] ?? null;

            if ('tool_use' === $type) {
                $results[] = new ToolCallResult([new ToolCall($content['id'], $content['name'], $content['input'])]);
            } elseif ('text' === $type) {
                $results[] = new TextResult($content['text']);
            } elseif ('thinking' === $type) {
                $thinking = $content['thinking'] ?? '';
                $signature = $content['signature'] ?? null;
                $results[] = new ThinkingResult(
                    $thinking,
                    '' === $thinking ? ThinkingRepresentation::OPAQUE : $this->thinkingRepresentation($options),
                    \is_string($signature) && '' !== $signature ? new ThinkingProviderState(ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, $signature) : null,
                );
            } elseif ('redacted_thinking' === $type) {
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

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
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
