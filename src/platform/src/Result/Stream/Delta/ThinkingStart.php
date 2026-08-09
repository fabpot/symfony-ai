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
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ThinkingStart implements DeltaInterface
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        private readonly string $id,
        private readonly ThinkingRepresentation $representation,
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

    public function getRepresentation(): ThinkingRepresentation
    {
        return $this->representation;
    }
}
