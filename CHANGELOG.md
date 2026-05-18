# Release Notes

## [Unreleased](https://github.com/laravel/ai/compare/v0.6.9...0.x)

## [v0.6.9](https://github.com/laravel/ai/compare/v0.6.8...v0.6.9) - 2026-05-18

## [v0.6.8](https://github.com/laravel/ai/compare/v0.6.7...v0.6.8) - 2026-05-11

* Delete update-changelog.yml by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/525
* Add error handling tests for VoyageAI reranking by [@mosabbirrakib](https://github.com/mosabbirrakib) in https://github.com/laravel/ai/pull/527
* Add error handling tests for OpenAI image generation by [@mosabbirrakib](https://github.com/mosabbirrakib) in https://github.com/laravel/ai/pull/528
* Add error handling tests for VoyageAI embeddings by [@mosabbirrakib](https://github.com/mosabbirrakib) in https://github.com/laravel/ai/pull/526
* Handle Anthropic pause_turn server-tool continuations by [@CodeWrap](https://github.com/CodeWrap) in https://github.com/laravel/ai/pull/493
* Add sub-agent support as tools by [@JVillator0](https://github.com/JVillator0) in https://github.com/laravel/ai/pull/348
* Make conversation/message table names configurable by [@timmcleod](https://github.com/timmcleod) in https://github.com/laravel/ai/pull/484
* Add error handling tests for image generation (Gemini, AzureOpenAI, OpenRouter) by [@mdalikadar](https://github.com/mdalikadar) in https://github.com/laravel/ai/pull/545
* Add rate limit and overloaded error tests for ElevenLabs audio by [@mosabbirrakib](https://github.com/mosabbirrakib) in https://github.com/laravel/ai/pull/542
* Add rate limit and overloaded error tests for Cohere reranking by [@mosabbirrakib](https://github.com/mosabbirrakib) in https://github.com/laravel/ai/pull/540
* Add rate limit and overloaded error tests for Jina reranking by [@mosabbirrakib](https://github.com/mosabbirrakib) in https://github.com/laravel/ai/pull/541
* Add tests for Gemini image generation safety blocks by [@mosabbirrakib](https://github.com/mosabbirrakib) in https://github.com/laravel/ai/pull/547
* Add error handling tests for embeddings (OpenAI, Gemini) by [@mdalikadar](https://github.com/mdalikadar) in https://github.com/laravel/ai/pull/546
* Fix parameter name in GeneratesEmbeddings PHPDoc by [@mosabbirrakib](https://github.com/mosabbirrakib) in https://github.com/laravel/ai/pull/549
* Remove stale @param and unused Request import in Vercel protocol trait by [@mosabbirrakib](https://github.com/mosabbirrakib) in https://github.com/laravel/ai/pull/551
* Fix nonexistent ImageAttachment type in image-related PHPDoc by [@mosabbirrakib](https://github.com/mosabbirrakib) in https://github.com/laravel/ai/pull/550
* Add error handling tests for AzureOpenAI image generation by [@mosabbirrakib](https://github.com/mosabbirrakib) in https://github.com/laravel/ai/pull/537
* Mark nullable size and quality docblocks across image gateways by [@mosabbirrakib](https://github.com/mosabbirrakib) in https://github.com/laravel/ai/pull/552
* Broadcast stream_failed event when BroadcastAgent job fails by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/536
* Gemini context cache support via cachedContent param in agent provider options by [@dumbbellcode](https://github.com/dumbbellcode) in https://github.com/laravel/ai/pull/556
* Fix: DeepSeek 400 Bad Request on multi-turn tool-calls by preserving `reasoning_content` by [@k1rana](https://github.com/k1rana) in https://github.com/laravel/ai/pull/534
* OpenRouter TTS and STT support by [@ondrejehrlich](https://github.com/ondrejehrlich) in https://github.com/laravel/ai/pull/559
* Support provider options in embeddings by [@dumbbellcode](https://github.com/dumbbellcode) in https://github.com/laravel/ai/pull/555
* Enable failover during stream iteration by [@kachelle](https://github.com/kachelle) in https://github.com/laravel/ai/pull/279

## [v0.6.7](https://github.com/laravel/ai/compare/v0.6.6...v0.6.7) - 2026-05-07

### What's Changed

* Add coding standards workflow by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/487
* Bind AiManager as singleton to preserve driver extensions across jobs by [@ejtmicroventures](https://github.com/ejtmicroventures) in https://github.com/laravel/ai/pull/411
* fix(anthropic): preserve provider content blocks on assistant replay (refs #298) by [@CodeWrap](https://github.com/CodeWrap) in https://github.com/laravel/ai/pull/392
* Drop redundant tool_config from Gemini text requests by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/490
* Update AssistantMessage.php by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/491
* Add GitHub community health files and workflows by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/488
* Add error handling tests for Mistral embeddings by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/495
* Add error handling tests for Ollama embeddings by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/496
* Add diarize and error handling tests for Mistral transcription by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/494
* Add error handling tests for Azure OpenAI embeddings by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/497
* Add error handling tests for OpenAI transcription by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/501
* Add error handling tests for OpenRouter embeddings by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/498
* Add error handling tests for xAI image generation by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/502
* Add toArray and jsonSerialize to StructuredStep by [@plusemon](https://github.com/plusemon) in https://github.com/laravel/ai/pull/504
* Bedrock : support Base64 image for BedrockTextGateway by [@DenneulinThomas](https://github.com/DenneulinThomas) in https://github.com/laravel/ai/pull/500
* [0.x] Add image generation support to AzureOpenAiProvider by [@maherelgamil](https://github.com/maherelgamil) in https://github.com/laravel/ai/pull/505
* Fix OpenAI citation deduplication by URL by [@Anoop-Kadachi](https://github.com/Anoop-Kadachi) in https://github.com/laravel/ai/pull/509
* Remove URL overrides from providers that rarely need them by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/492
* Preserve OpenAI url citation span indices and stop deduping by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/510
* Add Gemini TTS and STT support by [@allurco](https://github.com/allurco) in https://github.com/laravel/ai/pull/81
* Allow configuring the database connection for DatabaseConversationStore by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/469
* Align xAI URL citations with OpenAI response parsing by [@Anoop-Kadachi](https://github.com/Anoop-Kadachi) in https://github.com/laravel/ai/pull/513
* Add missing type declaration to ToolResult $result property by [@mahfuz-rahman007](https://github.com/mahfuz-rahman007) in https://github.com/laravel/ai/pull/518
* Test Anthropic streaming tool_use finish reason without tool blocks by [@Anoop-Kadachi](https://github.com/Anoop-Kadachi) in https://github.com/laravel/ai/pull/515
* Add missing type hint to $quality parameter in defaultImageOptions by [@mahfuz-rahman007](https://github.com/mahfuz-rahman007) in https://github.com/laravel/ai/pull/516
* Add missing parameter name in EmbeddingsResponse PHPDoc by [@mahfuz-rahman007](https://github.com/mahfuz-rahman007) in https://github.com/laravel/ai/pull/517
* Add config option to disable LLM-generated conversation titles by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/474
* Sync conversation metadata after streamed responses by [@dhrupo](https://github.com/dhrupo) in https://github.com/laravel/ai/pull/434
* Expand streaming finish-reason parity coverage across providers by [@Anoop-Kadachi](https://github.com/Anoop-Kadachi) in https://github.com/laravel/ai/pull/521
* Expand OpenAI-family streaming finish reason parity tests by [@Anoop-Kadachi](https://github.com/Anoop-Kadachi) in https://github.com/laravel/ai/pull/523

### New Contributors

* [@ejtmicroventures](https://github.com/ejtmicroventures) made their first contribution in https://github.com/laravel/ai/pull/411
* [@plusemon](https://github.com/plusemon) made their first contribution in https://github.com/laravel/ai/pull/504
* [@DenneulinThomas](https://github.com/DenneulinThomas) made their first contribution in https://github.com/laravel/ai/pull/500
* [@Anoop-Kadachi](https://github.com/Anoop-Kadachi) made their first contribution in https://github.com/laravel/ai/pull/509
* [@allurco](https://github.com/allurco) made their first contribution in https://github.com/laravel/ai/pull/81
* [@mahfuz-rahman007](https://github.com/mahfuz-rahman007) made their first contribution in https://github.com/laravel/ai/pull/518
* [@dhrupo](https://github.com/dhrupo) made their first contribution in https://github.com/laravel/ai/pull/434

**Full Changelog**: https://github.com/laravel/ai/compare/v0.6.6...v0.6.7

## [v0.6.6](https://github.com/laravel/ai/compare/v0.6.5...v0.6.6) - 2026-05-02

### What's Changed

* Add transcription feature tests for OpenAI provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/468
* Add audio feature tests for OpenAI provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/471
* Fix reasoning data lost when restoring conversation history by [@Cbrad24](https://github.com/Cbrad24) in https://github.com/laravel/ai/pull/301
* Add top_p configuration option for text generation requests by [@Duetro](https://github.com/Duetro) in https://github.com/laravel/ai/pull/306
* Allow tools to declare their name dynamically by [@seankndy](https://github.com/seankndy) in https://github.com/laravel/ai/pull/420
* feat: enhance assistant message mapping and extend tool call attributes by [@mohali-id](https://github.com/mohali-id) in https://github.com/laravel/ai/pull/461
* Pass through provider specific Gemini image aspect ratios by [@morcken](https://github.com/morcken) in https://github.com/laravel/ai/pull/282
* Add image generation support to OpenRouterProvider by [@billyfranklim1](https://github.com/billyfranklim1) in https://github.com/laravel/ai/pull/333
* Throw helpful error when ai.default config is an array by [@JoshSalway](https://github.com/JoshSalway) in https://github.com/laravel/ai/pull/327
* [0.x] Fix conversational message iterable handling in text generation by [@Button99](https://github.com/Button99) in https://github.com/laravel/ai/pull/345
* Surface usage tokens in OpenAI and Gemini image responses by [@maherelgamil](https://github.com/maherelgamil) in https://github.com/laravel/ai/pull/482
* Reindex tool calls and results before persisting to prevent JSON object encoding by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/473

### New Contributors

* [@Cbrad24](https://github.com/Cbrad24) made their first contribution in https://github.com/laravel/ai/pull/301
* [@Duetro](https://github.com/Duetro) made their first contribution in https://github.com/laravel/ai/pull/306
* [@seankndy](https://github.com/seankndy) made their first contribution in https://github.com/laravel/ai/pull/420
* [@mohali-id](https://github.com/mohali-id) made their first contribution in https://github.com/laravel/ai/pull/461
* [@morcken](https://github.com/morcken) made their first contribution in https://github.com/laravel/ai/pull/282
* [@billyfranklim1](https://github.com/billyfranklim1) made their first contribution in https://github.com/laravel/ai/pull/333
* [@JoshSalway](https://github.com/JoshSalway) made their first contribution in https://github.com/laravel/ai/pull/327

**Full Changelog**: https://github.com/laravel/ai/compare/v0.6.5...v0.6.6

## [v0.6.5](https://github.com/laravel/ai/compare/v0.6.4...v0.6.5) - 2026-04-29

### What's Changed

* fix(bedrock): bedrock converse API parameter edge cases by [@dumbbellcode](https://github.com/dumbbellcode) in https://github.com/laravel/ai/pull/463
* fix(bedrock): incorrect name format in user attachments for bedrock provider by [@dumbbellcode](https://github.com/dumbbellcode) in https://github.com/laravel/ai/pull/465
* fix(bedrock): silent tool call failures by [@dumbbellcode](https://github.com/dumbbellcode) in https://github.com/laravel/ai/pull/464
* Support configurable base URL for Jina provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/470
* Support configurable base URL for ElevenLabs provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/472
* Add embedding feature tests for OpenAI provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/467
* fix(openai, anthropic): cast empty tool args to object by [@CodeWrap](https://github.com/CodeWrap) in https://github.com/laravel/ai/pull/419

**Full Changelog**: https://github.com/laravel/ai/compare/v0.6.4...v0.6.5

## [v0.6.4](https://github.com/laravel/ai/compare/v0.6.3...v0.6.4) - 2026-04-28

### What's Changed

* Add tool mapping test suite for DeepSeek provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/440
* Add provider options test suite for DeepSeek provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/439
* Add message mapping test suite for DeepSeek provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/438
* Fix duplicate helper function causing fatal test bootstrap error by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/443
* use getter functions in toArray method of file classes by [@GigaGiorgadze](https://github.com/GigaGiorgadze) in https://github.com/laravel/ai/pull/436
* fix: detect LocalImage MIME type across native gateways by [@maherelgamil](https://github.com/maherelgamil) in https://github.com/laravel/ai/pull/430
* fix: only send explicitly set image options in OpenAI provider by [@navneetkumar-pim-webkul](https://github.com/navneetkumar-pim-webkul) in https://github.com/laravel/ai/pull/415
* Add toAudio macro to Stringable by [@nhedger](https://github.com/nhedger) in https://github.com/laravel/ai/pull/264
* Adding agent methods for text generation options by [@MaestroError](https://github.com/MaestroError) in https://github.com/laravel/ai/pull/198
* Add configurable URL support and base URL tests for Cohere provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/452
* Add error handling feature tests for ElevenLabs, Cohere, Jina, and VoyageAi by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/448
* Add image generation feature tests for xAI provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/447
* Add embedding feature tests for Gemini provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/450
* Add base URL feature tests for VoyageAi provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/451
* Align Bedrock image and error handling with AWS API by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/442
* Add image generation feature tests for Gemini provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/449
* fix(bedrock): bedrock text gateway response messages and steps by [@dumbbellcode](https://github.com/dumbbellcode) in https://github.com/laravel/ai/pull/458
* fix(bedrock): correct default model IDs by [@catatsumuri](https://github.com/catatsumuri) in https://github.com/laravel/ai/pull/460

### New Contributors

* [@GigaGiorgadze](https://github.com/GigaGiorgadze) made their first contribution in https://github.com/laravel/ai/pull/436
* [@navneetkumar-pim-webkul](https://github.com/navneetkumar-pim-webkul) made their first contribution in https://github.com/laravel/ai/pull/415
* [@MaestroError](https://github.com/MaestroError) made their first contribution in https://github.com/laravel/ai/pull/198
* [@dumbbellcode](https://github.com/dumbbellcode) made their first contribution in https://github.com/laravel/ai/pull/458
* [@catatsumuri](https://github.com/catatsumuri) made their first contribution in https://github.com/laravel/ai/pull/460

**Full Changelog**: https://github.com/laravel/ai/compare/v0.6.3...v0.6.4

## [v0.6.3](https://github.com/laravel/ai/compare/v0.6.2...v0.6.3) - 2026-04-22

* fix(gateway): fix DeepSeek usage stats by [@aaronlei](https://github.com/aaronlei) in https://github.com/laravel/ai/pull/435
* Add agent fake test suite for DeepSeek provider by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/437
* Bedrock provider by [@tott](https://github.com/tott) in https://github.com/laravel/ai/pull/270

## [v0.6.2](https://github.com/laravel/ai/compare/v0.6.1...v0.6.2) - 2026-04-21

**Full Changelog**: https://github.com/laravel/ai/compare/v0.6.1...v0.6.2

## [v0.6.1](https://github.com/laravel/ai/compare/v0.6.0...v0.6.1) - 2026-04-21

* Add HandlesFailoverErrors to VoyageAiGateway by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/424
* Add HandlesFailoverErrors to CohereGateway and JinaGateway by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/423
* Add test suite for CohereProvider (embeddings and reranking) by [@maherelgamil](https://github.com/maherelgamil) in https://github.com/laravel/ai/pull/425
* Add test suite for ElevenLabsProvider (audio and transcription) by [@maherelgamil](https://github.com/maherelgamil) in https://github.com/laravel/ai/pull/426
* Memoize ElevenLabs gateway instances by [@maherelgamil](https://github.com/maherelgamil) in https://github.com/laravel/ai/pull/427
* Add test suite for JinaProvider (embeddings and reranking) by [@maherelgamil](https://github.com/maherelgamil) in https://github.com/laravel/ai/pull/418
* Remove redundant match expressions in ElevenLabsGateway by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/422
* Apply Files::put(name:) override to StorableFile inputs by [@maherelgamil](https://github.com/maherelgamil) in https://github.com/laravel/ai/pull/428
* Remove unused prism-php/prism dependency by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/414
* [0.x] Add timeout parameter to Agent contract by [@Tiagospem](https://github.com/Tiagospem) in https://github.com/laravel/ai/pull/412

## [v0.6.0](https://github.com/laravel/ai/compare/v0.5.1...v0.6.0) - 2026-04-16

### What's Changed

* fix(anthropic): cast server_tool_use.input to object by [@CodeWrap](https://github.com/CodeWrap) in https://github.com/laravel/ai/pull/389
* Fix Mistral transcription with diarize() by [@NoelDeMartin](https://github.com/NoelDeMartin) in https://github.com/laravel/ai/pull/385
* [0.x] Add feature tests into CI by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/ai/pull/384
* Update StoreTest.php by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/394
* Handle nullable tool parameter types for Gemini gateway by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/386
* Fix HasStructuredOutput agents crashing with empty schema by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/391
* Move test fixture classes and files into tests/Fixtures/ by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/397
* [0.x] Fix actions deprecation warnings by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/ai/pull/401
* Add OpenRouter gateway for direct API integration by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/395
* Migrate VoyageAi off Prism to native HTTP gateway by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/398
* Add Ollama gateway for direct API integration by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/396
* Migrate DeepSeek provider to a native gateway by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/399
* Add correct finish reason to Gemini streaming text responses by [@dash8x](https://github.com/dash8x) in https://github.com/laravel/ai/pull/403
* Refine document attachment mapping and parameterize agent tests by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/408
* [0.x] Fix HasTools::tools() return type to accept ProviderTool by [@sumaiazaman](https://github.com/sumaiazaman) in https://github.com/laravel/ai/pull/353
* Fix structured output and tool-call handling in native gateways by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/409
* Migrate AzureOpenAiProvider from Prism to dedicated gateway by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/404
* Update Anthropic smartest model default to Claude Opus 4.7 by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/413

### New Contributors

* [@CodeWrap](https://github.com/CodeWrap) made their first contribution in https://github.com/laravel/ai/pull/389
* [@dash8x](https://github.com/dash8x) made their first contribution in https://github.com/laravel/ai/pull/403
* [@sumaiazaman](https://github.com/sumaiazaman) made their first contribution in https://github.com/laravel/ai/pull/353

**Full Changelog**: https://github.com/laravel/ai/compare/v0.5.1...v0.6.0

## [v0.5.1](https://github.com/laravel/ai/compare/v0.5.0...v0.5.1) - 2026-04-10

### What's Changed

* Handle provider overload errors in native gateways for failover by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/378
* Fix MistralProvider ignoring injected fake gateways by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/375
* Migrate test suite from PHPUnit to Pest PHP by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/366
* Move Gemini file and store gateways into Gemini namespace by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/382

**Full Changelog**: https://github.com/laravel/ai/compare/v0.5.0...v0.5.1

## [v0.5.0](https://github.com/laravel/ai/compare/v0.4.5...v0.5.0) - 2026-04-09

* Fix Mistral default transcription model by [@NoelDeMartin](https://github.com/NoelDeMartin) in https://github.com/laravel/ai/pull/325
* Update prism-php/prism dependency version by [@rspahni](https://github.com/rspahni) in https://github.com/laravel/ai/pull/322
* Switch Gemini structured output to response_json_schema by [@ipalaus](https://github.com/ipalaus) in https://github.com/laravel/ai/pull/364
* Preserve configured options in tool loop follow-up requests by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/363
* Add Mistral gateway for direct API integration by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/368
* Add xAI gateway for Responses API by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/310
* Add missing gateway tests for Groq and OpenAI providers by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/370

## [v0.4.5](https://github.com/laravel/ai/compare/v0.4.4...v0.4.5) - 2026-04-08

* Fix ProviderDocument OpenAI attachment mapping by [@qwertyquest](https://github.com/qwertyquest) in https://github.com/laravel/ai/pull/360
* Add Gemini gateway for direct API integration by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/312

## [v0.4.4](https://github.com/laravel/ai/compare/v0.4.3...v0.4.4) - 2026-04-06

* Add Groq gateway for Chat Completions API by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/311
* Add direct Anthropic gateway for Messages API by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/309
* Fix OpenAI strict tool parameters and persist providerOptions in tool loops by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/340
* Fix missing additionalProperties on nested objects in strict schemas by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/357

## [v0.4.3](https://github.com/laravel/ai/compare/v0.4.2...v0.4.3) - 2026-04-01

* Apply missing providerOptions and align maxSteps in OpenAI gateway by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/338
* Add missing filename to OpenAI input_file attachment mappings by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/324

## [v0.4.2](https://github.com/laravel/ai/compare/v0.4.1...v0.4.2) - 2026-03-27

* fix: use correct file extension for audio transcription uploads by [@radumetes](https://github.com/radumetes) in https://github.com/laravel/ai/pull/316
* [0.x] Fix type error in fileMatchingCallback when fileId is null by [@Button99](https://github.com/Button99) in https://github.com/laravel/ai/pull/295

## [v0.4.1](https://github.com/laravel/ai/compare/v0.4.0...v0.4.1) - 2026-03-26

* Respect configured OpenAI base URL and fixes #314 by [@AnnoyingTechnology](https://github.com/AnnoyingTechnology) in https://github.com/laravel/ai/pull/315

## [v0.4.0](https://github.com/laravel/ai/compare/v0.3.2...v0.4.0) - 2026-03-25

* Add OpenAI gateway for Responses API by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/275
* Update skill Content by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/287

## [v0.3.2](https://github.com/laravel/ai/compare/v0.3.1...v0.3.2) - 2026-03-18

**Full Changelog**: https://github.com/laravel/ai/compare/v0.3.1...v0.3.2

## [v0.3.1](https://github.com/laravel/ai/compare/v0.3.0...v0.3.1) - 2026-03-17

* Update default OpenRouter text models to Anthropics 4.6 versions by [@fosron](https://github.com/fosron) in https://github.com/laravel/ai/pull/268
* [0.x] Remove redundant Collection wrapping in StreamEnd::combineUsage(). by [@Button99](https://github.com/Button99) in https://github.com/laravel/ai/pull/272
* Add configurable timeout for audio requests by [@nhedger](https://github.com/nhedger) in https://github.com/laravel/ai/pull/263
* [0.x] Fix file_get_contents() return value not handled if the file does not exist in Local file classes by [@Button99](https://github.com/Button99) in https://github.com/laravel/ai/pull/267

## [v0.3.0](https://github.com/laravel/ai/compare/v0.2.8...v0.3.0) - 2026-03-12

* [0.x] Adjust AddsToolsToPrismRequestsTest by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/ai/pull/256
* Add configurable timeout for embedding requests by [@nhedger](https://github.com/nhedger) in https://github.com/laravel/ai/pull/262
* Fix conversation leakage in RemembersConversations::forUser() by [@Button99](https://github.com/Button99) in https://github.com/laravel/ai/pull/260
* [0.x] Create a CI test workflow by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/ai/pull/258
* [0.x] rename anonymous class helper methods to avoid Pint renaming by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/ai/pull/257
* Add failover support for insufficient credits and quota errors by [@meirdick](https://github.com/meirdick) in https://github.com/laravel/ai/pull/249
* Fix missing audio file types in PrismMessages attachment conversion by [@sulimanbenhalim](https://github.com/sulimanbenhalim) in https://github.com/laravel/ai/pull/247
* Add embedding support for OpenRouter provider by [@remcom](https://github.com/remcom) in https://github.com/laravel/ai/pull/237
* Fix ErrorEvent property access in PrismStreamEvent by [@Husseinadq](https://github.com/Husseinadq) in https://github.com/laravel/ai/pull/217
* fix: structured output schema definition by [@ralphjsmit](https://github.com/ralphjsmit) in https://github.com/laravel/ai/pull/252
* Add Ollama dimensions option to embeddings by [@WoutervdBrink](https://github.com/WoutervdBrink) in https://github.com/laravel/ai/pull/190
* Fix tool call history not round-tripped in conversation context by [@nicodevs](https://github.com/nicodevs) in https://github.com/laravel/ai/pull/203
* [0.x] Add support for configuring provider options on agents by [@shafimsp](https://github.com/shafimsp) in https://github.com/laravel/ai/pull/166

## [v0.2.8](https://github.com/laravel/ai/compare/v0.2.7...v0.2.8) - 2026-03-10

* [0.x] Makes imports consistent by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/ai/pull/253

## [v0.2.7](https://github.com/laravel/ai/compare/v0.2.6...v0.2.7) - 2026-03-10

* refactor: add `Lab` enum as a type to the `$provider` parameter in agent methods in Agent contract. by [@delabon](https://github.com/delabon) in https://github.com/laravel/ai/pull/245
* refactor: remove the `$options` parameter from `addProviderTools` method calls by [@delabon](https://github.com/delabon) in https://github.com/laravel/ai/pull/244
* Fix StreamableAgentResponse signature to accept conversation user by [@Button99](https://github.com/Button99) in https://github.com/laravel/ai/pull/246
* fix: pass outputDimension for VoyageAI embeddings by [@markritterman](https://github.com/markritterman) in https://github.com/laravel/ai/pull/243
* Fix BroadcastAgent passing wrong response type to then() callbacks by [@sulimanbenhalim](https://github.com/sulimanbenhalim) in https://github.com/laravel/ai/pull/248

## [v0.2.6](https://github.com/laravel/ai/compare/v0.2.5...v0.2.6) - 2026-03-04

* Rename Skill by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/227
* [0.x] Allow for arrays and enum for providers in the macros by [@josephkerkhof](https://github.com/josephkerkhof) in https://github.com/laravel/ai/pull/242
* [0.x] Add Message import and @return docblock to agent stubs by [@josephkerkhof](https://github.com/josephkerkhof) in https://github.com/laravel/ai/pull/240
* Renamed image to image_size in geminiprovider according to gemini api by [@Jeroen-chu](https://github.com/Jeroen-chu) in https://github.com/laravel/ai/pull/235
* [0.x] Fix middleware stub naming conflict by [@mt-shihab26](https://github.com/mt-shihab26) in https://github.com/laravel/ai/pull/234
* Improve base URL logic to prevent double path segments by [@Danni2901](https://github.com/Danni2901) in https://github.com/laravel/ai/pull/226
* Fix undefined variable $e in withModelFailover when providers array is empty by [@sulimanbenhalim](https://github.com/sulimanbenhalim) in https://github.com/laravel/ai/pull/221
* Fix undefined variable $e in PendingResponse failover loops by [@sulimanbenhalim](https://github.com/sulimanbenhalim) in https://github.com/laravel/ai/pull/225

## [v0.2.5](https://github.com/laravel/ai/compare/v0.2.4...v0.2.5) - 2026-02-26

* Revert "Fix streaming generators under Octane" by [@taylorotwell](https://github.com/taylorotwell) in https://github.com/laravel/ai/pull/220

## [v0.2.4](https://github.com/laravel/ai/compare/v0.2.3...v0.2.4) - 2026-02-26

* [0.x] Fix missing Base64Document import in Store. by [@Button99](https://github.com/Button99) in https://github.com/laravel/ai/pull/207
* Chore: Fix Pint issues by [@delabon](https://github.com/delabon) in https://github.com/laravel/ai/pull/196
* Fix ReasoningDelta Vercel serialization to match Prism delta events by [@christopherarter](https://github.com/christopherarter) in https://github.com/laravel/ai/pull/202

## [v0.2.3](https://github.com/laravel/ai/compare/v0.2.2...v0.2.3) - 2026-02-26

* Add PHP 8.3 support by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/212

## [v0.2.2](https://github.com/laravel/ai/compare/v0.2.1...v0.2.2) - 2026-02-25

* Add PHP 8.3 support by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/212

## [v0.2.1](https://github.com/laravel/ai/compare/v0.2.0...v0.2.1) - 2026-02-19

* Make provider default models configurable via ai config by [@pfrug](https://github.com/pfrug) in https://github.com/laravel/ai/pull/183
* Fix typo in comment for tool calls storagefix: correct typo in CanStr… by [@wuwx](https://github.com/wuwx) in https://github.com/laravel/ai/pull/181
* Add support for timeouts in transcription by [@NietThijmen](https://github.com/NietThijmen) in https://github.com/laravel/ai/pull/176
* [0.x] Add make:agent-middleware command by [@mt-shihab26](https://github.com/mt-shihab26) in https://github.com/laravel/ai/pull/177
* [0.x] Allow nullable user IDs in conversation tables by [@Button99](https://github.com/Button99) in https://github.com/laravel/ai/pull/175
* Update parameter name in EmbeddingProvider contract for consistency by [@josephkerkhof](https://github.com/josephkerkhof) in https://github.com/laravel/ai/pull/168
* [0.x] Add return type annotations to interfaces by [@josephkerkhof](https://github.com/josephkerkhof) in https://github.com/laravel/ai/pull/167
* Allow Lab enum for Provider attribute by [@olavocneto](https://github.com/olavocneto) in https://github.com/laravel/ai/pull/169
* Fix missing exception handling by [@mubbi](https://github.com/mubbi) in https://github.com/laravel/ai/pull/161
* chore: document thrown exceptions by [@mubbi](https://github.com/mubbi) in https://github.com/laravel/ai/pull/160
* Fix invalid Message role and null response in HandlesRateLimiting by [@monayemislam](https://github.com/monayemislam) in https://github.com/laravel/ai/pull/150
* chore: add return/parameter types by [@mubbi](https://github.com/mubbi) in https://github.com/laravel/ai/pull/157
* [0.x] Ensure StreamEvent broadcast doesn't double prefix private channels by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/ai/pull/148

## [v0.2.0](https://github.com/laravel/ai/compare/v0.1.5...v0.2.0) - 2026-02-18

**Full Changelog**: https://github.com/laravel/ai/compare/v0.1.5...v0.2.0

## [v0.1.5](https://github.com/laravel/ai/compare/v0.1.4...v0.1.5) - 2026-02-12

**Full Changelog**: https://github.com/laravel/ai/compare/v0.1.4...v0.1.5

## [v0.1.4](https://github.com/laravel/ai/compare/v0.1.3...v0.1.4) - 2026-02-12

* [0.x] Fix ThinkingComplete stream event passing undefined $delta property by [@jkbennemann](https://github.com/jkbennemann) in https://github.com/laravel/ai/pull/88
* Add missing chunking_strategy provider option for diarized transcriptions by [@kachelle](https://github.com/kachelle) in https://github.com/laravel/ai/pull/87
* Return $this when calling location method in WebSearch by [@lcorbett89](https://github.com/lcorbett89) in https://github.com/laravel/ai/pull/84
* [0.x] Sort arguments by [@lucasmichot](https://github.com/lucasmichot) in https://github.com/laravel/ai/pull/74
* [0x] Use new `Collection` and new `Stringable` by [@lucasmichot](https://github.com/lucasmichot) in https://github.com/laravel/ai/pull/73
* [0.x] Use `contains` and `doesntCountain` for all assertions by [@lucasmichot](https://github.com/lucasmichot) in https://github.com/laravel/ai/pull/70
* [0.x] Prefer `::class` notation by [@lucasmichot](https://github.com/lucasmichot) in https://github.com/laravel/ai/pull/69
* Support user string IDs by [@bensherred](https://github.com/bensherred) in https://github.com/laravel/ai/pull/66
* [0.x] Normalize `composer.json` by [@lucasmichot](https://github.com/lucasmichot) in https://github.com/laravel/ai/pull/68
* Add support for anonymous users by [@bensherred](https://github.com/bensherred) in https://github.com/laravel/ai/pull/122
* chore(config): remove unused import from config by [@monurakkaya](https://github.com/monurakkaya) in https://github.com/laravel/ai/pull/116
* fix: fall back to raw arguments when schema_definition key is missing by [@MaximeWillinger](https://github.com/MaximeWillinger) in https://github.com/laravel/ai/pull/115
* fix: handle nullable usage in stream end events by [@MaximeWillinger](https://github.com/MaximeWillinger) in https://github.com/laravel/ai/pull/114
* Add API key to provider configuration in image and audio generation by [@Claudio-Emmolo](https://github.com/Claudio-Emmolo) in https://github.com/laravel/ai/pull/110
* Fix fromUpload() ignoring $mime parameter by [@mubbi](https://github.com/mubbi) in https://github.com/laravel/ai/pull/108
* Fix PrismException::toAiException() crash by [@mubbi](https://github.com/mubbi) in https://github.com/laravel/ai/pull/106
* Adds missing Tool import to stubs by [@florianraith](https://github.com/florianraith) in https://github.com/laravel/ai/pull/103
* [0.x] Prefer specific PHPUnit assertions by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/ai/pull/96
* [0.x] Forward dimensions to Prism embedding requests by [@alaminfirdows](https://github.com/alaminfirdows) in https://github.com/laravel/ai/pull/97
* [0.0.x] Add array return type to `toArray` by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/ai/pull/95
* Fix AgentPrompted event null prompt when middleware short-circuits by [@SanderMuller](https://github.com/SanderMuller) in https://github.com/laravel/ai/pull/100
* [0.x] Adjust assertEquals order by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/ai/pull/94
* Fix streaming generators under Octane by [@csfh](https://github.com/csfh) in https://github.com/laravel/ai/pull/118
* Add Reranking to Voyage AI by [@eznix86](https://github.com/eznix86) in https://github.com/laravel/ai/pull/63
* Add string representation to Provider to prevent fatal error in unsupported provider path by [@boynet](https://github.com/boynet) in https://github.com/laravel/ai/pull/142
* fix: add missing arguments fallback in PrismTool object code paths by [@anilcancakir](https://github.com/anilcancakir) in https://github.com/laravel/ai/pull/140
* Fix docblock for transcribe() in GeneratesTranscriptions by [@monayemislam](https://github.com/monayemislam) in https://github.com/laravel/ai/pull/136
* Fix typos in AiManager DocBlocks by [@jasim-k](https://github.com/jasim-k) in https://github.com/laravel/ai/pull/129
* Fix undefined array key error in queued agent assertions by [@mubbi](https://github.com/mubbi) in https://github.com/laravel/ai/pull/126
* Chore: Add unit test suite to phpunit.xml.dist by [@delabon](https://github.com/delabon) in https://github.com/laravel/ai/pull/124
* Add Azure OpenAI support by [@imYashGupta](https://github.com/imYashGupta) in https://github.com/laravel/ai/pull/60
* Allow stream provider failover by [@Button99](https://github.com/Button99) in https://github.com/laravel/ai/pull/135
* Add `AiProvider` enum for provider identifiers by [@pushpak1300](https://github.com/pushpak1300) in https://github.com/laravel/ai/pull/24
* feat: add custom base URL support for AI providers by [@sadhakbj](https://github.com/sadhakbj) in https://github.com/laravel/ai/pull/52

## [v0.1.3](https://github.com/laravel/ai/compare/v0.1.2...v0.1.3) - 2026-02-06

* Feature/deepseek integration by [@ihxnnxs](https://github.com/ihxnnxs) in https://github.com/laravel/ai/pull/29
* [0.x] Update .gitignore by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/ai/pull/43
* Fix ToolCall arguments passed as JSON string instead of array by [@nickgerrer](https://github.com/nickgerrer) in https://github.com/laravel/ai/pull/37
* Fix/typos param order by [@CaReS0107](https://github.com/CaReS0107) in https://github.com/laravel/ai/pull/35
* Allow passing connection config parameters in provider configuration (#32) by [@ihxnnxs](https://github.com/ihxnnxs) in https://github.com/laravel/ai/pull/34
* Add Model attribute by [@dwightwatson](https://github.com/dwightwatson) in https://github.com/laravel/ai/pull/57
* Support dynamic tool names via method_exists fallback by [@gracjankubicki](https://github.com/gracjankubicki) in https://github.com/laravel/ai/pull/56
* [0.x] Add VoyageAI driver by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/ai/pull/47
* [0.x] Add Mistral AI support by [@jackbayliss](https://github.com/jackbayliss) in https://github.com/laravel/ai/pull/40

## [v0.1.2](https://github.com/laravel/ai/compare/v0.1.1...v0.1.2) - 2026-02-05

**Full Changelog**: https://github.com/laravel/ai/compare/v0.1.1...v0.1.2

## [v0.1.1](https://github.com/laravel/ai/compare/v0.1.0...v0.1.1) - 2026-02-05

* [0.x] use `array_any` by [@lucasmichot](https://github.com/lucasmichot) in https://github.com/laravel/ai/pull/28
* Rename index for agent conversations table by [@leek](https://github.com/leek) in https://github.com/laravel/ai/pull/27
