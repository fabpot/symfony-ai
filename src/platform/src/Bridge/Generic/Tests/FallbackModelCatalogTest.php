<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Generic\Tests;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\EmbeddingsModel;
use Symfony\AI\Platform\Bridge\Generic\FallbackModelCatalog;
use Symfony\AI\Platform\Capability;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class FallbackModelCatalogTest extends TestCase
{
    #[TestWith(['gpt-4o'])]
    #[TestWith(['claude-3-5-sonnet-20241022'])]
    #[TestWith(['mistral-large'])]
    #[TestWith(['deepseek-chat'])]
    public function testCompletionsModel(string $modelName): void
    {
        $catalog = new FallbackModelCatalog();
        $model = $catalog->getModel($modelName);

        $this->assertInstanceOf(CompletionsModel::class, $model);
        $this->assertSame($modelName, $model->getName());
    }

    #[TestWith(['text-embedding-3-small'])]
    #[TestWith(['text-embedding-ada-002'])]
    #[TestWith(['text-embedding-004'])]
    #[TestWith(['gemini-embedding-001'])]
    public function testEmbeddingsModel(string $modelName): void
    {
        $catalog = new FallbackModelCatalog();
        $model = $catalog->getModel($modelName);

        $this->assertInstanceOf(EmbeddingsModel::class, $model);
        $this->assertSame($modelName, $model->getName());
    }

    public function testAllCapabilitiesArePresent(): void
    {
        $catalog = new FallbackModelCatalog();
        $model = $catalog->getModel('test-model');

        foreach (Capability::cases() as $capability) {
            $this->assertTrue($model->supports($capability), \sprintf('Model should have capability %s', $capability->value));
        }
    }

    public function testModelWithOptions(): void
    {
        $catalog = new FallbackModelCatalog();
        $model = $catalog->getModel('gpt-4o?temperature=0.7&max_tokens=1000');

        $this->assertInstanceOf(CompletionsModel::class, $model);
        $this->assertSame('gpt-4o', $model->getName());
        $this->assertSame(0.7, $model->getOptions()['temperature']);
        $this->assertSame(1000, $model->getOptions()['max_tokens']);
    }

    public function testGetModelsReturnsEmptyArray(): void
    {
        $catalog = new FallbackModelCatalog();

        $this->assertSame([], $catalog->getModels());
    }
}
