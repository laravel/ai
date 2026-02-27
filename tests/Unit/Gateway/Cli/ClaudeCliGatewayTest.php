<?php

namespace Tests\Unit\Gateway\Cli;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Cli\ClaudeCliGateway;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

class ClaudeCliGatewayTest extends TestCase
{
    private ClaudeCliGateway $gw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gw = new ClaudeCliGateway(['timeout' => 30]);
    }

    private function callMethod(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($obj, $args);
    }

    private function getProperty(object $obj, string $prop): mixed
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);

        return $ref->getValue($obj);
    }

    private function mockProvider(string $name = 'test'): TextProvider
    {
        return new class($name) implements TextProvider
        {
            public function __construct(private string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function driver(): string
            {
                return $this->n;
            }

            public function prompt(\Laravel\Ai\Prompts\AgentPrompt $p): \Laravel\Ai\Responses\AgentResponse
            {
                throw new \RuntimeException('not impl');
            }

            public function stream(\Laravel\Ai\Prompts\AgentPrompt $p): \Laravel\Ai\Responses\StreamableAgentResponse
            {
                throw new \RuntimeException('not impl');
            }

            public function textGateway(): \Laravel\Ai\Contracts\Gateway\TextGateway
            {
                throw new \RuntimeException('not impl');
            }

            public function useTextGateway(\Laravel\Ai\Contracts\Gateway\TextGateway $g): self
            {
                return $this;
            }

            public function defaultTextModel(): string
            {
                return 'default';
            }

            public function cheapestTextModel(): string
            {
                return 'cheap';
            }

            public function smartestTextModel(): string
            {
                return 'smart';
            }
        };
    }

    private function mockArrayable(array $data): \Illuminate\Contracts\Support\Arrayable
    {
        return new class($data) implements \Illuminate\Contracts\Support\Arrayable
        {
            public function __construct(private array $data) {}

            public function toArray(): array
            {
                return $this->data;
            }
        };
    }

    // ── A8: buildCommand() ──

    public function test_build_command_base_includes_claude_p_dash_output_format_json(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', null, null, null, false]);
        $this->assertSame('claude', $cmd[0]);
        $this->assertSame('-p', $cmd[1]);
        $this->assertSame('-', $cmd[2]);
        $this->assertSame('--output-format', $cmd[3]);
        $this->assertSame('json', $cmd[4]);
    }

    public function test_build_command_adds_model_when_non_empty(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['sonnet', null, null, null, false]);
        $idx = array_search('--model', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame('sonnet', $cmd[$idx + 1]);
    }

    public function test_build_command_adds_system_prompt_when_not_continuation_session(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', 'Be helpful', null, null, false]);
        $idx = array_search('--system-prompt', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame('Be helpful', $cmd[$idx + 1]);
    }

    public function test_build_command_skips_system_prompt_for_continuation_with_session(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', 'Be helpful', null, 'sess123', true]);
        $this->assertNotContains('--system-prompt', $cmd);
    }

    public function test_build_command_adds_resume_for_continuation_with_session(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', null, null, 'sess123', true]);
        $idx = array_search('--resume', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame('sess123', $cmd[$idx + 1]);
    }

    public function test_build_command_adds_json_schema_when_schema_provided(): void
    {
        $mockType = $this->mockArrayable(['type' => 'string']);
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', null, ['name' => $mockType], null, false]);
        $idx = array_search('--json-schema', $cmd);
        $this->assertNotFalse($idx);
        $decoded = json_decode($cmd[$idx + 1], true);
        $this->assertSame('object', $decoded['type']);
        $this->assertArrayHasKey('name', $decoded['properties']);
    }

    public function test_build_command_does_not_add_json_schema_when_null(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', null, null, null, false]);
        $this->assertNotContains('--json-schema', $cmd);
    }

    // ── A9: buildStreamCommand() ──

    public function test_build_stream_command_includes_stream_json_verbose_partial(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildStreamCommand', ['', null, null, false]);
        $this->assertContains('stream-json', $cmd);
        $this->assertContains('--verbose', $cmd);
        $this->assertContains('--include-partial-messages', $cmd);
    }

    public function test_build_stream_command_adds_model_resume_skips_system_prompt(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildStreamCommand', ['sonnet', 'sys prompt', 'sess1', true]);
        $this->assertNotContains('--system-prompt', $cmd);
        $idx = array_search('--resume', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame('sess1', $cmd[$idx + 1]);
        $idx2 = array_search('--model', $cmd);
        $this->assertNotFalse($idx2);
        $this->assertSame('sonnet', $cmd[$idx2 + 1]);
    }

    public function test_build_stream_command_does_not_include_json_schema(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildStreamCommand', ['', null, null, false]);
        $this->assertNotContains('--json-schema', $cmd);
    }

    // ── A10: parseJsonOutput() ──

    public function test_parse_json_output_valid_json_returns_decoded(): void
    {
        $r = $this->callMethod($this->gw, 'parseJsonOutput', ['{"result":"hi","session_id":"s1"}']);
        $this->assertSame('hi', $r['result']);
        $this->assertSame('s1', $r['session_id']);
    }

    public function test_parse_json_output_invalid_json_returns_result_raw(): void
    {
        $r = $this->callMethod($this->gw, 'parseJsonOutput', ['not json at all']);
        $this->assertSame('not json at all', $r['result']);
    }

    public function test_parse_json_output_result_and_session_id_accessible(): void
    {
        $r = $this->callMethod($this->gw, 'parseJsonOutput', ['{"result":"answer","session_id":"abc123"}']);
        $this->assertSame('answer', $r['result']);
        $this->assertSame('abc123', $r['session_id']);
    }

    // ── A11: parseStreamLine() ──

    public function test_parse_stream_line_empty_string_returns_null(): void
    {
        $this->assertNull($this->callMethod($this->gw, 'parseStreamLine', ['']));
    }

    public function test_parse_stream_line_invalid_json_returns_null(): void
    {
        $this->assertNull($this->callMethod($this->gw, 'parseStreamLine', ['not json']));
    }

    public function test_parse_stream_line_text_delta_returns_text(): void
    {
        $event = json_encode([
            'type' => 'stream_event',
            'event' => ['delta' => ['type' => 'text_delta', 'text' => 'Hello']],
        ]);
        $this->assertSame('Hello', $this->callMethod($this->gw, 'parseStreamLine', [$event]));
    }

    public function test_parse_stream_line_result_with_session_id_stores_and_returns_null(): void
    {
        $gw = new ClaudeCliGateway(['timeout' => 30]);
        $event = json_encode(['type' => 'result', 'session_id' => 'stream_sess_42']);
        $this->assertNull($this->callMethod($gw, 'parseStreamLine', [$event]));
        $sessions = $this->getProperty($gw, 'sessions');
        $this->assertSame('stream_sess_42', $sessions['_last_stream']);
    }

    public function test_parse_stream_line_other_event_type_returns_null(): void
    {
        $event = json_encode(['type' => 'init', 'data' => 'something']);
        $this->assertNull($this->callMethod($this->gw, 'parseStreamLine', [$event]));
    }

    // ── A12: convertSchema() ──

    public function test_convert_schema_single_property(): void
    {
        $mockType = $this->mockArrayable(['type' => 'string']);
        $r = $this->callMethod($this->gw, 'convertSchema', [['name' => $mockType]]);
        $this->assertSame('object', $r['type']);
        $this->assertSame(['type' => 'string'], $r['properties']['name']);
        $this->assertSame(['name'], $r['required']);
    }

    public function test_convert_schema_multi_property(): void
    {
        $mockType = $this->mockArrayable(['type' => 'string']);
        $mockType2 = $this->mockArrayable(['type' => 'integer']);
        $r = $this->callMethod($this->gw, 'convertSchema', [['name' => $mockType, 'age' => $mockType2]]);
        $this->assertSame('object', $r['type']);
        $this->assertSame(['type' => 'string'], $r['properties']['name']);
        $this->assertSame(['type' => 'integer'], $r['properties']['age']);
        $this->assertSame(['name', 'age'], $r['required']);
    }

    // ── A13: binary() ──

    public function test_binary_returns_config_binary_when_set(): void
    {
        $gw = new ClaudeCliGateway(['binary' => '/usr/local/bin/claude']);
        $this->assertSame('/usr/local/bin/claude', $this->callMethod($gw, 'binary'));
    }

    public function test_binary_returns_claude_when_not_set(): void
    {
        $gw = new ClaudeCliGateway([]);
        $this->assertSame('claude', $this->callMethod($gw, 'binary'));
    }

    // ── F1: parseJsonOutput non-array JSON values ──

    public function test_parse_json_output_null_returns_fallback(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ['null']);
        $this->assertIsArray($r);
        $this->assertSame('null', $r['result']);
    }

    public function test_parse_json_output_integer_returns_fallback(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ['42']);
        $this->assertIsArray($r);
        $this->assertSame('42', $r['result']);
    }

    public function test_parse_json_output_string_returns_fallback(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ['"hello"']);
        $this->assertIsArray($r);
    }

    public function test_parse_json_output_boolean_returns_fallback(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ['true']);
        $this->assertIsArray($r);
    }

    public function test_parse_json_output_empty_array_returns_valid(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ['[]']);
        $this->assertIsArray($r);
        $this->assertSame([], $r);
    }

    public function test_parse_json_output_whitespace_string_returns_fallback(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ['   ']);
        $this->assertIsArray($r);
        $this->assertSame('   ', $r['result']);
    }

    // ── F3: Claude buildCommand falsy strings ──

    public function test_build_command_zero_instructions_not_added(): void
    {
        $gw = new ClaudeCliGateway([]);
        $cmd = $this->callMethod($gw, 'buildCommand', ['', '0', null, null, false]);
        $this->assertNotContains('--system-prompt', $cmd);
    }

    public function test_build_command_zero_model_not_added(): void
    {
        $gw = new ClaudeCliGateway([]);
        $cmd = $this->callMethod($gw, 'buildCommand', ['0', null, null, null, false]);
        $this->assertNotContains('--model', $cmd);
    }

    // ── F7: stdin construction paths ──

    public function test_path1_non_continuation_instructions_not_in_stdin(): void
    {
        $gw = new class(['timeout' => 30]) extends ClaudeCliGateway
        {
            public ?string $capturedStdin = null;

            public ?array $capturedCommand = null;

            protected function runProcess(array $command, ?string $stdin = null, ?int $timeout = null): string
            {
                $this->capturedCommand = $command;
                $this->capturedStdin = $stdin;

                return json_encode(['result' => 'ok']);
            }
        };

        $provider = $this->mockProvider('test');
        $gw->generateText($provider, 'sonnet', 'Be concise', [new UserMessage('hello')]);

        $idx = array_search('--system-prompt', $gw->capturedCommand);
        $this->assertNotFalse($idx);
        $this->assertSame('Be concise', $gw->capturedCommand[$idx + 1]);
        $this->assertStringNotContainsString('Be concise', $gw->capturedStdin);
        $this->assertStringContainsString('hello', $gw->capturedStdin);
    }

    public function test_path2_continuation_no_session_instructions_in_system_prompt_only(): void
    {
        $gw = new ClaudeCliGateway([]);
        $messages = [
            new UserMessage('hi'),
            new AssistantMessage('hello'),
            new UserMessage('how are you'),
        ];

        $this->assertTrue($this->callMethod($gw, 'isContinuation', [$messages]));

        $stdin = $this->callMethod($gw, 'formatAllMessages', [null, $messages]);
        $this->assertStringNotContainsString('Be concise', $stdin);
        $this->assertStringContainsString('hi', $stdin);
        $this->assertStringContainsString('how are you', $stdin);

        $cmd = $this->callMethod($gw, 'buildCommand', ['', 'Be concise', null, null, true]);
        $this->assertContains('--system-prompt', $cmd);
    }

    public function test_path3_continuation_with_session_stdin_is_last_user_message(): void
    {
        $gw = new ClaudeCliGateway([]);
        $messages = [
            new UserMessage('original question'),
            new AssistantMessage('original answer'),
            new UserMessage('follow-up question'),
        ];

        $this->assertTrue($this->callMethod($gw, 'isContinuation', [$messages]));
        $stdin = $this->callMethod($gw, 'lastUserMessage', [$messages]);
        $this->assertSame('follow-up question', $stdin);

        $cmd = $this->callMethod($gw, 'buildCommand', ['', 'Be concise', null, 'sess-id', true]);
        $this->assertNotContains('--system-prompt', $cmd);
        $this->assertContains('--resume', $cmd);
    }

    public function test_continuation_all_assistant_messages_last_user_empty(): void
    {
        $gw = new ClaudeCliGateway([]);
        $messages = [new AssistantMessage('a1'), new AssistantMessage('a2')];
        $this->assertTrue($this->callMethod($gw, 'isContinuation', [$messages]));
        $this->assertSame('', $this->callMethod($gw, 'lastUserMessage', [$messages]));
    }

    // ── F8: Schema edge cases ──

    public function test_convert_schema_empty_array_valid_empty_object(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'convertSchema', [[]]);
        $this->assertSame(['type' => 'object', 'properties' => [], 'required' => []], $r);
    }

    public function test_parse_json_output_no_result_key(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ['{"session_id":"abc","cost":0.01}']);
        $this->assertArrayNotHasKey('result', $r);
        $this->assertSame('abc', $r['session_id']);
    }

    public function test_parse_json_output_result_null_preserved(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ['{"result":null}']);
        $this->assertNull($r['result']);
    }

    // ── F9: Claude streaming parse edge cases ──

    public function test_parse_stream_line_text_delta_empty_text_returns_empty_string(): void
    {
        $gw = new ClaudeCliGateway([]);
        $event = json_encode([
            'type' => 'stream_event',
            'event' => ['delta' => ['type' => 'text_delta', 'text' => '']],
        ]);
        $this->assertSame('', $this->callMethod($gw, 'parseStreamLine', [$event]));
    }

    public function test_parse_stream_line_text_delta_missing_text_returns_null(): void
    {
        $gw = new ClaudeCliGateway([]);
        $event = json_encode([
            'type' => 'stream_event',
            'event' => ['delta' => ['type' => 'text_delta']],
        ]);
        $this->assertNull($this->callMethod($gw, 'parseStreamLine', [$event]));
    }

    public function test_parse_stream_line_wrong_delta_type_returns_null(): void
    {
        $gw = new ClaudeCliGateway([]);
        $event = json_encode([
            'type' => 'stream_event',
            'event' => ['delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
        ]);
        $this->assertNull($this->callMethod($gw, 'parseStreamLine', [$event]));
    }

    public function test_parse_stream_line_no_delta_at_all_returns_null(): void
    {
        $gw = new ClaudeCliGateway([]);
        $event = json_encode(['type' => 'stream_event', 'event' => ['something' => 'else']]);
        $this->assertNull($this->callMethod($gw, 'parseStreamLine', [$event]));
    }

    public function test_parse_stream_line_no_type_key_returns_null(): void
    {
        $gw = new ClaudeCliGateway([]);
        $event = json_encode(['data' => 'something', 'id' => '123']);
        $this->assertNull($this->callMethod($gw, 'parseStreamLine', [$event]));
    }

    // ── F5: parseStreamLine session edge case ──

    public function test_parse_stream_line_result_without_session_id_no_last_stream(): void
    {
        $gw = new ClaudeCliGateway([]);
        $event = json_encode(['type' => 'result', 'subtype' => 'success']);
        $this->callMethod($gw, 'parseStreamLine', [$event]);
        $sessions = $this->getProperty($gw, 'sessions');
        $this->assertArrayNotHasKey('_last_stream', $sessions);
    }

    // ── F16: parseJsonOutput unicode ──

    public function test_parse_json_output_unicode_decoded_correctly(): void
    {
        $gw = new ClaudeCliGateway([]);
        $json = json_encode(['result' => 'こんにちは', 'session_id' => 'abc']);
        $r = $this->callMethod($gw, 'parseJsonOutput', [$json]);
        $this->assertSame('こんにちは', $r['result']);
    }

    public function test_parse_json_output_whitespace_and_newlines_trimmed(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ["  \n  {\"result\":\"hi\"}  \n  "]);
        $this->assertSame('hi', $r['result']);
    }

    public function test_parse_stream_line_whitespace_only_returns_null(): void
    {
        $gw = new ClaudeCliGateway([]);
        $this->assertNull($this->callMethod($gw, 'parseStreamLine', ["   \t  "]));
    }

    // ── G1: Instructions duplication ──

    public function test_generate_text_stdin_does_not_duplicate_instructions(): void
    {
        $gw = new class(['timeout' => 30]) extends ClaudeCliGateway
        {
            public ?string $capturedStdin = null;

            public ?array $capturedCommand = null;

            protected function runProcess(array $command, ?string $stdin = null, ?int $timeout = null): string
            {
                $this->capturedCommand = $command;
                $this->capturedStdin = $stdin;

                return json_encode(['result' => 'mocked', 'session_id' => 'sess']);
            }
        };

        $provider = $this->mockProvider('test');
        $gw->generateText($provider, 'sonnet', 'System instructions', [new UserMessage('user prompt')]);

        $this->assertContains('--system-prompt', $gw->capturedCommand);
        $this->assertStringNotContainsString('System instructions', $gw->capturedStdin);
        $this->assertStringContainsString('user prompt', $gw->capturedStdin);
    }

    // ── G3: Claude stream session persistence ──

    public function test_parse_stream_line_session_id_from_result_stored(): void
    {
        $gw = new ClaudeCliGateway([]);
        $line = json_encode(['type' => 'result', 'subtype' => 'success', 'session_id' => 'stream-sess-123']);
        $this->callMethod($gw, 'parseStreamLine', [$line]);
        $sessions = $this->getProperty($gw, 'sessions');
        $this->assertSame('stream-sess-123', $sessions['_last_stream'] ?? null);
    }

    public function test_stream_text_persists_session_id_to_conversation_key(): void
    {
        $gw = new class(['timeout' => 30]) extends ClaudeCliGateway
        {
            protected function startProcess(array $command, ?string $stdin = null, ?int $timeout = null): \Symfony\Component\Process\Process
            {
                $resultLine = json_encode(['type' => 'result', 'subtype' => 'success', 'session_id' => 'persisted-sess']);
                $process = new \Symfony\Component\Process\Process(['echo', $resultLine]);
                $process->start();

                return $process;
            }
        };

        $provider = $this->mockProvider('test');
        $instructions = 'test instructions';
        $messages = [new UserMessage('hello')];
        $convKey = $this->callMethod($gw, 'conversationKey', [$instructions, $messages]);

        $generator = $gw->streamText('inv-1', $provider, 'sonnet', $instructions, $messages);
        foreach ($generator as $event) {
            // consume all events
        }

        $sess = $this->callMethod($gw, 'getSession', [$convKey]);
        $this->assertSame('persisted-sess', $sess);
    }

    // ── G4: Streaming timeout ──

    public function test_streaming_timeout_throws_cli_process_exception(): void
    {
        $gw = new class(['timeout' => 30]) extends ClaudeCliGateway
        {
            protected function startProcess(array $command, ?string $stdin = null, ?int $timeout = null): \Symfony\Component\Process\Process
            {
                $process = new \Symfony\Component\Process\Process(
                    ['php', '-r', 'sleep(10);'], null, null, null, 1
                );
                $process->start();

                return $process;
            }
        };

        $provider = $this->mockProvider('test');
        $this->expectException(\Laravel\Ai\Exceptions\CliProcessException::class);

        $gen = $gw->streamText('inv-1', $provider, 'sonnet', 'test', [new UserMessage('hello')]);
        foreach ($gen as $event) {
            // consume
        }
    }
}
