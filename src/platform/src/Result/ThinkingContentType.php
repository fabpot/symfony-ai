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

/**
 * Describes how much of a model's reasoning is exposed as readable content.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
enum ThinkingContentType: string
{
    case FULL = 'full';
    case SUMMARY = 'summary';
    case OPAQUE = 'opaque';
    case REDACTED = 'redacted';
}
