<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Tests\Thinking;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Thinking\ThinkingProviderState;

final class ThinkingProviderStateTest extends TestCase
{
    #[DataProvider('provideInvalidState')]
    public function testRejectsEmptyState(string $format, string $payload, string $message)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ThinkingProviderState($format, $payload);
    }

    public static function provideInvalidState(): iterable
    {
        yield 'format' => ['', 'payload', 'Thinking provider state format cannot be empty.'];
        yield 'payload' => [ThinkingProviderState::FORMAT_UNKNOWN, '', 'Thinking provider state payload cannot be empty.'];
    }
}
