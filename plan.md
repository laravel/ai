# Usage value objects: remaining work

PR 1 (`pr/1.x/usage-value-object`, laravel/ai#1021) landed the base class. It renamed
`promptTokens`/`completionTokens` to `inputTokens`/`outputTokens`, made both counts inclusive,
made the cache and reasoning details `?int`, and fixed the Vercel and AG-UI protocols.

`raw` was deliberately dropped from the base class. Provider-specific numbers therefore have
no escape hatch, so a capability subclass carries them as typed properties or the SDK does not
expose them at all. A new property has to be a real billing unit reported by two or more
providers; anything narrower stays unexposed.

## Shape

```
Usage                inputTokens, outputTokens, cacheRead?, cacheWrite?, reasoning?
├── ImageUsage         + imageInputTokens?, imageOutputTokens?
├── TranscriptionUsage + audioSeconds?, audioInputTokens?
├── SpeechUsage        + characters?, audioOutputTokens?
└── RerankingUsage     + searchUnits?
```

Text, steps, streaming and embeddings keep the base `Usage`. No `StreamUsage`: streaming
produces the same shape per step, the difference is scope, not shape.

Every subclass overrides `add()` only if its response is ever aggregated. Today only text usage
is summed, so the base implementation is the only one that must exist.

## Dependencies

PRs 2 to 5 each need PR 1 merged. They are independent of each other and can land in any order.
PR 4 and PR 5 both touch `GeminiGateway` and `OpenRouterGateway`, but different methods.

---

## PR 2: ImageUsage

`ImageResponse::$usage` becomes `ImageUsage`.

| Provider | imageInputTokens | imageOutputTokens |
|---|---|---|
| OpenAI | `usage.input_tokens_details.image_tokens` | `usage.output_tokens_details.image_tokens` |
| Azure (preview api-version only) | same as OpenAI | same as OpenAI |
| xAI | `usage.input_tokens_details.image_tokens` | `usage.output_tokens_details.image_tokens` |
| Gemini | `promptTokensDetails[modality=IMAGE].tokenCount` | `candidatesTokensDetails[modality=IMAGE].tokenCount` |
| OpenRouter | null | null (undocumented on chat-based image generation) |
| Bedrock, DALL-E, Imagen | null | null (no usage returned at all) |

Notes:
- OpenAI's streaming image completion event omits `output_tokens_details`, the non-streaming
  response has it. Both paths must tolerate the absence.
- Gemini needs a modality helper to pull a `tokenCount` out of the details array by modality.
  That helper is reused by PRs 3 and 5, so land it here.
- Files: `src/Responses/ImageResponse.php`, `src/Responses/Data/ImageUsage.php`,
  the five image gateways, `FakeImageGateway`.

## PR 3: TranscriptionUsage

Supersedes the abandoned `transcription-duration` branch, which put `durationSeconds` on an
`AudioUsage extends Usage` and derived Gemini's duration from the last segment end. Do not
derive duration from transcript segments; that is a transcript artifact, not a billing figure.

| Provider | audioSeconds | audioInputTokens |
|---|---|---|
| OpenAI | `usage.seconds` (duration variant) or top-level `duration` (verbose_json) | `usage.input_token_details.audio_tokens` (singular `token`, a documented OpenAI quirk) |
| Groq | `duration` (verbose_json only; typed string in Groq's own spec, parse leniently) | null |
| Mistral | `usage.prompt_audio_seconds` | null |
| ElevenLabs | `audio_duration_secs` | null |
| OpenRouter | `usage.seconds` | null |
| OpenAI-compatible | `duration` or `usage.seconds` | null |
| Gemini | null | `promptTokensDetails[modality=AUDIO].tokenCount` |

Notes:
- OpenAI discriminates on `usage.type` (`"duration"` vs `"tokens"`). Read the discriminator
  rather than guessing from the model name.
- Mistral's own doc example violates `total = prompt + completion`, so assert nothing about that.
- Files: `src/Responses/TranscriptionResponse.php`, `src/Responses/Data/TranscriptionUsage.php`,
  seven transcription gateways, `FakeTranscriptionGateway`.

## PR 4: RerankingUsage and embeddings usage

Two things in one PR because both are "a response that carries no usage object today".

`RerankingResponse` gains `$usage` as `RerankingUsage`:

| Provider | inputTokens | searchUnits |
|---|---|---|
| Cohere | `meta.billed_units.input_tokens` | `meta.billed_units.search_units` (float; >1 when documents are chunked) |
| OpenRouter | `usage.total_tokens` | `usage.search_units` |
| Voyage | `usage.total_tokens` | null |
| Jina | `usage.total_tokens` | null |
| Bedrock | null | null (Rerank returns no usage) |

`EmbeddingsResponse::$tokens` (`int`) becomes `$usage` (`Usage`). This is the breaking part:

| Provider | inputTokens |
|---|---|
| OpenAI, Azure, OpenRouter, OpenAI-compatible | `usage.prompt_tokens` |
| Gemini | `usageMetadata.promptTokenCount` (note the singular `promptTokenDetails` here, unlike `generateContent`) |
| Bedrock Titan | `inputTextTokenCount` |
| Bedrock Cohere | `x-amzn-bedrock-input-token-count` header (already handled on 1.x) |
| Cohere | `meta.billed_units.input_tokens` |
| Voyage, Jina | `usage.total_tokens` |
| Ollama | `prompt_eval_count` |

Notes:
- Cohere distinguishes billed units from actual tokens and documents that they differ. Prefer
  `billed_units`, since that is what the user pays for.
- Voyage multimodal reports `image_pixels`, and Cohere reports a billed `images` count. Neither
  is a token count and neither has a second provider reporting the same unit, so leave both out.
- Upgrade note: `EmbeddingsResponse::$tokens` is removed in favour of `$usage->inputTokens`.

## PR 5: SpeechUsage

`AudioResponse` gains `$usage`. Smallest value, so last.

| Provider | characters | audioOutputTokens |
|---|---|---|
| ElevenLabs | `character-cost` response header | null |
| Gemini | null | `candidatesTokensDetails[modality=AUDIO].tokenCount` |
| OpenAI | null | `usage.output_tokens` on the `speech.audio.done` SSE event only |
| OpenRouter, Groq | null | null (raw audio bytes, no usage) |

Notes:
- Non-streaming OpenAI TTS returns nothing. An empty `SpeechUsage` is the honest answer.
- `AudioResponse` has no `usage` property today, so this is additive, not breaking.

---

## Test rule for every PR

Each provider gets one test asserting the exact normalized object from a documented fixture, and
one asserting `null` where the provider reports nothing. No tests that restate the fixture.

Run `vendor/bin/pest --exclude-group integration`. Note that the Anthropic "omits the api key
header" test fails locally when `ANTHROPIC_API_KEY` is set in `.env`; clear it for that test.
