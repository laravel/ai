<?php

namespace Tests\Unit\Gateway\Cli;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Cli\CodexCliGateway;
use Laravel\Ai\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

class CodexCliGatewayTest extends TestCase
{
    private CodexCliGateway $gw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gw = new CodexCliGateway(['timeout' => 30]);
    }

    private function callMethod(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($obj, $args);
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

    // ── A14: buildCommand() ──

    public function test_build_command_new_conversation_uses_exec_dash(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', null, null, null, false]);
        $this->assertSame('codex', $cmd[0]);
        $this->assertSame('exec', $cmd[1]);
        $this->assertSame('-', $cmd[2]);
    }

    public function test_build_command_continuation_with_session_uses_resume(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', null, null, 'sess1', true]);
        $this->assertSame('codex', $cmd[0]);
        $this->assertSame('exec', $cmd[1]);
        $this->assertSame('resume', $cmd[2]);
        $this->assertSame('sess1', $cmd[3]);
    }

    public function test_build_command_always_includes_json_and_skip_git(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', null, null, null, false]);
        $this->assertContains('--json', $cmd);
        $this->assertContains('--skip-git-repo-check', $cmd);
    }

    public function test_build_command_adds_model_when_provided(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['gpt-4.1', null, null, null, false]);
        $idx = array_search('--model', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame('gpt-4.1', $cmd[$idx + 1]);
    }

    public function test_build_command_adds_system_prompt_when_not_continuation_session(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', 'Be short', null, null, false]);
        $idx = array_search('--system-prompt', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame('Be short', $cmd[$idx + 1]);
    }

    public function test_build_command_adds_output_schema_when_provided(): void
    {
        $mockType = $this->mockArrayable(['type' => 'string']);
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', null, ['name' => $mockType], null, false]);
        $idx = array_search('--output-schema', $cmd);
        $this->assertNotFalse($idx);
    }

    // ── A15: parseOutput() ──

    public function test_parse_output_single_message_extracts_text(): void
    {
        $line = json_encode(['type' => 'message', 'content' => 'hello']);
        $r = $this->callMethod($this->gw, 'parseOutput', [$line]);
        $this->assertSame('hello', $r['text']);
    }

    public function test_parse_output_multiple_lines_concatenates(): void
    {
        $lines = json_encode(['type' => 'message', 'content' => 'hello '])."\n".
                 json_encode(['type' => 'message', 'content' => 'world']);
        $r = $this->callMethod($this->gw, 'parseOutput', [$lines]);
        $this->assertSame('hello world', $r['text']);
    }

    public function test_parse_output_extracts_session_id(): void
    {
        $line = json_encode(['type' => 'message', 'content' => 'hi', 'session_id' => 'sess42']);
        $r = $this->callMethod($this->gw, 'parseOutput', [$line]);
        $this->assertSame('sess42', $r['session_id']);
    }

    public function test_parse_output_extracts_structured_output(): void
    {
        $line = json_encode(['type' => 'message', 'content' => 'hi', 'structured_output' => ['name' => 'John']]);
        $r = $this->callMethod($this->gw, 'parseOutput', [$line]);
        $this->assertSame(['name' => 'John'], $r['structured_output']);
    }

    public function test_parse_output_invalid_json_treated_as_plain_text(): void
    {
        $r = $this->callMethod($this->gw, 'parseOutput', ['plain text output']);
        $this->assertSame('plain text output', $r['text']);
    }

    public function test_parse_output_no_text_falls_back_to_raw(): void
    {
        $line = json_encode(['type' => 'status', 'data' => 'running']);
        $r = $this->callMethod($this->gw, 'parseOutput', [$line]);
        $this->assertSame($line, $r['text']);
    }

    public function test_parse_output_array_content_json_encoded(): void
    {
        $line = json_encode(['type' => 'message', 'content' => ['key' => 'val']]);
        $r = $this->callMethod($this->gw, 'parseOutput', [$line]);
        $this->assertSame(json_encode(['key' => 'val']), $r['text']);
    }

    public function test_parse_output_string_content_used_as_is(): void
    {
        $line = json_encode(['type' => 'message', 'content' => 'direct string']);
        $r = $this->callMethod($this->gw, 'parseOutput', [$line]);
        $this->assertSame('direct string', $r['text']);
    }

    // ── A16: parseStreamLine() ──

    public function test_parse_stream_line_empty_returns_null(): void
    {
        $this->assertNull($this->callMethod($this->gw, 'parseStreamLine', ['']));
    }

    public function test_parse_stream_line_invalid_json_returns_null(): void
    {
        $this->assertNull($this->callMethod($this->gw, 'parseStreamLine', ['not json']));
    }

    public function test_parse_stream_line_message_string_content_returns_string(): void
    {
        $line = json_encode(['type' => 'message', 'content' => 'chunk']);
        $this->assertSame('chunk', $this->callMethod($this->gw, 'parseStreamLine', [$line]));
    }

    public function test_parse_stream_line_message_array_content_json_encodes(): void
    {
        $line = json_encode(['type' => 'message', 'content' => ['a' => 1]]);
        $this->assertSame(json_encode(['a' => 1]), $this->callMethod($this->gw, 'parseStreamLine', [$line]));
    }

    public function test_parse_stream_line_other_type_returns_null(): void
    {
        $line = json_encode(['type' => 'status', 'data' => 'ok']);
        $this->assertNull($this->callMethod($this->gw, 'parseStreamLine', [$line]));
    }

    // ── A17: binary() ──

    public function test_binary_returns_config_binary_when_set(): void
    {
        $gw = new CodexCliGateway(['binary' => '/opt/codex']);
        $this->assertSame('/opt/codex', $this->callMethod($gw, 'binary'));
    }

    public function test_binary_returns_codex_when_not_set(): void
    {
        $gw = new CodexCliGateway([]);
        $this->assertSame('codex', $this->callMethod($gw, 'binary'));
    }

    // ── F3: Codex buildCommand falsy ──

    public function test_build_command_zero_model_not_added(): void
    {
        $gw = new CodexCliGateway([]);
        $cmd = $this->callMethod($gw, 'buildCommand', ['0', null, null, null, false]);
        $this->assertNotContains('--model', $cmd);
    }

    // ── F8: Codex convertSchema ──

    public function test_convert_schema_empty_array(): void
    {
        $gw = new CodexCliGateway([]);
        $r = $this->callMethod($gw, 'convertSchema', [[]]);
        $this->assertSame(['type' => 'object', 'properties' => [], 'required' => []], $r);
    }

    // ── F9: Codex streaming parse edge cases ──

    public function test_parse_stream_line_message_null_content_returns_null(): void
    {
        $gw = new CodexCliGateway([]);
        $line = json_encode(['type' => 'message', 'content' => null]);
        $this->assertNull($this->callMethod($gw, 'parseStreamLine', [$line]));
    }

    public function test_parse_stream_line_message_empty_string_returns_empty(): void
    {
        $gw = new CodexCliGateway([]);
        $line = json_encode(['type' => 'message', 'content' => '']);
        $this->assertSame('', $this->callMethod($gw, 'parseStreamLine', [$line]));
    }

    public function test_parse_stream_line_message_integer_content_not_null(): void
    {
        $gw = new CodexCliGateway([]);
        $line = json_encode(['type' => 'message', 'content' => 42]);
        $this->assertNotNull($this->callMethod($gw, 'parseStreamLine', [$line]));
    }

    // ── F10: Codex parseOutput edge cases ──

    public function test_parse_output_all_blank_lines_falls_to_raw(): void
    {
        $gw = new CodexCliGateway([]);
        $input = "\n\n\n";
        $r = $this->callMethod($gw, 'parseOutput', [$input]);
        $this->assertSame($input, $r['text']);
    }

    public function test_parse_output_mixed_json_and_plain_text(): void
    {
        $gw = new CodexCliGateway([]);
        $input = "plain line\n".json_encode(['type' => 'message', 'content' => ' json part']);
        $r = $this->callMethod($gw, 'parseOutput', [$input]);
        $this->assertSame('plain line json part', $r['text']);
    }

    public function test_parse_output_multiple_session_ids_last_wins(): void
    {
        $gw = new CodexCliGateway([]);
        $input = json_encode(['session_id' => 'first'])."\n".
                 json_encode(['session_id' => 'second']);
        $r = $this->callMethod($gw, 'parseOutput', [$input]);
        $this->assertSame('second', $r['session_id']);
    }

    public function test_parse_output_integer_zero_content_preserved(): void
    {
        $gw = new CodexCliGateway([]);
        $line = json_encode(['type' => 'message', 'content' => 0]);
        $r = $this->callMethod($gw, 'parseOutput', [$line]);
        $this->assertSame('0', $r['text']);
    }

    public function test_parse_output_session_id_on_non_message_captured(): void
    {
        $gw = new CodexCliGateway([]);
        $line = json_encode(['type' => 'status', 'session_id' => 'abc']);
        $r = $this->callMethod($gw, 'parseOutput', [$line]);
        $this->assertSame('abc', $r['session_id']);
    }

    public function test_parse_output_structured_output_on_non_message_captured(): void
    {
        $gw = new CodexCliGateway([]);
        $line = json_encode(['type' => 'result', 'structured_output' => ['key' => 'val']]);
        $r = $this->callMethod($gw, 'parseOutput', [$line]);
        $this->assertSame(['key' => 'val'], $r['structured_output']);
    }

    public function test_parse_output_crlf_line_endings_handled(): void
    {
        $gw = new CodexCliGateway([]);
        $input = json_encode(['type' => 'message', 'content' => 'hello'])."\r\n".
                 json_encode(['type' => 'message', 'content' => ' world']);
        $r = $this->callMethod($gw, 'parseOutput', [$input]);
        $this->assertSame('hello world', $r['text']);
    }

    // ── F15: Codex continuation edge cases ──

    public function test_continuation_true_no_session_uses_exec_dash(): void
    {
        $gw = new CodexCliGateway([]);
        $cmd = $this->callMethod($gw, 'buildCommand', ['', null, null, null, true]);
        $this->assertSame('exec', $cmd[1]);
        $this->assertSame('-', $cmd[2]);
        $this->assertNotContains('resume', $cmd);
    }

    public function test_instructions_skipped_for_continuation_with_session(): void
    {
        $gw = new CodexCliGateway([]);
        $cmd = $this->callMethod($gw, 'buildCommand', ['', 'Be helpful', null, 'sess1', true]);
        $this->assertNotContains('--system-prompt', $cmd);
    }

    public function test_stream_always_ignores_session_state(): void
    {
        $gw = new CodexCliGateway([]);
        $cmd = $this->callMethod($gw, 'buildCommand', ['gpt-4', 'sys', null, null, false, true]);
        $this->assertSame('exec', $cmd[1]);
        $this->assertSame('-', $cmd[2]);
        $this->assertContains('--system-prompt', $cmd);
        $this->assertNotContains('resume', $cmd);
    }

    // ── F19: Codex volume ──

    public function test_parse_output_50_jsonl_lines_concatenated(): void
    {
        $gw = new CodexCliGateway([]);
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = json_encode(['type' => 'message', 'content' => "p{$i} "]);
        }
        $r = $this->callMethod($gw, 'parseOutput', [implode("\n", $lines)]);
        $this->assertStringContainsString('p0 ', $r['text']);
        $this->assertStringContainsString('p49 ', $r['text']);
    }

    // ── G1: Instructions duplication ──

    public function test_generate_text_stdin_does_not_duplicate_instructions(): void
    {
        $gw = new class(['timeout' => 30]) extends CodexCliGateway
        {
            public ?string $capturedStdin = null;

            public ?array $capturedCommand = null;

            protected function runProcess(array $command, ?string $stdin = null, ?int $timeout = null): string
            {
                $this->capturedCommand = $command;
                $this->capturedStdin = $stdin;

                return json_encode(['type' => 'message', 'content' => 'mocked']);
            }
        };

        $provider = $this->mockProvider('test');
        $gw->generateText($provider, 'gpt-4', 'System instructions', [new UserMessage('user prompt')]);

        $this->assertContains('--system-prompt', $gw->capturedCommand);
        $this->assertStringNotContainsString('System instructions', $gw->capturedStdin);
        $this->assertStringContainsString('user prompt', $gw->capturedStdin);
    }

    // ── G2: Codex "0" text ──

    public function test_parse_output_text_zero_preserved(): void
    {
        $gw = new CodexCliGateway([]);
        $line = json_encode(['type' => 'message', 'content' => '0']);
        $r = $this->callMethod($gw, 'parseOutput', [$line]);
        $this->assertSame('0', $r['text']);
    }
}
