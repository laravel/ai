<?php

use Laravel\Ai\Sandboxes\BoatFactory;

beforeEach(function (): void {
    requiresApiKey('BOAT_API_KEY');

    $this->factory = new BoatFactory(['key' => env('BOAT_API_KEY'), 'type' => 'small', 'ttl' => 600]);
    $this->id = 'integration-'.uniqid();
});

afterEach(function (): void {
    if (isset($this->factory)) {
        $this->factory->forget($this->id);
    }
});

test('a boat sandbox runs commands and keeps files across a suspend and a checkpoint', function (): void {
    $sandbox = $this->factory->create($this->id);

    $sandbox->write('notes.txt', 'v1');

    expect($sandbox->exec('cat notes.txt && pwd')->stdout)->toBe("v1/home/user\n");

    $checkpoint = $this->factory->checkpoint($this->id);

    $sandbox->write('notes.txt', 'v2');

    $this->factory->suspend($this->id);
    $this->factory->restore($this->id, $checkpoint);

    expect($this->factory->create($this->id)->read('notes.txt'))->toBe('v1');
});
