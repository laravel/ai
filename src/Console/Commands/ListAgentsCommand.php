<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Providers\Tools\ProviderTool;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\table;

#[AsCommand(name: 'ai:list-agents')]
class ListAgentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:list-agents';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all registered agents';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $agents = $this->discoverAgents();

        if (empty($agents)) {
            $this->warn('No agents found. Create agents using: php artisan make:agent');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($agents as $agent) {
            $reflection = new ReflectionClass($agent);
            $interfaces = $reflection->getInterfaceNames();
            $traits = array_map(fn ($trait) => class_basename($trait), $reflection->getTraits());

            $features = [];
            if (in_array(\Laravel\Ai\Contracts\Conversational::class, $interfaces)) {
                $features[] = 'Conversational';
            }
            if (in_array(HasTools::class, $interfaces)) {
                $toolNames = $this->getAgentToolNames($agent, $reflection);
                if (! empty($toolNames)) {
                    $features[] = implode(', ', $toolNames);
                }
            }
            if ($reflection->hasMethod('instructions')) {
                try {
                    $instructions = $reflection->getMethod('instructions')->invoke(
                        $reflection->newInstanceWithoutConstructor()
                    );
                    $instructionsPreview = Str::limit((string) $instructions, 50);
                } catch (\Exception $e) {
                    $instructionsPreview = 'N/A';
                }
            } else {
                $instructionsPreview = 'N/A';
            }

            $structuredOutput = in_array(HasStructuredOutput::class, $interfaces) ? 'true' : 'false';

            $rows[] = [
                class_basename($agent),
                $this->getNamespace($agent),
                $instructionsPreview,
                implode(', ', $features) ?: 'None',
                $structuredOutput,
            ];
        }

        table(
            headers: ['Agent', 'Namespace', 'Instructions Preview', 'Features', 'Structured Output'],
            rows: $rows,
        );

        $this->newLine();
        $this->info('Total agents: '.count($agents));

        return self::SUCCESS;
    }

    /**
     * Discover all agent classes in the application.
     *
     * Uses the same path as make:agent (getDefaultNamespace returns App\Ai\Agents,
     * which maps to app/Ai/Agents).
     */
    protected function discoverAgents(): array
    {
        $agents = [];

        $agentsPath = app_path('Ai/Agents');

        if (! is_dir($agentsPath)) {
            return $agents;
        }

        $files = glob($agentsPath.DIRECTORY_SEPARATOR.'*.php');

        foreach ($files as $file) {
            $className = $this->getClassNameFromFile($file);

            if (! $className) {
                continue;
            }

            try {
                if (! class_exists($className)) {
                    require_once $file;
                }

                $reflection = new ReflectionClass($className);

                if ($reflection->implementsInterface(\Laravel\Ai\Contracts\Agent::class) &&
                    ! $reflection->isAbstract() &&
                    ! $reflection->isInterface()) {
                    $agents[] = $className;
                }
            } catch (\Exception $e) {
                // Skip files that can't be loaded or don't implement Agent
                continue;
            }
        }

        return $agents;
    }

    /**
     * Get the list of tool names for an agent that implements HasTools.
     *
     * @return array<int, string>
     */
    protected function getAgentToolNames(string $agentClass, ReflectionClass $reflection): array
    {
        try {
            $agent = $reflection->newInstanceWithoutConstructor();
            $tools = $agent->tools();
        } catch (\Throwable) {
            return [];
        }

        $names = [];
        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                $names[] = class_basename($tool);
            } else {
                $names[] = method_exists($tool, 'name')
                    ? $tool->name()
                    : class_basename($tool);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Get the fully qualified class name from a file path.
     */
    protected function getClassNameFromFile(string $file): ?string
    {
        $content = file_get_contents($file);

        if (! $content) {
            return null;
        }

        // Extract namespace
        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
            $namespace = $namespaceMatches[1];
        } else {
            return null;
        }

        // Extract class name
        if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            $className = $classMatches[1];

            return $namespace.'\\'.$className;
        }

        return null;
    }

    /**
     * Get a shortened namespace for display.
     */
    protected function getNamespace(string $className): string
    {
        $namespace = (new ReflectionClass($className))->getNamespaceName();

        // Shorten App\Ai\Agents to just show it's in the default location
        if (Str::startsWith($namespace, 'App\\Ai\\Agents')) {
            return 'App\\Ai\\Agents';
        }

        return $namespace;
    }
}
