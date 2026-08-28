<?php

namespace Laravel\Ai\Tools;

use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;
use Laravel\Ai\Attributes\Bind;
use Laravel\Ai\Contracts\Tool;
use ReflectionMethod;
use ReflectionParameter;

class ModelBinder
{
    /**
     * The resolved models keyed by handler parameter name.
     *
     * @var array<string, UrlRoutable>
     */
    protected array $bindings = [];

    /**
     * The resolved models keyed by tool argument name.
     *
     * @var array<string, UrlRoutable>
     */
    protected array $models = [];

    /**
     * The name of the handler parameter that receives the request.
     */
    protected ?string $requestParameter = null;

    /**
     * The message describing the first argument that could not be resolved.
     */
    protected ?string $error = null;

    /**
     * Resolve the models bound to the given tool's handler.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(Tool $tool, protected array $arguments = [])
    {
        foreach ((new ReflectionMethod($tool, 'handle'))->getParameters() as $parameter) {
            if (! $class = Reflector::getParameterClassName($parameter)) {
                continue;
            }

            if (is_a($class, Request::class, true)) {
                $this->requestParameter ??= $parameter->getName();
            } elseif (Reflector::isParameterSubclassOf($parameter, UrlRoutable::class)) {
                $this->bind($parameter, $class);
            }
        }
    }

    /**
     * Get the arguments the tool's handler should be invoked with.
     *
     * @return array<string, mixed>
     */
    public function parameters(Request $request): array
    {
        return is_null($this->requestParameter)
            ? $this->bindings
            : [$this->requestParameter => $request, ...$this->bindings];
    }

    /**
     * Get the resolved models keyed by tool argument name.
     *
     * @return array<string, UrlRoutable>
     */
    public function models(): array
    {
        return $this->models;
    }

    /**
     * Get the message describing the first argument that could not be resolved.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * Resolve the model for the given handler parameter.
     *
     * @param  class-string<UrlRoutable>  $class
     */
    protected function bind(ReflectionParameter $parameter, string $class): void
    {
        if (is_null($name = $this->argumentName($parameter))) {
            return;
        }

        $value = $this->arguments[$name];

        if (is_null($value)) {
            return;
        }

        if ($value instanceof $class) {
            $this->models[$name] = $this->bindings[$parameter->getName()] = $value;

            return;
        }

        $instance = Container::getInstance()->make($class);

        $model = is_scalar($value) ? $instance->resolveRouteBinding($value) : null;

        if (is_null($model)) {
            $this->error ??= sprintf(
                'No %s was found matching %s [%s].',
                Str::snake(class_basename($class), ' '),
                $instance->getRouteKeyName(),
                is_scalar($value) ? $value : json_encode($value),
            );

            return;
        }

        $this->models[$name] = $this->bindings[$parameter->getName()] = $model;
    }

    /**
     * Get the tool argument name feeding the given handler parameter.
     */
    protected function argumentName(ReflectionParameter $parameter): ?string
    {
        $attribute = ($parameter->getAttributes(Bind::class)[0] ?? null)?->newInstance();

        $name = $attribute?->name ?? $parameter->getName();

        return match (true) {
            array_key_exists($name, $this->arguments) => $name,
            array_key_exists($snaked = Str::snake($name), $this->arguments) => $snaked,
            default => null,
        };
    }
}
