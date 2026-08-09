CHANGELOG
=========

0.13
----

 * [BC BREAK] Persist `ThinkingResult` representation and nested provider state; legacy signatures are retained as unknown-format state without inferring a representation

0.11
----

 * Add `CacheKeyGenerator` strategy and a default set (`MessageBag`, `DocumentUrl`, `ImageUrl`, `File`/`Audio`), so `CachePlatform` can cache document, OCR and audio-transcription tasks, not just `string`/`array`/`MessageBag` inputs; custom input types can be supported by registering an additional generator

0.3
---

 * Add the bridge
