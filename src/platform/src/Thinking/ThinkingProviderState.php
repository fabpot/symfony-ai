<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Thinking;

use Symfony\AI\Platform\Exception\InvalidArgumentException;

/**
 * Opaque provider state required to replay a thinking block on a later turn.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class ThinkingProviderState
{
    public const FORMAT_ANTHROPIC_SIGNATURE = 'anthropic-signature';
    public const FORMAT_ANTHROPIC_REDACTED = 'anthropic-redacted';
    public const FORMAT_OPEN_RESPONSES_REASONING = 'open-responses-reasoning';
    public const FORMAT_GEMINI_THOUGHT_SIGNATURE = 'gemini-thought-signature';
    public const FORMAT_UNKNOWN = 'unknown';

    /**
     * @param non-empty-string $format
     * @param non-empty-string $payload
     */
    public function __construct(
        private readonly string $format,
        private readonly string $payload,
    ) {
        if ('' === $format) {
            throw new InvalidArgumentException('Thinking provider state format cannot be empty.');
        }

        if ('' === $payload) {
            throw new InvalidArgumentException('Thinking provider state payload cannot be empty.');
        }
    }

    /**
     * @return non-empty-string
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * @return non-empty-string
     */
    public function getPayload(): string
    {
        return $this->payload;
    }
}
