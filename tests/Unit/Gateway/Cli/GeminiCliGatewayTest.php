<?php

namespace Tests\Unit\Gateway\Cli;

use Laravel\Ai\Gateway\Cli\GeminiCliGateway;
use PHPUnit\Framework\TestCase;

class GeminiCliGatewayTest extends TestCase
{
    private GeminiCliGateway $gw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gw = new GeminiCliGateway(['timeout' => 30]);
    }

    private function callMethod(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($obj, $args);
    }

    // ── A18: buildCommand() ──

    public function test_build_command_base_is_gemini_prompt_text(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', 'hello world']);
        $this->assertSame('gemini', $cmd[0]);
        $this->assertSame('--prompt', $cmd[1]);
        $this->assertSame('hello world', $cmd[2]);
        $this->assertCount(3, $cmd);
    }

    public function test_build_command_adds_model_when_provided(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['gemini-2.5-flash', 'test']);
        $idx = array_search('--model', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame('gemini-2.5-flash', $cmd[$idx + 1]);
    }

    public function test_build_command_does_not_add_model_for_empty_string(): void
    {
        $cmd = $this->callMethod($this->gw, 'buildCommand', ['', 'test']);
        $this->assertNotContains('--model', $cmd);
    }

    // ── A19: parseJsonOutput() ──

    public function test_parse_json_output_valid_json_returns_decoded(): void
    {
        $r = $this->callMethod($this->gw, 'parseJsonOutput', ['{"response":"hi"}']);
        $this->assertSame('hi', $r['response']);
    }

    public function test_parse_json_output_invalid_json_returns_response_trimmed(): void
    {
        $r = $this->callMethod($this->gw, 'parseJsonOutput', ['  plain text  ']);
        $this->assertSame('plain text', $r['response']);
    }

    // ── A20: binary() ──

    public function test_binary_returns_config_binary_when_set(): void
    {
        $gw = new GeminiCliGateway(['binary' => '/opt/gemini']);
        $this->assertSame('/opt/gemini', $this->callMethod($gw, 'binary'));
    }

    public function test_binary_returns_gemini_when_not_set(): void
    {
        $gw = new GeminiCliGateway([]);
        $this->assertSame('gemini', $this->callMethod($gw, 'binary'));
    }

    // ── F3: Gemini buildCommand falsy ──

    public function test_build_command_zero_model_not_added(): void
    {
        $gw = new GeminiCliGateway([]);
        $cmd = $this->callMethod($gw, 'buildCommand', ['0', 'test']);
        $this->assertNotContains('--model', $cmd);
    }

    // ── F12: Gemini edge cases ──

    public function test_empty_model_empty_prompt_minimal_command(): void
    {
        $gw = new GeminiCliGateway([]);
        $cmd = $this->callMethod($gw, 'buildCommand', ['', '']);
        $this->assertSame(['gemini', '--prompt', ''], $cmd);
    }

    public function test_parse_json_output_array_returns_as_is(): void
    {
        $gw = new GeminiCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ['["a","b"]']);
        $this->assertSame(['a', 'b'], $r);
    }

    public function test_parse_json_output_null_returns_fallback(): void
    {
        $gw = new GeminiCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ['null']);
        $this->assertIsArray($r);
        $this->assertSame('null', $r['response']);
    }

    public function test_parse_json_output_integer_returns_fallback(): void
    {
        $gw = new GeminiCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ['99']);
        $this->assertIsArray($r);
        $this->assertSame('99', $r['response']);
    }

    public function test_parse_json_output_empty_string_returns_fallback(): void
    {
        $gw = new GeminiCliGateway([]);
        $r = $this->callMethod($gw, 'parseJsonOutput', ['']);
        $this->assertIsArray($r);
        $this->assertSame('', $r['response']);
    }

    // ── F17: Command injection safety ──

    public function test_build_command_shell_metacharacters_passed_as_literal(): void
    {
        $gw = new GeminiCliGateway([]);
        $evil = '$(whoami) | cat /etc/passwd';
        $cmd = $this->callMethod($gw, 'buildCommand', ['', $evil]);
        $this->assertSame($evil, $cmd[2]);
    }
}
