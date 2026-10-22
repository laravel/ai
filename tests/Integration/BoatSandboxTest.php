<?php

use Laravel\Ai\Sandboxes\BoatProvider;

beforeEach(function (): void {
    requiresApiKey('BOAT_API_KEY');

    $this->provider = new BoatProvider(['name' => 'boat', 'driver' => 'boat', 'key' => env('BOAT_API_KEY'), 'type' => 'small', 'ttl' => 600]);
    $this->ids = [];
});

afterEach(function (): void {
    foreach ($this->ids ?? [] as $id) {
        $this->provider->delete($id);
    }
});

test('a boat sandbox runs commands, keeps files across a suspend, and restores a checkpoint into a new sandbox', function (): void {
    $sandbox = $this->provider->create();
    $this->ids[] = $sandbox->id();

    $sandbox->write('notes.txt', 'v1');

    expect($sandbox->exec('cat notes.txt && pwd')->stdout)->toBe("v1/home/user\n");

    $checkpoint = $this->provider->checkpoint($sandbox->id());

    $sandbox->write('notes.txt', 'v2');

    $this->provider->suspend($sandbox->id());
    $this->provider->resume($sandbox->id());

    $restored = $this->provider->restore($sandbox->id(), $checkpoint);
    $this->ids[] = $restored->id();

    expect($restored->read('notes.txt'))->toBe('v1');

    $this->provider->forgetCheckpoint($restored->id(), $checkpoint);
});
