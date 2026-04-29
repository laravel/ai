<?php

$tools = [
    [
        'name' => 'say_hello',
        'description' => 'Say hello to a person.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ],
    ],
    [
        'name' => 'answer',
        'description' => 'Return a fixed answer.',
        'inputSchema' => [
            'type' => 'object',
            'additionalProperties' => false,
        ],
    ],
];

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode(trim($line), true);

    if (! is_array($message)) {
        continue;
    }

    $id = $message['id'] ?? null;
    $method = $message['method'] ?? null;

    if ($method === 'initialize') {
        fwrite(STDOUT, json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [
                    'tools' => ['listChanged' => true],
                ],
                'serverInfo' => [
                    'name' => 'test-mcp-server',
                    'version' => '1.0.0',
                ],
            ],
        ])."\n");

        fflush(STDOUT);

        continue;
    }

    if ($method === 'notifications/initialized') {
        continue;
    }

    if ($method === 'tools/list') {
        $cursor = $message['params']['cursor'] ?? null;

        fwrite(STDOUT, json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $cursor === null
                ? ['tools' => [$tools[0]], 'nextCursor' => 'next']
                : ['tools' => [$tools[1]]],
        ])."\n");

        fflush(STDOUT);

        continue;
    }

    if ($method === 'tools/call') {
        $name = $message['params']['name'] ?? '';
        $arguments = $message['params']['arguments'] ?? [];

        $text = $name === 'say_hello'
            ? 'Hello, '.($arguments['name'] ?? 'World')
            : '42';

        fwrite(STDOUT, json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => $text],
                ],
                'structuredContent' => ['value' => $text],
                'isError' => false,
            ],
        ])."\n");

        fflush(STDOUT);
    }
}
