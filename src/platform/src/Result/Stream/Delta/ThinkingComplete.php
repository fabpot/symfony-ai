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

use Symfony\AI\Platform\Result\ThinkingContentType;

/**
 * Signals that a thinking block is complete with accumulated content.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class ThinkingComplete implements DeltaInterface
{
    public function __construct(
        private readonly string $thinking,
        private readonly ?string $signature = null,
        private readonly ThinkingContentType $contentType = ThinkingContentType::FULL,
    ) {
    }

    public function getThinking(): string
    {
        return $this->thinking;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function getContentType(): ThinkingContentType
    {
        return $this->contentType;
    }
}
