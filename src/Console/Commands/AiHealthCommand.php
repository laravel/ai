<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'ai:health')]
class AiHealthCommand extends Command
{
    protected $signature = 'ai:health
        {--provider= : Check a specific provider instead of all}
        {--stream : Also test streaming capability}
        {--json : Output results as JSON}
        {--fail-on= : Exit with error if status matches (healthy, degraded, unhealthy)}';

    protected $description = 'Check AI provider configuration and availability';

    public function handle(AiManager $aiManager): int
    {
        $providers = $this->getProvidersToCheck($aiManager);
        $results = [];

        foreach ($providers as $name => $config) {
            $results[$name] = $this->checkProvider($name, $config);
        }

        if ($this->option('json')) {
            $this->outputJson($results);
            return $this->determineExitCode($results);
        }

        $this->outputTable($results);

        return $this->determineExitCode($results);
    }

    protected function getProvidersToCheck(AiManager $aiManager): array
    {
        $provider = $this->option('provider');

        if ($provider) {
            $config = $aiManager->getInstanceConfig($provider);
            return [$provider => $config];
        }

        return $aiManager->getInstanceConfig($aiManager->getDefaultInstance() ?? 'openai') 
            ? [$aiManager->getDefaultInstance() => $aiManager->getInstanceConfig($aiManager->getDefaultInstance())]
            : [];
    }

    protected function checkProvider(string $name, array $config): array
    {
        $driver = $config['driver'] ?? $name;
        
        $result = [
            'name' => $name,
            'driver' => $driver,
            'status' => 'healthy',
            'config_valid' => true,
            'api_key_present' => !empty($config['key']),
            'connectivity' => null,
            'streaming' => null,
            'latency_ms' => null,
            'error' => null,
        ];

        if (empty($config['key']) && $driver !== 'ollama') {
            $result['config_valid'] = false;
            $result['status'] = 'unhealthy';
            $result['error'] = 'API key is missing';
            return $result;
        }

        $connectivityResult = $this->testConnectivity($config);
        $result['connectivity'] = $connectivityResult['status'];
        $result['latency_ms'] = $connectivityResult['latency_ms'];

        if ($connectivityResult['status'] === 'unhealthy') {
            $result['status'] = 'unhealthy';
            $result['error'] = $connectivityResult['error'];
            return $result;
        }

        if ($connectivityResult['status'] === 'degraded') {
            $result['status'] = 'degraded';
            $result['error'] = $connectivityResult['error'];
        }

        if ($this->option('stream')) {
            $streamingResult = $this->testStreaming($config);
            $result['streaming'] = $streamingResult['status'];

            if ($streamingResult['status'] === 'unhealthy') {
                $result['status'] = 'degraded';
                $result['error'] = $streamingResult['error'] ?? 'Streaming test failed';
            }
        }

        return $result;
    }

