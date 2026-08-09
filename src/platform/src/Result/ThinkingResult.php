<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Result;

use Symfony\AI\Platform\Thinking\ThinkingProviderState;
use Symfony\AI\Platform\Thinking\ThinkingRepresentation;

/**
 * Represents a separate thinking block/part.
 */
final class ThinkingResult extends BaseResult
{
    public function __construct(
        private readonly ?string $content = null,
        private readonly ThinkingRepresentation $representation = ThinkingRepresentation::UNKNOWN,
        private readonly ?ThinkingProviderState $providerState = null,
    ) {
    }

    public function getContent(): ?string
    {
        return $this->content;
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
