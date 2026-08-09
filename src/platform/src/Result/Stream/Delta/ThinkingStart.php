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
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ThinkingStart implements DeltaInterface
{
    public function __construct(
        private readonly ThinkingContentType $contentType = ThinkingContentType::FULL,
    ) {
    }

    public function getContentType(): ThinkingContentType
    {
        return $this->contentType;
    }
}
