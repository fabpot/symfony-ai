<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\ModelCatalog;

use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\EmbeddingsModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Model;

/**
 * A model catalog that accepts any model name, optionally delegating to a
 * primary catalog first.
 *
 * When a primary catalog is provided, it is tried first. If it throws a
 * {@see ModelNotFoundException}, the fallback heuristic kicks in: models
 * whose name contains "embed" (case-insensitive) are created as
 * {@see EmbeddingsModel}, all others as {@see CompletionsModel}. Every
 * fallback model receives all capabilities since we cannot know the exact
 * set for an unknown model.
 *
 * This is especially useful when wrapping a curated catalog (e.g. from
 * models.dev) so that newly released or custom models that are not yet in
 * the registry still resolve instead of throwing.
 *
 * @author Oskar Stark <oskarstark@googlemail.com>
 */
class FallbackModelCatalog extends AbstractModelCatalog
{
    public function __construct(
        private readonly ?ModelCatalogInterface $primary = null,
    ) {
        $this->models = [];
    }

    public function getModel(string $modelName): Model
    {
        if (null !== $this->primary) {
            try {
                return $this->primary->getModel($modelName);
            } catch (ModelNotFoundException) {
                // Fall through to heuristic
            }
        }

        $parsed = self::parseModelName($modelName);

        if (str_contains(strtolower($parsed['name']), 'embed')) {
            return new EmbeddingsModel($parsed['name'], Capability::cases(), $parsed['options']);
        }

        return new CompletionsModel($parsed['name'], Capability::cases(), $parsed['options']);
    }
}
