<?php

use Illuminate\Queue\Events\Looping;
use Laravel\Ai\InvocationContext;

afterEach(fn () => InvocationContext::flush());

test('the queue worker looping event flushes a leaked invocation context', function () {
    // Simulate a previous job that leaked context onto the worker-global stack.
    InvocationContext::push(InvocationContext::root('leaked-from-prior-job'));

    expect(InvocationContext::current())->not->toBeNull();

    event(new Looping('redis', 'default'));

    expect(InvocationContext::current())->toBeNull();
});

test('an octane request boundary flushes a leaked invocation context', function () {
    InvocationContext::push(InvocationContext::root('leaked-from-prior-request'));

    expect(InvocationContext::current())->not->toBeNull();

    event('Laravel\Octane\Events\RequestReceived');

    expect(InvocationContext::current())->toBeNull();
});
