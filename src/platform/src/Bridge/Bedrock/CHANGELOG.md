CHANGELOG
=========

0.10
----

 * Preserve explicit Claude thinking representations and typed Anthropic replay state
 * Allow overriding `tool_choice` via caller options instead of always forcing `['type' => 'auto']` for Anthropic Claude models

0.9
---

 * Nova `AssistantMessageNormalizer` interleaves `text` and `toolUse` blocks in their original order instead of dropping text whenever tool calls are present.

0.8
---

 * [BC BREAK] Rename `PlatformFactory` to `Factory` with explicit `createProvider()` and `createPlatform()` methods

0.7
---

 * Add `ai:bedrock:model-list` command to list available foundation models

0.6
---

 * Add structured output support

0.1
---

 * Add the bridge
