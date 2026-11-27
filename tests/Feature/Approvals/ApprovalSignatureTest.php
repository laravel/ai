<?php

use Illuminate\Encryption\Encrypter;
use Laravel\Ai\Approvals\ApprovalSignature;

test('a signature verifies for the exact call it was issued for', function (): void {
    $signature = ApprovalSignature::sign('call-1', 'DeleteFile', ['path' => 'a.txt']);

    expect(ApprovalSignature::verify($signature, 'call-1', 'DeleteFile', ['path' => 'a.txt']))->toBeTrue();
});

test('a signature is rejected when any part of the call changes', function (string $id, string $tool, array $arguments): void {
    $signature = ApprovalSignature::sign('call-1', 'DeleteFile', ['path' => 'a.txt']);

    expect(ApprovalSignature::verify($signature, $id, $tool, $arguments))->toBeFalse();
})->with([
    'id' => ['call-2', 'DeleteFile', ['path' => 'a.txt']],
    'tool' => ['call-1', 'ReadFile', ['path' => 'a.txt']],
    'argument value' => ['call-1', 'DeleteFile', ['path' => 'b.txt']],
    'extra argument' => ['call-1', 'DeleteFile', ['path' => 'a.txt', 'force' => true]],
]);

test('a signature survives the changes a JavaScript client makes to arguments', function (): void {
    $signature = ApprovalSignature::sign('call-1', 'Charge', ['amount' => 5.0, 'meta' => ['b' => 1, 'a' => 2], 'tags' => ['x', 'y']]);

    expect(ApprovalSignature::verify($signature, 'call-1', 'Charge', ['tags' => ['x', 'y'], 'meta' => ['a' => 2, 'b' => 1], 'amount' => 5]))->toBeTrue();
});

test('reordering a list of arguments invalidates the signature', function (): void {
    $signature = ApprovalSignature::sign('call-1', 'Notify', ['to' => ['a', 'b']]);

    expect(ApprovalSignature::verify($signature, 'call-1', 'Notify', ['to' => ['b', 'a']]))->toBeFalse();
});

test('a signature issued under a previous app key still verifies', function (): void {
    $oldKey = Encrypter::generateKey(config('app.cipher'));
    $newKey = Encrypter::generateKey(config('app.cipher'));

    app()->instance('encrypter', new Encrypter($oldKey, config('app.cipher')));
    $signature = ApprovalSignature::sign('call-1', 'DeleteFile', []);

    app()->instance('encrypter', (new Encrypter($newKey, config('app.cipher')))->previousKeys([$oldKey]));

    expect(ApprovalSignature::verify($signature, 'call-1', 'DeleteFile', []))->toBeTrue();

    app()->instance('encrypter', new Encrypter($newKey, config('app.cipher')));

    expect(ApprovalSignature::verify($signature, 'call-1', 'DeleteFile', []))->toBeFalse();
});
