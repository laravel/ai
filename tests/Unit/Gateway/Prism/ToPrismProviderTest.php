<?php

namespace Tests\Unit\Gateway\Prism;

use InvalidArgumentException;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Providers\Provider;
use Prism\Prism\Enums\Provider as PrismProvider;
use ReflectionMethod;
use Tests\TestCase;

class ToPrismProviderTest extends TestCase
{
    /**
     * Call the protected static toPrismProvider method via reflection.
     */
    protected function callToPrismProvider(Provider $provider): PrismProvider|string
    {
        $method = new ReflectionMethod(PrismGateway::class, 'toPrismProvider');

        return $method->invoke(null, $provider);
    }

    /**
     * Create a minimal Provider stub with the given driver name.
     */
    protected function makeProvider(string $driver): Provider
    {
        return new class($driver) extends Provider
        {
            public function __construct(string $driver)
            {
                parent::__construct(
                    new \Laravel\Ai\Gateway\Prism\PrismGateway(app('events')),
                    ['driver' => $driver, 'key' => 'test-key', 'name' => $driver],
                    app('events'),
                );
            }
        };
    }

    public function test_built_in_drivers_return_prism_provider_enum(): void
    {
        $mappings = [
            'anthropic' => PrismProvider::Anthropic,
            'azure' => PrismProvider::OpenAI,
            'deepseek' => PrismProvider::DeepSeek,
            'gemini' => PrismProvider::Gemini,
            'groq' => PrismProvider::Groq,
            'mistral' => PrismProvider::Mistral,
            'ollama' => PrismProvider::Ollama,
            'openai' => PrismProvider::OpenAI,
            'openrouter' => PrismProvider::OpenRouter,
            'voyageai' => PrismProvider::VoyageAI,
            'xai' => PrismProvider::XAI,
        ];

        foreach ($mappings as $driver => $expectedEnum) {
            $result = $this->callToPrismProvider($this->makeProvider($driver));

            $this->assertInstanceOf(PrismProvider::class, $result, "Driver '{$driver}' should return a PrismProvider enum");
            $this->assertSame($expectedEnum, $result, "Driver '{$driver}' mapped to wrong enum");
        }
    }

    public function test_unknown_driver_returns_string_for_custom_prism_providers(): void
    {
        $result = $this->callToPrismProvider($this->makeProvider('bedrock'));

        $this->assertIsString($result);
        $this->assertSame('bedrock', $result);
    }

    public function test_custom_driver_name_is_returned_as_is(): void
    {
        $result = $this->callToPrismProvider($this->makeProvider('workers-ai'));

        $this->assertIsString($result);
        $this->assertSame('workers-ai', $result);
    }
}
