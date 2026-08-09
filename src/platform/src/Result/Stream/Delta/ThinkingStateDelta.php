<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Result\Stream\Delta;

use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Thinking\ThinkingRepresentation;

/**
 * Carries a chunk of provider state for a streamed thinking block.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class ThinkingStateDelta implements DeltaInterface
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string $format
     * @param non-empty-string $payload
     */
    public function __construct(
        private readonly string $id,
        private readonly string $format,
        private readonly string $payload,
        private readonly ThinkingRepresentation $representation,
    ) {
        if ('' === $id) {
            throw new InvalidArgumentException('Thinking stream ID cannot be empty.');
        }

        if ('' === $format) {
            throw new InvalidArgumentException('Thinking state delta format cannot be empty.');
        }

        if ('' === $payload) {
            throw new InvalidArgumentException('Thinking state delta payload cannot be empty.');
        }
    }

    /**
     * @return non-empty-string
     */
    public function getId(): string
    {
        return $this->id;
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

    public function getRepresentation(): ThinkingRepresentation
    {
        return $this->representation;
    }
}
