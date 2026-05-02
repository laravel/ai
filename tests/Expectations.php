<?php

expect()->extend('toBeValidAgentResponse', function (?string $provider = null) {
    $this->text->not->toBeEmpty()
        ->steps->not->toBeEmpty();

    if ($provider !== null) {
        $this->meta->provider->toBe($provider);
    }

    return $this;
});

expect()->extend('toContainStreamEventTypes', function (array $eventClasses) {
    $types = array_map(fn ($e) => $e::class, $this->value);

    foreach ($eventClasses as $class) {
        expect($types)->toContain($class);
    }

    return $this;
});

expect()->extend('toHaveUsage', function (int $promptTokens, int $completionTokens) {
    $this->usage->promptTokens->toBe($promptTokens)
        ->usage->completionTokens->toBe($completionTokens);

    return $this;
});
