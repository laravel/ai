<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Feature\Agents\AssistantAgent;

function makeResponseWithToolCalls(array $toolCalls): TextResponse
{
    return (new TextResponse('', new Usage, new Meta))
        ->withToolCallsAndResults(
            new Collection($toolCalls),
            new Collection,
        );
}

describe('assertAgentCalledTool', function () {
    test('passes when the named tool was called', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: ['region' => 'napa']),
        ]);

        Ai::assertAgentCalledTool($response, 'search_wines');
    });

    test('passes when the named tool was called among many', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'list_regions', arguments: []),
            new ToolCall(id: '2', name: 'search_wines', arguments: ['region' => 'napa']),
            new ToolCall(id: '3', name: 'rank_results', arguments: []),
        ]);

        Ai::assertAgentCalledTool($response, 'search_wines');
    });

    test('fails when the named tool was not called', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'list_regions', arguments: []),
        ]);

        Ai::assertAgentCalledTool($response, 'search_wines');
    })->throws(AssertionFailedError::class, 'The expected tool [search_wines] was not called.');

    test('passes when the tool was called with an exact argument subset', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: [
                'region' => 'napa',
                'limit' => 10,
                'sort' => 'rating',
            ]),
        ]);

        Ai::assertAgentCalledTool($response, 'search_wines', ['region' => 'napa']);
        Ai::assertAgentCalledTool($response, 'search_wines', ['region' => 'napa', 'limit' => 10]);
    });

    test('passes when arguments contain a nested subset', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: [
                'filter' => ['region' => 'napa', 'min_rating' => 90],
                'limit' => 10,
            ]),
        ]);

        Ai::assertAgentCalledTool($response, 'search_wines', [
            'filter' => ['region' => 'napa'],
        ]);
    });

    test('fails when the tool was called but the argument value differs', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: ['region' => 'sonoma']),
        ]);

        Ai::assertAgentCalledTool($response, 'search_wines', ['region' => 'napa']);
    })->throws(AssertionFailedError::class, 'The tool [search_wines] was called, but not with the expected arguments.');

    test('fails when the tool was called but a required argument is missing', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: ['limit' => 10]),
        ]);

        Ai::assertAgentCalledTool($response, 'search_wines', ['region' => 'napa']);
    })->throws(AssertionFailedError::class);

    test('passes when a closure predicate matches the arguments', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: ['query' => 'cabernet napa 2019']),
        ]);

        Ai::assertAgentCalledTool(
            $response,
            'search_wines',
            fn (array $args) => str_contains($args['query'], 'napa'),
        );
    });

    test('fails when a closure predicate does not match', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: ['query' => 'pinot oregon']),
        ]);

        Ai::assertAgentCalledTool(
            $response,
            'search_wines',
            fn (array $args) => str_contains($args['query'], 'napa'),
        );
    })->throws(AssertionFailedError::class);

    test('static helper on Promptable delegates correctly', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: ['region' => 'napa']),
        ]);

        AssistantAgent::assertCalledTool($response, 'search_wines');
        AssistantAgent::assertCalledTool($response, 'search_wines', ['region' => 'napa']);
    });
});

describe('assertAgentDidNotCallTool', function () {
    test('passes when the tool was not called', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'list_regions', arguments: []),
        ]);

        Ai::assertAgentDidNotCallTool($response, 'delete_database');
    });

    test('passes when no tools were called at all', function () {
        $response = makeResponseWithToolCalls([]);

        Ai::assertAgentDidNotCallTool($response, 'anything');
    });

    test('fails when the tool was called', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'delete_database', arguments: []),
        ]);

        Ai::assertAgentDidNotCallTool($response, 'delete_database');
    })->throws(AssertionFailedError::class, 'The unexpected tool [delete_database] was called.');

    test('static helper on Promptable delegates correctly', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'list_regions', arguments: []),
        ]);

        AssistantAgent::assertDidNotCallTool($response, 'delete_database');
    });
});

describe('assertAgentCalledNoTools', function () {
    test('passes when the response has no tool calls', function () {
        $response = makeResponseWithToolCalls([]);

        Ai::assertAgentCalledNoTools($response);
    });

    test('fails when any tool was called and lists the called tools', function () {
        $response = makeResponseWithToolCalls([
            new ToolCall(id: '1', name: 'search_wines', arguments: []),
            new ToolCall(id: '2', name: 'rank_results', arguments: []),
        ]);

        Ai::assertAgentCalledNoTools($response);
    })->throws(AssertionFailedError::class, 'search_wines, rank_results');

    test('static helper on Promptable delegates correctly', function () {
        $response = makeResponseWithToolCalls([]);

        AssistantAgent::assertCalledNoTools($response);
    });
});
