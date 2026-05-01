<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\Fixtures\Agents\AssistantAgent;

function fakeWithToolCalls(array $toolCalls): void
{
    AssistantAgent::fake([
        (new TextResponse('ok', new Usage, new Meta))
            ->withToolCallsAndResults(new Collection($toolCalls), new Collection),
    ]);

    (new AssistantAgent)->prompt('Do something');
}

describe('assertAgentCalledTool', function () {
    test('passes when the named tool was called', function () {
        fakeWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: ['region' => 'napa']),
        ]);

        AssistantAgent::assertCalledTool('search_wines');
        Ai::assertAgentCalledTool(AssistantAgent::class, 'search_wines');
    });

    test('passes when the named tool was called among many', function () {
        fakeWithToolCalls([
            new ToolCall(id: '1', name: 'list_regions', arguments: []),
            new ToolCall(id: '2', name: 'search_wines', arguments: ['region' => 'napa']),
            new ToolCall(id: '3', name: 'rank_results', arguments: []),
        ]);

        AssistantAgent::assertCalledTool('search_wines');
    });

    test('fails when the named tool was not called', function () {
        fakeWithToolCalls([
            new ToolCall(id: '1', name: 'list_regions', arguments: []),
        ]);

        expect(fn () => AssistantAgent::assertCalledTool('search_wines'))
            ->toThrow('The expected tool [search_wines] was not called.');
    });

    test('passes when the tool was called with an exact argument subset', function () {
        fakeWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: [
                'region' => 'napa',
                'limit' => 10,
                'sort' => 'rating',
            ]),
        ]);

        AssistantAgent::assertCalledTool('search_wines', ['region' => 'napa']);
        AssistantAgent::assertCalledTool('search_wines', ['region' => 'napa', 'limit' => 10]);
    });

    test('passes when arguments contain a nested subset', function () {
        fakeWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: [
                'filter' => ['region' => 'napa', 'min_rating' => 90],
                'limit' => 10,
            ]),
        ]);

        AssistantAgent::assertCalledTool('search_wines', ['filter' => ['region' => 'napa']]);
    });

    test('fails when the tool was called but the argument value differs', function () {
        fakeWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: ['region' => 'sonoma']),
        ]);

        expect(fn () => AssistantAgent::assertCalledTool('search_wines', ['region' => 'napa']))
            ->toThrow('The tool [search_wines] was called, but not with the expected arguments.');
    });

    test('fails when the tool was called but a required argument is missing', function () {
        fakeWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: ['limit' => 10]),
        ]);

        expect(fn () => AssistantAgent::assertCalledTool('search_wines', ['region' => 'napa']))
            ->toThrow('The tool [search_wines] was called, but not with the expected arguments.');
    });

    test('passes when a closure predicate matches the arguments', function () {
        fakeWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: ['query' => 'cabernet napa 2019']),
        ]);

        AssistantAgent::assertCalledTool(
            'search_wines',
            fn (array $args) => str_contains($args['query'], 'napa'),
        );
    });

    test('fails when a closure predicate does not match', function () {
        fakeWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: ['query' => 'pinot oregon']),
        ]);

        expect(fn () => AssistantAgent::assertCalledTool(
            'search_wines',
            fn (array $args) => str_contains($args['query'], 'napa'),
        ))->toThrow('The tool [search_wines] was called, but not with the expected arguments.');
    });
});

describe('assertAgentDidNotCalledTool', function () {
    test('passes when the tool was not called', function () {
        fakeWithToolCalls([
            new ToolCall(id: '1', name: 'list_regions', arguments: []),
        ]);

        AssistantAgent::assertDidNotCalledTool('delete_database');
        Ai::assertAgentDidNotCalledTool(AssistantAgent::class, 'delete_database');
    });

    test('passes when no tools were called at all', function () {
        fakeWithToolCalls([]);

        AssistantAgent::assertDidNotCalledTool('anything');
    });

    test('fails when the tool was called', function () {
        fakeWithToolCalls([
            new ToolCall(id: '1', name: 'delete_database', arguments: []),
        ]);

        expect(fn () => AssistantAgent::assertDidNotCalledTool('delete_database'))
            ->toThrow('The unexpected tool [delete_database] was called.');
    });
});

describe('assertAgentCalledNoTools', function () {
    test('passes when the agent made no tool calls', function () {
        fakeWithToolCalls([]);

        AssistantAgent::assertCalledNoTools();
        Ai::assertAgentCalledNoTools(AssistantAgent::class);
    });

    test('fails when any tool was called and lists the called tools', function () {
        fakeWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: []),
            new ToolCall(id: '2', name: 'rank_results', arguments: []),
        ]);

        expect(fn () => AssistantAgent::assertCalledNoTools())
            ->toThrow('search_wines, rank_results');
    });
});