    protected function testConnectivity(array $config): array
    {
        $driver = $config['driver'] ?? '';
        $startTime = microtime(true);

        try {
            $response = $this->makeTestRequest($driver, $config);
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                return [
                    'status' => 'healthy',
                    'latency_ms' => $latencyMs,
                ];
            }

            return [
                'status' => 'unhealthy',
                'latency_ms' => $latencyMs,
                'error' => 'API request failed: ' . $response->status(),
            ];
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            return [
                'status' => 'unhealthy',
                'latency_ms' => $latencyMs,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function makeTestRequest(string $driver, array $config)
    {
        $baseUrl = $this->getBaseUrl($driver, $config);
        $endpoint = $this->getEndpoint($driver);
        $headers = $this->getHeaders($driver, $config);

        return Http::withHeaders($headers)
            ->timeout(10)
            ->post($baseUrl . $endpoint, [
                'model' => $this->getTestModel($driver),
                'messages' => [['role' => 'user', 'content' => 'Hi']],
                'max_tokens' => 1,
            ]);
    }

    protected function getEndpoint(string $driver): string
    {
        return match ($driver) {
            'ollama' => '/api/chat',
            default => '/chat/completions',
        };
    }

    protected function testStreaming(array $config): array
    {
        $driver = $config['driver'] ?? '';

        try {
            $baseUrl = $this->getBaseUrl($driver, $config);
            $endpoint = $this->getEndpoint($driver);
            $headers = $this->getHeaders($driver, $config);

            $client = new \GuzzleHttp\Client([
                'base_uri' => $baseUrl,
                'headers' => $headers,
                'timeout' => 15,
            ]);

            $response = $client->post($endpoint, [
                'json' => [
                    'model' => $this->getTestModel($driver),
                    'messages' => [['role' => 'user', 'content' => 'Hi']],
                    'stream' => true,
                ],
                'stream' => true,
            ]);

            $body = $response->getBody();
            $receivedData = false;
            while (!$body->eof()) {
                $chunk = $body->read(1024);
                if (!empty($chunk)) {
                    $receivedData = true;
                    break;
                }
            }

            return $receivedData 
                ? ['status' => 'healthy']
                : ['status' => 'unhealthy', 'error' => 'No streaming data received'];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getBaseUrl(string $driver, array $config): string
    {
        return match ($driver) {
            'ollama' => $config['url'] ?? 'http://localhost:11434',
            'azure' => rtrim($config['url'] ?? '', '/'),
            default => 'https://api.openai.com/v1',
        };
    }

    protected function getHeaders(string $driver, array $config): array
    {
        $headers = ['Content-Type' => 'application/json'];

        $apiKey = $config['key'] ?? '';

        if ($driver === 'azure') {
            $headers['api-key'] = $apiKey;
        } else {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        if (isset($config['api_version'])) {
            $headers['api-key'] = $config['key'] ?? '';
            $headers['Accept'] = 'application/json';
        }

        return $headers;
    }

    protected function getTestModel(string $driver): string
    {
        return match ($driver) {
            'openai' => 'gpt-4o-mini',
            'anthropic' => 'claude-3-haiku-20240307',
            'gemini' => 'gemini-2.0-flash',
            'azure' => $config['deployment'] ?? 'gpt-4o',
            'ollama' => 'llama3.2',
            'deepseek' => 'deepseek-chat',
            'groq' => 'llama-3.1-70b-versatile',
            'mistral' => 'mistral-small-latest',
            'cohere' => 'command-r-plus',
            'openrouter' => 'openai/gpt-4o-mini',
            'voyageai' => 'voyage-3',
            'xai' => 'grok-2-1212',
            'jina' => 'jina-chat',
            'eleven' => 'eleven_multilingual_v2',
            default => 'gpt-4o-mini',
        };
    }

    protected function outputTable(array $results): void
    {
        $headers = ['Provider', 'Driver', 'Status', 'API Key', 'Connectivity', 'Latency', 'Streaming'];

        $rows = collect($results)->map(function ($result) {
            return [
                $result['name'],
                $result['driver'],
                $this->formatStatus($result['status']),
                $result['api_key_present'] ? '✓' : '✗',
                $result['connectivity'] ?? 'N/A',
                $result['latency_ms'] ? $result['latency_ms'] . 'ms' : 'N/A',
                $result['streaming'] ?? 'N/A',
            ];
        })->toArray();

        $this->table($headers, $rows);
    }

    protected function outputJson(array $results): void
    {
        $output = [
            'timestamp' => now()->toIso8601String(),
            'providers' => array_values($results),
            'summary' => [
                'total' => count($results),
                'healthy' => count(array_filter($results, fn($r) => $r['status'] === 'healthy')),
                'degraded' => count(array_filter($results, fn($r) => $r['status'] === 'degraded')),
                'unhealthy' => count(array_filter($results, fn($r) => $r['status'] === 'unhealthy')),
            ],
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }

    protected function formatStatus(string $status): string
    {
        return match ($status) {
            'healthy' => '<fg=green>✓ Healthy</>',
            'degraded' => '<fg=yellow>⚠ Degraded</>',
            'unhealthy' => '<fg=red>✗ Unhealthy</>',
            default => $status,
        };
    }

    protected function determineExitCode(array $results): int
    {
        $failOn = $this->option('fail-on');

        if (!$failOn) {
            $hasUnhealthy = collect($results)->contains(fn($r) => $r['status'] === 'unhealthy');
            return $hasUnhealthy ? 1 : 0;
        }

        $statuses = collect($results)->pluck('status')->toArray();

        return match ($failOn) {
            'healthy' => in_array('healthy', $statuses) ? 0 : 2,
            'degraded' => in_array('degraded', $statuses) || in_array('healthy', $statuses) ? 0 : 2,
            'unhealthy' => in_array('unhealthy', $statuses) ? 2 : 0,
            default => 0,
        };
    }
}
