<?php

namespace Tests\Unit\Gateway\Cli;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\CliProcessException;
use Laravel\Ai\Gateway\Cli\ClaudeCliGateway;
use Laravel\Ai\Gateway\Cli\CodexCliGateway;
use Laravel\Ai\Gateway\Cli\GeminiCliGateway;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class CliGatewayTest extends TestCase
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

    // ── A1: environment() ──

    public function test_environment_returns_array(): void
    {
        $env = $this->callMethod($this->gw, 'environment');
        $this->assertIsArray($env);
    }

    public function test_environment_merges_config_env_values(): void
    {
        $gw = new ClaudeCliGateway(['timeout' => 30, 'env' => ['MY_CUSTOM_VAR' => 'hello']]);
        $env = $this->callMethod($gw, 'environment');
        $this->assertSame('hello', $env['MY_CUSTOM_VAR'] ?? null);
    }

    public function test_environment_strips_claudecode(): void
    {
        putenv('CLAUDECODE=1');
        $_ENV['CLAUDECODE'] = '1';

        try {
            $env = $this->callMethod($this->gw, 'environment');
            $this->assertArrayNotHasKey('CLAUDECODE', $env);
        } finally {
            putenv('CLAUDECODE');
            unset($_ENV['CLAUDECODE']);
        }
    }

    public function test_environment_strips_claude_code_entrypoint(): void
    {
        putenv('CLAUDE_CODE_ENTRYPOINT=test');
        $_ENV['CLAUDE_CODE_ENTRYPOINT'] = 'test';

        try {
            $env = $this->callMethod($this->gw, 'environment');
            $this->assertArrayNotHasKey('CLAUDE_CODE_ENTRYPOINT', $env);
        } finally {
            putenv('CLAUDE_CODE_ENTRYPOINT');
            unset($_ENV['CLAUDE_CODE_ENTRYPOINT']);
        }
    }

    public function test_environment_strips_claude_code_session(): void
    {
        putenv('CLAUDE_CODE_SESSION=abc');
        $_ENV['CLAUDE_CODE_SESSION'] = 'abc';

        try {
            $env = $this->callMethod($this->gw, 'environment');
            $this->assertArrayNotHasKey('CLAUDE_CODE_SESSION', $env);
        } finally {
            putenv('CLAUDE_CODE_SESSION');
            unset($_ENV['CLAUDE_CODE_SESSION']);
        }
    }

    public function test_environment_preserves_path_and_home(): void
    {
        $env = $this->callMethod($this->gw, 'environment');
        $this->assertArrayHasKey('PATH', $env);
        $this->assertArrayHasKey('HOME', $env);
    }

    // ── A2: isContinuation() ──

    public function test_is_continuation_returns_false_for_empty_messages(): void
    {
        $this->assertFalse($this->callMethod($this->gw, 'isContinuation', [[]]));
    }

    public function test_is_continuation_returns_false_for_only_user_message(): void
    {
        $this->assertFalse($this->callMethod($this->gw, 'isContinuation', [[new UserMessage('hi')]]));
    }

    public function test_is_continuation_returns_true_when_assistant_message_present(): void
    {
        $this->assertTrue($this->callMethod($this->gw, 'isContinuation', [[
            new UserMessage('hi'),
            new AssistantMessage('hello'),
        ]]));
    }

    // ── A3: lastUserMessage() ──

    public function test_last_user_message_returns_empty_for_empty_array(): void
    {
        $this->assertSame('', $this->callMethod($this->gw, 'lastUserMessage', [[]]));
    }

    public function test_last_user_message_returns_content_of_only_user_message(): void
    {
        $this->assertSame('hello world', $this->callMethod($this->gw, 'lastUserMessage', [[new UserMessage('hello world')]]));
    }

    public function test_last_user_message_returns_last_user_message(): void
    {
        $this->assertSame('last', $this->callMethod($this->gw, 'lastUserMessage', [[
            new UserMessage('first'),
            new AssistantMessage('mid'),
            new UserMessage('last'),
        ]]));
    }

    public function test_last_user_message_returns_empty_when_only_assistant_messages(): void
    {
        $this->assertSame('', $this->callMethod($this->gw, 'lastUserMessage', [[new AssistantMessage('only assistant')]]));
    }

    // ── A4: formatAllMessages() ──

    public function test_format_all_messages_returns_just_instructions_when_no_messages(): void
    {
        $this->assertSame('Be helpful', $this->callMethod($this->gw, 'formatAllMessages', ['Be helpful', []]));
    }

    public function test_format_all_messages_returns_just_content_when_instructions_null(): void
    {
        $this->assertSame('hi', $this->callMethod($this->gw, 'formatAllMessages', [null, [new UserMessage('hi')]]));
    }

    public function test_format_all_messages_joins_instructions_and_messages_with_newlines(): void
    {
        $result = $this->callMethod($this->gw, 'formatAllMessages', ['sys', [
            new UserMessage('user1'),
            new AssistantMessage('asst1'),
        ]]);
        $this->assertSame("sys\n\nuser1\n\nasst1", $result);
    }

    public function test_format_all_messages_handles_multiple_messages_in_order(): void
    {
        $result = $this->callMethod($this->gw, 'formatAllMessages', [null, [
            new UserMessage('a'),
            new AssistantMessage('b'),
            new UserMessage('c'),
        ]]);
        $this->assertSame("a\n\nb\n\nc", $result);
    }

    // ── A5: conversationKey() + sessions ──

    public function test_conversation_key_returns_md5_hash(): void
    {
        $key = $this->callMethod($this->gw, 'conversationKey', ['inst', [new UserMessage('hi')]]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key);
    }

    public function test_same_inputs_produce_same_key(): void
    {
        $msgs = [new UserMessage('hi')];
        $k1 = $this->callMethod($this->gw, 'conversationKey', ['inst', $msgs]);
        $k2 = $this->callMethod($this->gw, 'conversationKey', ['inst', $msgs]);
        $this->assertSame($k1, $k2);
    }

    public function test_different_inputs_produce_different_keys(): void
    {
        $k1 = $this->callMethod($this->gw, 'conversationKey', ['a', [new UserMessage('x')]]);
        $k2 = $this->callMethod($this->gw, 'conversationKey', ['b', [new UserMessage('y')]]);
        $this->assertNotSame($k1, $k2);
    }

    public function test_store_session_and_get_session_round_trips(): void
    {
        $this->callMethod($this->gw, 'storeSession', ['testkey', 'session123']);
        $this->assertSame('session123', $this->callMethod($this->gw, 'getSession', ['testkey']));
    }

    public function test_get_session_returns_null_for_unknown_key(): void
    {
        $this->assertNull($this->callMethod($this->gw, 'getSession', ['nonexistent_key_xyz']));
    }

    // ── A6: makeTextResponse() ──

    public function test_make_text_response_returns_text_response_with_correct_text(): void
    {
        $provider = $this->mockProvider('claude-cli');
        $r = $this->callMethod($this->gw, 'makeTextResponse', ['hello world', $provider, 'sonnet']);
        $this->assertInstanceOf(TextResponse::class, $r);
        $this->assertSame('hello world', $r->text);
    }

    public function test_make_text_response_usage_has_zero_tokens(): void
    {
        $provider = $this->mockProvider('claude-cli');
        $r = $this->callMethod($this->gw, 'makeTextResponse', ['hi', $provider, 'sonnet']);
        $this->assertSame(0, $r->usage->promptTokens);
        $this->assertSame(0, $r->usage->completionTokens);
    }

    public function test_make_text_response_meta_has_correct_provider_and_model(): void
    {
        $provider = $this->mockProvider('claude-cli');
        $r = $this->callMethod($this->gw, 'makeTextResponse', ['hi', $provider, 'sonnet']);
        $this->assertSame('claude-cli', $r->meta->provider);
        $this->assertSame('sonnet', $r->meta->model);
    }

    // ── A7: onToolInvocation() ──

    public function test_on_tool_invocation_returns_self_fluent(): void
    {
        $result = $this->gw->onToolInvocation(function () {}, function () {});
        $this->assertSame($this->gw, $result);
    }

    public function test_on_tool_invocation_stores_both_callbacks(): void
    {
        $cb1 = function () { return 'invoking'; };
        $cb2 = function () { return 'invoked'; };
        $this->gw->onToolInvocation($cb1, $cb2);
        $this->assertSame($cb1, $this->getProperty($this->gw, 'invokingToolCallback'));
        $this->assertSame($cb2, $this->getProperty($this->gw, 'toolInvokedCallback'));
    }

    // ── E: runProcess error paths ──

    public function test_run_process_non_zero_exit_code_throws_cli_process_exception(): void
    {
        $gw = new ClaudeCliGateway(['timeout' => 10]);
        try {
            $this->callMethod($gw, 'runProcess', [['bash', '-c', 'echo "error msg" >&2; exit 1'], null, 10]);
            $this->fail('Should throw CliProcessException');
        } catch (CliProcessException $e) {
            $this->assertStringContainsString('error msg', $e->getMessage());
        }
    }

    public function test_run_process_timeout_throws_cli_process_exception(): void
    {
        $gw = new ClaudeCliGateway(['timeout' => 300]);
        try {
            $this->callMethod($gw, 'runProcess', [['sleep', '30'], null, 1]);
            $this->fail('Should throw CliProcessException');
        } catch (CliProcessException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public function test_run_process_successful_run_returns_output(): void
    {
        $gw = new ClaudeCliGateway(['timeout' => 10]);
        $output = $this->callMethod($gw, 'runProcess', [['echo', 'hello'], null, 10]);
        $this->assertSame("hello\n", $output);
    }

    // ── F2: conversationKey collision and null handling ──

    public function test_conversation_key_null_instructions_same_as_empty_string(): void
    {
        $gw = new ClaudeCliGateway([]);
        $msgs = [new UserMessage('hello')];
        $k1 = $this->callMethod($gw, 'conversationKey', [null, $msgs]);
        $k2 = $this->callMethod($gw, 'conversationKey', ['', $msgs]);
        $this->assertSame($k1, $k2);
    }

    public function test_conversation_key_no_delimiter_causes_collision(): void
    {
        $gw = new ClaudeCliGateway([]);
        $k1 = $this->callMethod($gw, 'conversationKey', ['ab', [new UserMessage('c')]]);
        $k2 = $this->callMethod($gw, 'conversationKey', ['a', [new UserMessage('bc')]]);
        $this->assertSame($k1, $k2);
    }

    public function test_conversation_key_only_uses_first_user_message(): void
    {
        $gw = new ClaudeCliGateway([]);
        $k1 = $this->callMethod($gw, 'conversationKey', [null, [
            new UserMessage('first'),
            new UserMessage('second'),
        ]]);
        $k2 = $this->callMethod($gw, 'conversationKey', [null, [
            new UserMessage('first'),
            new UserMessage('DIFFERENT'),
        ]]);
        $this->assertSame($k1, $k2);
    }

    public function test_conversation_key_no_user_message_key_from_instructions_only(): void
    {
        $gw = new ClaudeCliGateway([]);
        $k1 = $this->callMethod($gw, 'conversationKey', ['inst', [new AssistantMessage('resp')]]);
        $k2 = $this->callMethod($gw, 'conversationKey', ['inst', []]);
        $this->assertSame($k1, $k2);
    }

    public function test_conversation_key_assistant_first_then_user_uses_user(): void
    {
        $gw = new ClaudeCliGateway([]);
        $k1 = $this->callMethod($gw, 'conversationKey', [null, [
            new AssistantMessage('asst'),
            new UserMessage('user'),
        ]]);
        $this->assertSame(md5(''.'user'), $k1);
    }

    // ── F3: formatAllMessages falsy strings ──

    public function test_format_all_messages_empty_string_instructions_skipped(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'formatAllMessages', ['', [new UserMessage('hello')]]);
        $this->assertSame('hello', $r);
    }

    public function test_format_all_messages_zero_string_instructions_skipped(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'formatAllMessages', ['0', [new UserMessage('test')]]);
        $this->assertSame('test', $r);
    }

    // ── F4: Message array edge cases ──

    public function test_is_continuation_assistant_message_first_still_true(): void
    {
        $gw = new ClaudeCliGateway([]);
        $this->assertTrue($this->callMethod($gw, 'isContinuation', [[
            new AssistantMessage('first'),
            new UserMessage('second'),
        ]]));
    }

    public function test_last_user_message_empty_content_returned_as_is(): void
    {
        $gw = new ClaudeCliGateway([]);
        $this->assertSame('', $this->callMethod($gw, 'lastUserMessage', [[new UserMessage('')]]));
    }

    public function test_format_all_messages_empty_content_creates_empty_part(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'formatAllMessages', [null, [
            new UserMessage('a'),
            new AssistantMessage(''),
            new UserMessage('b'),
        ]]);
        $this->assertSame("a\n\n\n\nb", $r);
    }

    public function test_format_all_messages_no_instructions_no_messages_empty_string(): void
    {
        $gw = new ClaudeCliGateway([]);
        $this->assertSame('', $this->callMethod($gw, 'formatAllMessages', [null, []]));
    }

    public function test_is_continuation_many_user_messages_no_assistant_false(): void
    {
        $gw = new ClaudeCliGateway([]);
        $msgs = array_fill(0, 10, new UserMessage('msg'));
        $this->assertFalse($this->callMethod($gw, 'isContinuation', [$msgs]));
    }

    public function test_last_user_message_interleaved_picks_actual_last(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'lastUserMessage', [[
            new UserMessage('first'),
            new AssistantMessage('a1'),
            new UserMessage('second'),
            new AssistantMessage('a2'),
            new UserMessage('LAST'),
            new AssistantMessage('a3'),
        ]]);
        $this->assertSame('LAST', $r);
    }

    // ── F5: Session state edge cases ──

    public function test_store_session_overwrites_existing(): void
    {
        $gw = new ClaudeCliGateway([]);
        $this->callMethod($gw, 'storeSession', ['key1', 'old-session']);
        $this->callMethod($gw, 'storeSession', ['key1', 'new-session']);
        $this->assertSame('new-session', $this->callMethod($gw, 'getSession', ['key1']));
    }

    public function test_sessions_not_shared_between_instances(): void
    {
        $gw1 = new ClaudeCliGateway([]);
        $gw2 = new ClaudeCliGateway([]);
        $this->callMethod($gw1, 'storeSession', ['key', 'sess1']);
        $this->assertNull($this->callMethod($gw2, 'getSession', ['key']));
    }

    public function test_multiple_session_stores_different_keys_coexist(): void
    {
        $gw = new ClaudeCliGateway([]);
        $this->callMethod($gw, 'storeSession', ['conv-a', 'sess-a']);
        $this->callMethod($gw, 'storeSession', ['conv-b', 'sess-b']);
        $this->assertSame('sess-a', $this->callMethod($gw, 'getSession', ['conv-a']));
        $this->assertSame('sess-b', $this->callMethod($gw, 'getSession', ['conv-b']));
    }

    // ── F6: Environment edge cases ──

    public function test_config_env_overrides_system_env_var(): void
    {
        putenv('MY_EDGE_VAR=system');

        try {
            $gw = new ClaudeCliGateway(['env' => ['MY_EDGE_VAR' => 'config']]);
            $env = $this->callMethod($gw, 'environment');
            $this->assertSame('config', $env['MY_EDGE_VAR']);
        } finally {
            putenv('MY_EDGE_VAR');
        }
    }

    public function test_config_env_claudecode_still_gets_stripped(): void
    {
        $gw = new ClaudeCliGateway(['env' => ['CLAUDECODE' => 'injected']]);
        $env = $this->callMethod($gw, 'environment');
        $this->assertArrayNotHasKey('CLAUDECODE', $env);
    }

    public function test_config_env_claude_code_entrypoint_still_gets_stripped(): void
    {
        $gw = new ClaudeCliGateway(['env' => ['CLAUDE_CODE_ENTRYPOINT' => 'injected']]);
        $env = $this->callMethod($gw, 'environment');
        $this->assertArrayNotHasKey('CLAUDE_CODE_ENTRYPOINT', $env);
    }

    public function test_config_env_claude_code_session_still_gets_stripped(): void
    {
        $gw = new ClaudeCliGateway(['env' => ['CLAUDE_CODE_SESSION' => 'injected']]);
        $env = $this->callMethod($gw, 'environment');
        $this->assertArrayNotHasKey('CLAUDE_CODE_SESSION', $env);
    }

    public function test_environment_all_three_nesting_vars_stripped_simultaneously(): void
    {
        $gw = new ClaudeCliGateway(['env' => [
            'CLAUDECODE' => '1',
            'CLAUDE_CODE_ENTRYPOINT' => '2',
            'CLAUDE_CODE_SESSION' => '3',
            'SAFE_VAR' => 'kept',
        ]]);
        $env = $this->callMethod($gw, 'environment');
        $this->assertArrayNotHasKey('CLAUDECODE', $env);
        $this->assertArrayNotHasKey('CLAUDE_CODE_ENTRYPOINT', $env);
        $this->assertArrayNotHasKey('CLAUDE_CODE_SESSION', $env);
        $this->assertSame('kept', $env['SAFE_VAR']);
    }

    // ── F13: runProcess timeout/stderr/exit code ──

    public function test_run_process_exit_zero_with_stderr_returns_stdout_only(): void
    {
        $gw = new ClaudeCliGateway([]);
        $output = $this->callMethod($gw, 'runProcess', [
            ['bash', '-c', 'echo stdout; echo stderr >&2; exit 0'], null, 10,
        ]);
        $this->assertSame("stdout\n", $output);
    }

    public function test_run_process_failure_with_empty_stderr_uses_exit_code(): void
    {
        $gw = new ClaudeCliGateway([]);
        try {
            $this->callMethod($gw, 'runProcess', [['bash', '-c', 'exit 42'], null, 10]);
            $this->fail('Should have thrown');
        } catch (CliProcessException $e) {
            $this->assertStringContainsString('42', $e->getMessage());
        }
    }

    public function test_run_process_failure_with_whitespace_stderr_uses_exit_code_msg(): void
    {
        $gw = new ClaudeCliGateway([]);
        try {
            $this->callMethod($gw, 'runProcess', [
                ['bash', '-c', 'echo "  " >&2; exit 1'], null, 10,
            ]);
            $this->fail('Should have thrown');
        } catch (CliProcessException $e) {
            $this->assertStringContainsString('exited with code', $e->getMessage());
        }
    }

    public function test_run_process_timeout_preserves_previous_exception(): void
    {
        $gw = new ClaudeCliGateway([]);
        try {
            $this->callMethod($gw, 'runProcess', [['sleep', '30'], null, 1]);
            $this->fail('Should have thrown');
        } catch (CliProcessException $e) {
            $this->assertInstanceOf(ProcessTimedOutException::class, $e->getPrevious());
        }
    }

    public function test_run_process_config_timeout_used_when_explicit_null(): void
    {
        $gw = new ClaudeCliGateway(['timeout' => 2]);
        try {
            $this->callMethod($gw, 'runProcess', [['sleep', '10'], null, null]);
            $this->fail('Should have thrown');
        } catch (CliProcessException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public function test_run_process_explicit_timeout_overrides_config(): void
    {
        $gw = new ClaudeCliGateway(['timeout' => 300]);
        try {
            $this->callMethod($gw, 'runProcess', [['sleep', '30'], null, 1]);
            $this->fail('Should have thrown');
        } catch (CliProcessException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public function test_run_process_defaults_to_300s_when_no_config(): void
    {
        $gw = new ClaudeCliGateway([]);
        $output = $this->callMethod($gw, 'runProcess', [['echo', 'ok'], null, null]);
        $this->assertSame("ok\n", $output);
    }

    // ── F16: Unicode and special characters ──

    public function test_format_all_messages_unicode_preserved(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'formatAllMessages', ['日本語', [new UserMessage('こんにちは')]]);
        $this->assertSame("日本語\n\nこんにちは", $r);
    }

    public function test_conversation_key_unicode_produces_valid_md5(): void
    {
        $gw = new ClaudeCliGateway([]);
        $k = $this->callMethod($gw, 'conversationKey', ['指示', [new UserMessage('世界')]]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $k);
    }

    public function test_make_text_response_emoji_and_unicode_preserved(): void
    {
        $gw = new ClaudeCliGateway([]);
        $provider = $this->mockProvider('test');
        $r = $this->callMethod($gw, 'makeTextResponse', ['Ñoño 🎉 émoji', $provider, 'model']);
        $this->assertSame('Ñoño 🎉 émoji', $r->text);
    }

    public function test_last_user_message_with_newlines_returned_intact(): void
    {
        $gw = new ClaudeCliGateway([]);
        $r = $this->callMethod($gw, 'lastUserMessage', [[
            new UserMessage("line1\nline2\nline3"),
        ]]);
        $this->assertSame("line1\nline2\nline3", $r);
    }

    // ── F17: Command injection safety ──

    public function test_build_command_shell_metacharacters_passed_as_literal(): void
    {
        $gw = new ClaudeCliGateway([]);
        $evil = 'echo "$(whoami)" && rm -rf / ; `cat /etc/passwd`';
        $cmd = $this->callMethod($gw, 'buildCommand', ['', $evil, null, null, false]);
        $idx = array_search('--system-prompt', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame($evil, $cmd[$idx + 1]);
    }

    // ── F18: onToolInvocation overwrite ──

    public function test_on_tool_invocation_second_call_overwrites_first(): void
    {
        $gw = new ClaudeCliGateway([]);
        $cb1 = function () { return 1; };
        $cb2 = function () { return 2; };
        $cb3 = function () { return 3; };
        $cb4 = function () { return 4; };
        $gw->onToolInvocation($cb1, $cb2);
        $gw->onToolInvocation($cb3, $cb4);
        $this->assertSame($cb3, $this->getProperty($gw, 'invokingToolCallback'));
        $this->assertSame($cb4, $this->getProperty($gw, 'toolInvokedCallback'));
    }

    public function test_on_tool_invocation_initial_state_is_null(): void
    {
        $gw = new ClaudeCliGateway([]);
        $this->assertNull($this->getProperty($gw, 'invokingToolCallback'));
        $this->assertNull($this->getProperty($gw, 'toolInvokedCallback'));
    }

    // ── F19: Stress / volume ──

    public function test_format_all_messages_100_messages_joined_correctly(): void
    {
        $gw = new ClaudeCliGateway([]);
        $messages = [];
        for ($i = 0; $i < 100; $i++) {
            $messages[] = new UserMessage("msg{$i}");
        }
        $r = $this->callMethod($gw, 'formatAllMessages', ['sys', $messages]);
        $parts = explode("\n\n", $r);
        $this->assertCount(101, $parts);
        $this->assertSame('sys', $parts[0]);
        $this->assertSame('msg99', $parts[100]);
    }

    public function test_conversation_key_100_messages_still_uses_first(): void
    {
        $gw = new ClaudeCliGateway([]);
        $messages = [];
        for ($i = 0; $i < 100; $i++) {
            $messages[] = new UserMessage("msg{$i}");
        }
        $k = $this->callMethod($gw, 'conversationKey', ['inst', $messages]);
        $this->assertSame(md5('inst'.'msg0'), $k);
    }

    // ── F20: Cross-gateway consistency ──

    public function test_all_gateways_environment_strips_same_vars(): void
    {
        $envConfig = ['env' => ['CLAUDECODE' => '1', 'CLAUDE_CODE_ENTRYPOINT' => '2', 'CLAUDE_CODE_SESSION' => '3']];
        $gateways = [
            new ClaudeCliGateway($envConfig),
            new CodexCliGateway($envConfig),
            new GeminiCliGateway($envConfig),
        ];
        foreach ($gateways as $gw) {
            $env = $this->callMethod($gw, 'environment');
            $this->assertArrayNotHasKey('CLAUDECODE', $env, get_class($gw).' should strip CLAUDECODE');
            $this->assertArrayNotHasKey('CLAUDE_CODE_ENTRYPOINT', $env, get_class($gw).' should strip entrypoint');
            $this->assertArrayNotHasKey('CLAUDE_CODE_SESSION', $env, get_class($gw).' should strip session');
        }
    }

    public function test_all_gateways_is_continuation_identical_behavior(): void
    {
        $gateways = [new ClaudeCliGateway([]), new CodexCliGateway([]), new GeminiCliGateway([])];
        $msgsTrue = [new UserMessage('a'), new AssistantMessage('b')];
        $msgsFalse = [new UserMessage('a')];
        foreach ($gateways as $gw) {
            $class = get_class($gw);
            $this->assertTrue($this->callMethod($gw, 'isContinuation', [$msgsTrue]), "{$class} continuation=true");
            $this->assertFalse($this->callMethod($gw, 'isContinuation', [$msgsFalse]), "{$class} continuation=false");
        }
    }

    public function test_all_gateways_conversation_key_same_for_same_inputs(): void
    {
        $gateways = [new ClaudeCliGateway([]), new CodexCliGateway([]), new GeminiCliGateway([])];
        $msgs = [new UserMessage('test')];
        $keys = [];
        foreach ($gateways as $gw) {
            $keys[] = $this->callMethod($gw, 'conversationKey', ['inst', $msgs]);
        }
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame($keys[1], $keys[2]);
    }

    public function test_all_gateways_make_text_response_returns_correct_structure(): void
    {
        $gateways = [new ClaudeCliGateway([]), new CodexCliGateway([]), new GeminiCliGateway([])];
        $provider = $this->mockProvider('test');
        foreach ($gateways as $gw) {
            $r = $this->callMethod($gw, 'makeTextResponse', ['hello', $provider, 'model']);
            $this->assertInstanceOf(TextResponse::class, $r);
            $this->assertSame('hello', $r->text);
            $this->assertSame('test', $r->meta->provider);
            $this->assertSame('model', $r->meta->model);
            $this->assertSame(0, $r->usage->promptTokens);
            $this->assertSame(0, $r->usage->completionTokens);
        }
    }
}
