<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Tests;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\EmbeddingsModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

/**
 * @author Oskar Stark <oskarstark@googlemail.com>
 */
final class FallbackModelCatalogTest extends TestCase
{
    public function testGetModelReturnsCompletionsModelByDefault()
    {
        $catalog = new FallbackModelCatalog();
        $model = $catalog->getModel('gpt-4o');

        $this->assertInstanceOf(CompletionsModel::class, $model);
        $this->assertSame('gpt-4o', $model->getName());

        // Check that all capabilities are present
        foreach (Capability::cases() as $capability) {
            $this->assertTrue($model->supports($capability), \sprintf('Model should have capability %s', $capability->value));
        }
    }

    #[TestWith(['text-embedding-3-small'])]
    #[TestWith(['text-embedding-ada-002'])]
    #[TestWith(['voyage-embed-3'])]
    #[TestWith(['Embed-v1'])]
    public function testGetModelReturnsEmbeddingsModelWhenNameContainsEmbed(string $modelName)
    {
        $catalog = new FallbackModelCatalog();
        $model = $catalog->getModel($modelName);

        $this->assertInstanceOf(EmbeddingsModel::class, $model);
        $this->assertSame($modelName, $model->getName());
    }

    #[TestWith(['gpt-4o'])]
    #[TestWith(['claude-3-opus'])]
    #[TestWith(['mistral-large'])]
    #[TestWith(['llama-3.1-70b'])]
    public function testGetModelReturnsCompletionsModelWhenNameDoesNotContainEmbed(string $modelName)
    {
        $catalog = new FallbackModelCatalog();
        $model = $catalog->getModel($modelName);

        $this->assertInstanceOf(CompletionsModel::class, $model);
        $this->assertSame($modelName, $model->getName());
    }

    public function testGetModelWithOptions()
    {
        $catalog = new FallbackModelCatalog();
        $model = $catalog->getModel('test-model?temperature=0.7&max_tokens=1000');

        $this->assertInstanceOf(CompletionsModel::class, $model);
        $this->assertSame('test-model', $model->getName());

        $options = $model->getOptions();
        $this->assertSame(0.7, $options['temperature']);
        $this->assertIsFloat($options['temperature']);
        $this->assertSame(1000, $options['max_tokens']);
        $this->assertIsInt($options['max_tokens']);
    }

    #[TestWith(['gpt-4'])]
    #[TestWith(['claude-3-opus'])]
    #[TestWith(['mistral-large'])]
    #[TestWith(['some/random/model:v1.0'])]
    #[TestWith(['huggingface/model-name'])]
    #[TestWith(['custom-local-model'])]
    public function testGetModelAcceptsAnyModelName(string $modelName)
    {
        $catalog = new FallbackModelCatalog();
        $model = $catalog->getModel($modelName);

        $this->assertInstanceOf(Model::class, $model);
        $this->assertSame($modelName, $model->getName());
    }

    public function testGetModelsReturnsEmptyArray()
    {
        $this->assertSame([], (new FallbackModelCatalog())->getModels());
        $this->assertSame([], (new FallbackModelCatalog($this->createMock(ModelCatalogInterface::class)))->getModels());
    }

    public function testPrimaryCatalogIsTriedFirst()
    {
        $primaryModel = new CompletionsModel('known-model', [Capability::INPUT_MESSAGES]);
        $primary = $this->createMock(ModelCatalogInterface::class);
        $primary->method('getModel')->with('known-model')->willReturn($primaryModel);

        $catalog = new FallbackModelCatalog($primary);
        $model = $catalog->getModel('known-model');

        $this->assertSame($primaryModel, $model);
    }

    public function testFallbackIsUsedWhenPrimaryThrows()
    {
        $primary = $this->createMock(ModelCatalogInterface::class);
        $primary->method('getModel')
            ->with('unknown-model')
            ->willThrowException(new ModelNotFoundException('Not found'));

        $catalog = new FallbackModelCatalog($primary);
        $model = $catalog->getModel('unknown-model');

        $this->assertInstanceOf(CompletionsModel::class, $model);
        $this->assertSame('unknown-model', $model->getName());
    }

    public function testFallbackEmbedHeuristicWithPrimary()
    {
        $primary = $this->createMock(ModelCatalogInterface::class);
        $primary->method('getModel')
            ->with('custom-embedding-model')
            ->willThrowException(new ModelNotFoundException('Not found'));

        $catalog = new FallbackModelCatalog($primary);
        $model = $catalog->getModel('custom-embedding-model');

        $this->assertInstanceOf(EmbeddingsModel::class, $model);
    }

}
