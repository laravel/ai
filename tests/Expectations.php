<?php

expect()->extend('toBeValidAgentResponse', function (?string $provider = null): object {
    $this->text->not->toBeEmpty()
        ->steps->not->toBeEmpty();

    if ($provider !== null) {
        $this->meta->provider->toBe($provider);
    }

    return $this;
});

expect()->extend('toContainStreamEventTypes', function (array $eventClasses): object {
    $types = array_map(fn ($e) => $e::class, $this->value);

    foreach ($eventClasses as $class) {
        expect($types)->toContain($class);
    }

    return $this;
});

expect()->extend('toHaveUsage', function (int $promptTokens, int $completionTokens): object {
    $this->usage->promptTokens->toBe($promptTokens)
        ->usage->completionTokens->toBe($completionTokens);

    return $this;
});
