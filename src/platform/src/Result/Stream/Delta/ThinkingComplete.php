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
use Symfony\AI\Platform\Thinking\ThinkingProviderState;
use Symfony\AI\Platform\Thinking\ThinkingRepresentation;

/**
 * Signals that a thinking block is complete with accumulated content.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class ThinkingComplete implements DeltaInterface
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        private readonly string $id,
        private readonly string $thinking,
        private readonly ThinkingRepresentation $representation,
        private readonly ?ThinkingProviderState $providerState = null,
    ) {
        if ('' === $id) {
            throw new InvalidArgumentException('Thinking stream ID cannot be empty.');
        }
    }

    /**
     * @return non-empty-string
     */
    public function getId(): string
    {
        return $this->id;
    }

    public function getThinking(): string
    {
        return $this->thinking;
    }

    public function getRepresentation(): ThinkingRepresentation
    {
        return $this->representation;
    }

    public function getProviderState(): ?ThinkingProviderState
    {
        return $this->providerState;
    }
}
