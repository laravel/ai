<?php

use Laravel\Ai\InvocationContext;

afterEach(fn () => InvocationContext::flush());

test('a root context has no parent and is its own root', function () {
    $context = InvocationContext::root('inv-1');

    expect($context->id)->toBe('inv-1')
        ->and($context->parentId)->toBeNull()
        ->and($context->rootId)->toBe('inv-1')
        ->and($context->isRoot())->toBeTrue();
});

test('there is no active context outside of run', function () {
    expect(InvocationContext::current())->toBeNull();
});

test('for() creates a root when no context is active', function () {
    $context = InvocationContext::for('inv-1');

    expect($context->parentId)->toBeNull()
        ->and($context->rootId)->toBe('inv-1');
});

test('for() nests beneath the active context', function () {
    InvocationContext::run(InvocationContext::root('parent'), function () {
        $child = InvocationContext::for('child');

        expect($child->id)->toBe('child')
            ->and($child->parentId)->toBe('parent')
            ->and($child->rootId)->toBe('parent')
            ->and($child->isRoot())->toBeFalse();
    });
});

test('rehydrate reconstructs a context with no tracked parent', function () {
    $context = InvocationContext::rehydrate('parent-inv', 'root-inv');

    expect($context->id)->toBe('parent-inv')
        ->and($context->parentId)->toBeNull()
        ->and($context->rootId)->toBe('root-inv');
});

test('rehydrate defaults the root to the id when none is given', function () {
    expect(InvocationContext::rehydrate('inv-1')->rootId)->toBe('inv-1');
});

test('run activates the context for the callback and restores the previous state after', function () {
    expect(InvocationContext::current())->toBeNull();

    $context = InvocationContext::root('inv-1');

    $seen = InvocationContext::run($context, fn () => InvocationContext::current());

    expect($seen)->toBe($context)
        ->and(InvocationContext::current())->toBeNull();
});

test('nested runs preserve the root across every level', function () {
    InvocationContext::run(InvocationContext::for('root'), function () {
        InvocationContext::run(InvocationContext::for('level-1'), function () {
            $level2 = InvocationContext::for('level-2');

            expect($level2->parentId)->toBe('level-1')
                ->and($level2->rootId)->toBe('root');
        });

        expect(InvocationContext::current()->id)->toBe('root');
    });
});

test('run pops the context even when the callback throws', function () {
    try {
        InvocationContext::run(InvocationContext::root('inv-1'), function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // Intentionally ignored - we only care that the stack was restored.
    }

    expect(InvocationContext::current())->toBeNull();
});

test('push and pop activate and deactivate the context manually', function () {
    $a = InvocationContext::root('a');
    $b = InvocationContext::root('b');

    InvocationContext::push($a);
    expect(InvocationContext::current())->toBe($a);

    InvocationContext::push($b);
    expect(InvocationContext::current())->toBe($b);

    InvocationContext::pop();
    expect(InvocationContext::current())->toBe($a);

    InvocationContext::pop();
    expect(InvocationContext::current())->toBeNull();
});

test('runRehydrated runs the callback with no active context when no parent id is given', function () {
    $seen = InvocationContext::runRehydrated(null, null, fn () => InvocationContext::current());

    expect($seen)->toBeNull()
        ->and(InvocationContext::current())->toBeNull();
});

test('runRehydrated re-establishes the carried context for the callback and restores after', function () {
    $seen = InvocationContext::runRehydrated('parent-inv', 'root-inv', fn () => InvocationContext::current());

    expect($seen)->toBeInstanceOf(InvocationContext::class)
        ->and($seen->id)->toBe('parent-inv')
        ->and($seen->rootId)->toBe('root-inv')
        ->and(InvocationContext::current())->toBeNull();
});

test('pop removes a specific context even when it is not on top', function () {
    $a = InvocationContext::root('a');
    $b = InvocationContext::root('b');

    InvocationContext::push($a);
    InvocationContext::push($b);

    // 'a' unwinds before 'b' (e.g. two interleaved streams consumed out of order).
    InvocationContext::pop($a);

    expect(InvocationContext::current())->toBe($b);

    InvocationContext::pop($b);

    expect(InvocationContext::current())->toBeNull();
});

test('popping a specific context is a no-op when it is no longer on the stack', function () {
    $a = InvocationContext::root('a');

    InvocationContext::push($a);
    InvocationContext::pop($a);

    // Popping again must not remove an unrelated entry.
    $b = InvocationContext::root('b');
    InvocationContext::push($b);
    InvocationContext::pop($a);

    expect(InvocationContext::current())->toBe($b);
});

test('a failed nested invocation does not leak context to its parent', function () {
    InvocationContext::run(InvocationContext::root('parent'), function () {
        try {
            InvocationContext::run(InvocationContext::for('child'), function () {
                throw new RuntimeException('failover');
            });
        } catch (RuntimeException) {
            // Simulates a provider failover that aborts the child invocation.
        }

        expect(InvocationContext::current()->id)->toBe('parent');
    });

    expect(InvocationContext::current())->toBeNull();
});
