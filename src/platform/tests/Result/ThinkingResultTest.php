<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Tests\Result;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Thinking\ThinkingProviderState;
use Symfony\AI\Platform\Thinking\ThinkingRepresentation;

final class ThinkingResultTest extends TestCase
{
    public function testCarriesThinkingRepresentationAndProviderState()
    {
        $providerState = new ThinkingProviderState(ThinkingProviderState::FORMAT_ANTHROPIC_SIGNATURE, 'sig_abc');
        $result = new ThinkingResult('Thinking step by step…', ThinkingRepresentation::SUMMARY, $providerState);

        $this->assertSame('Thinking step by step…', $result->getContent());
        $this->assertSame(ThinkingRepresentation::SUMMARY, $result->getRepresentation());
        $this->assertSame($providerState, $result->getProviderState());
    }

    public function testDefaultsToUnknownRepresentationWithoutProviderState()
    {
        $result = new ThinkingResult();

        $this->assertNull($result->getContent());
        $this->assertSame(ThinkingRepresentation::UNKNOWN, $result->getRepresentation());
        $this->assertNull($result->getProviderState());
    }
}
