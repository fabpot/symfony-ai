<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Toolbox;

use Symfony\AI\Agent\Exception\LogicException;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\ContentInterface;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\CompleteEvent;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStateDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\Stream\StartEvent;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Thinking\ThinkingProviderState;
use Symfony\AI\Platform\Thinking\ThinkingRepresentation;

/**
 * @author Denis Zunke <denis.zunke@gmail.com>
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class StreamListener extends AbstractStreamListener
{
    /**
     * Content is collected in provider order; strings reserve the positions announced by ToolCallStart.
     *
     * @var list<ContentInterface|string>
     */
    private array $assistantContent = [];

    /**
     * Stable block IDs keep interleaved thinking deltas associated with the right content.
     *
     * @var array<string, int>
     */
    private array $thinkingContentIndexes = [];
    private ?ResultInterface $result = null;
    private bool $toolHandled = false;

    public function __construct(
        private readonly \Closure $handleToolCallsCallback,
    ) {
    }

    public function onStart(StartEvent $event): void
    {
        $this->assistantContent = [];
        $this->thinkingContentIndexes = [];
        $this->result = null;
        $this->toolHandled = false;
    }

    public function onDelta(DeltaEvent $event): void
    {
        if ($this->toolHandled) {
            $event->skipDelta();

            return;
        }

        $delta = $event->getDelta();

        // Empty deltas carry no replay state, and some providers reject empty content blocks
        if ($delta instanceof TextDelta && '' === $delta->getText()) {
            $event->skipDelta();

            return;
        }

        // Build the assistant message that will be replayed with the tool results
        if ($delta instanceof TextDelta) {
            $index = array_key_last($this->assistantContent);
            $text = null === $index ? null : $this->assistantContent[$index];
            if ($text instanceof Text) {
                $this->assistantContent[$index] = new Text($text->getText().$delta->getText(), $text->getSignature());
            } else {
                $this->assistantContent[] = new Text($delta->getText());
            }
        } elseif ($delta instanceof ThinkingStart) {
            $this->getThinkingContentIndex($delta->getId(), $delta->getRepresentation());
        } elseif ($delta instanceof ThinkingDelta) {
            $index = $this->getThinkingContentIndex($delta->getId(), $delta->getRepresentation());
            /** @var Thinking $thinking */
            $thinking = $this->assistantContent[$index];
            $this->assistantContent[$index] = new Thinking(
                $thinking->getContent().$delta->getThinking(),
                $delta->getRepresentation(),
                $thinking->getProviderState(),
            );
        } elseif ($delta instanceof ThinkingStateDelta) {
            $index = $this->getThinkingContentIndex($delta->getId(), $delta->getRepresentation());
            /** @var Thinking $thinking */
            $thinking = $this->assistantContent[$index];
            $providerState = $thinking->getProviderState();
            if (null !== $providerState && $providerState->getFormat() !== $delta->getFormat()) {
                throw new LogicException(\sprintf('Thinking block "%s" changed provider state format from "%s" to "%s".', $delta->getId(), $providerState->getFormat(), $delta->getFormat()));
            }

            $this->assistantContent[$index] = new Thinking(
                $thinking->getContent(),
                $delta->getRepresentation(),
                new ThinkingProviderState($delta->getFormat(), ($providerState?->getPayload() ?? '').$delta->getPayload()),
            );
        } elseif ($delta instanceof ThinkingComplete) {
            $index = $this->getThinkingContentIndex($delta->getId(), $delta->getRepresentation());
            /** @var Thinking $thinking */
            $thinking = $this->assistantContent[$index];
            $providerState = $thinking->getProviderState();
            $completedProviderState = $delta->getProviderState();
            if (null !== $providerState && null !== $completedProviderState && $providerState->getFormat() !== $completedProviderState->getFormat()) {
                throw new LogicException(\sprintf('Thinking block "%s" changed provider state format from "%s" to "%s".', $delta->getId(), $providerState->getFormat(), $completedProviderState->getFormat()));
            }

            $this->assistantContent[$index] = new Thinking(
                $delta->getThinking(),
                $delta->getRepresentation(),
                $completedProviderState ?? $providerState,
            );
        } elseif ($delta instanceof ToolCallStart) {
            $this->assistantContent[] = $delta->getId();
        }

        if (!$delta instanceof ToolCallComplete) {
            return;
        }

        // Replace ToolCallStart placeholders with completed calls while retaining their
        // positions relative to text and thinking blocks
        $toolCalls = [];
        foreach ($delta->getToolCalls() as $toolCall) {
            $toolCalls[$toolCall->getId()] = $toolCall;
        }

        $content = [];
        $placedToolCalls = [];
        foreach ($this->assistantContent as $part) {
            // A frame without content or provider state is structural only and must not be replayed
            if ($part instanceof Thinking && '' === $part->getContent() && null === $part->getProviderState()) {
                continue;
            }

            if ($part instanceof ContentInterface) {
                $content[] = $part;
            } elseif (isset($toolCalls[$part])) {
                $content[] = $toolCalls[$part];
                $placedToolCalls[$part] = true;
            }
        }

        // Bridges that do not emit ToolCallStart still need all completed calls replayed
        foreach ($delta->getToolCalls() as $toolCall) {
            if (!isset($placedToolCalls[$toolCall->getId()])) {
                $content[] = $toolCall;
            }
        }

        $this->result = ($this->handleToolCallsCallback)(
            new ToolCallResult($delta->getToolCalls()),
            new AssistantMessage(...$content),
        );

        $content = $this->result->getContent();
        $event->setDelta(\is_string($content) ? new TextDelta($content) : $content);

        $this->toolHandled = true;
    }

    public function onComplete(CompleteEvent $event): void
    {
        $this->assistantContent = [];
        $this->thinkingContentIndexes = [];
        $this->toolHandled = false;

        if (null !== $this->result) {
            $event->getMetadata()->merge($this->result->getMetadata());
        }
    }

    private function getThinkingContentIndex(string $id, ThinkingRepresentation $representation): int
    {
        if (isset($this->thinkingContentIndexes[$id])) {
            $index = $this->thinkingContentIndexes[$id];
            /** @var Thinking $thinking */
            $thinking = $this->assistantContent[$index];
            if ($thinking->getRepresentation() !== $representation) {
                throw new LogicException(\sprintf('Thinking block "%s" changed representation from "%s" to "%s".', $id, $thinking->getRepresentation()->value, $representation->value));
            }

            return $index;
        }

        $this->assistantContent[] = new Thinking('', $representation);

        return $this->thinkingContentIndexes[$id] = \count($this->assistantContent) - 1;
    }
}
