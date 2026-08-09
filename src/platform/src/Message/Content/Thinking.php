<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Message\Content;

use Symfony\AI\Platform\Thinking\ThinkingProviderState;
use Symfony\AI\Platform\Thinking\ThinkingRepresentation;

/**
 * Represents a thinking/reasoning block emitted by an assistant.
 */
final class Thinking implements ContentInterface
{
    public function __construct(
        private readonly string $content,
        private readonly ThinkingRepresentation $representation = ThinkingRepresentation::UNKNOWN,
        private readonly ?ThinkingProviderState $providerState = null,
    ) {
    }

    public function getContent(): string
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
